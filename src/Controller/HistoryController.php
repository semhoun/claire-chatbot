<?php

declare(strict_types=1);

namespace App\Controller;

use App\Brain\BrainRegistry;
use App\Brain\ChatHistory\UserChatHistory;
use App\Entity\ChatHistory as ChatHistoryEntity;
use App\Services\Auth;
use App\Services\Session\SessionFromRequestTrait;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final readonly class HistoryController
{
    use SessionFromRequestTrait;

    public function __construct(
        private Twig $twig,
        private EntityManagerInterface $entityManager,
        private BrainRegistry $brainRegistry,
        private Settings $settings,
    ) {
    }

    /**
     * Crée une nouvelle conversation :
     * - génère un nouveau threadId
     * - le place dans la session sous `chatId`
     * - retourne un fragment HTML vide pour remplacer la zone #messages
     */
    public function create(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);

        // Nettoyage des conversations vides de l'utilisateur
        $userId = (string) $session->get(Auth::USERID);
        if ($userId !== '') {
            $this->entityManager->getRepository(ChatHistoryEntity::class)->deleteEmptyConversations($userId);
        }

        // Nouveau thread
        $threadId = uniqid(UserChatHistory::CHAT_WEB, true);
        $session->set('chatId', $threadId);
        $userChatHistory = new UserChatHistory(
            session: $session,
            pdo: $this->entityManager->getConnection()->getNativeConnection(),
            table: UserChatHistory::TABLE,
            contextWindow: $this->settings->get('llm.openai.contextWindow')
        );
        $userChatHistory->setThreadId($threadId);

        $currentBrain = $session->get('brain_avatar');
        $assistantMessage = new AssistantMessage(
            $this->brainRegistry->get($currentBrain, $session)->getOpeningText()
        );
        $assistantMessage->addMetadata('timestamp', new \DateTimeImmutable()->format(\DateTimeInterface::ATOM));

        $messages = $userChatHistory->getFormattedMessages($session->get('chat_mode'), [$assistantMessage]);

        return $this->twig->render($response, 'partials/messages_list.twig', [
            'messages' => $messages,
        ]);
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
     * - Met à jour la session `chatId` avec le `thread_id` sélectionné
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

        $session->set('chatId', $threadId);
        $userChatHistory = new UserChatHistory(
            session: $session,
            pdo: $this->entityManager->getConnection()->getNativeConnection(),
            table: UserChatHistory::TABLE,
            contextWindow: $this->settings->get('llm.openai.contextWindow')
        );
        $userChatHistory->setThreadId($threadId);

        $messages = $userChatHistory->getFormattedMessages($session->get('chat_mode'));
        if ($messages === []) {
            return $response->withStatus(400);
        }

        return $this->twig->render($response, 'partials/messages_list.twig', [
            'messages' => $messages,
        ]);
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

        if (! $this->entityManager->getRepository(ChatHistoryEntity::class)->deleteThread($userId, $threadId)) {
            return $response->withStatus(400);
        }

        // Important for HTMX swap: return 200 with an empty body so that
        // hx-swap="outerHTML" on the <li> effectively removes the element.
        $response->getBody()->write('');
        return $response->withStatus(200);
    }
}
