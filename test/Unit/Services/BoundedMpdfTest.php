<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\Pdf\BoundedMpdf;
use App\Services\Pdf\ResourceContainer;
use App\Services\Pdf\RestrictedAssetFetcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BoundedMpdfTest extends TestCase
{
    public function testCapIsCheckedBeforeAllocationButNotForConditionalNoOp(): void
    {
        $pdf = new BoundedMpdf(
            ['tempDir' => sys_get_temp_dir()], new ResourceContainer(new RestrictedAssetFetcher([])), 2,
        );
        $pdf->AddPageByArray([]);
        $pdf->AddPageByArray([]);
        $pdf->AddPage(condition: 'E');
        self::assertSame(2, $pdf->page);
        try {
            $pdf->AddPageByArray([]);
            self::fail('Expected page limit failure.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('page limit of 2', $exception->getMessage());
        }
        self::assertSame(2, $pdf->page);
        self::assertCount(2, $pdf->pages);
    }

    public function testNaturalPaginationStopsDuringWriteHtml(): void
    {
        $pdf = new BoundedMpdf(
            ['tempDir' => sys_get_temp_dir()], new ResourceContainer(new RestrictedAssetFetcher([])), 2,
        );
        try {
            $pdf->WriteHTML(str_repeat('<p>Some text</p>', 200));
            self::fail('Expected page limit failure before Output.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('page limit of 2', $exception->getMessage());
        }
        self::assertCount(2, $pdf->pages);
    }

    public function testNamedSelectorCanReuseFirstPageAtLimit(): void
    {
        $pdf = new BoundedMpdf(
            ['tempDir' => sys_get_temp_dir()], new ResourceContainer(new RestrictedAssetFetcher([])), 1,
        );
        $pdf->WriteHTML('<style>@page chapter {margin:10mm;}</style><div style="page:chapter">Text</div>');
        self::assertSame(1, $pdf->page);
        self::assertStringStartsWith('%PDF-', $pdf->Output('', 'S'));
    }

    public function testInvalidLimitIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('maxPages must be a positive integer');
        new BoundedMpdf(
            ['tempDir' => sys_get_temp_dir()], new ResourceContainer(new RestrictedAssetFetcher([])), 0,
        );
    }

    public function testDuplexBlankPageCountsTowardsCap(): void
    {
        $pdf = new BoundedMpdf(
            ['tempDir' => sys_get_temp_dir(), 'mirrorMargins' => true],
            new ResourceContainer(new RestrictedAssetFetcher([])), 2,
        );
        $pdf->AddPage();
        try {
            $pdf->AddPage(condition: 'NEXT-ODD');
            self::fail('Expected page limit failure.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('page limit of 2', $exception->getMessage());
        }
        self::assertCount(2, $pdf->pages);
    }
}
