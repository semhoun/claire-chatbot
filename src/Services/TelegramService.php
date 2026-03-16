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
use Phptg\BotApi\TelegramBotApi;
use Phptg\BotApi\Type\Message;
use Phptg\BotApi\Type\Update\Update;

final readonly class TelegramService
{
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

            $this->handleMessage($message);
        } catch (\Throwable $throwable) {
            $this->logger->error('Telegram update processing error: ' . $throwable->getMessage(), [
                'exception' => $throwable,
                'update_id' => $update->updateId,
            ]);
        }
    }

    private function manageSession(string $telegramUserId, bool $renew = false):bool
    {
        // Initialize session for this Telegram user
        $this->telegramSession->load($telegramUserId);
        if ($renew || $this->telegramSession->get(Auth::AUTHENTICATED, false) !== true) {
            $user = $this->entityManager->getRepository(User::class)->findByTelegramId($telegramUserId);
            if ($user === null) {
                return false;
            }

            $this->telegramSession->set(Auth::USERID, $user->getId());
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

            $chatId = $this->telegramSession->get('chatId');
            if ($renew || ! $chatId) {
                $chatId = uniqid(UserChatHistory::CHAT_TELEGRAM, true);
                $this->telegramSession->set('chatId', $chatId);
            }
        }

        return true;
    }

    public function handleMessage(Message $message): void
    {
        $chatId = (string) $message->chat->id;

        $telegramUserId = (string) $message->from->id;
        if (!$this->manageSession($telegramUserId)) {
            $this->sendMessage($chatId, "Je ne vous reconnais pas, merci d'ajouter votre id " . $telegramUserId . " sur l'interface web");
            return;
        }

        $entityRepository = $this->entityManager->getRepository(User::class);
        $user = $entityRepository->findByTelegramId($telegramUserId);

        if ($message->photo !== null && $message->photo !== []) {
            $this->handlePhoto($message, $user);
            return;
        }

        if ($message->document instanceof \Phptg\BotApi\Type\Document) {
            $this->handleDocument($message, $user);
            return;
        }

        $text = $message->text;
        if ($text === null) {
            $this->sendMessage($chatId, 'Je ne peux traiter que du texte, des photos et des documents.');
            return;
        }

        if (str_starts_with($text, '/')) {
            $handled = $this->handleCommand($text, $chatId, $user);
            if ($handled) {
                return;
            }
        }

        $this->processChatMessage($text, $chatId, $user);
    }

    public function handlePhoto(Message $message, User $user): void
    {
        $chatId = (string) $message->chat->id;
        $photos = $message->photo;

        if ($photos === null || $photos === []) {
            return;
        }

        $photo = $photos[count($photos) - 1];
        $fileId = $photo->fileId;

        $this->sendChatAction($chatId, 'typing');

        try {
            $file = $this->telegramBotApi->getFile(fileId: $fileId);
            $filePath = $file->filePath;
            $fileUrl = sprintf('https://api.telegram.org/file/bot%s/%s', $this->settings->get('telegram.bot_token'), $filePath);

            $imageContent = file_get_contents($fileUrl);
            if ($imageContent === false) {
                throw new \RuntimeException('Failed to download image');
            }

            $localPath = sprintf('telegram/%s/', $user->getId()) . uniqid('photo_', true) . '.jpg';
            $this->filesystem->write($localPath, $imageContent);

            $caption = $message->caption ?? 'Décris cette image';

            $this->processChatMessage(
                "[Image: {$localPath}]\n\n{$caption}",
                $chatId,
                $user
            );
        } catch (\Throwable $throwable) {
            $this->logger->error('Photo handling error: ' . $throwable->getMessage());
            $this->sendMessage($chatId, 'Désolé, je n\'ai pas pu traiter cette image.');
        }
    }

    public function handleDocument(Message $message, User $user): void
    {
        $chatId = (string) $message->chat->id;
        $document = $message->document;

        if (!$document instanceof \Phptg\BotApi\Type\Document) {
            return;
        }

        $fileId = $document->fileId;
        $fileName = $document->fileName ?? 'document';
        $mimeType = $document->mimeType ?? 'application/octet-stream';

        $this->sendChatAction($chatId, 'typing');

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
                $chatId,
                $user
            );
        } catch (\Throwable $throwable) {
            $this->logger->error('Document handling error: ' . $throwable->getMessage());
            $this->sendMessage($chatId, 'Désolé, je n\'ai pas pu traiter ce document.');
        }
    }

    private function handleCommand(string $text, string $chatId, User $user): bool
    {
        $parts = explode(' ', $text);
        $command = substr($parts[0], 1);

        if ($command === 'start') {
            $this->manageSession($user->getTelegramId(), true);
            $this->sendMessage(
                $chatId,
                $this->brainRegistry->get($this->telegramSession->get('brain_avatar'), $this->telegramSession)->getOpeningText()
            );
            return true;
        }

        if ($command === 'help') {
            $message = <<<EOF
Commandes disponibles :
/start - Démarrer la conversation
/help - Afficher cette aide

EOF;
            $brains = $this->brainRegistry->list();
            foreach ($brains as $brain) {
                $message .= sprintf('/%s - Agent %s (%s)', $brain['slug'], $brain['name'], $brain['description']) . "\n";
            }

            $this->sendMessage(
                $chatId,
                $message
            );
            return true;
        }

        if ($command === 'list') {
            $this->sendMessage($chatId, "Cerveaux disponibles :\n" . $this->getBrainListText());
            return true;
        }

        if ($this->brainRegistry->has($command)) {
            $this->setBrainForUser($user, $command);
            $meta = $this->brainRegistry->getMeta($command);
            $this->sendMessage(
                $chatId,
                "Cerveau '{$meta['name']}' sélectionné.\n{$meta['description']}\n\nQue puis-je faire pour vous ?"
            );
            return true;
        }

        return false;
    }

    private function processChatMessage(string $text, string $chatId, User $user): void
    {
        $this->sendChatAction($chatId, 'typing');

        try {
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

    private function setBrainForUser(User $user, string $brainName): void
    {
        $entityRepository = $this->entityManager->getRepository(User::class);
        $entityRepository->updateBrainAvatar($user, $brainName);
    }

    private function sendChatAction(string $chatId, string $action): void
    {
        try {
            $this->telegramBotApi->sendChatAction(chatId: $chatId, action: $action);
        } catch (\Throwable $throwable) {
            $msg = 'Failed to send chat action: ' . $throwable->getMessage();
            $this->logger->debug($msg);
        }
    }

    private function sendMessage(string $chatId, string $text): void
    {
        try {
            $chunks = $this->splitMessage($text);
            foreach ($chunks as $chunk) {
                $this->telegramBotApi->sendMessage(chatId: $chatId, text: $chunk, parseMode: 'Markdown');
            }
        } catch (\Throwable $throwable) {
            $msg = 'Failed to send message: ' . $throwable->getMessage();
            $this->logger->error($msg);
            try {
                $this->telegramBotApi->sendMessage(chatId: $chatId, text: $text);
            } catch (\Throwable $e2) {
                $msg = 'Failed to send plain message: ' . $e2->getMessage();
                $this->logger->error($msg);
            }
        }
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
}
