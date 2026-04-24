<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\ChatHistoryFile;
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
        $format = $params['format'] ?? $this->settings->get('pdf.defaultFormat');
        $displayName = $params['filename'] ?? null;
        $pageSize = $params['pageSize'] ?? $this->settings->get('pdf.defaultPageSize');
        $orientation = $params['orientation'] ?? 'portrait';
        $margins = $params['margins'] ?? [];

        if (strlen($content) > self::MAX_CONTENT_LENGTH) {
            throw new RuntimeException('PDF content exceeds maximum allowed size');
        }

        $html = $format === 'markdown'
            ? $this->markdown->convert($content)
            : $content;

        $pdfContent = $this->renderPdf($html, $pageSize, $orientation, $margins);

        $userId = (string) $session->get(Auth::USERID);
        $uuid = Uuid::uuid4()->toString();
        $diskFilename = $uuid . '.pdf';

        $localPath = GeneratedFileService::FOLDER_PREFIX . '/' . $userId . '/' . $diskFilename;
        $fileId = '@@GENERATED@@' . $userId . GeneratedFileService::FOLDER_SEPARATOR . $diskFilename . '@@';

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
        ]);

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
            'tempDir' => $this->settings->get('pdf.tempDir'),
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
    private function saveFileReference(string $threadId, string $filePath, array $metadata): void
    {
        $history = $this->chatHistoryRepository->findOneBy(['threadId' => $threadId]);

        if ($history === null) {
            return;
        }

        $chatHistoryFile = new ChatHistoryFile();
        $chatHistoryFile->setHistory($history);
        $chatHistoryFile->setUser($history->getUser());
        $chatHistoryFile->setFileType('pdf');
        $chatHistoryFile->setFilePath($filePath);
        $chatHistoryFile->setMetadata($metadata);

        $this->entityManager->persist($chatHistoryFile);
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
}
