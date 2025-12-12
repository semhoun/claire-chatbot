<?php

declare(strict_types=1);

namespace App\Services;

use Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

class Markdown
{
    public static function fromHtml(string $html): string
    {
        $htmlConverter = new HtmlConverter([
            'hard_break' => false,
            'strip_tags' => true,
            'use_autolinks' => false,
            'remove_nodes' => 'script style',
        ]);
        $htmlConverter->getEnvironment()->addConverter(new TableConverter());
        return $htmlConverter->convert($html);
    }

    public static function fromPdf(string $pdfContent): string
    {
        return new PdfToMarkdownParser()->parseContent($pdfContent);
    }
}
