<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\BrainRegistry;
use App\Brain\ChatHistory\UserChatHistory;
use App\Brain\Tools\GenerateImageTool;
use App\Entity\User;
use App\Enums\TelegramAction;
use App\Queue\QueueDoer;
use App\Services\Session\TelegramSession;
use Doctrine\ORM\EntityManager;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\UserMessage;
use Phptg\BotApi\Constant\ParseMode;
use Phptg\BotApi\FailResult;
use Phptg\BotApi\TelegramBotApi;
use Phptg\BotApi\Type\InputFile;
use Phptg\BotApi\Type\Message;
use Phptg\BotApi\Type\Update\Update;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface as Logger;

class TelegramService implements QueueDoer
{
    public const array COMMANDS = [
        'start' => 'Démarrer une nouvelle conversation',
        'help' => "Afficher l'aide",
        'brain' => 'Voir ou changer de personnalité',
        'comfyui' => 'Voir ou changer le workflow ComfyUI',
    ];

    /**
     * Load session for a user and return their settings.
     *
     * @return array<string, mixed>|null Returns null if user not found
     */
    public function getUserSettings(string $telegramUserId): ?array
    {
        $this->telegramSession->load($telegramUserId);

        $user = $this->entityManager->getRepository(User::class)->findByTelegramId($telegramUserId);
        if ($user === null) {
            return null;
        }

        $this->setUserSessionData($telegramUserId, $user);
        $this->setUserParams($user);
        $this->setDefaultSessionParams();
        $this->initializeComfyUIWorkflow();
        if (!$this->telegramSession->has('chatId')) {
            $this->initializeChatId(false);
        }

        $settings = [
            'brain_avatar' => $this->telegramSession->get('brain_avatar'),
        ];

        if ($this->comfyUIWorkflowRegistry->isEnabled()) {
            $settings['comfyui_workflow'] = $this->telegramSession->get(
                ComfyUIWorkflowRegistry::SESSION_KEY,
            );
        }

        return $settings;
    }

    /**
     * Update a user setting.
     */
    public function updateUserSetting(string $telegramUserId, string $key, mixed $value): bool
    {
        $this->telegramSession->load($telegramUserId);

        $user = $this->entityManager->getRepository(User::class)->findByTelegramId($telegramUserId);
        if ($user === null) {
            return false;
        }

        if ($key === 'brain_avatar') {
            $brain = (string) $value;
            if ($brain === 'reset') {
                $brain = $this->settings->get('session.defaultParams.brain_avatar');
            }

            if (!$this->brainRegistry->has($brain)) {
                return false;
            }

            $this->telegramSession->set('brain_avatar', $brain);
            $this->telegramSession->flush();

            return true;
        }

        if ($key === 'comfyui_workflow' && $this->comfyUIWorkflowRegistry->isEnabled()) {
            $workflow = (string) $value;
            if ($this->comfyUIWorkflowRegistry->has($workflow)) {
                $this->telegramSession->set(ComfyUIWorkflowRegistry::SESSION_KEY, $workflow);
                $this->persistWorkflowToUser($workflow);
                $this->telegramSession->flush();

                return true;
            }

            return false;
        }

        return false;
    }

    /**
     * Start a new chat for a user and send welcome message.
     */
    public function startNewChat(string $telegramUserId, int $telegramChatId): bool
    {
        try {
            $this->telegramSession->load($telegramUserId);

            $user = $this->entityManager->getRepository(User::class)->findByTelegramId($telegramUserId);
            if ($user === null) {
                return false;
            }

            // Initialize session data (similar to getUserSettings)
            $this->setUserSessionData($telegramUserId, $user);
            $this->setUserParams($user);
            $this->setDefaultSessionParams();
            $this->initializeComfyUIWorkflow();
            $this->initializeChatId(true);

            // Get data before flush (flush unloads the session)
            $currentBrain = $this->telegramSession->get('brain_avatar');

            $this->telegramSession->flush();

            // Send welcome message like /start does
            $agent = $this->brainRegistry->get($currentBrain, $this->telegramSession);
            $openingText = $agent->getOpeningText();

            // Handle images in welcome message
            $imageIds = $this->extractImageIds($openingText);
            if ($imageIds !== []) {
                $this->handleImageResponse($telegramChatId, $openingText, $imageIds);

                return true;
            }

            $this->sendMessage($telegramChatId, $openingText);

            return true;
        } catch (\Throwable $throwable) {
            $this->logger->error('Failed to start new chat: ' . $throwable->getMessage(), [
                'userId' => $telegramUserId,
                'chatId' => $telegramChatId,
                'exception' => $throwable,
            ]);

            return false;
        }
    }

