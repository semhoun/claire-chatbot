<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use Mpdf\Mpdf;
use Mpdf\PageFormat;
use RuntimeException;

final class PageSizeNormalizer
{
    public function normalize(string $html): string
    {
        // Match the style blocks consumed by mPDF, not arbitrary document text.
        return preg_replace_callback('/(<style.*?>)(.*?)(<\/style>)/si', static function (array $style): string {
            $css = preg_replace('~/\*.*?\*/~s', ' ', $style[2]);
            $css = preg_replace_callback('/(@page\b[^{}]*\{)([^{}]*)(\})/i', static function (array $page): string {
                $body = preg_replace_callback(
                    '/(^|;)\s*size\s*:\s*([^;]+)(?=;|$)/i',
                    static function (array $declaration): string {
                        $value = trim($declaration[2]);
                        if (in_array(strtolower($value), ['auto', 'portrait', 'landscape'], true)) {
                            return $declaration[1] . 'size: ' . strtolower($value);
                        }
                        if (! preg_match(
                            '/^([a-z][a-z0-9]*|[24]a0)(?:\s+(portrait|landscape)|-(L))?$/i', $value, $name,
                        )) {
                            return $declaration[0];
                        }

                        // Use the same public format catalogue as page_size.
                        [$width, $height] = PageFormat::getSizeFromName($name[1]);
                        if (strtolower($name[2] ?? '') === 'landscape' || isset($name[3])) {
                            [$width, $height] = [max($width, $height), min($width, $height)];
                        } elseif (strtolower($name[2] ?? '') === 'portrait') {
                            [$width, $height] = [min($width, $height), max($width, $height)];
                        }

                        return $declaration[1] . sprintf(
                            'size: %.5Fmm %.5Fmm', $width / Mpdf::SCALE, $height / Mpdf::SCALE,
                        );
                    },
                    $page[2],
                ) ?? throw new RuntimeException('Cannot normalize PDF page size.');

                return $page[1] . $body . $page[3];
            }, $css ?? throw new RuntimeException('Cannot normalize PDF CSS.'));

            return $style[1] . ($css ?? throw new RuntimeException('Cannot normalize PDF CSS.')) . $style[3];
        }, $html) ?? throw new RuntimeException('Cannot normalize PDF styles.');
    }
}
