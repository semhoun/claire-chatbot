<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\ChatHistory;
use App\Entity\File;
use App\Entity\User;
use App\Services\Session\SessionInterface;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\Output\Destination;
use RuntimeException;

final readonly class PdfGeneratorService
{
    private const int MAX_CONTENT_LENGTH = 10 * 1024 * 1024;

    private const string DEFAULT_CSS = <<<'CSS'
        body { font-family: dejavusans; font-size: 10.5pt; line-height: 1.5; color: #263445; }
        p { margin: 0 0 3mm; }
        h1, h2, h3, h4, h5, h6 {
            font-weight: bold; line-height: 1.2;
            color: #182b3a; page-break-after: avoid;
        }
        h1 { font-size: 24pt; margin: 0 0 6mm; }
        h2 { font-size: 16pt; margin: 7mm 0 3mm; }
        h3 { font-size: 12pt; margin: 5mm 0 2mm; }
        h4 { font-size: 11pt; margin: 4mm 0 2mm; }
        h5, h6 { font-size: 10.5pt; margin: 4mm 0 2mm; }
        ul, ol { margin: 2mm 0 4mm; padding-left: 6mm; }
        blockquote {
            margin: 4mm 0; padding: 3mm 4mm; border-left: 0.8mm solid #879bac;
            background-color: #f3f6f8; color: #435568;
        }
        /* mPDF's overflow: wrap breaks long cell values instead of shrinking the entire table. */
        table {
            border-collapse: collapse; margin: 3mm 0 5mm; font-size: 9.5pt;
            line-height: 1.35; overflow: wrap;
        }
        th, td { padding: 2.5mm 3mm; border-bottom: 0.2mm solid #d5dee5; vertical-align: top; }
        th { background-color: #eaf0f5; color: #182b3a; font-weight: bold; text-align: left; }
        pre, code, kbd, samp { font-family: dejavusansmono; font-size: 9pt; }
        pre {
            margin: 3mm 0 4mm; padding: 3mm; line-height: 1.4;
            background-color: #f3f6f8; border: 0.2mm solid #d5dee5;
        }
        img { max-width: 100%; }
        a { color: #245b80; text-decoration: underline; }
        hr { color: #d5dee5; height: 0.2mm; margin: 5mm 0; }
        CSS;

    public function __construct(
        private Settings $settings,
        private Filesystem $filesystem,
        private EntityManagerInterface $entityManager,
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
        $displayName = trim($params['filename'] ?? '');
        $displayName = $displayName !== '' ? $displayName : 'document';
        $pageSize = $params['pageSize'] ?? $this->settings->get('tools.pdf.defaultPageSize');
        $orientation = $params['orientation'] ?? 'portrait';
        $margins = $params['margins'] ?? [];

        if (strlen($content) > self::MAX_CONTENT_LENGTH) {
            throw new RuntimeException('PDF content exceeds maximum allowed size');
        }

        $user = $this->entityManager->getRepository(User::class)->getCurrentUser($session);
        if (! $user) {
            throw new RuntimeException('User not found for PDF generation');
        }

        $history = $this->entityManager->getRepository(ChatHistory::class)->getCurrentUserChatHistory($session, $threadId);
        if ($history === null) {
            throw new RuntimeException('Cannot generate PDF for non-existent chat history');
        }

        $html = $format === 'markdown'
            ? $this->markdown->convert($content)
            : $content;

        [$html, $tempFiles] = $this->resolveGeneratedImages($html, $user);

        try {
            $pdfContent = $this->renderPdf($html, $pageSize, $orientation, $margins);
        } finally {
            $this->cleanupTempFiles($tempFiles);
        }

        $file = new File();
        $file->setGeneratedFileData(
            $history,
            $displayName,
            'pdf',
            strlen($pdfContent),
            [
                'format' => $format,
                'pageSize' => $pageSize,
                'orientation' => $orientation === 'portrait' ? 'P' : 'L',
            ]
        );

        try {
            $this->filesystem->write($file->getFilePath(), $pdfContent);
        } catch (FilesystemException $filesystemException) {
            throw new RuntimeException(
                'Failed to save generated PDF: ' . $filesystemException->getMessage(),
                (int) $filesystemException->getCode(),
                $filesystemException
            );
        }

        $fileId = $file->getFileId();

        $this->entityManager->persist($file);
        $this->entityManager->flush();

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
            'setExternalImageTimeout' => 2,
            'setExternalResourceTimeout' => 2,
            'curlAllowUnsafeSsl' => false,
        ]);

        // Load defaults first so document styles and explicit page settings remain authoritative.
        $mpdf->WriteHTML(self::DEFAULT_CSS, HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * Resolve @@GENERATED@@ image tokens to temporary file <img> tags.
     * Uses file paths instead of base64 to avoid pcre.backtrack_limit issues.
     *
     * @return array{string, list<string>} HTML with resolved images and list of temp files
     */
    private function resolveGeneratedImages(string $html, User $user): array
    {
        $tempFiles = [];

        $resolvedHtml = preg_replace_callback(
            File::GENERATED_FILE_PATTERN,
            function (array $matches) use ($user, &$tempFiles): string {
                $fileId = str_replace(['"', "'"], ['', ''], $matches[2]);

                $file = $this->entityManager->getRepository(File::class)->findOneBy(['fileId' => $fileId, 'user' => $user]);
                if ($file === null) {
                    throw new RuntimeException(sprintf('Image ID %s not found. Use only IDs from previous generate_image calls.', $fileId));
                }

                if ($file->fileType() !== File::FILE_TYPE_IMAGE) {
                    return $matches[0];
                }

                // Write to temp file instead of base64 to avoid pcre.backtrack_limit
                $tempFile = $this->writeImageToTempFile($file);
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
    private function writeImageToTempFile(File $file): ?string
    {
        $filePath = $file?->getFilePath();
        if ($filePath === null) {
            return null;
        }

        try {
            $imageData = $this->filesystem->read($filePath);
        } catch (FilesystemException) {
            // Leave token as-is if file not found
            return null;
        }

        $tempDir = $this->settings->get('tools.pdf.tempDir');
        $tempFile = $tempDir . '/' . $file->getFilename();

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
