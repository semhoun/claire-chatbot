<?php

declare(strict_types=1);

namespace Test\Unit\Services;

use App\Services\Twig\OCFilterExtension;
use PHPUnit\Framework\TestCase;

final class OCFilterTest extends TestCase
{
    private OCFilterExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new OCFilterExtension();
    }

    public function testFilterOCTagsSingleLine(): void
    {
        $content = "Visible text [OC]Hidden content[/OC] still visible";
        $expected = "Visible text  still visible";
        $this->assertSame($expected, $this->extension->filterOCTags($content));
    }

    public function testFilterOCTagsMultiLine(): void
    {
        $content = "Part 1\n[OC]\nHidden line 1\nHidden line 2\n[/OC]\nPart 2";
        $expected = "Part 1\n\nPart 2";
        $this->assertSame($expected, $this->extension->filterOCTags($content));
    }

    public function testFilterOCTagsMultipleBlocks(): void
    {
        $content = "[OC]B1[/OC]Text[OC]B2[/OC]";
        $expected = "Text";
        $this->assertSame($expected, $this->extension->filterOCTags($content));
    }

    public function testFilterOCTagsUnclosed(): void
    {
        $content = "Visible [OC]Hidden tag";
        $expected = "Visible Hidden tag";
        $this->assertSame($expected, $this->extension->filterOCTags($content));
    }
}
