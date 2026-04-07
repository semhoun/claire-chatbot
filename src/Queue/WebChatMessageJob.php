<?php

declare(strict_types=1);

namespace App\Queue;

use App\Brain\BrainRegistry;
use App\Brain\Summary;
use App\Services\ChatStreamPublisher;
use App\Services\Session\SessionInterface;
use App\Services\Settings;
use Doctrine\DBAL\Connection;
use DateTimeImmutable;
use DateTimeInterface;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\UserMessage;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface as Logger;
use Slim\Views\Twig;

final readonly class WebChatMessageJob implements QueueDoer
{
    public function __construct(
        private Logger $logger,
        private Twig $twig,
        private BrainRegistry $brainRegistry,
        private ChatStreamPublisher $chatStreamPublisher,
        private Connection $connection,
        private Settings $settings,
    ) {
    }

    public static function make(ContainerInterface $container): self
    {
        return $container->get(self::class);
    }

    public function handle(array $payload): void
    {
        $messageText = trim((string) ($payload['message'] ?? ''));
        $chatId = (string) ($payload['chatId'] ?? '');
        // sessionId is the per-tab SSE binding key (stable across chat switches)
        $sessionId = (string) ($payload['sessionId'] ?? '');
        $brainAvatar = (string) ($payload['brainAvatar'] ?? '');
        $sessionValues = $payload['session'] ?? [];

        if ($messageText === '' || $chatId === '' || $sessionId === '' || ! is_array($sessionValues)) {
            return;
        }

        $inMemorySession = new InMemorySession($sessionValues);
        $inMemorySession->set('chatId', $chatId);

        $agent = $this->brainRegistry->get($brainAvatar, $inMemorySession);
        $userMessage = new UserMessage($messageText);
        $userMessage->addMetadata('timestamp', new DateTimeImmutable()->format(DateTimeInterface::ATOM));

        $streamedText = '';
        $toolCallId = null;
        $toolText = '';
        $toolPlaceholderPublished = false;
        $assistantStarted = false;
        $messageId = uniqid('assistant-', true);
        $messageArticleId = trim((string) ($payload['messageArticleId'] ?? ''));
        if ($messageArticleId === '') {
            $messageArticleId = uniqid('assistant-message-', true);
        }
        $timestamp = new DateTimeImmutable()->format(DateTimeInterface::ATOM);

        try {
            $agentHandler = $agent->stream($userMessage);

            foreach ($agentHandler->events() as $chunk) {
                if ($chunk instanceof ToolCallChunk || $chunk instanceof ToolResultChunk) {
                    if (! $assistantStarted) {
                        $placeholderHtml = $this->twig->fetch('partials/message.twig', [
                            'message' => $streamedText,
                            'time' => $timestamp,
                            'sent' => false,
                            'messageArticleId' => $messageArticleId,
                            'streamId' => $messageId,
                            'toolCallId' => null,
                            'toolCall' => null,
                        ]);
                        // Publish to sessionId (per-tab stream) instead of chatId
                        $this->chatStreamPublisher->publish($sessionId, 'message.assistant.start', [
                            'chatId' => $chatId,
                            'sessionId' => $sessionId,
                            'messageId' => $messageId,
                            'messageArticleId' => $messageArticleId,
                        ]);
                        $this->chatStreamPublisher->publish($sessionId, 'message.assistant.placeholder', [
                            'chatId' => $chatId,
                            'sessionId' => $sessionId,
                            'messageId' => $messageId,
                            'messageArticleId' => $messageArticleId,
                            'html' => $placeholderHtml,
                        ]);
                        $assistantStarted = true;
                    }

                    $toolCallId ??= uniqid('tool-', true);
                    $toolText .= $this->formatToolChunk($chunk);

                    if (! $toolPlaceholderPublished) {
                        $placeholderHtml = $this->twig->fetch('partials/message.twig', [
                            'message' => $streamedText,
                            'time' => $timestamp,
                            'sent' => false,
                            'messageArticleId' => $messageArticleId,
                            'streamId' => $messageId,
                            'toolCallId' => $toolCallId,
                            'toolCall' => $toolText,
                        ]);
                        $this->chatStreamPublisher->publish($sessionId, 'message.assistant.placeholder', [
                            'chatId' => $chatId,
                            'sessionId' => $sessionId,
                            'messageId' => $messageId,
                            'messageArticleId' => $messageArticleId,
                            'html' => $placeholderHtml,
                        ]);
                        $toolPlaceholderPublished = true;
                    }

                    $this->chatStreamPublisher->publish($sessionId, 'tool.update', [
                        'chatId' => $chatId,
                        'sessionId' => $sessionId,
                        'messageId' => $messageId,
                        'messageArticleId' => $messageArticleId,
                        'toolCallId' => $toolCallId,
                        'html' => $toolText,
                    ]);

                    continue;
                }

                if (! $chunk instanceof ReasoningChunk && ! $chunk instanceof TextChunk) {
                    continue;
                }

                if (! $assistantStarted) {
                    $placeholderHtml = $this->twig->fetch('partials/message.twig', [
                        'message' => '',
                        'time' => $timestamp,
                        'sent' => false,
                        'messageArticleId' => $messageArticleId,
                        'streamId' => $messageId,
                        'toolCallId' => null,
                        'toolCall' => null,
                    ]);
                    $this->chatStreamPublisher->publish($sessionId, 'message.assistant.start', [
                        'chatId' => $chatId,
                        'sessionId' => $sessionId,
                        'messageId' => $messageId,
                        'messageArticleId' => $messageArticleId,
                    ]);
                    $this->chatStreamPublisher->publish($sessionId, 'message.assistant.placeholder', [
                        'chatId' => $chatId,
                        'sessionId' => $sessionId,
                        'messageId' => $messageId,
                        'messageArticleId' => $messageArticleId,
                        'html' => $placeholderHtml,
                    ]);
                    $assistantStarted = true;
                }

                $streamedText .= $chunk->content;
                $html = $this->twig->fetch('partials/md.twig', ['message' => $streamedText]);

                $this->chatStreamPublisher->publish($sessionId, 'message.assistant.delta', [
                    'chatId' => $chatId,
                    'sessionId' => $sessionId,
                    'messageId' => $messageId,
                    'messageArticleId' => $messageArticleId,
                    'html' => $html,
                ]);
            }

            $agentMessage = $agentHandler->getMessage();
            $finalHtml = $this->twig->fetch('partials/md.twig', [
                'message' => $agentMessage->getContent(),
            ]);

            $this->chatStreamPublisher->publish($sessionId, 'message.assistant.delta', [
                'chatId' => $chatId,
                'sessionId' => $sessionId,
                'messageId' => $messageId,
                'messageArticleId' => $messageArticleId,
                'html' => $finalHtml,
            ]);
            $this->chatStreamPublisher->publish($sessionId, 'message.assistant.done', [
                'chatId' => $chatId,
                'sessionId' => $sessionId,
                'messageId' => $messageId,
                'messageArticleId' => $messageArticleId,
            ]);

            $this->manageSummary($inMemorySession);
        } catch (\Throwable $throwable) {
            $this->logger->error('Web chat job failed', ['exception' => $throwable, 'chatId' => $chatId, 'sessionId' => $sessionId]);
            $this->chatStreamPublisher->publish($sessionId, 'chat.error', [
                'chatId' => $chatId,
                'sessionId' => $sessionId,
                'message' => 'Désolé, une erreur est survenue lors du traitement de votre message.',
            ]);
        }
    }

    private function formatToolChunk(ToolCallChunk|ToolResultChunk $chunk): string
    {
        $tool = $chunk->tool;
        $toolText = $chunk instanceof ToolResultChunk
            ? '<span class="tools-done-flag" style="display:none"></span>' . "\n"
            : '';
        $toolText .= "Utilisation de l'outil : " . $tool->getName() . "<br>\n";
        $toolText .= "Paramètres : <br>\n<ul>\n";

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

        return $toolText;
    }

    private function manageSummary(SessionInterface $session): void
    {
        $summary = new Summary($this->connection, $this->settings, $session);
        $messages = $summary->getChatHistory()->getDisplayMessages();
        if ($messages === [] || count($messages) < $this->settings->get('llm.summary.minMessages')) {
            return;
        }

        if (count($messages) > $this->settings->get('llm.summary.maxMessages') && $summary->getChatHistory()->getTitle() !== null) {
            return;
        }

        $summary->generateAndPersist();
    }
}

final class InMemorySession implements SessionInterface
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->values;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function setValues(array $values): void
    {
        $this->values = [...$this->values, ...$values];
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function delete(string $key): void
    {
        unset($this->values[$key]);
    }

    public function clear(): void
    {
        $this->values = [];
    }

    public function getFlash(): \App\Services\Session\FlashInterface
    {
        throw new \RuntimeException('Flash not available in queue session');
    }
}
