<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use RuntimeException;

final class DocumentResourceValidator
{
    public function validate(string $html): void
    {
        if (! mb_check_encoding($html, 'UTF-8')) {
            throw new RuntimeException('PDF content must be valid UTF-8.');
        }

        // CssParser removes styles before AdjustHTML removes scripts/comments.
        // Validate each resulting view, including reconstructed tags/attributes.
        // Repeated passes also cover header/footer HTML reparsed by mPDF.
        for ($depth = 0; $depth < 8; ++$depth) {
            $this->validateMarkup($html);
            $normalized = preg_replace('/<style.*?>(.*?)<\/style>/si', '', $html) ?? $this->deny();
            $normalized = preg_replace('/<script.*?<\/script>/is', '', $normalized) ?? $this->deny();
            $normalized = preg_replace('/<!--mpdf/i', '', $normalized) ?? $this->deny();
            $normalized = preg_replace('/mpdf-->/i', '', $normalized) ?? $this->deny();
            $normalized = preg_replace('/<!--.*?-->/s', '', $normalized) ?? $this->deny();
            $normalized = str_replace(["\r", "\f"], '', $normalized);
            if ($normalized === $html) {
                return;
            }

            $html = $normalized;
        }

        $this->deny();
    }

    private function validateMarkup(string $html): void
    {
        // mPDF extracts SVG even inside comments, before its HTML preprocessing.
        if (preg_match('/<svg/i', $html)) {
            $this->deny();
        }

        // Inspect lexical tags, including duplicates and raw-text contexts: mPDF
        // is not a browser DOM parser and can interpret those differently.
        preg_match_all(
            '/<\s*([a-z][a-z0-9:-]*)\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>/is',
            $html,
            $tags,
            PREG_SET_ORDER,
        );
        if (preg_last_error() !== PREG_NO_ERROR) {
            $this->deny();
        }

        // mPDF also splits malformed tags at the first '>', regardless of quotes.
        if (preg_match_all('/<\s*([a-z][a-z0-9:-]*)\b([^>]*)>/is', $html, $looseTags, PREG_SET_ORDER) === false) {
            $this->deny();
        }

        $tags = array_merge($tags, $looseTags);
        foreach ($tags as $tag) {
            $name = strtolower($tag[1]);
            if (in_array($name, ['svg', 'link', 'base'], true)) {
                $this->deny();
            }

            if (preg_match_all(
                '/([^\s=\/"\'<>]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/s',
                $tag[2],
                $attrs,
                PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
            ) === false) {
                $this->deny();
            }

            foreach ($attrs as $attr) {
                $key = strtolower($attr[1]);
                if ($name === 'annotation' && $key === 'file') {
                    $this->deny();
                }

                if (in_array($name, ['img', 'input', 'watermarkimage'], true)
                    && in_array($key, ['src', 'orig_src'], true)) {
                    $source = $attr[2] ?? $attr[3] ?? $attr[4];
                    for ($depth = 0; $depth < 8; ++$depth) {
                        $decoded = html_entity_decode(rawurldecode($source), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        if ($decoded === $source) {
                            break;
                        }

                        $source = $decoded;
                    }

                    // ImageProcessor recognizes data/var anywhere in an image source.
                    if ($depth === 8 || preg_match('/(?:data|var)\s*:/i', $source)) {
                        $this->deny();
                    }
                }

                if ($key === 'style') {
                    $this->validateCss(html_entity_decode(
                        $attr[2] ?? $attr[3] ?? $attr[4],
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8',
                    ));
                }
            }
        }

        // CssParser also consumes malformed names beginning with "style".
        if (preg_match_all('/<style[^>]*>(.*?)(?:<\/style>|$)/is', $html, $styles) === false) {
            $this->deny();
        }

        foreach ($styles[1] as $css) {
            $this->validateCss($css);
        }
    }

    private function validateCss(string $css): void
    {
        // Decode CSS escapes, including the optional whitespace after hex digits.
        $css = preg_replace_callback(
            '/\\\\([0-9a-f]{1,6}[\t\n\r\f ]?|[^\r\n\f])/i',
            static function (array $match): string {
                $escape = $match[1];
                if (ctype_xdigit(trim($escape))) {
                    $code = hexdec(trim($escape));

                    return mb_chr($code > 0 && $code <= 0x10ffff ? $code : 0xfffd, 'UTF-8');
                }

                return $escape;
            },
            $css,
        ) ?? $this->deny();
        $css = preg_replace('~/\*.*?(?:\*/|$)~s', '', $css) ?? $this->deny();
        // mPDF splits declarations and extracts image URLs even inside quoted
        // values. Inspect its image properties before tokenizing CSS strings.
        if (preg_match('/\b(?:background(?:-image)?|list-style(?:-image)?)\s*:'
            . '[^;{}]*(?:url\s*\(|(?:data|var)\s*:)/i', $css) !== 0) {
            $this->deny();
        }

        // Tokenize strings separately so decorative content:"url(...)" is legal.
        if (preg_match_all(
            '/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|@?[a-z_-][a-z0-9_-]*|[^\s]/is',
            $css,
            $tokens,
        ) === false) {
            $this->deny();
        }

        foreach ($tokens[0] as $index => $token) {
            if (strtolower($token) === '@import'
                || (strtolower($token) === 'url' && ($tokens[0][$index + 1] ?? '') === '(')) {
                $this->deny();
            }
        }
    }

    private function deny(): never
    {
        throw new RuntimeException(
            'PDF resource denied. External styles, CSS URLs, data/var images, SVG '
            . 'and file annotations are forbidden. Use generate_image markers.',
        );
    }
}
