<?php

declare(strict_types=1);

namespace App\Services;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\Strikethrough\Strikethrough;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;

final readonly class TelegramMarkdown
{
    /**
     * Converts standard Markdown to Telegram MarkdownV2 format.
     *
     * @param string $markdown The markdown content to convert
     *
     * @return string The content formatted for Telegram MarkdownV2
     */
    public function convertToMarkdownV2(string $markdown): string
    {
        // Pre-process spoiler syntax (||text||) to a custom tag before parsing
        $markdown = $this->preprocessSpoilers($markdown);

        // Create CommonMark environment with necessary extensions
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new StrikethroughExtension());
        $environment->addExtension(new TableExtension());

        $markdownParser = new MarkdownParser($environment);
        $document = $markdownParser->parse($markdown);

        // Convert the AST to MarkdownV2
        return $this->convertNode($document);
    }

    /**
     * Pre-process spoiler syntax ||text|| and convert to <tg-spoiler> tags.
     */
    private function preprocessSpoilers(string $markdown): string
    {
        // Convert ||text|| to <tg-spoiler>text</tg-spoiler>
        // Use a regex that handles nested spoilers and escaped pipes
        return (string) preg_replace(
            '/\|\|([^|]+)\|\|/',
            '<tg-spoiler>$1</tg-spoiler>',
            $markdown
        );
    }

    /**
     * Convert a node to MarkdownV2 format.
     */
    private function convertNode(
        Node $node,
        bool $inUrl = false
    ): string {
        return match (true) {
            $node instanceof Text => $this->escapeText($node->getLiteral(), $inUrl),
            $node instanceof Newline => "\n",
            $node instanceof Strong => $this->wrapChildren($node, '*'),
            $node instanceof Emphasis => $this->wrapChildren($node, '_'),
            $node instanceof Code => '`' . $this->escapeCode($node->getLiteral()) . '`',
            $node instanceof Strikethrough => $this->wrapChildren($node, '~'),
            $node instanceof Link => $this->convertLink($node),
            $node instanceof Image => $this->convertImage($node),
            $node instanceof HtmlInline => $this->convertHtmlInline($node),
            $node instanceof Paragraph => $this->convertChildren($node, $inUrl) . "\n",
            $node instanceof Heading => $this->convertHeading($node),
            $node instanceof FencedCode, $node instanceof IndentedCode => $this->convertCodeBlock($node),
            $node instanceof BlockQuote => $this->convertBlockQuote($node),
            $node instanceof ListBlock => $this->convertListBlock($node),
            $node instanceof ListItem => $this->convertListItem($node),
            $node instanceof ThematicBreak => "\-\-\-\n",
            $node instanceof HtmlBlock => $this->convertHtmlBlock($node),
            $node instanceof Table => $this->convertTable($node),
            default => $this->convertChildren($node, $inUrl),
        };
    }

    private function convertImage(Image $image): string
    {
        $alt = $this->getChildrenText($image);
        $url = $image->getUrl();

        if ($url === '') {
            return $this->escapeText($alt);
        }

        return sprintf('[%s](%s)', $this->escapeText($alt), $this->escapeUrl($url));
    }

    private function convertHtmlInline(HtmlInline $htmlInline): string
    {
        $literal = $htmlInline->getLiteral();

        if (str_starts_with($literal, '<tg-spoiler>')) {
            return $this->wrapChildren($htmlInline, '||');
        }

        return '';
    }

    private function convertHtmlBlock(HtmlBlock $htmlBlock): string
    {
        $literal = $htmlBlock->getLiteral();

        if (str_contains($literal, '<tg-spoiler>')) {
            return $this->convertSpoilerBlock($literal);
        }

        return $this->escapeText(strip_tags($literal)) . "\n";
    }

    private function convertSpoilerBlock(string $literal): string
    {
        $content = strip_tags($literal, '<tg-spoiler>');
        $content = str_replace(
            ['<tg-spoiler>', '</tg-spoiler>'],
            ['', ''],
            $content
        );

        return '||' . $this->escapeText($content) . "||\n";
    }

    /**
     * Convert children of a node to MarkdownV2.
     */
    private function convertChildren(Node $node, bool $inUrl = false): string
    {
        $result = '';

        foreach ($node->children() as $child) {
            $result .= $this->convertNode($child, $inUrl);
        }

        return $result;
    }

    /**
     * Wrap children with delimiters.
     */
    private function wrapChildren(
        Node $node,
        string $delimiter
    ): string {
        // Convert children and escape for formatted text context
        // (escapes everything except the delimiter itself)
        $content = $this->convertChildrenForFormattedRegion($node, $delimiter);

        // Trim to avoid issues with whitespace inside formatting
        $content = trim($content);

        if ($content === '') {
            return '';
        }

        return $delimiter . $content . $delimiter;
    }

    /**
     * Convert children for use inside a formatted region (bold, italic, etc.).
     * Escapes all special characters except the formatting delimiter.
     */
    private function convertChildrenForFormattedRegion(
        Node $node,
        string $delimiter
    ): string {
        $result = '';

        foreach ($node->children() as $child) {
            $result .= $this->convertFormattedChild($child, $delimiter);
        }

        return $result;
    }

    private function convertFormattedChild(Node $node, string $delimiter): string
    {
        return match (true) {
            $node instanceof Text => $this->escapeFormattedText(
                $node->getLiteral(),
                $delimiter
            ),
            $node instanceof Newline => "\n",
            $node instanceof Strong => $this->wrapChildren($node, '*'),
            $node instanceof Emphasis => $this->wrapChildren($node, '_'),
            $node instanceof Strikethrough => $this->wrapChildren($node, '~'),
            $node instanceof Code => '`' . $this->escapeCode($node->getLiteral()) . '`',
            $node instanceof Link => $this->convertLink($node),
            default => $this->convertNode($node),
        };
    }

    /**
     * Escape text for use inside a formatted region.
     * Escapes chars that need escaping in any context EXCEPT the delimiter itself.
     * Other formatting chars (_ * ~ [ ] etc.) are NOT escaped inside formatted text.
     */
    private function escapeFormattedText(
        string $text,
        string $delimiter
    ): string {
        // Characters that ALWAYS need escaping in MarkdownV2:
        // @see https://core.telegram.org/bots/api#markdownv2-style
        $charsToEscape = '_*[]()~`>#+-=|{}!\.\\';

        // Handle multi-char delimiters (like || for spoilers)
        // Each char in the delimiter shouldn't be escaped
        $delimiterChars = str_split($delimiter);
        foreach ($delimiterChars as $delimiterChar) {
            $charsToEscape = str_replace($delimiterChar, '', $charsToEscape);
        }

        return addcslashes($text, $charsToEscape);
    }

    /**
     * Convert a heading element.
     */
    private function convertHeading(Heading $heading): string
    {
        $level = $heading->getLevel();
        $content = trim($this->convertChildren($heading));

        if ($content === '') {
            return "\n";
        }

        // Apply different styling based on heading level
        return match ($level) {
            1 => "*{$content}*\n\n",           // H1: Bold
            2 => "*{$content}*\n\n",           // H2: Bold
            3 => "_{$content}_\n\n",           // H3: Italic
            4 => "__{$content}__\n\n",         // H4: Underline
            5 => "*_{$content}_*\n\n",         // H5: Bold Italic
            default => $content . "\n\n",      // H6+: Plain
        };
    }

    /**
     * Convert a code block.
     */
    private function convertCodeBlock(FencedCode|IndentedCode $node): string
    {
        $content = $node->getLiteral();

        // Escape backticks in the content
        $escapedContent = $this->escapeCodeBlock($content);

        // Use triple backticks for code blocks
        // If content contains triple backticks, use quadruple, etc.
        $backticks = $this->getCodeFence($escapedContent);

        $language = '';
        if ($node instanceof FencedCode) {
            $info = $node->getInfo();

            if ($info !== null && $info !== '') {
                $language = explode(' ', $info)[0];
            }
        }

        return $backticks . $language . PHP_EOL
            . $escapedContent . "\n{$backticks}\n\n";
    }

    /**
     * Get appropriate code fence for the content.
     */
    private function getCodeFence(string $content): string
    {
        // Start with triple backticks
        $backticks = '```';

        // If content contains triple backticks, use more
        while (str_contains($content, $backticks)) {
            $backticks .= '`';
        }

        return $backticks;
    }

    /**
     * Convert a block quote.
     */
    private function convertBlockQuote(BlockQuote $blockQuote): string
    {
        $content = $this->convertChildren($blockQuote);
        $lines = explode("\n", trim($content));
        $result = '';

        foreach ($lines as $line) {
            if ($line !== '') {
                $result .= '> ' . $line . "\n";
            }
        }

        return $result . "\n";
    }

    /**
     * Convert a list block.
     */
    private function convertListBlock(ListBlock $listBlock): string
    {
        $isOrdered = $listBlock->getListData()->type
            === ListBlock::TYPE_ORDERED;
        $result = '';
        $number = $listBlock->getListData()->start ?? 1;

        foreach ($listBlock->children() as $child) {
            if ($child instanceof ListItem) {
                $prefix = $isOrdered ? $number . '\. ' : '• ';
                $itemContent = trim($this->convertListItemContent($child));

                if ($itemContent !== '') {
                    $result .= $prefix . $itemContent . "\n";
                }

                ++$number;
            }
        }

        return $result . "\n";
    }

    /**
     * Convert a list item.
     */
    private function convertListItem(ListItem $listItem): string
    {
        return $this->convertListItemContent($listItem);
    }

    /**
     * Convert list item content, handling nested structures.
     */
    private function convertListItemContent(ListItem $listItem): string
    {
        $result = '';

        foreach ($listItem->children() as $child) {
            $result .= $this->convertListItemChild($child);
        }

        return $result;
    }

    private function convertListItemChild(Node $node): string
    {
        return match (true) {
            $node instanceof Paragraph => $this->convertChildren($node),
            $node instanceof ListBlock => $this->indentNestedList($node),
            default => $this->convertNode($node),
        };
    }

    private function indentNestedList(ListBlock $listBlock): string
    {
        $nested = $this->convertListBlock($listBlock);
        $lines = explode("\n", $nested);
        $result = '';

        foreach ($lines as $line) {
            if ($line !== '') {
                $result .= '  ' . $line . "\n";
            }
        }

        return $result;
    }

    /**
     * Convert a link.
     */
    private function convertLink(Link $link): string
    {
        $url = $link->getUrl();
        $link->getTitle() ?? '';
        $text = $this->convertChildren($link);

        // If text equals URL or is empty, use URL as text
        if ($text === '' || $text === $url) {
            $text = $url;
        }

        $escapedUrl = $this->escapeUrl($url);
        $escapedText = $this->escapeText(trim($text));

        return '[' . $escapedText . '](' . $escapedUrl . ')';
    }

    /**
     * Convert a table to monospace pre block.
     */
    private function convertTable(Table $table): string
    {
        $rows = $this->extractTableRows($table);

        if ($rows === []) {
            return '';
        }

        return $this->formatTableAsCodeBlock($rows);
    }

    /**
     * @return array<int, string>
     */
    private function extractTableRows(Table $table): array
    {
        $rows = [];

        foreach ($table->children() as $section) {
            if (! $section instanceof TableSection) {
                continue;
            }

            foreach ($section->children() as $row) {
                $rowText = $this->convertTableRow($row);
                if ($rowText !== null) {
                    $rows[] = $rowText;
                }
            }
        }

        return $rows;
    }

    private function convertTableRow(Node $node): ?string
    {
        if (! $node instanceof TableRow) {
            return null;
        }

        $cells = $this->extractCellsFromRow($node);

        return $cells !== [] ? '| ' . implode(' | ', $cells) . ' |' : null;
    }

    /**
     * @return array<int, string>
     */
    private function extractCellsFromRow(TableRow $tableRow): array
    {
        $cells = [];

        foreach ($tableRow->children() as $cell) {
            if ($cell instanceof TableCell) {
                $cells[] = trim($this->getChildrenText($cell));
            }
        }

        return $cells;
    }

    /**
     * @param array<int, string> $rows
     */
    private function formatTableAsCodeBlock(array $rows): string
    {
        $tableText = implode("\n", $rows);
        $escapedTableText = $this->escapeCodeBlock($tableText);

        return "```\n" . $escapedTableText . "\n```\n\n";
    }

    /**
     * Get all text content from children.
     */
    private function getChildrenText(Node $node): string
    {
        $text = '';

        foreach ($node->children() as $child) {
            if ($child instanceof Text) {
                $text .= $child->getLiteral();
            } else {
                $text .= $this->getChildrenText($child);
            }
        }

        return $text;
    }

    /**
     * Escape text for MarkdownV2.
     */
    private function escapeText(string $text, bool $inUrl = false): string
    {
        if ($inUrl) {
            return $this->escapeForUrl($text);
        }

        // Characters that need escaping in MarkdownV2 plain text:
        // _ * [ ] ( ) ~ ` > # + - = | { } . !
        $specialChars = '_*[]()~`>#+-=|{}.!';

        // Escape each special character with a backslash
        return addcslashes($text, $specialChars . '\\');
    }

    /**
     * Escape text for code content.
     */
    private function escapeCode(string $text): string
    {
        // Only escape backticks and backslashes in inline code
        return str_replace(['\\', '`'], ['\\\\', '\\`'], $text);
    }

    /**
     * Escape text for code blocks.
     */
    private function escapeCodeBlock(string $text): string
    {
        // In code blocks, only escape backslashes
        return str_replace('\\', '\\\\', $text);
    }

    /**
     * Escape URL for MarkdownV2.
     */
    private function escapeUrl(string $url): string
    {
        // In URLs, only escape ) and \
        return str_replace(['\\', ')'], ['\\\\', '\\)'], $url);
    }

    /**
     * Escape text for URL context.
     */
    private function escapeForUrl(string $text): string
    {
        // Inside URLs in MarkdownV2, only ) and \ need escaping
        return str_replace(['\\', ')'], ['\\\\', '\\)'], $text);
    }
}
