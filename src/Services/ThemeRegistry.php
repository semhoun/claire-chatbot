<?php

declare(strict_types=1);

namespace App\Services;

use JsonException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ThemeRegistry
{
    /** @var array<string, array{tokens:array<string, string>, variants:array<string, string>}>|null */
    private ?array $catalog = null;

    /** @var array<string, true> */
    private array $tokens = [];

    /** @var array<string, list<string>> */
    private array $variants = [];

    public function __construct(private readonly Settings $settings)
    {
    }

    /** @return array{preset:string, tokens:array<string, string>, variants:array<string, string>} */
    public function resolve(mixed $theme): array
    {
        $this->loadCatalog();
        $reference = is_array($theme) ? ($theme['preset'] ?? null) : $theme;
        $preset = is_string($reference) && isset($this->catalog[$reference]) ? $reference : 'cyberpunk';
        $base = $this->catalog[$preset] ?? ['tokens' => [], 'variants' => []];
        $overrides = $this->filter(is_array($theme) ? $theme : []);

        return [
            'preset' => $preset,
            'tokens' => array_replace($base['tokens'], $overrides['tokens']),
            'variants' => array_replace($base['variants'], $overrides['variants']),
        ];
    }

    private function loadCatalog(): void
    {
        if ($this->catalog !== null) {
            return;
        }

        $this->catalog = [];
        $path = $this->settings->get('themes.path', Settings::getAppRoot() . '/config/themes');

        // Only local files are read; never resolve URLs or agent-supplied paths.
        if (! is_string($path) || str_contains($path, '://') || ! is_dir($path)) {
            return;
        }

        if ($this->loadContract($path)) {
            $this->loadPresets($path);
        }
    }

    private function loadContract(string $path): bool
    {
        // Missing or unreadable optional catalogs fall back without leaking filesystem warnings.
        $json = @file_get_contents($path . '/contract.json');
        if ($json === false) {
            return false;
        }

        try {
            $contract = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (! $this->hasThemeSections($contract) || ! array_is_list($contract['tokens'])) {
            return false;
        }

        $this->normalizeContract($contract);
        return true;
    }

    /** @param array{tokens:list<mixed>, variants:array<mixed>} $contract */
    private function normalizeContract(array $contract): void
    {
        $this->tokens = array_fill_keys(array_filter(
            $contract['tokens'],
            static fn (mixed $token): bool => is_string($token)
                && preg_match('/^--claire-[a-z0-9-]+$/D', $token) === 1
        ), true);

        $variants = array_filter(
            $contract['variants'],
            static fn (mixed $values, int|string $key): bool => is_string($key)
                && preg_match('/^[a-z][a-z0-9-]*$/D', $key) === 1
                && is_array($values) && array_is_list($values),
            ARRAY_FILTER_USE_BOTH
        );
        $this->variants = array_map(
            static fn (array $values): array => array_values(array_filter($values, is_string(...))),
            $variants
        );
    }

    private function loadPresets(string $path): void
    {
        $files = glob($path . '/*.yaml');
        foreach ($files === false ? [] : $files as $file) {
            $slug = basename($file, '.yaml');
            if (preg_match('/^[a-z][a-z0-9-]*$/D', $slug) !== 1) {
                continue;
            }

            try {
                $data = Yaml::parseFile($file);
            } catch (ParseException) {
                continue;
            }

            if (! $this->hasThemeSections($data) || ! array_all(
                [$data['tokens'], $data['variants']],
                static fn (array $section): bool => $section === [] || ! array_is_list($section)
            )) {
                continue;
            }

            $this->catalog[$slug] = $this->filter($data);
        }
    }

    private function hasThemeSections(mixed $data): bool
    {
        return is_array($data) && array_all(
            ['tokens', 'variants'],
            static fn (string $key): bool => is_array($data[$key] ?? null)
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @return array{tokens:array<string, string>, variants:array<string, string>}
     */
    private function filter(array $data): array
    {
        $tokens = array_intersect_key(is_array($data['tokens'] ?? null) ? $data['tokens'] : [], $this->tokens);
        $variants = array_intersect_key(is_array($data['variants'] ?? null) ? $data['variants'] : [], $this->variants);

        return [
            'tokens' => array_filter($tokens, is_string(...)),
            'variants' => array_filter(
                $variants,
                fn (mixed $value, string $key): bool => in_array($value, $this->variants[$key], true),
                ARRAY_FILTER_USE_BOTH
            ),
        ];
    }
}