    private readonly TelegramSession $telegramSession;

    private ?\DateTime $lastChatActionDate = null;

    public function __construct(
        private readonly Logger $logger,
        private readonly BrainRegistry $brainRegistry,
        private readonly EntityManager $entityManager,
        private readonly Settings $settings,
        private readonly ComfyUIWorkflowRegistry $comfyUIWorkflowRegistry,
        private readonly Filesystem $filesystem,
        private readonly TelegramBotApi $telegramBotApi,
        private readonly TelegramMarkdown $telegramMarkdown,
    ) {
        $this->telegramSession = new TelegramSession($entityManager);
    }

    public function processUpdate(Update $update): void
    {
        $message = $update->message;
        if (! $message instanceof \Phptg\BotApi\Type\Message) {
            return;
        }

        $telegramChatId = $message->chat->id ?? null;
        if ($telegramChatId === '') {
            return;
        }

        try {
            $this->handleMessage($telegramChatId, $message);
        } catch (\Throwable $throwable) {
            $this->logger->error('Telegram update processing error: ' . $throwable->getMessage(), [
                'exception' => $throwable,
                'update_id' => $update->updateId,
            ]);
            $this->sendMessage($telegramChatId, 'Désolé, j\'ai un soucis.');
        }
    }

    public static function make(ContainerInterface $container): self
    {
        return $container->get(self::class);
    }

    /** @param array<string, mixed> $payload */
    public function handle(array $payload): void
    {
        $updateJson = (string) ($payload['update_json'] ?? '');
        if ($updateJson === '') {
            return;
        }

        $this->processUpdate(Update::fromJson($updateJson));
    }

    public function handleMessage(int $telegramChatId, Message $message): void
    {
        $telegramUserId = (string) $message->from->id;
        if (! $this->manageSession($telegramUserId)) {
            $this->sendMessage($telegramChatId, "Je ne vous reconnais pas, merci d'ajouter votre id " . $telegramUserId . " sur l'interface web");
            return;
        }

        $this->processMessageByType($telegramChatId, $message);
        $this->telegramSession->flush();
    }

