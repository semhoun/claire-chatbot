<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\Brain;
use App\Agent\Summary;
use Doctrine\ORM\EntityManager;
use Monolog\Logger;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
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
        private Summary $summary,
        private Logger $logger,
        private EntityManager $entityManager,
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

        // Read optional attachments coming from the chat form
        $attachments = $this->getAttachments($request);

        $time = new \DateTime()->format('H:i');

        $message = new UserMessage($userMessage);
        foreach ($attachments as $attachment) {
            $message->addContent(
                new FileContent(
                    content: base64_encode((string) $attachment['content']),
                    sourceType: SourceType::BASE64,
                    mediaType: $attachment['mime'],
                    filename: $attachment['filename'],
                )
            );
        }

        $this->brain->chat($message);
        $agentMessageStr = $message->getContent();

        $this->manageSummary();

        return $this->twig->render($response, 'partials/message.twig', [
            'message' => $agentMessageStr,
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

        // Read optional attachments coming from the chat form
        $this->getAttachments($request);

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
        $toolText = null;

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
                $this->logger->error('Unknown chunk type: ' . $chunk::class);
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
            if ($toolCallId !== null && $toolText !== null) {
                $body->write('streamId:' . $toolCallId . "\n" . $toolText . self::STREAM_STOP);
                $toolCallId = null;
            }
        }

        $this->manageSummary();

        return $response;
    }

    private function manageSummary(): void
    {
        $messages = $this->brain->getChatHistory()->getMessages();
        $this->logger->debug('Manage summary', $messages);
        $this->summary->generateAndPersist();
    }

    private function getUserMessage(Request $request): string
    {
        if ($request->getMethod() === 'POST') {
            $data = (array) ($request->getParsedBody() ?? []);
            return trim((string) ($data['message'] ?? ''));
        }

        return trim((string) ($request->getQueryParams()['message'] ?? []));
    }

    /**
     * Retrieves a list of attachments from the request, including files referenced by `file_ids`
     * and uploaded files from the `upload_files` field.
     *
     * @param Request $request The incoming HTTP request containing file references and/or uploads.
     *
     * @return array An array of attachments, where each attachment includes 'filename', 'mime', and 'content' (base64 encoded).
     */
    private function getAttachments(Request $request): array
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $files = [];

        $fileIds = array_map(strval(...), (array) ($body['file_ids'] ?? []));
        foreach ($fileIds as &$fileId) {
            $file = $this->entityManager->find(\App\Entity\File::class, $fileId);
            if ($file !== null) {
                $files[] = [
                    'filename' => $file->getFilename(),
                    'mime' => $file->getMimeType(),
                    'content' => base64_encode(stream_get_contents($file->getContent())),
                ];
            }
        }

        $uploadedFiles = (array) ($request->getUploadedFiles()['upload_files'] ?? []);
        foreach ($uploadedFiles as $uploadedFile) {
            try {
                if (! method_exists($uploadedFile, 'getError')) {
                    continue;
                }
                if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
                    continue;
                }

                $stream = $uploadedFile->getStream();
                $stream->rewind();
                $files[] = [
                    'filename' => $uploadedFile->getClientFilename() ?? 'fichier',
                    'mime' => $uploadedFile->getClientMediaType() ?? 'application/octet-stream',
                    'content' => base64_encode((string) $stream->getContents()),
                ];
            } catch (\Throwable $e) {
                // best-effort; ignore faulty upload and continue
                $this->logger->warning('Failed to read inline upload', ['error' => $e->getMessage()]);
            }
        }

        return $files;
    }
}
