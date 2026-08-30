<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\BrainRegistry;
use App\Brain\ChatHistory\UserChatHistory;
use App\Brain\LongTermMemory;
use App\Brain\LongTermMemoryRebuilder;
use App\Brain\Tools\GenerateImageTool;
use App\Entity\File;
use App\Entity\User;
use App\Enums\TelegramAction;
use App\Services\Audio\AudioServiceInterface;
use App\Services\Queue\QueueDoer;
use App\Services\Session\TelegramSession;
use Doctrine\ORM\EntityManager;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\UserMessage;
use Phptg\BotApi\Constant\ParseMode;
use Phptg\BotApi\FailResult;
use Phptg\BotApi\TelegramBotApi;
use Phptg\BotApi\Type\Audio;
use Phptg\BotApi\Type\InputFile;
use Phptg\BotApi\Type\Message;
use Phptg\BotApi\Type\Update\Update;
use Phptg\BotApi\Type\Voice;
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

    private const float CHAT_ACTION_REFRESH_INTERVAL = 4.0;

    private readonly TelegramSession $telegramSession;

    private float $lastChatActionAt = 0.0;

    private ?int $lastChatActionChatId = null;

    private ?TelegramAction $telegramAction = null;

    public function __construct(
        private readonly Logger $logger,
        private readonly BrainRegistry $brainRegistry,
        private readonly EntityManager $entityManager,
        private readonly Settings $settings,
        private readonly ComfyUIWorkflowRegistry $comfyUIWorkflowRegistry,
        private readonly Filesystem $filesystem,
        private readonly TelegramBotApi $telegramBotApi,
        private readonly TelegramMarkdown $telegramMarkdown,
        private readonly AudioServiceInterface $audioService,
        private readonly TelegramAudioService $telegramAudioService,
        private readonly TelegramChatActionHeartbeat $telegramChatActionHeartbeat,
    ) {
        $this->telegramSession = new TelegramSession($entityManager);
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

    /**
     * Update a user setting.
     */
    public function updateUserSetting(string $key, mixed $value): bool
    {
        $this->telegramSession->ensureLoaded();

        if ($key === 'brain_avatar') {
            $brain = (string) $value;
            if ($brain === 'reset') {
                $brain = $this->settings->get('session.defaultParams.brain_avatar');
            }

            if (! $this->brainRegistry->has($brain)) {
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
                $this->telegramSession->flush();

                return true;
            }

            return false;
        }

        if ($key === LongTermMemory::SESSION_KEY && is_bool($value)) {
            $this->telegramSession->set(LongTermMemory::SESSION_KEY, $value);

            $userId = (string) $this->telegramSession->get(Auth::USERID, '');
            $user = $this->entityManager->getRepository(User::class)->find($userId);
            if ($user instanceof User) {
                $params = $user->getParams() ?? [];
                $params[LongTermMemory::SESSION_KEY] = $value;
                $user->setParams($params);
            }

            $this->telegramSession->flush();

            return true;
        }

        if ($key === AudioServiceInterface::VOICE_SESSION_KEY && is_string($value)) {
            if (! $this->audioService->isAllowedVoice($value)) {
                return false;
            }

            $this->telegramSession->set(AudioServiceInterface::VOICE_SESSION_KEY, $value);
            $userId = (string) $this->telegramSession->get(Auth::USERID, '');
            $user = $this->entityManager->getRepository(User::class)->find($userId);
            if ($user instanceof User) {
                $params = $user->getParams() ?? [];
                $params[AudioServiceInterface::VOICE_SESSION_KEY] = $value;
                $user->setParams($params);
            }

            $this->telegramSession->flush();

            return true;
        }

        return false;
    }

    public function rebuildLongTermMemory(): void
    {
        $this->telegramSession->ensureLoaded();

        if ($this->telegramSession->get(LongTermMemory::SESSION_KEY, false) !== true) {
            throw new \RuntimeException('Long-term memory is disabled');
        }

        new LongTermMemoryRebuilder(
            connection: $this->entityManager->getConnection(),
            settings: $this->settings,
            session: $this->telegramSession,
        )->rebuild();
    }

    /**
     * Start a new chat for a user and send welcome message.
     */
    public function startNewChat(int $telegramChatId): void
    {
        $this->telegramSession->ensureLoaded();

        $threadId = uniqid(UserChatHistory::CHAT_TELEGRAM, true);
        $this->telegramSession->set('threadId', $threadId);

        $currentBrain = $this->telegramSession->get('brain_avatar');
        $agent = $this->brainRegistry->get($currentBrain, $this->telegramSession, $threadId);
        $openingText = $agent->getOpeningText();
        $chatHistory = $agent->getChatHistory();
        $assistantMessage = new AssistantMessage($openingText)
            ->addMetadata('timestamp', new \DateTimeImmutable()->format(\DateTimeInterface::ATOM));
        $chatHistory->initializeWithOpeningMessage($assistantMessage, false);

        // Handle files in welcome message
        $fileIds = $this->extractFileIds($openingText);
        if ($fileIds !== []) {
            $this->handleFileResponse($telegramChatId, $openingText, $fileIds);
            return;
        }

        $this->sendMessage($telegramChatId, $openingText);
        $this->telegramSession->flush();
    }

    public function processUpdate(Update $update): void
    {
        $message = $update->message;
        if (! $message instanceof \Phptg\BotApi\Type\Message) {
            return;
        }

        $telegramChatId = $message->chat->id ?? null;
        if ($telegramChatId === 0) {
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

    public function manageSession(string $telegramUserId): bool
    {
        $this->telegramSession->load($telegramUserId);
        if ($this->telegramSession->get(Auth::AUTHENTICATED, false) === true) {
            return true;
        }

        $user = $this->entityManager->getRepository(User::class)->findByTelegramId($telegramUserId);
        if ($user === null) {
            return false;
        }

        // Set user session data
        $this->telegramSession->set(Auth::USERID, $user->getId());
        $this->telegramSession->set('telegram_id', $telegramUserId);
        $this->telegramSession->set(Auth::AUTHENTICATED, true);
        $this->telegramSession->set(Auth::USERINFO, [
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'email' => $user->getEmail(),
            'displayName' => trim($user->getFirstName() . ' ' . $user->getLastName()),
        ]);

        // Set User params
        foreach ($user->getParams() ?? [] as $key => $value) {
            if ($this->telegramSession->get($key) === null) {
                $this->telegramSession->set($key, $value);
            }
        }

        // setDefaultSessionParams
        foreach ($this->settings->get('session.defaultParams') as $key => $value) {
            if (! $this->telegramSession->has($key)) {
                $this->telegramSession->set($key, $value);
            }
        }

        // Intitialize comfyUI workflow
        if ($this->settings->get('tools.comfyui.enabled') === true) {
            $workflow = (string) $this->telegramSession->get(ComfyUIWorkflowRegistry::SESSION_KEY, '');
            if ($workflow === '' && $this->comfyUIWorkflowRegistry->has($workflow)) {
                $defaultWorkflow = $this->comfyUIWorkflowRegistry->getDefaultSlug();
                $this->telegramSession->set(ComfyUIWorkflowRegistry::SESSION_KEY, $defaultWorkflow);
            }
        }

        $this->telegramSession->set('threadId', null);

        return true;
    }

    /**
     * Retrieve user settings based on the provided Telegram user ID.
     *
     * @param string $telegramUserId The unique identifier of the Telegram user.
     *
     * @return array<string, mixed>|null An associative array containing the user settings, or null if no settings are available.
     */
    public function getUserSettings(string $telegramUserId): ?array
    {
        $this->telegramSession->ensureLoaded();

        $settings = [
            'brain_avatar' => $this->telegramSession->get('brain_avatar'),
            LongTermMemory::SESSION_KEY => $this->telegramSession->get(
                LongTermMemory::SESSION_KEY,
                false
            ),
        ];

        if ($this->comfyUIWorkflowRegistry->isEnabled()) {
            $settings['comfyui_workflow'] = $this->telegramSession->get(
                ComfyUIWorkflowRegistry::SESSION_KEY,
            );
        }

        $voice = (string) $this->telegramSession->get(
            AudioServiceInterface::VOICE_SESSION_KEY,
            $this->audioService->defaultVoice(),
        );
        $settings[AudioServiceInterface::VOICE_SESSION_KEY] = $this->audioService
            ->isAllowedVoice($voice) ? $voice : $this->audioService->defaultVoice();

        return $settings;
    }

    /**
     * Sends a message to a specific Telegram chat.
     *
     * @param int $telegramChatId The ID of the Telegram chat where the message will be sent.
     * @param string $text The text content of the message to be sent.
     */
    public function sendMessage(int $telegramChatId, string $text): void
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

    private function handleMessage(int $telegramChatId, Message $message): void
    {
        $telegramUserId = (string) $message->from->id;
        if (! $this->manageSession($telegramUserId)) {
            $this->sendMessage($telegramChatId, "Je ne vous reconnais pas, merci d'ajouter votre id " . $telegramUserId . " sur l'interface web");
            return;
        }

        $this->processMessageByType($telegramChatId, $message);
        $this->telegramSession->flush();
    }

    private function handlePhoto(int $telegramChatId, Message $message): void
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

    private function handleDocument(int $telegramChatId, Message $message): void
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
        if ($this->hasAudio($message)) {
            $this->handleAudio($telegramChatId, $message);
            return;
        }

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
            $this->sendMessage(
                $telegramChatId,
                'Je ne peux traiter que du texte, de l’audio, des photos et des documents.',
            );
            return;
        }

        $this->processTextMessage($telegramChatId, $text);
    }

    private function handleAudio(int $telegramChatId, Message $message): void
    {
        if (! $this->audioService->isAvailable()) {
            $this->sendMessage($telegramChatId, 'La fonction audio n’est pas configurée.');
            return;
        }

        $audio = $message->voice ?? $message->audio;
        if (! $audio instanceof Voice && ! $audio instanceof Audio) {
            return;
        }

        try {
            $text = $this->telegramAudioService->transcribe($audio);
            $this->processChatMessage($telegramChatId, $text, voiceResponse: true);
        } catch (\Throwable $throwable) {
            $this->logger->error('Audio handling error: ' . $throwable->getMessage(), [
                'exception' => $throwable,
            ]);
            $this->sendMessage($telegramChatId, 'Désolé, je n’ai pas pu transcrire ce message audio.');
        }
    }

    private function hasPhoto(Message $message): bool
    {
        return $message->photo !== null && $message->photo !== [];
    }

    private function hasDocument(Message $message): bool
    {
        return $message->document instanceof \Phptg\BotApi\Type\Document;
    }

    private function hasAudio(Message $message): bool
    {
        return $message->voice instanceof Voice || $message->audio instanceof Audio;
    }

    private function processTextMessage(int $telegramChatId, string $text): void
    {
        if (! str_starts_with($text, '/')) {
            $this->processChatMessage($telegramChatId, $text);
            return;
        }

        $handled = $this->handleCommand($telegramChatId, $text);
        if (! $handled) {
            $this->sendMessage($telegramChatId, 'Commande inconnue');
        }
    }

    private function handleCommand(int $telegramChatId, string $text): bool
    {
        $parts = explode(' ', $text);
        $command = substr(array_shift($parts), 1);

        if (! isset(self::COMMANDS[$command])) {
            return false;
        }

        $method = match ($command) {
            'start' => 'startNewChat',
            'help' => 'cmdHelp',
            'brain' => 'cmdBrain',
            'comfyui' => 'cmdComfyui',
        };

        $this->$method($telegramChatId, $parts);

        return true;
    }

    private function sendChatAction(
        int $telegramChatId,
        TelegramAction $telegramAction,
        bool $force = false,
    ): void {
        $now = hrtime(true) / 1_000_000_000;
        if (! $this->shouldSendChatAction(
            $telegramChatId,
            $telegramAction,
            $now,
            $force,
        )) {
            return;
        }

        $this->lastChatActionAt = $now;
        $this->lastChatActionChatId = $telegramChatId;
        $this->telegramAction = $telegramAction;

        $res = $this->telegramBotApi->sendChatAction(
            $telegramChatId,
            $telegramAction->value,
        );
        if ($res instanceof FailResult) {
            $this->logger->error('Failed to send chat action', [
                'chatId' => $telegramChatId,
                'error' => $res,
            ]);
        }
    }

    private function shouldSendChatAction(
        int $telegramChatId,
        TelegramAction $telegramAction,
        float $now,
        bool $force,
    ): bool {
        return $force
            || $this->lastChatActionChatId !== $telegramChatId
            || $this->telegramAction !== $telegramAction
            || $now - $this->lastChatActionAt >= self::CHAT_ACTION_REFRESH_INTERVAL;
    }

    private function processChatMessage(
        int $telegramChatId,
        string $text,
        bool $voiceResponse = false,
    ): void {
        try {
            $responseText = $this->generateChatResponse($telegramChatId, $text);
            $this->sendChatResponse($telegramChatId, $responseText);
            if ($voiceResponse) {
                $voice = (string) $this->telegramSession->get(
                    AudioServiceInterface::VOICE_SESSION_KEY,
                    $this->audioService->defaultVoice(),
                );
                if (! $this->audioService->isAllowedVoice($voice)) {
                    $voice = $this->audioService->defaultVoice();
                }

                $this->telegramAudioService->sendResponse(
                    $telegramChatId,
                    $responseText,
                    $voice,
                );
            }
        } catch (\Throwable $throwable) {
            $this->logger->error('Chat processing error: ' . $throwable->getMessage());
            $this->sendMessage($telegramChatId, 'Désolé, une erreur est survenue lors du traitement de votre message.');
        }
    }

    private function generateChatResponse(int $telegramChatId, string $text): string
    {
        $this->logger->info('Generating chat response for chat ID: ' . $telegramChatId, ['text' => $text, 'threadId' => $this->telegramSession->get('threadId')]);
        $this->sendChatAction($telegramChatId, TelegramAction::TEXT, force: true);

        $currentBrain = $this->telegramSession->get('brain_avatar');
        $agent = $this->brainRegistry->get($currentBrain, $this->telegramSession, $this->telegramSession->get('threadId'));

        $userMessage = new UserMessage($text);
        $userMessage->addMetadata('timestamp', new \DateTimeImmutable()->format(\DateTimeInterface::ATOM));

        return $this->telegramChatActionHeartbeat->run(
            $telegramChatId,
            TelegramAction::TEXT,
            function () use ($agent, $userMessage, $telegramChatId): string {
                $agentHandler = $agent->stream($userMessage);
                foreach ($agentHandler->events() as $chunk) {
                    $action = $this->resolveChunkAction($chunk);
                    if ($action instanceof \App\Enums\TelegramAction) {
                        $this->sendChatAction($telegramChatId, $action);
                    }
                }

                $agentMessage = $agentHandler->getMessage();
                $agentMessage->addMetadata(
                    'timestamp',
                    new \DateTimeImmutable()->format(\DateTimeInterface::ATOM),
                );

                return $agentMessage->getContent() ?? '';
            }
        );
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
        $fileIds = $this->extractFileIds($responseText);
        if ($fileIds !== []) {
            $this->handleFileResponse($telegramChatId, $responseText, $fileIds);
            return;
        }

        $this->sendMessage($telegramChatId, $responseText);
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
     * Extract file ids from text matching pattern.
     *
     * @return array<int, string>
     */
    private function extractFileIds(string $content): array
    {
        if (preg_match_all(File::GENERATED_FILE_PATTERN, $content, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        return array_map(
            static fn (array $match): string => str_replace(['"', "'"], ['', ''], $match[2]),
            $matches
        );
    }

    /**
     * Handle response containing files by sending them with captions.
     *
     * @param array<int, string> $fileIds
     */
    private function handleFileResponse(int $telegramChatId, string $responseText, array $fileIds): void
    {
        // Remove file paths from text to create caption
        $caption = preg_replace(File::GENERATED_FILE_PATTERN, '', $responseText);
        // Filter OC tags
        $caption = $this->filterOCTags((string) $caption);

        // Send each file
        $fileCount = count($fileIds);
        foreach ($fileIds as $index => $fileId) {
            $isLast = $index === $fileCount - 1;
            // Only add caption to the last file, or if there's only one file
            $fileCaption = $isLast || $fileCount === 1 ? $caption : null;

            $file = $this->entityManager->getRepository(File::class)->findOneBy(['fileId' => $fileId]);
            if ($file === null) {
                $this->logger->error('File not found for ID: ' . $fileId);
                $this->sendMessage($telegramChatId, "Désolé j'ai un soucis pour trouver le fichier à envoyer.");
                continue;
            }

            if ($file->fileType() === File::FILE_TYPE_IMAGE) {
                $this->sendPhoto($telegramChatId, $file, $fileCaption);
            } elseif ($file->fileType() === File::FILE_TYPE_AUDIO) {
                $this->sendAudio($telegramChatId, $file, $fileCaption);
            } else {
                $this->sendDocument($telegramChatId, $file, $fileCaption);
            }
        }
    }

    private function sendAudio(int $telegramChatId, File $file, ?string $caption = null): void
    {
        try {
            $this->sendChatAction($telegramChatId, TelegramAction::VOICE);
            $tempFile = $this->prepareTempFile(
                $telegramChatId,
                $file->getFilePath(),
                'fichier audio',
            );
            if ($tempFile === null) {
                return;
            }

            $formattedCaption = $this->formatCaption($caption);
            $inputFile = new InputFile($tempFile, $file->getFilename());
            if ($this->shouldSendWithCaption($formattedCaption)) {
                $result = $this->telegramBotApi->sendAudio(
                    chatId: $telegramChatId,
                    audio: $inputFile,
                    caption: $formattedCaption,
                    parseMode: ParseMode::MARKDOWN_V2,
                );
                $formattedCaption = null;
            } else {
                $result = $this->telegramBotApi->sendAudio(
                    chatId: $telegramChatId,
                    audio: $inputFile,
                );
            }

            $this->handleFileSendResult(
                $result,
                $telegramChatId,
                $file->getFilePath(),
                $formattedCaption,
                'fichier audio',
            );
        } catch (\Throwable $throwable) {
            $this->handleFileSendError(
                $throwable,
                $telegramChatId,
                $file->getFilePath(),
                'fichier audio',
            );
        } finally {
            $this->cleanupTempFile($tempFile ?? null);
        }
    }

    /**
     * Send a document to Telegram chat.
     */
    private function sendDocument(int $telegramChatId, File $file, ?string $caption = null): void
    {
        try {
            $this->sendChatAction($telegramChatId, TelegramAction::DOCUMENT);
            $tempFile = $this->prepareTempFile($telegramChatId, $file->getFilePath(), 'document');
            if ($tempFile === null) {
                return;
            }

            $formattedCaption = $this->formatCaption($caption);
            $inputFile = new InputFile($tempFile, $file->getFilename());

            if ($this->shouldSendWithCaption($formattedCaption)) {
                $result = $this->telegramBotApi->sendDocument(
                    chatId: $telegramChatId,
                    document: $inputFile,
                    caption: $formattedCaption,
                    parseMode: ParseMode::MARKDOWN_V2
                );
                $formattedCaption = null;
            } else {
                $result = $this->telegramBotApi->sendDocument(
                    chatId: $telegramChatId,
                    document: $inputFile
                );
            }

            $this->handleFileSendResult($result, $telegramChatId, $file->getFilePath(), $formattedCaption, 'document');
        } catch (\Throwable $throwable) {
            $this->handleFileSendError($throwable, $telegramChatId, $file->getFilePath(), 'document');
        } finally {
            $this->cleanupTempFile($tempFile ?? null);
        }
    }

    /**
     * Send a photo to Telegram chat.
     */
    private function sendPhoto(int $telegramChatId, File $file, ?string $caption = null): void
    {
        try {
            $this->sendChatAction($telegramChatId, TelegramAction::PHOTO);
            $tempFile = $this->prepareTempFile($telegramChatId, $file->getFilePath(), 'image');
            if ($tempFile === null) {
                return;
            }

            $formattedCaption = $this->formatCaption($caption);
            $this->sendPhotoWithInputFile($telegramChatId, $tempFile, $file->getFilePath(), $formattedCaption);
        } catch (\Throwable $throwable) {
            $this->handleFileSendError($throwable, $telegramChatId, $file->getFilePath(), 'image');
        } finally {
            $this->cleanupTempFile($tempFile ?? null);
        }
    }

    private function prepareTempFile(int $telegramChatId, string $filePath, string $type = 'fichier'): ?string
    {
        if (! $this->filesystem->fileExists($filePath)) {
            $this->logger->error($type . ' file not found', ['path' => $filePath]);
            $this->sendMessage($telegramChatId, sprintf("Désolé, le %s généré n'a pas pu être trouvé.", $type));

            return null;
        }

        $fileContent = $this->filesystem->read($filePath);
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
        $inputFile = new InputFile($tempFile, basename($imagePath));

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

        $this->handleFileSendResult($result, $telegramChatId, $imagePath, $formattedCaption, 'image');
    }

    private function shouldSendWithCaption(?string $formattedCaption): bool
    {
        return $formattedCaption !== null && strlen($formattedCaption) <= 1024;
    }

    private function handleFileSendResult(mixed $result, int $telegramChatId, string $filePath, ?string $remainingCaption, string $type = 'fichier'): void
    {
        if ($result instanceof FailResult) {
            $this->logger->error('Failed to send ' . $type, [
                'chatId' => $telegramChatId,
                'path' => $filePath,
                'error' => $result,
                'caption' => $remainingCaption,
            ]);
            $this->sendMessage($telegramChatId, sprintf("Désolé, je n'ai pas pu envoyer le %s.", $type));
        }

        if ($remainingCaption !== null) {
            $this->sendMessage($telegramChatId, $remainingCaption);
        }
    }

    private function handleFileSendError(\Throwable $throwable, int $telegramChatId, string $filePath, string $type = 'fichier'): void
    {
        $message = $throwable instanceof FilesystemException
            ? sprintf('Filesystem error sending %s: ', $type) . $throwable->getMessage()
            : sprintf('Error sending %s: ', $type) . $throwable->getMessage();

        $this->logger->error($message, ['path' => $filePath]);
        $this->sendMessage($telegramChatId, sprintf("Désolé, une erreur est survenue lors de l'envoi du %s.", $type));
    }

    private function cleanupTempFile(?string $tempFile): void
    {
        if ($tempFile !== null && file_exists($tempFile)) {
            unlink($tempFile);
        }
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
        $message = '**Personnalité actuelle : ';
        try {
            $currentBrain = $this->telegramSession->get('brain_avatar');
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
        $success = $this->updateUserSetting('brain_avatar', $brain);

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
        return $this->settings->get('tools.comfyui.enabled') === true;
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

        $success = $this->updateUserSetting('comfyui_workflow', $workflow);

        if ($success) {
            $meta = $this->comfyUIWorkflowRegistry->getMeta($workflow);
            $this->sendMessage($telegramChatId, sprintf('Workflow ComfyUI changé : %s', $meta['label']));
        } else {
            $this->sendMessage($telegramChatId, 'Erreur : impossible de changer le workflow.');
        }
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
