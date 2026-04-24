<?php

declare(strict_types=1);

namespace App\Brain\Tools;

use App\Services\PdfGeneratorService;
use App\Services\Session\SessionInterface;
use App\Services\Settings;
use JsonException;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class PdfGeneratorTool extends Tool implements MessagePostProcessorInterface
{
    public function __construct(
        private readonly PdfGeneratorService $pdfGeneratorService,
        private readonly Settings $settings,
        private readonly SessionInterface $session,
    ) {
        $description = <<<'EOT'
Generates a PDF document from HTML or Markdown content. The generated PDF will be sent to the user.
Use this tool whenever the user requests or needs a PDF document, report, or printable output.
The content can be provided as HTML or Markdown (specify the format using the 'format' parameter).

IMPORTANT: The tool returns two fields:
- "id": The file identifier in the format @@GENERATED@@<user_id@uuid.pdf>@@. This ID must be used in the message text to reference the PDF. Do NOT use <a> tags or markdown links.
- "name": The human-readable display name of the file. This is shown to the user when they download the PDF.

Always use the "id" value (the @@GENERATED@@...@@ pattern) in your message, NOT the name.
EOT;

        parent::__construct(
            'generate_pdf',
            $description
        );
    }

    /**
     * @param string $content The HTML or Markdown content to convert to PDF
     * @param string $format Input format: 'html' or 'markdown'
     * @param string|null $filename Human-readable display name for the PDF (without extension), shown to the user on download
     * @param string $page_size Page size: A4, Letter, A3, A5
     * @param string $orientation Page orientation: 'portrait' or 'landscape'
     * @param int $margin_top Top margin in mm
     * @param int $margin_bottom Bottom margin in mm
     * @param int $margin_left Left margin in mm
     * @param int $margin_right Right margin in mm
     */
    public function __invoke(
        string $content,
        string $format = 'html',
        ?string $filename = null,
        string $page_size = 'A4',
        string $orientation = 'portrait',
        int $margin_top = 15,
        int $margin_bottom = 15,
        int $margin_left = 15,
        int $margin_right = 15,
    ): string {
        try {
            $enabled = $this->settings->get('tools.pdf.enabled');

            if (! $enabled) {
                return json_encode([
                    'status' => 'error',
                    'message' => 'PDF generation is not enabled.',
                ], JSON_THROW_ON_ERROR);
            }

            $threadId = $this->session->get('threadId');

            if ($threadId === null) {
                return json_encode([
                    'status' => 'error',
                    'message' => 'No active conversation thread.',
                ], JSON_THROW_ON_ERROR);
            }

            $pdfId = $this->pdfGeneratorService->generatePdf($this->session, $threadId, [
                'content' => $content,
                'format' => $format,
                'filename' => $filename,
                'pageSize' => $page_size,
                'orientation' => $orientation,
                'margins' => [
                    'top' => $margin_top,
                    'bottom' => $margin_bottom,
                    'left' => $margin_left,
                    'right' => $margin_right,
                ],
            ]);

            $displayName = $filename ?? 'document';

            return json_encode([
                'status' => 'success',
                'message' => 'PDF generated successfully',
                'id' => $pdfId,
                'name' => $displayName . '.pdf',
            ], JSON_THROW_ON_ERROR);
        } catch (\Exception $exception) {
            return json_encode([
                'status' => 'error',
                'message' => 'Error generating PDF: ' . $exception->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }
    }

    #[\Override]
    public function postProcessMessage(Message $message): Message
    {
        $fileId = $this->extractFileId();

        if ($fileId === null) {
            return $message;
        }

        if ($this->isFileIdInMessage($message, $fileId)) {
            return $message;
        }

        return $this->appendFileIdToMessage($message, $fileId);
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[\Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                'content',
                PropertyType::STRING,
                'The HTML or Markdown content to convert to PDF.',
                true
            ),
            new ToolProperty(
                'format',
                PropertyType::STRING,
                'Input format: "html" or "markdown".',
                false
            ),
            new ToolProperty(
                'filename',
                PropertyType::STRING,
                'Human-readable display name for the PDF (without extension). This name is shown to the user when downloading the file.',
                false
            ),
            new ToolProperty(
                'page_size',
                PropertyType::STRING,
                'Page size: A4, Letter, A3, or A5.',
                false
            ),
            new ToolProperty(
                'orientation',
                PropertyType::STRING,
                'Page orientation: "portrait" or "landscape".',
                false
            ),
            new ToolProperty(
                'margin_top',
                PropertyType::INTEGER,
                'Top margin in millimeters.',
                false
            ),
            new ToolProperty(
                'margin_bottom',
                PropertyType::INTEGER,
                'Bottom margin in millimeters.',
                false
            ),
            new ToolProperty(
                'margin_left',
                PropertyType::INTEGER,
                'Left margin in millimeters.',
                false
            ),
            new ToolProperty(
                'margin_right',
                PropertyType::INTEGER,
                'Right margin in millimeters.',
                false
            ),
        ];
    }

    private function extractFileId(): ?string
    {
        $result = $this->getResult();

        if ($result === null) {
            return null;
        }

        try {
            $resultData = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

            if (! isset($resultData['status'], $resultData['id']) || $resultData['status'] !== 'success') {
                return null;
            }

            return $resultData['id'];
        } catch (JsonException) {
            return null;
        }
    }

    private function isFileIdInMessage(Message $message, string $fileId): bool
    {
        $messageContent = $message->getContent() ?? '';

        return str_contains($messageContent, $fileId);
    }

    private function appendFileIdToMessage(Message $message, string $fileId): Message
    {
        $newContent = $message->getContent() . "\n" . $fileId;
        $updatedBlocks = $this->updateContentBlocks($message->getContentBlocks(), $newContent, $fileId);

        $message->setContents($updatedBlocks);

        return $message;
    }

    /**
     * @param array<int, mixed> $contentBlocks
     *
     * @return array<int, mixed>
     */
    private function updateContentBlocks(array $contentBlocks, string $newContent, string $fileId): array
    {
        $updatedBlocks = [];
        $hasTextBlock = false;

        foreach ($contentBlocks as $contentBlock) {
            if ($contentBlock instanceof TextContent && ! $hasTextBlock) {
                $updatedBlocks[] = new TextContent($newContent);
                $hasTextBlock = true;
            } else {
                $updatedBlocks[] = $contentBlock;
            }
        }

        if (! $hasTextBlock) {
            $updatedBlocks[] = new TextContent($fileId);
        }

        return $updatedBlocks;
    }
}
