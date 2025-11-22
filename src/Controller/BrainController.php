<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\Brain;
use NeuronAI\Chat\Messages\UserMessage;
use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final readonly class BrainController
{
    public function __construct(
        private Twig $twig,
        private Brain $brain
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

        $message = $this->brain->chat(new UserMessage($userMessage));
        $agentMessage = $message->getContent();

        return $this->twig->render($response, 'partials/message.md.twig', [
            'message' => $agentMessage,
            'time' => $time,
            'sent' => false,
        ]);
    }
}
