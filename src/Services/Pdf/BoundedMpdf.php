<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use Mpdf\Container\ContainerInterface;
use Mpdf\Mpdf;
use RuntimeException;

final class BoundedMpdf extends Mpdf
{
    public function __construct(array $config, ContainerInterface $container, private readonly int $maxPages)
    {
        if ($maxPages < 1) {
            throw new RuntimeException('PDF maxPages must be a positive integer.');
        }
        parent::__construct($config, $container);
    }

    #[\Override]
    public function _beginpage(
        $orientation,
        $mgl = '', $mgr = '', $mgt = '', $mgb = '', $mgh = '', $mgf = '',
        $ohname = '', $ehname = '', $ofname = '', $efname = '',
        $ohvalue = 0, $ehvalue = 0, $ofvalue = 0, $efvalue = 0,
        $pagesel = '', $newformat = '',
    ): void {
        // This is mPDF's allocation hook, after conditional/duplex breaks.
        // Selecting a named page at the top of page 1 reuses that page.
        $reusesFirstPage = $pagesel && $this->page == 1
            && sprintf('%0.4f', $this->y) === sprintf('%0.4f', $this->tMargin);
        if (! $reusesFirstPage && $this->page >= $this->maxPages) {
            throw new RuntimeException(sprintf(
                'PDF page limit of %d exceeded during rendering. Split the document or reduce its content.',
                $this->maxPages,
            ));
        }

        parent::_beginpage(
            $orientation, $mgl, $mgr, $mgt, $mgb, $mgh, $mgf,
            $ohname, $ehname, $ofname, $efname, $ohvalue, $ehvalue, $ofvalue, $efvalue, $pagesel, $newformat,
        );
        $this->validatePrintableGeometry();
    }

    #[\Override]
    public function SetAutoPageBreak($auto, $margin = 0): void
    {
        parent::SetAutoPageBreak($auto, $margin);
        // Called after resolved @page margins, before headers or body layout,
        // including when mPDF revisits an existing page for floats.
        if ($this->page > 0) {
            $this->validatePrintableGeometry();
        }
    }

    private function validatePrintableGeometry(): void
    {
        $width = $this->w - $this->lMargin - $this->rMargin;
        $height = $this->h - $this->tMargin - $this->bMargin;
        if (! is_finite($width) || ! is_finite($height) || $width <= 0 || $height <= 0
            || $this->w <= 0 || $this->h <= 0) {
            throw new RuntimeException(sprintf(
                'Invalid PDF printable geometry on page %d (%.2F x %.2F mm). Check page size and margins.',
                $this->page, $width, $height,
            ));
        }
    }
}
