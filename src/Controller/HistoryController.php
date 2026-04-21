<?php

declare(strict_types=1);

namespace App\Controller;

use App\Brain\BrainRegistry;
use App\Brain\ChatHistory\UserChatHistory;
use App\Entity\ChatHistory as ChatHistoryEntity;
use App\Job\Web\StartThreadJob;
use App\Services\Auth;
use App\Services\ChatStreamPublisher;
use App\Services\Queue\QueueDispatcherInterface;
use App\Services\Session\Trait\SessionFromRequest;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Slim\Views\Twig;

final readonly class HistoryController
{
    use SessionFromRequest;

    public function __construct(
        private Logger $logger,
        private Twig $twig,
        private EntityManagerInterface $entityManager,
        private BrainRegistry $brainRegistry,
        private Settings $settings,
        private ChatStreamPublisher $chatStreamPublisher,
        private QueueDispatcherInterface $queueDispatcher,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * Crée une nouvelle conversation :
     * - génère un nouveau threadId
     * - le place dans la session sous `threadId`
     * - retourne un fragment HTML vide pour remplacer la zone #messages
     */
    public function create(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);
        $sessionId = trim((string) ($request->getParsedBody()['sessionId'] ?? $request->getQueryParams()['sessionId'] ?? ''));

        // Nettoyage des conversations vides de l'utilisateur
        $this->entityManager->getRepository(ChatHistoryEntity::class)->deleteEmptyConversations((string) $session->get(Auth::USERID));

        $session->get('brain_avatar');
        // Nouveau thread
        $threadId = uniqid(UserChatHistory::CHAT_WEB, true);
        $this->queueDispatcher->dispatch(
            StartThreadJob::class,
            [
                'threadId' => $threadId,
                'sessionId' => $sessionId,
                'session' => $session->all(),
            ],
            $this->settings->get('queue.defaultQueue')
        );

        $this->publishSnapshot($threadId, null, $sessionId);

        $response->getBody()->write(json_encode([
            'threadId' => $threadId,
        ], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Compte le nombre d'historiques de conversation associés à l'utilisateur en session.
     * - Récupère l'ID utilisateur depuis la session
     * - Interroge le référentiel d'historique pour obtenir le compte
     * - Écrit le résultat en tant que réponse.
     *
     * @param Request $request L'objet requête contenant les informations de la requête HTTP.
     * @param Response $response L'objet réponse pour envoyer les données de la réponse HTTP.
     *
     * @return Response La réponse contenant le nombre d'historiques de conversation de l'utilisateur.
     */
    public function count(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);

        $userId = (string) $session->get(Auth::USERID);
        $count = $this->entityManager->getRepository(ChatHistoryEntity::class)->countByUserId($userId);
        $response->getBody()->write((string) $count);
        return $response;
    }

    /**
     * Récupère la liste des historiques de conversation de l'utilisateur en session.
     * - Charge les historiques appartenant à l'utilisateur identifié via la session
     * - Retourne le HTML pour mettre à jour le conteneur #history-list (HTMX).
     *
     * @param Request $request La requête HTTP courante
     * @param Response $response La réponse HTTP courante
     *
     * @return Response La réponse modifiée contenant le rendu des historiques
     */
    public function list(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);

        $userId = (string) $session->get(Auth::USERID);
        $histories = $this->entityManager->getRepository(ChatHistoryEntity::class)->getHistoryList($userId);
        return $this->twig->render($response, 'partials/history_list.twig', [
            'histories' => $histories,
        ]);
    }

    /**
     * Ouvre une conversation de l'historique et remplace la conversation courante.
     * - Vérifie que l'historique appartient à l'utilisateur en session
     * - Met à jour la session `threadId` avec le `thread_id` sélectionné
     * - Retourne le HTML des messages pour remplacer le conteneur #messages (HTMX).
     */
    public function open(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);

        $userId = (string) $session->get(Auth::USERID);
        if ($userId === '') {
            return $response->withStatus(403);
        }

        // Nettoyage des conversations vides de l'utilisateur
        $this->entityManager->getRepository(ChatHistoryEntity::class)->deleteEmptyConversations($userId);

        $threadId = $request->getAttribute('threadId');
        if ($threadId === null) {
            return $response->withStatus(400);
        }

        $session->set('threadId', $threadId);

        $userChatHistory = new UserChatHistory(
            session: $session,
            pdo: $this->entityManager->getConnection()->getNativeConnection(),
            contextWindow: $this->settings->get('llm.openai.contextWindow'),
            threadId: $threadId
        );
        $userChatHistory->validateMessageSequences();

        $messages = $userChatHistory->getFormattedMessages();
        if ($messages === []) {
            return $response->withStatus(400);
        }

        // sessionId from request (per-tab SSE binding key)
        $sessionId = trim((string) ($request->getParsedBody()['sessionId'] ?? $request->getQueryParams()['sessionId'] ?? ''));
        $this->publishSnapshot($threadId, $userChatHistory, $sessionId);

        $response->getBody()->write(json_encode([
            'threadId' => $threadId,
        ], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Supprime une conversation (par threadId) appartenant à l'utilisateur courant.
     * Retourne 204 en cas de succès (prévu pour HTMX: suppression de l'élément de liste côté client).
     */
    public function delete(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);

        $userId = (string) $session->get(Auth::USERID);
        if ($userId === '') {
            return $response->withStatus(403);
        }

        $threadId = $request->getAttribute('threadId');
        if (! is_string($threadId) || $threadId === '') {
            return $response->withStatus(400);
        }

        if (! $this->entityManager->getRepository(ChatHistoryEntity::class)->deleteThread($userId, $threadId, $this->filesystem)) {
            return $response->withStatus(400);
        }

        // Important for HTMX swap: return 200 with an empty body so that
        // hx-swap="outerHTML" on the <li> effectively removes the element.
        $response->getBody()->write('');
        return $response->withStatus(200);
    }

    /**
     * Supprime le dernier échange de la conversation courante et retourne le message utilisateur supprimé.
     */
    public function deleteLastExchange(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);

        $userId = (string) $session->get(Auth::USERID);
        if ($userId === '') {
            return $response->withStatus(403);
        }

        $threadId = (string) $session->get('threadId');
        if ($threadId === '') {
            return $response->withStatus(400);
        }

        $userChatHistory = new UserChatHistory(
            session: $session,
            pdo: $this->entityManager->getConnection()->getNativeConnection(),
            contextWindow: $this->settings->get('llm.openai.contextWindow')
        );
        $userChatHistory->setThreadId($threadId);

        $removedMessage = $userChatHistory->removeLastExchange();
        if ($removedMessage === null) {
            return $response->withStatus(400);
        }

        // sessionId from request (per-tab SSE binding key)
        $sessionId = trim((string) ($request->getParsedBody()['sessionId'] ?? $request->getQueryParams()['sessionId'] ?? ''));
        $this->publishSnapshot($threadId, $userChatHistory, $sessionId);

        $response->getBody()->write(json_encode([
            'threadId' => $threadId,
            'removedMessage' => $removedMessage,
        ], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function publishSnapshot(string $threadId, ?UserChatHistory $userChatHistory, string $sessionId): void
    {
        $messages = null;
        if ($userChatHistory instanceof \App\Brain\ChatHistory\UserChatHistory) {
            $messages = $userChatHistory->getFormattedMessages();
        }

        $messagesHtml = $this->twig->fetch('partials/messages_list.twig', [
            'messages' => $messages,
        ]);

        // Use sessionId as channel if provided, otherwise fall back to threadId
        $channelId = $sessionId !== '' ? $sessionId : $threadId;
        $this->chatStreamPublisher->publish($channelId, 'chat.snapshot', [
            'threadId' => $threadId,
            'sessionId' => $sessionId,
            'html' => $messagesHtml,
        ]);
    }
}
