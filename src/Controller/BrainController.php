<?php

declare(strict_types=1);

namespace App\Controller;

use App\Brain\Claire;
use App\Brain\Summary;
use App\Services\Settings;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToReadFile;
use Monolog\Logger;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
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
        private Claire $claire,
        private Summary $summary,
        private Logger $logger,
        private EntityManager $entityManager,
        private Settings $settings,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * Processes a chat request, generates a response from the agent, and renders the chat message output.
     *
     * @param Request $request The HTTP request containing the user message and optional attachment data.
     * @param Response $response The HTTP response object to which the output will be appended.
     *
     * @return Response The updated response object containing the rendered chat message.
     */
    public function chat(Request $request, Response $response): Response
    {
        if ($request->getMethod() === 'POST') {
            $data = (array) ($request->getParsedBody() ?? []);
            $userStr = trim((string) ($data['message'] ?? ''));
            $chatMode = (string) ($data['mode'] ?? 'chat');
        } else {
            $userStr = trim((string) ($request->getQueryParams()['message'] ?? ''));
            $chatMode = (string) ($request->getQueryParams()['mode'] ?? 'chat');
        }

        if ($userStr === '') {
            // Return a 422 with no body so client can handle error; minimal for now
            return $response->withStatus(422);
        }

        $userMessage = new UserMessage($userStr);
        $userMessage = $this->addAttachments($request, $userMessage);

        if ($chatMode === 'chat') {
            $agentMessage = $this->claire->chat($userMessage);
            $agentMessageStr = $agentMessage->getContent();

            $this->manageSummary();

            $response = $this->twig->render($response, 'partials/message.twig', [
                'message' => $agentMessageStr,
                'time' => new \DateTime()->format('H:i'),
                'sent' => false,
            ]);
        } else {
            // SSE headers
            $response = $response
                ->withBody(new NonBufferedBody())
                ->withHeader('content-type', 'text/stream')
                ->withHeader('cache-control', 'no-cache');

            $body = $response->getBody();

            $stream = $this->claire->stream($userMessage);

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
                } elseif ($chunk === null) {
                    $this->logger->error('Empty chunk');
                    continue;
                } else {
                    $this->logger->error('Unknown chunk type: ' . $chunk::class);
                    continue;
                }

                if ($streamId === null) {
                    $streamId = uniqid('stream-', true);
                    $html = $this->twig->fetch('partials/message.twig', [
                        'message' => $streamedText,
                        'time' => new \DateTime()->format('H:i'),
                        'sent' => false,
                        'streamId' => $streamId,
                        'toolCallId' => $toolCallId,
                        'toolCall' => $toolText,
                    ]);
                    $body->write($html);
                    $body->write(self::STREAM_STOP);

                    continue;
                }

                $html = $this->twig->fetch('partials/md.twig', ['message' => $streamedText]);
                $body->write('streamId:' . $streamId . "\n" . $html . self::STREAM_STOP);
                if ($toolCallId !== null && $toolText !== null) {
                    $body->write('streamId:' . $toolCallId . "\n" . $toolText . self::STREAM_STOP);
                    $toolCallId = null;
                }
            }
        }

        $this->manageSummary();
        return $response;
    }

    /**
     * Manages the generation and persistence of a summary based on chat history.
     *
     * This method retrieves the chat history messages, performs logging for debugging purposes,
     * and triggers the generation and storage of a summary. It is designed to operate
     * under the assumption that chat messages are present, and optimizations should
     * be applied to avoid unnecessary summary generation for empty or unmodified messages.
     */
    private function manageSummary(): void
    {
        $messages = $this->claire->getChatHistory()->getMessages();
        // TODO ne pas générer le summary si le message est vide, et pas à chaque message
        $this->logger->debug('Manage summary', $messages);
        $this->summary->generateAndPersist();
    }

    /**
     * Adds attachments to the provided UserMessage based on file IDs and uploaded files from the request.
     *
     * This method processes file IDs and uploaded files passed via the request, generates
     * the required content based on these inputs, and appends the generated content
     * to the given UserMessage. Files are handled with best-effort, allowing the
     * process to continue even if an error occurs while reading files.
     *
     * @param Request $request The request containing file IDs and/or uploaded files.
     * @param UserMessage $userMessage The message to which the attachments will be added.
     *
     * @return UserMessage The updated UserMessage containing the added attachments.
     */
    private function addAttachments(Request $request, UserMessage $userMessage): UserMessage
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $fileIds = array_map(strval(...), (array) ($body['file_ids'] ?? []));
        $uploadedFiles = (array) ($request->getUploadedFiles()['upload_files'] ?? []);

        if ($fileIds === [] && $uploadedFiles === []) {
            return $userMessage;
        }

        $text = "<!-- SYSTEM CONTEXT (NOT PART OF USER QUERY) -->\n"
            . "<context.instruction>following part contains context information injected by the system. Please follow these instructions:\n\n"
            . "1. Always prioritize handling user-visible content.\n"
            . "2. the context is only required when user's queries rely on it.\n"
            . "</context.instruction>\n"
            . "<files_info>\n"
            . "<files>\n"
            . "<files_docstring>here are user upload files you can refer to</files_docstring>\n";

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
                $text .= '<file'
                    . ' name="' . ($uploadedFile->getClientFilename() ?? 'fichier') . '"'
                    . ' type="' . ($uploadedFile->getClientMediaType() ?? 'application/octet-stream') . '"'
                    . ' size="' . $uploadedFile->getSize() . '"'
                    . '>';
                if (in_array($uploadedFile->getClientMediaType(), $this->settings->get('files.rawMimeTypes'), true)) {
                    $text .= $stream->getContents();
                } else {
                    $text .= base64_encode((string) $stream->getContents());
                }

                $text .= '</file>' . "\n";
            } catch (\Throwable $e) {
                // best-effort; ignore faulty upload and continue
                $this->logger->warning('Failed to read inline upload', ['error' => $e->getMessage()]);
            }
        }

        foreach ($fileIds as $fileId) {
            try {
                // choose action by mimetype
                $fileDB = $this->entityManager->find(\App\Entity\File::class, $fileId);
                if ($fileDB === null) {
                    continue;
                }

                $text .= '<file'
                    . ' id="' . $fileId . '"'
                    . ' name="' . $fileDB->getFilename() . '"'
                    . ' type="' . $fileDB->getMimeType() . '"'
                    . ' size="' . $fileDB->getSizeBytes() . '"'
                    . ' url="' . $request->getAttribute('base_url') . '/files/by-token/' . $fileDB->getToken() . '"'
                    . '>';

                if (in_array($fileDB->getMimeType(), $this->settings->get('files.rawMimeTypes'), true)) {
                    $text .= $this->filesystem->read($fileDB->getFileId());
                } else {
                    $text .= base64_encode($this->filesystem->read($fileDB->getFileId()));
                }

                $text .= '</file>' . "\n";
            } catch (OptimisticLockException | ORMException | FilesystemException | UnableToReadFile $exception) {
                $this->logger->error('Failed to add addAttachments', ['fileId' => $fileId, 'exception' => $exception]);
            }
        }

        $text .= "</files>\n"
            . "</files_info>\n"
            . '<!-- END SYSTEM CONTEXT -->';

        $userMessage->addContent(new TextContent($text));

        return $userMessage;
    }
}
