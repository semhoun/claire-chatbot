<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\Brain;
use App\Agent\Summary;
use App\Services\Settings;
use Doctrine\ORM\EntityManager;
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
        private Brain $brain,
        private Summary $summary,
        private Logger $logger,
        private EntityManager $entityManager,
        private Settings $settings,
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
        $userStr = $this->getUserMessage($request);
        if ($userStr === '') {
            // Return a 422 with no body so client can handle error; minimal for now
            return $response->withStatus(422);
        }

        $userMessage = new UserMessage($userStr);
        $userMessage = $this->addAttachments($request, $userMessage);

        $agentMessage = $this->brain->chat($userMessage);
        $agentMessageStr = $agentMessage->getContent();

        $this->manageSummary();

        return $this->twig->render($response, 'partials/message.twig', [
            'message' => $agentMessageStr,
            'time' => new \DateTime()->format('H:i'),
            'sent' => false,
        ]);
    }

    /**
     * Streaming SSE endpoint using Slim\Http\StreamingBody and NeuronAI Agent::stream
     */
    public function stream(Request $request, Response $response): Response
    {
        $userStr = $this->getUserMessage($request);
        if ($userStr === '') {
            // Return a 422 with no body so client can handle error; minimal for now
            return $response->withStatus(422);
        }

        $userMessage = new UserMessage($userStr);
        $userMessage = $this->addAttachments($request, $userMessage);

        // SSE headers
        $response = $response
            ->withBody(new NonBufferedBody())
            ->withHeader('content-type', 'text/stream')
            ->withHeader('cache-control', 'no-cache');

        $body = $response->getBody();

        $stream = $this->brain->stream($userMessage);

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

        foreach ($fileIds as &$fileId) {
            // choose action by mimetype
            $file = $this->entityManager->find(\App\Entity\File::class, $fileId);
            if ($file === null) {
                continue;
            }

            $text .= '<file'
                . ' id="' . $fileId . '"'
                . ' name="' . $file->getFilename() . '"'
                . ' type="' . $file->getMimeType() . '"'
                . ' size="' . $file->getSizeBytes() . '"'
                . ' url="' . $request->getAttribute('base_url') . '/files/by-token/' . $file->getToken() . '"'
                . ' user_id="' . $file->getUser()->getId() . '"'
                . '>';
            if (in_array($file->getMimeType(), $this->settings->get('file.rawMimeTypes'), true)) {
                $text .= $file->getContentAsString();
            } else {
                $text .= base64_encode((string) $file->getContentAsString());
            }

            $text .= '</file>' . "\n";
        }

        $text .= "</files>\n"
            . "</files_info>\n"
            . '<!-- END SYSTEM CONTEXT -->';

        $userMessage->addContent(new TextContent($text));

        return $userMessage;
    }
}
