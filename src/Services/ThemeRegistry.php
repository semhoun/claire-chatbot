<?php

declare(strict_types=1);

namespace App\Services;

use JsonException;
use RuntimeException;
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
        try {
            $path = $this->settings->get('themes.path');
        } catch (RuntimeException) {
            $path = Settings::getAppRoot() . '/config/themes';
        }

        // Only local files are read; never resolve URLs or agent-supplied paths.
        if (! is_string($path) || str_contains($path, '://') || ! is_dir($path)) {
            return;
        }

        $json = @file_get_contents($path . '/contract.json');
        if ($json === false) {
            return;
        }

        try {
            $contract = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }

        if (! is_array($contract) || ! is_array($contract['tokens'] ?? null)
            || ! array_is_list($contract['tokens']) || ! is_array($contract['variants'] ?? null)) {
            return;
        }

        foreach ($contract['tokens'] as $token) {
            if (is_string($token) && preg_match('/^--claire-[a-z0-9-]+$/D', $token) === 1) {
                $this->tokens[$token] = true;
            }
        }
        foreach ($contract['variants'] as $key => $values) {
            if (is_string($key) && preg_match('/^[a-z][a-z0-9-]*$/D', $key) === 1
                && is_array($values) && array_is_list($values)) {
                $this->variants[$key] = array_values(array_filter($values, is_string(...)));
            }
        }

        foreach (glob($path . '/*.yaml') ?: [] as $file) {
            $slug = basename($file, '.yaml');
            if (preg_match('/^[a-z][a-z0-9-]*$/D', $slug) !== 1) {
                continue;
            }
            try {
                $data = Yaml::parseFile($file);
            } catch (ParseException) {
                continue;
            }
            if (! is_array($data) || ! is_array($data['tokens'] ?? null)
                || ! is_array($data['variants'] ?? null)
                || ($data['tokens'] !== [] && array_is_list($data['tokens']))
                || ($data['variants'] !== [] && array_is_list($data['variants']))) {
                continue;
            }
            $this->catalog[$slug] = $this->filter($data);
        }
    }

    /**
     * @param array<mixed> $data
     * @return array{tokens:array<string, string>, variants:array<string, string>}
     */
    private function filter(array $data): array
    {
        $result = ['tokens' => [], 'variants' => []];
        foreach (is_array($data['tokens'] ?? null) ? $data['tokens'] : [] as $key => $value) {
            if (isset($this->tokens[$key]) && is_string($value)) {
                $result['tokens'][$key] = $value;
            }
        }
        foreach (is_array($data['variants'] ?? null) ? $data['variants'] : [] as $key => $value) {
            if (isset($this->variants[$key]) && is_string($value)
                && in_array($value, $this->variants[$key], true)) {
                $result['variants'][$key] = $value;
            }
        }

        return $result;
    }
}
