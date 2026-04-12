<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\TelegramService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[AllowMockObjectsWithoutExpectations]
final class OCFilterTest extends TestCase
{
    private TelegramService $service;

    private ReflectionMethod $filterMethod;

    protected function setUp(): void
    {
        $this->service = $this->createMock(TelegramService::class);
        $this->filterMethod = new ReflectionMethod(TelegramService::class, 'filterOCTags');
    }

    public function testFilterOCTagsSingleLine(): void
    {
        $content = "Visible text [OC]Hidden content[/OC] still visible";

        $this->assertSame(
            'Visible text  still visible',
            $this->filterOCTags($content)
        );
    }

    public function testFilterOCTagsMultiLine(): void
    {
        $content = "Part 1\n[OC]\nHidden line 1\nHidden line 2\n[/OC]\nPart 2";

        $this->assertSame("Part 1\n\nPart 2", $this->filterOCTags($content));
    }

    public function testFilterOCTagsMultipleBlocks(): void
    {
        $content = '[OC]B1[/OC]Text[OC]B2[/OC]';

        $this->assertSame('Text', $this->filterOCTags($content));
    }

    public function testFilterOCTagsUnclosed(): void
    {
        $content = 'Visible [OC]Hidden tag';

        $this->assertSame('Visible Hidden tag', $this->filterOCTags($content));
    }

    private function filterOCTags(string $content): string
    {
        return (string) $this->filterMethod->invoke($this->service, $content);
    }
}
