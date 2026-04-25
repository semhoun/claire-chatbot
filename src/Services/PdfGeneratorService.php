<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\File;
use App\Repository\ChatHistoryRepository;
use App\Services\Session\SessionInterface;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\Output\Destination;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class PdfGeneratorService
{
    private const int MAX_CONTENT_LENGTH = 10 * 1024 * 1024;

    public function __construct(
        private Settings $settings,
        private Filesystem $filesystem,
        private EntityManagerInterface $entityManager,
        private ChatHistoryRepository $chatHistoryRepository,
        private Markdown $markdown,
    ) {
    }

    /**
     * @param array{content: string, format?: string, filename?: string|null, pageSize?: string, orientation?: string, margins?: array{top?: int, bottom?: int, left?: int, right?: int}} $params
     */
    public function generatePdf(SessionInterface $session, string $threadId, array $params): string
    {
        $content = $params['content'] ?? '';
        $format = $params['format'] ?? $this->settings->get('tools.pdf.defaultFormat');
        $displayName = $params['filename'] ?? null;
        $pageSize = $params['pageSize'] ?? $this->settings->get('tools.pdf.defaultPageSize');
        $orientation = $params['orientation'] ?? 'portrait';
        $margins = $params['margins'] ?? [];

        if (strlen($content) > self::MAX_CONTENT_LENGTH) {
            throw new RuntimeException('PDF content exceeds maximum allowed size');
        }

        $userId = (string) $session->get(Auth::USERID);

        $html = $format === 'markdown'
            ? $this->markdown->convert($content)
            : $content;

        [$html, $tempFiles] = $this->resolveGeneratedImages($html, $userId);

        try {
            $pdfContent = $this->renderPdf($html, $pageSize, $orientation, $margins);
        } finally {
            $this->cleanupTempFiles($tempFiles);
        }

        $uuid = Uuid::uuid4()->toString();
        $diskFilename = $uuid . '.pdf';

        $localPath = File::GENERATED_FOLDER_PREFIX . '/' . $userId . '/' . $diskFilename;
        $fileId = '@@GENERATED@@' . $userId . File::GENERATED_FOLDER_SEPARATOR . $diskFilename . '@@';

        try {
            $this->filesystem->write($localPath, $pdfContent);
        } catch (FilesystemException $filesystemException) {
            throw new RuntimeException(
                'Failed to save generated PDF: ' . $filesystemException->getMessage(),
                (int) $filesystemException->getCode(),
                $filesystemException
            );
        }

        $safeDisplayName = $displayName !== null && $displayName !== ''
            ? $this->sanitizeFilename($displayName)
            : null;

        $this->saveFileReference($threadId, $localPath, [
            'displayName' => $safeDisplayName,
            'format' => $format,
            'pageSize' => $pageSize,
            'orientation' => $orientation === 'portrait' ? 'P' : 'L',
        ], $fileId);

        return $fileId;
    }

    /**
     * @param array{top?: int, bottom?: int, left?: int, right?: int} $margins
     *
     * @throws MpdfException
     */
    private function renderPdf(string $html, string $pageSize, string $orientation, array $margins): string
    {
        $mpdfOrientation = $orientation === 'landscape' ? 'L' : 'P';

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $pageSize,
            'orientation' => $mpdfOrientation,
            'tempDir' => $this->settings->get('tools.pdf.tempDir'),
            'margin_top' => $margins['top'] ?? 15,
            'margin_bottom' => $margins['bottom'] ?? 15,
            'margin_left' => $margins['left'] ?? 15,
            'margin_right' => $margins['right'] ?? 15,
        ]);

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function saveFileReference(string $threadId, string $filePath, array $metadata, string $fileId): void
    {
        $history = $this->chatHistoryRepository->findOneBy(['threadId' => $threadId]);

        if ($history === null) {
            return;
        }

        $file = new File();
        $file->setFileId($fileId);
        $file->setChatHistory($history);
        $file->setUser($history->getUser());
        $file->setFileType('pdf');
        $file->setFilePath($filePath);
        $file->setFilename(basename($filePath));
        $file->setMimeType('application/pdf');
        $file->setMetadata($metadata);

        $this->entityManager->persist($file);
        $this->entityManager->flush();
    }

    private function sanitizeFilename(?string $filename): ?string
    {
        if ($filename === null || $filename === '') {
            return null;
        }

        $sanitized = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $filename);

        return $sanitized !== null ? substr(trim($sanitized), 0, 100) : null;
    }

    /**
     * Resolve @@GENERATED@@ image tokens to temporary file <img> tags.
     * Uses file paths instead of base64 to avoid pcre.backtrack_limit issues.
     *
     * @return array{string, list<string>} HTML with resolved images and list of temp files
     */
    private function resolveGeneratedImages(string $html, string $userId): array
    {
        $tempFiles = [];

        $resolvedHtml = preg_replace_callback(
            File::GENERATED_FILE_PATTERN,
            function (array $matches) use ($userId, &$tempFiles): string {
                $fileId = $matches[1];

                // Skip PDF tokens - only process images
                if (str_ends_with(strtolower($fileId), '.pdf')) {
                    return $matches[0];
                }

                // Extract userId from token (format: userId@uuid.ext)
                $separatorPos = strpos($fileId, File::GENERATED_FOLDER_SEPARATOR);
                if ($separatorPos === false) {
                    return $matches[0];
                }

                $tokenUserId = substr($fileId, 0, $separatorPos);

                // Security check: only embed images belonging to current user
                if ($tokenUserId !== $userId) {
                    return $matches[0];
                }

                // Resolve filesystem path
                $token = '@@GENERATED@@' . $fileId . '@@';
                /** @var File|null $file */
                $file = $this->entityManager->getRepository(File::class)->findOneBy(['fileId' => $token]);

                if ($file !== null && $file->getFilePath() !== null) {
                    $filePath = $file->getFilePath();
                } else {
                    $filePath = File::GENERATED_FOLDER_PREFIX . '/'
                        . str_replace(File::GENERATED_FOLDER_SEPARATOR, '/', $fileId);
                }

                try {
                    $imageData = $this->filesystem->read($filePath);
                } catch (FilesystemException) {
                    // Leave token as-is if file not found
                    return $matches[0];
                }

                // Write to temp file instead of base64 to avoid pcre.backtrack_limit
                $tempFile = $this->writeImageToTempFile($fileId, $imageData);
                if ($tempFile === null) {
                    return $matches[0];
                }

                $tempFiles[] = $tempFile;

                return '<img src="' . $tempFile . '" style="max-width:100%;height:auto;">';
            },
            $html
        ) ?? $html;

        return [$resolvedHtml, $tempFiles];
    }

    /**
     * Write image data to a temporary file and return the path.
     * Uses the configured PDF temp directory.
     */
    private function writeImageToTempFile(string $fileId, string $imageData): ?string
    {
        $extension = strtolower(pathinfo($fileId, PATHINFO_EXTENSION));
        $tempDir = $this->settings->get('tools.pdf.tempDir') . '/images';

        if (! is_dir($tempDir) && ! @mkdir($tempDir, 0o750, true)) {
            return null;
        }

        // Extract uuid from fileId (userId@uuid.ext -> uuid)
        $separatorPos = strpos($fileId, File::GENERATED_FOLDER_SEPARATOR);
        $uuidPart = substr($fileId, $separatorPos !== false ? $separatorPos + 1 : 0);
        $uuid = pathinfo($uuidPart, PATHINFO_FILENAME);

        $tempFile = $tempDir . '/' . $uuid . '.' . $extension;

        if (file_put_contents($tempFile, $imageData) === false) {
            return null;
        }

        return $tempFile;
    }

    /**
     * Clean up temporary image files.
     *
     * @param list<string> $tempFiles
     */
    private function cleanupTempFiles(array $tempFiles): void
    {
        foreach ($tempFiles as $tempFile) {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }
}
