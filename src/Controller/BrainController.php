<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\Brain;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\UserMessage;
use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\NonBufferedBody;
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
        $userMessage = $this->getUserMessage($request);
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

    /**
     * Streaming SSE endpoint using Slim\Http\StreamingBody and NeuronAI Agent::stream
     */
    public function stream(Request $request, Response $response): Response
    {
        $userMessage = $this->getUserMessage($request);
        if ($userMessage === '') {
            return $response->withStatus(422);
        }

        // SSE headers
        $response = $response
            ->withBody(new NonBufferedBody())
            ->withHeader('content-type', 'text/event-stream')
            ->withHeader('cache-control', 'no-cache');

        $body = $response->getBody();

        $stream = $this->brain->stream(new UserMessage($userMessage));

        // Iterate chunks
        foreach ($stream as $chunk) {
            if ($chunk instanceof ToolCallChunk) {
                /*      // Output the ongoing tool call
                      $body->write("\n".\array_reduce($chunk->getTools(),
                          fn(string $carry, ToolInterface $tool)
                          => $carry .= '- Calling tool: '.$tool->getName()."\n",
                          ''));*/
                continue;
            }

            if ($chunk instanceof ToolResultChunk) {
                $body->write("- Tools execution completed\n");
                continue;
            }

            $body->write($chunk->content);
        }

        return $response;
    }

    /**
     * Set current chat mode in session ("chat" | "stream")
     */
    public function mode(Request $request, Response $response): Response
    {
        $data = (array) ($request->getParsedBody() ?? []);
        $mode = (string) ($data['mode'] ?? '');
        if (! in_array($mode, ['chat', 'stream'], true)) {
            return $response->withStatus(400);
        }

        $this->session->set('chat_mode', $mode);

        // HTMX friendly: no content needed
        return $response->withStatus(204);
    }

    private function getUserMessage(Request $request): string
    {
        if ($request->getMethod() === 'POST') {
            $data = (array) ($request->getParsedBody() ?? []);
            $userMessage = trim((string) ($data['message'] ?? ''));
        } else {
            $userMessage = trim((string) ($request->getQueryParams()['message'] ?? []));
        }

        return $userMessage;
    }
}
