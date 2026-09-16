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
        private readonly string $threadId,
    ) {
        $description = <<<'EOT'
Generates a PDF document from HTML or Markdown content. The generated PDF will be sent to the user.
Use this tool whenever the user requests or needs a PDF document, report, or printable output.
The content can be provided as HTML or Markdown (specify the format using the 'format' parameter).

Choose a visual direction appropriate to the user's document rather than always producing a plain report. Use HTML for custom-designed documents and Markdown for simple content. You are free to create a coordinated color palette, a cover, section banners, callout boxes, solid backgrounds, simple borders, generous padding/margins and typographic hierarchy. Adapt the design to the request; decoration must not compromise readability or invent factual content, branding, logos or dates.
The PDF provides default typography, spacing, table and code styles only as a fallback. Customize them freely with embedded <style> blocks, CSS classes and inline styles; explicit document CSS can override the default styles. Use semantic headings, paragraphs, lists and tables within your design.
HTML is rendered by mPDF, not a web browser. Use simple block layout and supported print CSS with pt/mm units; avoid flexbox, grid, JavaScript, CSS variables, web fonts, and fixed-position layouts. Available font families include dejavusans, dejavuserif and dejavusansmono. Use concrete CSS values and local fonts, not external stylesheets or imports.
For data tables use table/thead/tbody/tr/th/td so column headings repeat across pages. Keep tables reasonably narrow, allow natural page breaks, and split long identifiers with <wbr> when needed; do not apply page-break-inside:avoid to an entire long table. Keep code lines short. Use landscape or a larger page size for genuinely wide data rather than tiny text.
Use page-break-before:always or <pagebreak /> only for intentional section breaks in HTML. Add page numbers or running headers/footers only when appropriate to the requested document, using mPDF's supported features and sufficient margins. Preserve requested page size, orientation and margins, including zero.
The page_size parameter sets the paper size. In mPDF, CSS @page size defines the page box inside that paper, not a replacement paper size: use a matching named size (A4, Letter, A3 or A5), explicit dimensions, or auto to follow the paper. Use mPDF's sheet-size only when deliberately changing the paper in CSS. Margins must leave a positive printable width and height on every page, including named and left/right/first pages. A server-configured page limit (100 by default) is enforced during rendering; split longer documents rather than generating oversized output. Failed rendering does not save a partial PDF.

IMPORTANT: The tool returns two fields:
- "id": The file identifier in the format @@GENERATED@@<uuid>@@. This ID must be used in the message text to reference the PDF.
- "name": The human-readable display name of the file. This is shown to the user when they download the PDF.

Always use the "id" value (the @@GENERATED@@...@@ pattern) in your message, use it with or without the <a> tag or markdown link.

You can embed images in the PDF content by including their @@GENERATED@@<uuid>@@ tokens in the HTML/Markdown. These will be automatically resolved and embedded as images in the PDF.
Place generated image tokens on their own, not inside an img src attribute or a Markdown image URL. Preserve image aspect ratios. Only generated raster images resolved by Claire are allowed: do not supply image URLs, local file paths, data URIs, var: images, SVG, external stylesheets, CSS imports or file attachments. Unauthorized resource access fails generation; ordinary hyperlinks remain usable.
IMPORTANT: Use ONLY image IDs that have been explicitly provided by the generate_image tool in the current conversation. NEVER invent, placeholder, or hallucinate image IDs (like @@GENERATED@@placeholder@@). If you haven't called the tool yet, you don't have an ID to use.
If you need to include an image that hasn't been generated yet, you MUST call generate_image FIRST, wait for the response to get the ID, and ONLY THEN call generate_pdf. NEVER call both tools in parallel if one depends on the other.
EOT;

        parent::__construct(
            'generate_pdf',
            $description
        );
    }

    /**
     * @param string $content The HTML or Markdown content to convert to PDF
     * @param string|null $format Input format: 'html' or 'markdown'
     * @param string|null $filename Human-readable display name for the PDF (without extension), shown to the user on download
     * @param string|null $page_size Page size: A4, Letter, A3, A5
     * @param string|null $orientation Page orientation: 'portrait' or 'landscape'
     * @param int|null $margin_top Top margin in mm
     * @param int|null $margin_bottom Bottom margin in mm
     * @param int|null $margin_left Left margin in mm
     * @param int|null $margin_right Right margin in mm
     */
    public function __invoke(
        string $content,
        ?string $format = 'html',
        ?string $filename = null,
        ?string $page_size = 'A4',
        ?string $orientation = 'portrait',
        ?int $margin_top = 15,
        ?int $margin_bottom = 15,
        ?int $margin_left = 15,
        ?int $margin_right = 15,
    ): string {
        // Neuron passes explicit null for omitted optional properties.
        $filename = trim($filename ?? '');
        $filename = $filename !== '' ? $filename : 'document';
        try {
            $enabled = $this->settings->get('tools.pdf.enabled');

            if (! $enabled) {
                return json_encode([
                    'status' => 'error',
                    'message' => 'PDF generation is not enabled.',
                ], JSON_THROW_ON_ERROR);
            }

            $pdfId = $this->pdfGeneratorService->generatePdf($this->session, $this->threadId, [
                'content' => $content,
                'format' => $format ?? 'html',
                'filename' => $filename,
                'pageSize' => $page_size ?? 'A4',
                'orientation' => $orientation ?? 'portrait',
                'margins' => [
                    'top' => $margin_top ?? 15,
                    'bottom' => $margin_bottom ?? 15,
                    'left' => $margin_left ?? 15,
                    'right' => $margin_right ?? 15,
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
