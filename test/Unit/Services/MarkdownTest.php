<?php



declare(strict_types=1);



namespace App\Test\Unit\Services;



use App\Services\Markdown;

use PHPUnit\Framework\TestCase;



final class MarkdownTest extends TestCase

{

    private Markdown $markdown;



    protected function setUp(): void

    {

        $this->markdown = new Markdown();

    }



    public function testConvertMarkdownToHtml(): void

    {

        $markdown = "# Hello World\n\nThis is **bold** text.";

        $html = $this->markdown->convert($markdown);



        $this->assertStringContainsString('<h1>Hello World</h1>', $html);

        $this->assertStringContainsString('<strong>bold</strong>', $html);

    }



    public function testFromHtmlToMarkdown(): void

    {

        $html = "<h1>Hello World</h1><p>This is <strong>bold</strong> text.</p>";

        $markdown = Markdown::fromHtml($html);



        $this->assertStringContainsString('Hello World', $markdown);

        $this->assertStringContainsString('**bold**', $markdown);

    }

}

