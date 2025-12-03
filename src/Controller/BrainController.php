<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\Brain;
use Monolog\Logger;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\UserMessage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\NonBufferedBody;
use Slim\Views\Twig;

final readonly class BrainController
{
    private const string STREAM_STOP = "\n§STREAM-STOP§\n";

    public function __construct(
        private Twig $twig,
        private Brain $brain,
        private Logger $logger,
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

        return $this->twig->render($response, 'partials/message.twig', [
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
            ->withHeader('content-type', 'text/stream')
            ->withHeader('cache-control', 'no-cache');

        $body = $response->getBody();

        $stream = $this->brain->stream(new UserMessage($userMessage));

        $streamId = null;
        $toolCallId = null;

        $streamedText = '';
        $toolText = '';

        // Iterate chunks
        foreach ($stream as $chunk) {
            if ($chunk instanceof ToolCallChunk || $chunk instanceof ToolResultChunk) {
                $toolText = '';
                if ($toolCallId === null) {
                    $toolCallId = uniqid('tool-', true);
                }

                if ($chunk instanceof ToolResultChunk) {
                    $toolText = '<span class="tools-done-flag" style="display:none"></span>' . "\n";
                }

                foreach ($chunk->tools as $tool) {
                    $toolText .= "Utilisation de l'outil : " . $tool->getName() . "<br>\n";
                    $toolText .= "Paramètres : <br>\n";
                    $toolText .= "<ul>\n";
                    foreach ($tool->getInputs() as $name => $value) {
                        $toolText .= '<li>' . $name . ' : ' . $value . "</li>\n";
                    }

                    $toolText .= "</ul>\n";
                    if ($chunk instanceof ToolResultChunk) {
                        $toolText .= "Réponse : <br>\n";
                        if ($tool->getResult()) {
                            $toolText .= '<pre class="toolcall__result">' . $tool->getResult() . "</pre>\n";
                        }
                    }
                }
            } elseif ($chunk instanceof ReasoningChunk) {
                $streamedText .= $chunk->content;
            } elseif ($chunk instanceof TextChunk) {
                $streamedText .= $chunk->content;
            } else {
                $this->logger->error('Unknown chunk type: ' . get_class($chunk));
                continue;
            }

            if ($streamId === null) {
                $streamId = uniqid('stream-', true);
                $html = $this->twig->fetch('partials/message.twig', [
                    'message' => $streamedText,
                    'time' => date('H:i'),
                    'sent' => false,
                    'streamId' => $streamId,
                    'toolCallId' => $toolCallId,
                    'toolCall' => $toolText,
                ]);
                $body->write($html);
                $body->write(self::STREAM_STOP);

                continue;
            }

            $html = $this->twig->fetch('partials/md.twig', [ 'message' => $streamedText ]);
            $body->write('streamId:' . $streamId . "\n" . $html . self::STREAM_STOP);
            if ($toolCallId !== null) {
                $body->write('streamId:' . $toolCallId . "\n" . $toolText . self::STREAM_STOP);
            }
        }

        return $response;
    }

    private function getUserMessage(Request $request): string
    {
        if ($request->getMethod() === 'POST') {
            $data = (array) ($request->getParsedBody() ?? []);
            return trim((string) ($data['message'] ?? ''));
        }

        return trim((string) ($request->getQueryParams()['message'] ?? []));
    }
}
