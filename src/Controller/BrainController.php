<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\Brain;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final readonly class BrainController
{
    public function __construct(
        private Twig $twig,
        private Brain $brain,
        private SessionInterface $session
    ) {
    }

    /**
     * Handle chat request
     */
    public function chat(Request $request, Response $response): Response
    {
        $data = (array) ($request->getParsedBody() ?? []);
        $userMessage = trim((string) ($data['message'] ?? ''));

        if ($userMessage === '') {
            // Return a 422 with no body so client can handle error; minimal for now
            return $response->withStatus(422);
        }

        $time = new \DateTime()->format('H:i');

        $chat = [];
        $chatHistory = $this->session->get('chatHistory', []);
        $chatHistory[] = ['role' => 'user', 'content' => $userMessage, 'time' => $time];
        foreach ($chatHistory as &$message) {
            if ($message['role'] === 'user') {
                $chat[] = new UserMessage($message['content']);
            } else {
                $chat[] = new AssistantMessage($message['content']);
            }
        }

        $answer = $this->brain->chat($chat);
        $agentMessage = $answer->getContent();

        $chatHistory[] = ['role' => 'assistant', 'content' => $agentMessage, 'time' => $time];

        $this->session->set('chatHistory', $chatHistory);

        return $this->twig->render($response, 'partials/message.md.twig', [
            'message' => $agentMessage,
            'time' => $time,
            'sent' => false,
        ]);
    }
}
