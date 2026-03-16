<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\BrainRegistry;
use App\Brain\ChatHistory\UserChatHistory;
use App\Entity\User;
use App\Services\Session\TelegramSession;
use Doctrine\ORM\EntityManager;
use League\Flysystem\Filesystem;
use Monolog\Logger;
use NeuronAI\Chat\Messages\UserMessage;
use Phptg\BotApi\Constant\ParseMode;
use Phptg\BotApi\FailResult;
use Phptg\BotApi\TelegramBotApi;
use Phptg\BotApi\Type\Message;
use Phptg\BotApi\Type\Update\Update;

final readonly class TelegramService
{

    public const array COMMANDS = [
        'start' => 'Démarrer une nouvelle conversation',
        'help' => "Afficher l'aide",
        'list' => 'Lister les personnalités',
        'brain' => 'Voir ou changer de personnalité',
    ];

    private TelegramSession $telegramSession;

    public function __construct(
        private BrainRegistry $brainRegistry,
        private EntityManager $entityManager,
        private Settings $settings,
        private Logger $logger,
        private Filesystem $filesystem,
        private TelegramBotApi $telegramBotApi,
    ) {
        $this->telegramSession = new TelegramSession($entityManager);
    }

    public function processUpdate(Update $update): void
    {
        try {
            $message = $update->message;
            if (!$message instanceof \Phptg\BotApi\Type\Message) {
                return;
            }

            $this->handleMessage($message);
        } catch (\Throwable $throwable) {
            $this->logger->error('Telegram update processing error: ' . $throwable->getMessage(), [
                'exception' => $throwable,
                'update_id' => $update->updateId,
            ]);
        }
    }

    public function handleMessage(Message $message): void
    {
        $chatId = (string) $message->chat->id;

        $telegramUserId = (string) $message->from->id;
        if (! $this->manageSession($telegramUserId)) {
            $this->sendMessage($chatId, "Je ne vous reconnais pas, merci d'ajouter votre id " . $telegramUserId . " sur l'interface web");
            return;
        }

        $entityRepository = $this->entityManager->getRepository(User::class);
        $entityRepository->findByTelegramId($telegramUserId);

        if ($message->photo !== null && $message->photo !== []) {
            $this->handlePhoto($message);
            return;
        }

        if ($message->document instanceof \Phptg\BotApi\Type\Document) {
            $this->handleDocument($message);
            return;
        }

        $text = $message->text;
        if ($text === null) {
            $this->sendMessage($chatId, 'Je ne peux traiter que du texte, des photos et des documents.');
            return;
        }

        if (str_starts_with($text, '/')) {
            $handled = $this->handleCommand($text, $chatId);
            if (! $handled) {
                $this->sendMessage($chatId, '⚠ Commande inconnue');
            }

            $this->telegramSession->flush();
            return;
        }

        $this->processChatMessage($text, $chatId);

        $this->telegramSession->flush();
    }

    public function handlePhoto(Message $message): void
    {
        $chatId = (string) $message->chat->id;
        $photos = $message->photo;

        if ($photos === null || $photos === []) {
            return;
        }

        $photo = $photos[count($photos) - 1];
        $fileId = $photo->fileId;

        try {
            $file = $this->telegramBotApi->getFile(fileId: $fileId);
            $filePath = $file->filePath;
            $fileUrl = sprintf('https://api.telegram.org/file/bot%s/%s', $this->settings->get('telegram.bot_token'), $filePath);

            $imageContent = file_get_contents($fileUrl);
            if ($imageContent === false) {
                throw new \RuntimeException('Failed to download image');
            }

            $localPath = sprintf('telegram/%s/', $this->telegramSession->get('user_id')) . uniqid('photo_', true) . '.jpg';
            $this->filesystem->write($localPath, $imageContent);

            $caption = $message->caption ?? 'Décris cette image';

            $this->processChatMessage(
                "[Image: {$localPath}]\n\n{$caption}",
                $chatId
            );
        } catch (\Throwable $throwable) {
            $this->logger->error('Photo handling error: ' . $throwable->getMessage());
            $this->sendMessage($chatId, 'Désolé, je n\'ai pas pu traiter cette image.');
        }
    }

    public function handleDocument(Message $message): void
    {
        $chatId = (string) $message->chat->id;
        $document = $message->document;

        if (! $document instanceof \Phptg\BotApi\Type\Document) {
            return;
        }

        $fileId = $document->fileId;
        $fileName = $document->fileName ?? 'document';
        $mimeType = $document->mimeType ?? 'application/octet-stream';

        try {
            $file = $this->telegramBotApi->getFile(fileId: $fileId);
            $filePath = $file->filePath;
            $fileUrl = sprintf('https://api.telegram.org/file/bot%s/%s', $this->settings->get('telegram.bot_token'), $filePath);

            $fileContent = file_get_contents($fileUrl);
            if ($fileContent === false) {
                throw new \RuntimeException('Failed to download document');
            }

            $extension = pathinfo($fileName, PATHINFO_EXTENSION);
            $localPath = sprintf('telegram/%s/', $user->getId()) . uniqid('doc_', true) . '.' . $extension;
            $this->filesystem->write($localPath, $fileContent);

            $caption = $message->caption ?? 'Analyse ce document';

            $this->processChatMessage(
                "[Document: {$localPath} ({$mimeType})]\n\n{$caption}",
                $chatId
            );
        } catch (\Throwable $throwable) {
            $this->logger->error('Document handling error: ' . $throwable->getMessage());
            $this->sendMessage($chatId, 'Désolé, je n\'ai pas pu traiter ce document.');
        }
    }

    private function manageSession(string $telegramUserId, bool $renew = false): bool
    {
        // Initialize session for this Telegram user
        $this->telegramSession->load($telegramUserId);
        if ($renew || $this->telegramSession->get(Auth::AUTHENTICATED, false) !== true) {
            $user = $this->entityManager->getRepository(User::class)->findByTelegramId($telegramUserId);
            if ($user === null) {
                return false;
            }

            $this->telegramSession->set(Auth::USERID, $user->getId());
            $this->telegramSession->set('telegram_id', $telegramUserId);
            $this->telegramSession->set(Auth::AUTHENTICATED, true);
            $this->telegramSession->set(Auth::USERINFO, [
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'email' => $user->getEmail(),
                'displayName' => trim($user->getFirstName() . ' ' . $user->getLastName()),
            ]);
            foreach ($user->getParams() ?? [] as $key => $value) {
                $this->telegramSession->set($key, $value);
            }

            // Déterminer l'avatar/assistant courant (session, sinon préférence utilisateur, sinon défaut)
            $currentBrain = (string) ($this->telegramSession->get('brain_avatar') ?? '');
            if ($currentBrain === '') {
                $this->telegramSession->set('brain_avatar', $this->settings->get('llm.defaultBrain'));
            }

            $chatId = $this->telegramSession->get('chatId');
            if ($renew || ! $chatId) {
                $chatId = uniqid(UserChatHistory::CHAT_TELEGRAM, true);
                $this->telegramSession->set('chatId', $chatId);
            }
        }

        return true;
    }

    private function handleCommand(string $text, string $chatId): bool
    {
        $parts = explode(' ', $text);
        $command = substr(array_shift($parts), 1);

        if (! isset(self::COMMANDS[$command])) {
            return false;
        }

        $fct = 'cmd_' . $command;
        $this->$fct($chatId, $parts);

        return true;
    }

    private function processChatMessage(string $text, string $chatId): void
    {
        try {
            $res = $this->telegramBotApi->sendChatAction($chatId, 'typing');
            if ($res instanceof FailResult) {
                $this->logger->error('Failed to send typing action', ['chatId' => $chatId, 'error' => $res]);
            }

            $currentBrain = $this->telegramSession->get('brain_avatar');
            $brain = $this->brainRegistry->get($currentBrain, $this->telegramSession);
            $userMessage = new UserMessage($text);
            $agentMessage = $brain->chat($userMessage)->getMessage();
            $responseText = $agentMessage->getContent();
            $this->sendMessage($chatId, $responseText);
        } catch (\Throwable $throwable) {
            $this->logger->error('Chat processing error: ' . $throwable->getMessage(), [
                'user_id' => $user->getId(),
            ]);
            $this->sendMessage($chatId, 'Désolé, une erreur est survenue lors du traitement de votre message.');
        }
    }

    private function sendMessage(string $chatId, string $text): void
    {
        try {
            $escapedText = $this->escapeMarkdownV2($text);
            $chunks = $this->splitMessage($escapedText);
            foreach ($chunks as $chunk) {
                $result = $this->telegramBotApi->sendMessage(chatId: $chatId, text: $chunk, parseMode: ParseMode::MARKDOWN_V2);
                if ($result instanceof FailResult) {
                    $this->logger->error('Failed to send message chunk', ['chatId' => $chatId, 'chunk' => $chunk, 'error' => $result]);
                    continue;
                }
            }
        } catch (\Throwable $throwable) {
            $msg = 'Failed to send message: ' . $throwable->getMessage();
            $this->logger->error($msg);
            try {
                $plainText = $this->escapeMarkdownV2($text);
                $this->telegramBotApi->sendMessage(chatId: $chatId, text: $plainText, parseMode: ParseMode::MARKDOWN_V2);
            } catch (\Throwable $e2) {
                $msg = 'Failed to send plain message: ' . $e2->getMessage();
                $this->logger->error($msg);
            }
        }
    }

    private function escapeMarkdownV2(string $text): string
    {
        // Characters that must be escaped in MarkdownV2: _ * [ ] ( ) ~ ` > # + - = | { } . !
        return preg_replace('/([_\\*\\[\\]\\(\\)\\~\\`\\>\\#\\+\\-\\=\\|\\{\\}\\.\\!])/', '\\\\$1', $text);
    }

    /**
     * @return array<int, string>
     */
    private function splitMessage(string $text, int $maxLength = 4096): array
    {
        if (strlen($text) <= $maxLength) {
            return [$text];
        }

        $chunks = [];
        while (strlen($text) > $maxLength) {
            $splitAt = strrpos(substr($text, 0, $maxLength), "\n");
            if ($splitAt === false) {
                $splitAt = $maxLength;
            }

            $chunks[] = substr($text, 0, $splitAt);
            $text = substr($text, $splitAt + 1);
        }

        if ($text !== '') {
            $chunks[] = $text;
        }

        return $chunks;
    }

    private function cmd_start(string $chatId): void
    {
        $this->manageSession($this->telegramSession->get('telegram_id'), true);
        $this->sendMessage(
            $chatId,
            $this->brainRegistry->get($this->telegramSession->get('brain_avatar'), $this->telegramSession)->getOpeningText()
        );
    }

    private function cmd_help(string $chatId): void
    {
        $message = "Commandes disponibles :\n";
        foreach (self::COMMANDS as $key => $val) {
            $message .= sprintf('/%s - %s', $key, $val) . "\n";
        }

        $this->sendMessage($chatId, $message);
    }

    private function cmd_list(string $chatId): void
    {
        $message = "Personnalités disponibles :\n";
        $brains = $this->brainRegistry->list();
        foreach ($brains as $brain) {
            $message .= sprintf('/%s - %s', $brain['slug'], $brain['description']) . "\n";
        }

        $this->sendMessage($chatId, $message);
    }

    private function cmd_brain(string $chatId, array $args): void
    {
        if ($args === []) {
            $currentBrain = $this->telegramSession->get('brain_avatar');
            $this->sendMessage($chatId, sprintf('Personnalité actuelle : %s', $this->brainRegistry->getMeta($currentBrain)['name']));
            return;
        }

        $brain = (string) $args[0];
        try {
            $meta = $this->brainRegistry->getMeta($brain);
            $this->telegramSession->set('brain_avatar', $brain);
            $this->sendMessage($chatId, sprintf('Personnalité changée : %s', $meta['name']));
        } catch (\Exception $exception) {
            $this->sendMessage($chatId, sprintf('Erreur : %s', $exception->getMessage()));
        }
    }
}
