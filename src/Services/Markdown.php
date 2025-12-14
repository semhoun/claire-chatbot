<?php

declare(strict_types=1);

namespace App\Services;

use Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\Highlight\HighlightExtension;
use League\CommonMark\Extension\SmartPunct\SmartPunctExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;
use Spatie\CommonMarkHighlighter\FencedCodeRenderer;
use Spatie\CommonMarkHighlighter\IndentedCodeRenderer;
use Twig\Extra\Markdown\MarkdownInterface;

class Markdown implements MarkdownInterface
{
    private readonly MarkdownConverter $markdownConverter;

    public function __construct()
    {
        $environment = new Environment();

        $environment->addExtension(new SmartPunctExtension());
        $environment->addExtension(new StrikethroughExtension());
        $environment->addExtension(new HighlightExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addRenderer(FencedCode::class, new FencedCodeRenderer());
        $environment->addRenderer(IndentedCode::class, new IndentedCodeRenderer());

        $this->markdownConverter = new MarkdownConverter($environment);
    }

    public function convert(string $body): string
    {
        return (string) $this->markdownConverter->convert($body);
    }

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
