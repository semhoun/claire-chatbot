<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\Pdf\PageSizeNormalizer;
use Mpdf\MpdfException;
use PHPUnit\Framework\TestCase;

final class PageSizeNormalizerTest extends TestCase
{
    public function testOnlyNamedPageSizesAreRewritten(): void
    {
        $html = '<p>size:A4</p><style>p {font-size:12pt;} @page {size:auto;}'
            . '@page :first {size:210mm 297mm;} @page chapter {size:/* comment */ a4; margin:0;}'
            . '@page :left {size:Letter;} @page wide {size:A5 landscape;}</style>';
        $result = new PageSizeNormalizer()->normalize($html);
        self::assertStringContainsString('<p>size:A4</p>', $result);
        self::assertStringContainsString('p {font-size:12pt;}', $result);
        self::assertStringContainsString('@page {size: auto;}', $result);
        self::assertStringContainsString('@page :first {size:210mm 297mm;}', $result);
        self::assertStringContainsString('@page chapter {size: 210.00156mm 297.00008mm; margin:0;}', $result);
        self::assertStringContainsString('@page :left {size: 215.90000mm 279.40000mm;}', $result);
        self::assertStringContainsString('@page wide {size: 210.00156mm 148.00086mm;}', $result);
    }

    public function testUnknownNamedFormatFailsClearly(): void
    {
        $this->expectException(MpdfException::class);
        $this->expectExceptionMessage('Unknown page format UNKNOWN');
        new PageSizeNormalizer()->normalize('<style>@page {size:unknown;}</style>Text');
    }
}