    public function handlePhoto(int $telegramChatId, Message $message): void
    {
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

            $localPath = sprintf('telegram/%s/', $this->telegramSession->get(Auth::USERID)) . uniqid('photo_', true) . '.jpg';
            $this->filesystem->write($localPath, $imageContent);

            $caption = $message->caption ?? 'Décris cette image';

            $this->processChatMessage(
                $telegramChatId,
                "[Image: {$localPath}]\n\n{$caption}"
            );
        } catch (\Throwable $throwable) {
            $this->logger->error('Photo handling error: ' . $throwable->getMessage());
            $this->sendMessage($telegramChatId, 'Désolé, je n\'ai pas pu traiter cette image.');
        }
    }

    public function handleDocument(int $telegramChatId, Message $message): void
    {
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
            $localPath = sprintf('telegram/%s/', $this->telegramSession->get(Auth::USERID)) . uniqid('doc_', true) . '.' . $extension;
            $this->filesystem->write($localPath, $fileContent);

            $caption = $message->caption ?? 'Analyse ce document';

            $this->processChatMessage(
                $telegramChatId,
                "[Document: {$localPath} ({$mimeType})]\n\n{$caption}",
            );
        } catch (\Throwable $throwable) {
            $this->logger->error('Document handling error: ' . $throwable->getMessage());
            $this->sendMessage($telegramChatId, 'Désolé, je n\'ai pas pu traiter ce document.');
        }
    }

    private function processMessageByType(int $telegramChatId, Message $message): void
    {
        if ($this->hasPhoto($message)) {
            $this->handlePhoto($telegramChatId, $message);
            return;
        }

        if ($this->hasDocument($message)) {
            $this->handleDocument($telegramChatId, $message);
            return;
        }

        $text = $message->text;
        if ($text === null) {
            $this->sendMessage($telegramChatId, 'Je ne peux traiter que du texte, des photos et des documents.');
            return;
        }

        $this->processTextMessage($telegramChatId, $text);
    }

    private function hasPhoto(Message $message): bool
    {
        return $message->photo !== null && $message->photo !== [];
    }

    private function hasDocument(Message $message): bool
    {
        return $message->document instanceof \Phptg\BotApi\Type\Document;
    }

    private function processTextMessage(int $telegramChatId, string $text): void
    {
        if (! str_starts_with($text, '/')) {
            $this->processChatMessage($telegramChatId, $text);
            return;
        }

        $handled = $this->handleCommand($telegramChatId, $text);
        if (! $handled) {
            $this->sendMessage($telegramChatId, '⚠ Commande inconnue');
        }
    }

    private function manageSession(string $telegramUserId, bool $renew = false): bool
    {
        $this->telegramSession->load($telegramUserId);

        if (! $renew && $this->telegramSession->get(Auth::AUTHENTICATED, false) === true) {
            return true;
        }

        return $this->initializeSession($telegramUserId, $renew);
    }

    private function initializeSession(string $telegramUserId, bool $renew): bool
    {
        $user = $this->entityManager->getRepository(User::class)->findByTelegramId($telegramUserId);
        if ($user === null) {
            return false;
        }

        $this->setUserSessionData($telegramUserId, $user);
        $this->setUserParams($user);
        $this->setDefaultSessionParams();
        $this->initializeComfyUIWorkflow();
        $this->initializeChatId($renew);

        return true;
    }

    private function setUserSessionData(string $telegramUserId, User $user): void
    {
        $this->telegramSession->set(Auth::USERID, $user->getId());
        $this->telegramSession->set('telegram_id', $telegramUserId);
        $this->telegramSession->set(Auth::AUTHENTICATED, true);
        $this->telegramSession->set(Auth::USERINFO, [
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'email' => $user->getEmail(),
            'displayName' => trim($user->getFirstName() . ' ' . $user->getLastName()),
        ]);
    }

    private function setUserParams(User $user): void
    {
        foreach ($user->getParams() ?? [] as $key => $value) {
            if ($this->telegramSession->get($key) === null) {
                $this->telegramSession->set($key, $value);
            }
        }
    }

    private function setDefaultSessionParams(): void
    {
        foreach ($this->settings->get('session.defaultParams') as $key => $value) {
            if (! $this->telegramSession->has($key)) {
                $this->telegramSession->set($key, $value);
            }
        }
    }

    private function initializeComfyUIWorkflow(): void
    {
        if ($this->settings->get('comfyui.enabled') !== true) {
            return;
        }

        $workflow = (string) $this->telegramSession->get(ComfyUIWorkflowRegistry::SESSION_KEY, '');
        if ($workflow !== '' && $this->comfyUIWorkflowRegistry->has($workflow)) {
            return;
        }

        $defaultWorkflow = $this->comfyUIWorkflowRegistry->getDefaultSlug();
        if ($defaultWorkflow !== null) {
            $this->telegramSession->set(ComfyUIWorkflowRegistry::SESSION_KEY, $defaultWorkflow);
        }
    }

    private function initializeChatId(bool $renew): void
    {
        $chatId = $this->telegramSession->get('chatId');
        if (! $renew && $chatId) {
            return;
        }

        $this->telegramSession->set('chatId', uniqid(UserChatHistory::CHAT_TELEGRAM, true));
    }

    private function handleCommand(int $telegramChatId, string $text): bool
    {
        $parts = explode(' ', $text);
        $command = substr(array_shift($parts), 1);

        if (! isset(self::COMMANDS[$command])) {
            return false;
        }

        $method = match ($command) {
            'start' => 'cmdStart',
            'help' => 'cmdHelp',
            'brain' => 'cmdBrain',
            'comfyui' => 'cmdComfyui',
        };

        $this->$method($telegramChatId, $parts);

        return true;
    }

    private function sendChatAction(int $telegramChatId, TelegramAction $telegramAction): void
    {
        if (! $this->lastChatActionDate instanceof \DateTime || $this->lastChatActionDate->diff(new \DateTime())->s > 5) {
            $this->lastChatActionDate = new \DateTime();
            $res = $this->telegramBotApi->sendChatAction($telegramChatId, $telegramAction->value);
            if ($res instanceof FailResult) {
                $this->logger->error('Failed to send chat action', ['chatId' => $telegramChatId, 'error' => $res]);
            }
        }
    }

    private function processChatMessage(int $telegramChatId, string $text): void
    {
        try {
            $responseText = $this->generateChatResponse($telegramChatId, $text);
            $this->sendChatResponse($telegramChatId, $responseText);
        } catch (\Throwable $throwable) {
            $this->logger->error('Chat processing error: ' . $throwable->getMessage());
            $this->sendMessage($telegramChatId, 'Désolé, une erreur est survenue lors du traitement de votre message.');
        }
    }

    private function generateChatResponse(int $telegramChatId, string $text): string
    {
        $currentBrain = $this->telegramSession->get('brain_avatar');
        $agent = $this->brainRegistry->get($currentBrain, $this->telegramSession);

        $userMessage = new UserMessage($text);
        $userMessage->addMetadata('timestamp', new \DateTimeImmutable()->format(\DateTimeInterface::ATOM));

        $agentHandler = $agent->stream($userMessage);
        $this->processStreamChunks($agentHandler->events(), $telegramChatId);

        $agentMessage = $agentHandler->getMessage();
        $agentMessage->addMetadata('timestamp', new \DateTimeImmutable()->format(\DateTimeInterface::ATOM));

        return $agentMessage->getContent();
    }

    /**
     * @param iterable<mixed> $chunks
     */
    private function processStreamChunks(iterable $chunks, int $telegramChatId): void
    {
        foreach ($chunks as $chunk) {
            $action = $this->resolveChunkAction($chunk);
            if ($action instanceof \App\Enums\TelegramAction) {
                $this->sendChatAction($telegramChatId, $action);
            }
        }
    }

    private function resolveChunkAction(mixed $chunk): ?TelegramAction
    {
        if ($chunk instanceof ToolCallChunk || $chunk instanceof ToolResultChunk) {
            return $this->resolveToolChunkAction($chunk);
        }

        if ($chunk instanceof ReasoningChunk || $chunk instanceof TextChunk) {
            return TelegramAction::TEXT;
        }

        return null;
    }

    private function resolveToolChunkAction(ToolCallChunk|ToolResultChunk $chunk): TelegramAction
    {
        return $chunk->tool instanceof GenerateImageTool
            ? TelegramAction::GENERATE
            : TelegramAction::TEXT;
    }

    private function sendChatResponse(int $telegramChatId, string $responseText): void
    {
        $imageIds = $this->extractImageIds($responseText);
        if ($imageIds !== []) {
            $this->handleImageResponse($telegramChatId, $responseText, $imageIds);
            return;
        }

        $this->sendMessage($telegramChatId, $responseText);
    }

    private function sendMessage(int $telegramChatId, string $text): void
    {
        // Filtrer les balises [OC] et [/OC]
        $filteredText = $this->filterOCTags($text);
        if ($filteredText === '') {
            return;
        }

        try {
            $formattedText = $this->formatForTelegram($filteredText);
            $chunks = $this->splitMessage($formattedText);
            foreach ($chunks as $chunk) {
                $result = $this->telegramBotApi->sendMessage(chatId: $telegramChatId, text: $chunk, parseMode: ParseMode::MARKDOWN_V2);
                if ($result instanceof FailResult) {
                    $this->logger->error('Failed to send message chunk', ['chatId' => $telegramChatId, 'chunk' => $chunk, 'error' => $result]);
                }
            }
        } catch (\Throwable $throwable) {
            $msg = 'Failed to send message: ' . $throwable->getMessage();
            $this->logger->error($msg);
        }
    }

    /**
     * Format text for Telegram MarkdownV2.
     * Converts markdown to MarkdownV2 format, falling back to escaped plain text on failure.
     */
    private function formatForTelegram(string $text): string
    {
        try {
            return $this->telegramMarkdown->convertToMarkdownV2($text);
        } catch (\Throwable $throwable) {
            $this->logger->warning('Markdown conversion failed, falling back to escaped text: ' . $throwable->getMessage());
            $specialChars = '_*[]()~`>#+-=|{}!.';

            return addcslashes($text, $specialChars . '\\');
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

    /**
     * Extract image ids from text matching pattern.
     *
     * @return array<int, string>
     */
    private function extractImageIds(string $content): array
    {
        if (preg_match_all(ComfyUIService::IMAGE_PATTERN, $content, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        return array_map(
            static fn (array $match): string => $match[1],
            $matches
        );
    }

    /**
     * Handle response containing images by sending them with captions.
     *
     * @param array<int, string> $imageIds
     */
    private function handleImageResponse(int $telegramChatId, string $responseText, array $imageIds): void
    {
        // Remove image paths from text to create caption
        $caption = preg_replace(ComfyUIService::IMAGE_PATTERN, '', $responseText);
        // Filter OC tags
        $caption = $this->filterOCTags((string) $caption);

        // Send each image
        $imageCount = count($imageIds);
        foreach ($imageIds as $index => $imageId) {
            $isLast = $index === $imageCount - 1;
            // Only add caption to the last image, or if there's only one image
            $imageCaption = $isLast || $imageCount === 1 ? $caption : null;
            $this->sendPhoto($telegramChatId, $imageId, $imageCaption);
        }
    }

    /**
     * Send a photo to Telegram chat.
     */
    private function sendPhoto(int $telegramChatId, string $imageId, ?string $caption = null): void
    {
        $imagePath = ComfyUIService::FOLDER_PREFIX . '/' . str_replace(ComfyUIService::FOLDER_SEPARATOR, '/', $imageId);

        try {
            $this->sendChatAction($telegramChatId, TelegramAction::PHOTO);
            $tempFile = $this->prepareImageFile($telegramChatId, $imagePath);
            if ($tempFile === null) {
                return;
            }

            $formattedCaption = $this->formatCaption($caption);
            $this->sendPhotoWithInputFile($telegramChatId, $tempFile, $imagePath, $formattedCaption);
        } catch (\Throwable $throwable) {
            $this->handlePhotoSendError($throwable, $telegramChatId, $imagePath);
        } finally {
            $this->cleanupTempFile($tempFile ?? null);
        }
    }

    private function prepareImageFile(int $telegramChatId, string $imagePath): ?string
    {
        if (! $this->filesystem->fileExists($imagePath)) {
            $this->logger->error('Image file not found', ['path' => $imagePath]);
            $this->sendMessage($telegramChatId, 'Désolé, l\'image générée n\'a pas pu être trouvée.');

            return null;
        }

        $fileContent = $this->filesystem->read($imagePath);
        $tempFile = tempnam(sys_get_temp_dir(), 'telegram_');
        if ($tempFile === false) {
            throw new \RuntimeException('Failed to create temporary file');
        }

        file_put_contents($tempFile, $fileContent);

        return $tempFile;
    }

    private function formatCaption(?string $caption): ?string
    {
        if ($caption === null || $caption === '') {
            return null;
        }

        $filteredCaption = $this->filterOCTags($caption);
        if ($filteredCaption === '') {
            return null;
        }

        return $this->formatForTelegram($filteredCaption);
    }

    private function sendPhotoWithInputFile(int $telegramChatId, string $tempFile, string $imagePath, ?string $formattedCaption): void
    {
        $inputFile = InputFile::fromLocalFile($tempFile, basename($imagePath));

        if ($this->shouldSendWithCaption($formattedCaption)) {
            $result = $this->telegramBotApi->sendPhoto(
                chatId: $telegramChatId,
                photo: $inputFile,
                caption: $formattedCaption,
                parseMode: ParseMode::MARKDOWN_V2
            );
            $formattedCaption = null;
        } else {
            $result = $this->telegramBotApi->sendPhoto(
                chatId: $telegramChatId,
                photo: $inputFile
            );
        }

        $this->handlePhotoSendResult($result, $telegramChatId, $imagePath, $formattedCaption);
    }

    private function shouldSendWithCaption(?string $formattedCaption): bool
    {
        return $formattedCaption !== null && strlen($formattedCaption) <= 1024;
    }

    private function handlePhotoSendResult(mixed $result, int $telegramChatId, string $imagePath, ?string $remainingCaption): void
    {
        if ($result instanceof FailResult) {
            $this->logger->error('Failed to send photo', [
                'chatId' => $telegramChatId,
                'path' => $imagePath,
                'error' => $result,
                'caption' => $remainingCaption,
            ]);
            $this->sendMessage($telegramChatId, 'Désolé, je n\'ai pas pu envoyer l\'image.');
        }

        if ($remainingCaption !== null) {
            $this->sendMessage($telegramChatId, $remainingCaption);
        }
    }

    private function handlePhotoSendError(\Throwable $throwable, int $telegramChatId, string $imagePath): void
    {
        $message = $throwable instanceof FilesystemException
            ? 'Filesystem error sending photo: ' . $throwable->getMessage()
            : 'Error sending photo: ' . $throwable->getMessage();

        $this->logger->error($message, ['path' => $imagePath]);
        $this->sendMessage($telegramChatId, 'Désolé, une erreur est survenue lors de l\'envoi de l\'image.');
    }

    private function cleanupTempFile(?string $tempFile): void
    {
        if ($tempFile !== null && file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    private function cmdStart(int $telegramChatId): void
    {
        $telegramUserId = (string) $telegramChatId;
        $this->startNewChat($telegramUserId, $telegramChatId);
    }

    private function cmdHelp(int $telegramChatId): void
    {
        $message = "Commandes disponibles :\n";
        foreach (self::COMMANDS as $key => $val) {
            $message .= sprintf('/%s - %s', $key, $val) . "\n";
        }

        $this->sendMessage($telegramChatId, $message);
    }

    /** @param array<int, string> $args */
    private function cmdBrain(int $telegramChatId, array $args): void
    {
        if ($args === []) {
            $this->sendBrainListMessage($telegramChatId);
            return;
        }

        $this->setBrainFromCommand($telegramChatId, $args[0]);
    }

    private function sendBrainListMessage(int $telegramChatId): void
    {
        $telegramUserId = (string) $telegramChatId;
        $settings = $this->getUserSettings($telegramUserId);

        if ($settings === null) {
            $this->sendMessage($telegramChatId, 'Erreur : utilisateur non trouvé.');
            return;
        }

        $message = '**Personnalité actuelle : ';
        try {
            $currentBrain = $settings['brain_avatar'];
            $message .= $this->brainRegistry->getMeta($currentBrain)['name'];
        } catch (\Exception) {
            $message .= 'Aucune personnalité sélectionnée';
        }

        $message .= "**\n\n---\n_Personnalités disponibles_ :\n";
        $message .= "- **reset** : Personnalité par défaut\n";
        $brains = $this->brainRegistry->list();
        foreach ($brains as $brain) {
            $message .= sprintf('- `%s` : %s', $brain['slug'], $brain['description']) . "\n";
        }

        $this->sendMessage($telegramChatId, $message);
    }

    private function setBrainFromCommand(int $telegramChatId, string $brain): void
    {
        $telegramUserId = (string) $telegramChatId;
        $success = $this->updateUserSetting($telegramUserId, 'brain_avatar', $brain);

        if ($success) {
            $resolvedBrain = $brain === 'reset'
                ? $this->settings->get('session.defaultParams.brain_avatar')
                : $brain;
            $meta = $this->brainRegistry->getMeta($resolvedBrain);
            $this->sendMessage($telegramChatId, sprintf('Personnalité changée : %s', $meta['name']));
        } else {
            $this->sendMessage($telegramChatId, 'Erreur : personnalité invalide.');
        }
    }

    /** @param array<int, string> $args */
    private function cmdComfyui(int $telegramChatId, array $args): void
    {
        if (! $this->isComfyUIEnabled()) {
            $this->sendMessage($telegramChatId, 'ComfyUI est désactivé.');
            return;
        }

        $workflows = $this->comfyUIWorkflowRegistry->list();
        if ($workflows === []) {
            $this->sendMessage($telegramChatId, 'Aucun workflow ComfyUI disponible.');
            return;
        }

        if ($args === []) {
            $this->sendWorkflowListMessage($telegramChatId, $workflows);
            return;
        }

        $this->setWorkflowFromCommand($telegramChatId, strtolower(trim($args[0])));
    }

    private function isComfyUIEnabled(): bool
    {
        return $this->settings->get('comfyui.enabled') === true;
    }

    /**
     * @param array<int, array{slug:string, label:string, type:string}> $workflows
     */
    private function sendWorkflowListMessage(int $telegramChatId, array $workflows): void
    {
        $telegramUserId = (string) $telegramChatId;
        $settings = $this->getUserSettings($telegramUserId);

        if ($settings === null) {
            $this->sendMessage($telegramChatId, 'Erreur : utilisateur non trouvé.');
            return;
        }

        $currentWorkflow = (string) ($settings['comfyui_workflow'] ?? '');
        $message = '**Workflow ComfyUI actuel : ';

        if ($currentWorkflow !== '' && $this->comfyUIWorkflowRegistry->has($currentWorkflow)) {
            $message .= $this->comfyUIWorkflowRegistry->getMeta($currentWorkflow)['label'];
        } else {
            $message .= 'non défini';
        }

        $message .= "**\n\n---\n_Workflows disponibles_ :\n";
        foreach ($workflows as $workflow) {
            $message .= sprintf('- `%s` : %s', $workflow['slug'], $workflow['label']) . "\n";
        }

        $this->sendMessage($telegramChatId, $message);
    }

    private function setWorkflowFromCommand(int $telegramChatId, string $workflow): void
    {
        if (! $this->comfyUIWorkflowRegistry->has($workflow)) {
            $this->sendMessage($telegramChatId, 'Workflow ComfyUI inconnu.');
            return;
        }

        $telegramUserId = (string) $telegramChatId;
        $success = $this->updateUserSetting($telegramUserId, 'comfyui_workflow', $workflow);

        if ($success) {
            $meta = $this->comfyUIWorkflowRegistry->getMeta($workflow);
            $this->sendMessage($telegramChatId, sprintf('Workflow ComfyUI changé : %s', $meta['label']));
        } else {
            $this->sendMessage($telegramChatId, 'Erreur : impossible de changer le workflow.');
        }
    }

    private function persistWorkflowToUser(string $workflow): void
    {
        $user = $this->entityManager->getRepository(User::class)->find($this->telegramSession->get(Auth::USERID));
        if (! $user instanceof User) {
            return;
        }

        $params = $user->getParams() ?? [];
        $params[ComfyUIWorkflowRegistry::SESSION_KEY] = $workflow;
        $user->setParams($params);
        $this->entityManager->flush();
    }

    /**
     * Filtre les balises [OC] et [/OC] du contenu.
     * Retourne une chaîne vide si le contenu ne contient que ces balises ou est vide.
     */
    private function filterOCTags(string $content): string
    {
        // Retirer les blocs [OC]...[/OC] sur plusieurs lignes
        $filtered = preg_replace('/\[OC\].*?\[\/OC\]/s', '', $content);

        // Retirer les balises isolées [OC] et [/OC] au cas où
        $filtered = preg_replace('/\[OC\]|\[\/OC\]/', '', (string) $filtered);

        // Trim et vérifier si vide
        return trim((string) $filtered);
    }
}
