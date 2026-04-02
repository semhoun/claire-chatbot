<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\BrainRegistry;
use App\Brain\ChatHistory\UserChatHistory;
use App\Brain\Tools\GenerateImageTool;
use App\Entity\User;
use App\Enums\TelegramAction;
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
use Psr\Log\LoggerInterface as Logger;

class TelegramService
{
    public const array COMMANDS = [
        'start' => 'Démarrer une nouvelle conversation',
        'help' => "Afficher l'aide",
        'list' => 'Lister les personnalités',
        'brain' => 'Voir ou changer de personnalité (choisir reset pour celui par défaut)',
    ];

    private readonly TelegramSession $telegramSession;

    private ?\DateTime $lastChatActionDate = null;

    public function __construct(
        private readonly BrainRegistry $brainRegistry,
        private readonly EntityManager $entityManager,
        private readonly Settings $settings,
        private readonly Logger $logger,
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

    public function handleMessage(int $telegramChatId, Message $message): void
    {
        $telegramUserId = (string) $message->from->id;
        if (! $this->manageSession($telegramUserId)) {
            $this->sendMessage($telegramChatId, "Je ne vous reconnais pas, merci d'ajouter votre id " . $telegramUserId . " sur l'interface web");
            return;
        }

        $entityRepository = $this->entityManager->getRepository(User::class);
        $entityRepository->findByTelegramId($telegramUserId);

        if ($message->photo !== null && $message->photo !== []) {
            $this->handlePhoto($telegramChatId, $message);
            return;
        }

        if ($message->document instanceof \Phptg\BotApi\Type\Document) {
            $this->handleDocument($telegramChatId, $message);
            return;
        }

        $text = $message->text;
        if ($text === null) {
            $this->sendMessage($telegramChatId, 'Je ne peux traiter que du texte, des photos et des documents.');
            return;
        }

        if (str_starts_with($text, '/')) {
            $handled = $this->handleCommand($telegramChatId, $text);
            if (! $handled) {
                $this->sendMessage($telegramChatId, '⚠ Commande inconnue');
            }

            $this->telegramSession->flush();
            return;
        }

        $this->processChatMessage($telegramChatId, $text);

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

    private function handleCommand(int $telegramChatId, string $text): bool
    {
        $parts = explode(' ', $text);
        $command = substr(array_shift($parts), 1);

        if (! isset(self::COMMANDS[$command])) {
            return false;
        }

        $fct = 'cmd_' . $command;
        $this->$fct($telegramChatId, $parts);

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
            $currentBrain = $this->telegramSession->get('brain_avatar');
            $brain = $this->brainRegistry->get($currentBrain, $this->telegramSession);

            $userMessage = new UserMessage($text);
            $userMessage->addMetadata('timestamp', new \DateTimeImmutable()->format(\DateTimeInterface::ATOM));

            $agentHandler = $brain->stream($userMessage);

            // Iterate chunks
            foreach ($agentHandler->events() as $chunk) {
                if ($chunk instanceof ToolCallChunk || $chunk instanceof ToolResultChunk) {
                    if ($chunk->tool instanceof GenerateImageTool) {
                        $this->sendChatAction($telegramChatId, TelegramAction::GENERATE);
                    } else {
                        $this->sendChatAction($telegramChatId, TelegramAction::TEXT);
                    }
                } elseif ($chunk instanceof ReasoningChunk) {
                    $this->sendChatAction($telegramChatId, TelegramAction::TEXT);
                } elseif ($chunk instanceof TextChunk) {
                    $this->sendChatAction($telegramChatId, TelegramAction::TEXT);
                }
            }

            $agentMessage = $agentHandler->getMessage();

            $agentMessage->addMetadata('timestamp', new \DateTimeImmutable()->format(\DateTimeInterface::ATOM));
            $responseText = $agentMessage->getContent();

            // Check for generated images in the response
            $imageIds = $this->extractImageIds($responseText);
            if ($imageIds !== []) {
                $this->handleImageResponse($telegramChatId, $responseText, $imageIds);
                return;
            }

            $this->sendMessage($telegramChatId, $responseText);
        } catch (\Throwable $throwable) {
            $this->logger->error('Chat processing error: ' . $throwable->getMessage());
            $this->sendMessage($telegramChatId, 'Désolé, une erreur est survenue lors du traitement de votre message.');
        }
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
        try {
            $this->sendChatAction($telegramChatId, TelegramAction::PHOTO);

            $imagePath = ComfyUIService::FOLDER_PREFIX . '/' . str_replace(ComfyUIService::FOLDER_SEPARATOR, '/', $imageId);

            // Read file from Flysystem
            if (! $this->filesystem->fileExists($imagePath)) {
                $this->logger->error('Image file not found', ['path' => $imagePath]);
                $this->sendMessage($telegramChatId, 'Désolé, l\'image générée n\'a pas pu être trouvée.');

                return;
            }

            $fileContent = $this->filesystem->read($imagePath);

            // Create temporary file
            $tempFile = tempnam(sys_get_temp_dir(), 'telegram_');
            if ($tempFile === false) {
                throw new \RuntimeException('Failed to create temporary file');
            }

            file_put_contents($tempFile, $fileContent);

            $formattedCaption = null;
            if ($caption !== null && $caption !== '') {
                $filteredCaption = $this->filterOCTags($caption);
                if ($filteredCaption !== '') {
                    $formattedCaption = $this->formatForTelegram($filteredCaption);
                }
            }

            // Send photo using InputFile
            $inputFile = InputFile::fromLocalFile($tempFile, basename($imagePath));
            if (($formattedCaption !== null) && (strlen($formattedCaption) <= 1024)) {
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

            if ($result instanceof FailResult) {
                $this->logger->error('Failed to send photo', [
                    'chatId' => $telegramChatId,
                    'path' => $imagePath,
                    'error' => $result,
                    'caption' => $formattedCaption,
                ]);
                $this->sendMessage($telegramChatId, 'Désolé, je n\'ai pas pu envoyer l\'image.');
            }

            if ($formattedCaption !== null) {
                $this->sendMessage($telegramChatId, $formattedCaption);
            }
        } catch (FilesystemException $e) {
            $this->logger->error('Filesystem error sending photo: ' . $e->getMessage(), ['path' => $imagePath]);
            $this->sendMessage($telegramChatId, 'Désolé, une erreur est survenue lors de l\'envoi de l\'image.');
        } catch (\Throwable $throwable) {
            $this->logger->error('Error sending photo: ' . $throwable->getMessage(), ['path' => $imagePath]);
            $this->sendMessage($telegramChatId, 'Désolé, une erreur est survenue lors de l\'envoi de l\'image.');
        } finally {
            // Clean up temporary file
            if (isset($tempFile) && file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    private function cmd_start(int $telegramChatId): void
    {
        $this->manageSession($this->telegramSession->get('telegram_id'), true);

        $this->sendChatAction($telegramChatId, TelegramAction::TEXT);

        $this->sendMessage(
            $telegramChatId,
            $this->brainRegistry->get($this->telegramSession->get('brain_avatar'), $this->telegramSession)->getOpeningText()
        );
    }

    private function cmd_help(int $telegramChatId): void
    {
        $message = "Commandes disponibles :\n";
        foreach (self::COMMANDS as $key => $val) {
            $message .= sprintf('/%s - %s', $key, $val) . "\n";
        }

        $this->sendMessage($telegramChatId, $message);
    }

    private function cmd_list(int $telegramChatId): void
    {
        $message = "Personnalités disponibles :\n";
        $brains = $this->brainRegistry->list();
        foreach ($brains as $brain) {
            $message .= sprintf('- *%s* : %s', $brain['slug'], $brain['description']) . "\n";
        }

        $this->sendMessage($telegramChatId, $message);
    }

    private function cmd_brain(int $telegramChatId, array $args): void
    {
        if ($args === []) {
            $currentBrain = $this->telegramSession->get('brain_avatar');
            $this->sendMessage($telegramChatId, sprintf('Personnalité actuelle : %s', $this->brainRegistry->getMeta($currentBrain)['name']));
            return;
        }

        $brain = (string) $args[0];
        try {
            if ($brain === 'reset') {
                $brain = $this->settings->get('llm.defaultBrain');
            }

            $meta = $this->brainRegistry->getMeta($brain);

            $this->telegramSession->set('brain_avatar', $brain);
            $this->sendMessage($telegramChatId, sprintf('Personnalité changée : %s', $meta['name']));
        } catch (\Exception $exception) {
            $this->sendMessage($telegramChatId, sprintf('Erreur : %s', $exception->getMessage()));
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
