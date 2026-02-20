<?php

declare(strict_types=1);

namespace App\Controller;

use App\Brain\BrainRegistry;
use App\Brain\Summary;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToReadFile;
use Monolog\Logger;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
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
    private const string STREAM_STOP = "\n§STREAM-STOP§\n";

    public function __construct(
        private Twig $twig,
        private BrainRegistry $brainRegistry,
        private Summary $summary,
        private Logger $logger,
        private EntityManager $entityManager,
        private Filesystem $filesystem,
        private SessionInterface $session,
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

        // Choisir le cerveau selon la préférence en session
        $currentBrain = $this->session->get('defaultBrain');
        try {
            $brain = $this->brainRegistry->get($currentBrain);
        } catch (\InvalidArgumentException) {
            $currentBrain = 'claire';
            $this->session->set('brain_avatar', $currentBrain);
            $brain = $this->brainRegistry->get($currentBrain);
        }

        if ($chatMode === 'chat') {
            // Manage chat mode
            $agentMessage = $brain->chat($userMessage)->getMessage();
            $agentMessageStr = $agentMessage->getContent();

            $this->manageSummary();

            return $this->twig->render($response, 'partials/message.twig', [
                'message' => $agentMessageStr,
                'time' => new \DateTime()->format('H:i'),
                'sent' => false,
            ]);
        }

        // Some init for htmx
        $streamId = null;
        $toolCallId = null;
        $streamedText = '';
        $toolText = null;

        // SSE headers
        $response = $response
            ->withBody(new NonBufferedBody())
            ->withHeader('content-type', 'text/stream')
            ->withHeader('cache-control', 'no-cache');

        $stream = $response->getBody();

        $handler = $brain->stream($userMessage);

        // Iterate chunks
        foreach ($handler->events() as $chunk) {
            if ($chunk instanceof ToolCallChunk || $chunk instanceof ToolResultChunk) {
                $toolText = '';
                if ($toolCallId === null) {
                    $toolCallId = uniqid('tool-', true);
                }

                if ($chunk instanceof ToolResultChunk) {
                    $toolText = '<span class="tools-done-flag" style="display:none"></span>' . "\n";
                }

                $tool = $chunk->tool;
                $toolText .= "Utilisation de l'outil : " . $tool->getName() . "<br>\n";
                $toolText .= "Paramètres : <br>\n";
                $toolText .= "<ul>\n";
                foreach ($tool->getInputs() as $name => $value) {
                    $toolText .= '<li>' . $name . ' : ' . $value . "</li>\n";
                }

                $toolText .= "</ul>\n";
                if ($chunk instanceof ToolResultChunk) {
                    $toolText .= "Réponse : <br>\n";
                    if ($tool->getResult() !== '' && $tool->getResult() !== '0') {
                        $toolText .= '<pre class="toolcall__result">' . $tool->getResult() . "</pre>\n";
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
                $stream->write($html);
                $stream->write(self::STREAM_STOP);

                continue;
            }

            $html = $this->twig->fetch('partials/md.twig', ['message' => $streamedText]);
            $stream->write('streamId:' . $streamId . "\n" . $html . self::STREAM_STOP);
            if ($toolCallId !== null && $toolText !== null) {
                $stream->write('streamId:' . $toolCallId . "\n" . $toolText . self::STREAM_STOP);
                $toolCallId = null;
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
        $messages = $this->summary->getChatHistory()->getMessages();
        if ($messages === []) {
            return;
        }

        if ((int) (count($messages) / 2) > 3) {
            // ne gère le résumé que pour les conversations de 3 messages minimum
            return;
        }

        $result = $this->summary->generateAndPersist();
        $this->logger->debug('Manage summary', ['messages' => $messages, 'summary' => $result]);
    }

    /**
     * Adds attachments from the request to the user message.
     * Processes both uploaded files and files identified by IDs.
     *
     * @param Request $request The HTTP request containing the uploaded files and/or file IDs.
     * @param UserMessage $userMessage The user message to which the attachments will be added.
     *
     * @return UserMessage The updated user message with the added attachments.
     */
    private function addAttachments(Request $request, UserMessage $userMessage): UserMessage
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $fileIds = array_map(strval(...), (array) ($body['file_ids'] ?? []));
        $uploadedFiles = (array) ($request->getUploadedFiles()['upload_files'] ?? []);

        if ($fileIds === [] && $uploadedFiles === []) {
            return $userMessage;
        }

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

                $content = new FileContent(
                    base64_encode((string) $stream->getContents()),
                    SourceType::BASE64,
                    $uploadedFile->getClientMediaType() ?? 'application/octet-stream',
                    $uploadedFile->getClientFilename() ?? 'file'
                );
                $userMessage->addContent($content);
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

                $content = new FileContent(
                    base64_encode($this->filesystem->read($fileDB->getFileId())),
                    SourceType::BASE64,
                    $fileDB->getMimeType(),
                    $fileDB->getFilename(),
                );
                $userMessage->addContent($content);
            } catch (OptimisticLockException | ORMException | FilesystemException | UnableToReadFile $exception) {
                $this->logger->error('Failed to add addAttachments', ['fileId' => $fileId, 'exception' => $exception]);
            }
        }

        return $userMessage;
    }
}
