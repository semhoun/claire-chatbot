<?php

declare(strict_types=1);

namespace App\Controller;

use App\Brain\BrainRegistry;
use App\Brain\ChatHistory\UserChatHistory;
use App\Entity\ChatHistory as ChatHistoryEntity;
use App\Services\Auth;
use App\Services\Session\SessionInterface;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final readonly class HistoryController
{
    public function __construct(
        private Twig $twig,
        private SessionInterface $session,
        private EntityManagerInterface $entityManager,
        private UserChatHistory $userChatHistory,
        private BrainRegistry $brainRegistry,
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
        // Nettoyage des conversations vides de l'utilisateur
        $userId = (string) $this->session->get(Auth::USERID);
        if ($userId !== '') {
            $this->entityManager->getRepository(ChatHistoryEntity::class)->deleteEmptyConversations($userId);
        }

        // Nouveau thread
        $threadId = uniqid('', true);
        $this->session->set('chatId', $threadId);

        $currentBrain = $this->session->get('brain_avatar');
        $messages = [
            new AssistantMessage(
                $this->brainRegistry->get($currentBrain)->getOpeningText()
            ),
        ];

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
        $userId = (string) $this->session->get(Auth::USERID);
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
        $userId = (string) $this->session->get(Auth::USERID);
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
        $userId = (string) $this->session->get(Auth::USERID);
        if ($userId === '') {
            return $response->withStatus(403);
        }

        // Nettoyage des conversations vides de l'utilisateur
        $this->entityManager->getRepository(ChatHistoryEntity::class)->deleteEmptyConversations($userId);

        $threadId = $request->getAttribute('threadId');
        if ($threadId === null) {
            return $response->withStatus(400);
        }

        $this->session->set('chatId', $threadId);
        $this->userChatHistory->setThreadId($threadId);
        $messages = $this->userChatHistory->getMessages();
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
        $userId = (string) $this->session->get(Auth::USERID);
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
