<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Brain\BrainAvatar;
use App\Brain\BrainRegistry;
use App\Brain\Claire;
use App\Brain\Einstein;
use App\Services\Settings;
use App\Services\ThemeRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

final class ThemeRegistryTest extends TestCase
{
    private string $path;

    private Settings $settings;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/claire-themes-' . bin2hex(random_bytes(8));
        mkdir($this->path);
        mkdir($this->path . '/agents');
        $this->settings = new Settings([
            'themes' => ['path' => $this->path],
            'llm' => [
                'brains' => ['claire' => Claire::class, 'einstein' => Einstein::class],
                'yamlBrains' => ['path' => $this->path . '/agents'],
            ],
        ]);
        file_put_contents($this->path . '/contract.json', json_encode([
            'tokens' => ['--claire-accent', '--claire-bg-main'],
            'variants' => ['controls' => ['solid', 'outline', 'soft'], 'effects' => ['none', 'glow', 'satin']],
        ], JSON_THROW_ON_ERROR));
        foreach (['cyberpunk', 'neon', 'energy', 'light', 'romantic', 'dark'] as $preset) {
            file_put_contents($this->path . '/' . $preset . '.yaml', Yaml::dump([
                'tokens' => ['--claire-accent' => $preset, '--claire-bg-main' => '#123456'],
                'variants' => ['controls' => 'solid', 'effects' => 'none'],
            ]));
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->path . '/agents/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->path . '/agents');
        foreach (glob($this->path . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->path);
    }

    public function testResolvesAllSixPresetsAndCachesCatalogPerInstance(): void
    {
        $registry = new ThemeRegistry($this->settings);
        foreach (['cyberpunk', 'neon', 'energy', 'light', 'romantic', 'dark'] as $preset) {
            $resolved = $registry->resolve($preset);
            self::assertSame($preset, $resolved['preset']);
            self::assertSame($preset, $resolved['tokens']['--claire-accent']);
            self::assertSame(['controls' => 'solid', 'effects' => 'none'], $resolved['variants']);
        }
        unlink($this->path . '/neon.yaml');
        unlink($this->path . '/contract.json');
        self::assertSame('neon', $registry->resolve('neon')['preset']);
        self::assertSame(['preset' => 'cyberpunk', 'tokens' => [], 'variants' => []],
            new ThemeRegistry($this->settings)->resolve('neon'));
    }

    public function testConfiguredDefaultCatalogResolvesAllShippedPresets(): void
    {
        $settings = new Settings(['themes' => require Settings::getAppRoot() . '/config/settings/themes.php']);
        self::assertSame(Settings::getAppRoot() . '/config/themes', $settings->get('themes.path'));
        $registry = new ThemeRegistry($settings);
        foreach (['cyberpunk', 'neon', 'energy', 'light', 'romantic', 'dark'] as $preset) {
            $theme = $registry->resolve($preset);
            self::assertSame($preset, $theme['preset']);
            self::assertNotSame([], $theme['tokens']);
            self::assertNotSame([], $theme['variants']);
        }
        self::assertSame($registry->resolve('cyberpunk'), new ThemeRegistry(new Settings([]))->resolve(null));
    }

    public function testMissingCatalogDirectoryUsesEmptyFallback(): void
    {
        $registry = new ThemeRegistry(new Settings(['themes' => ['path' => $this->path . '/missing']]));
        self::assertSame(['preset' => 'cyberpunk', 'tokens' => [], 'variants' => []], $registry->resolve('light'));
    }

    public function testMergesOverridesAndFiltersUnknownKeysAndNonStringValues(): void
    {
        $registry = new ThemeRegistry($this->settings);
        self::assertSame([
            'preset' => 'light',
            'tokens' => ['--claire-accent' => 'var(--local-accent, #005c9f)', '--claire-bg-main' => '#123456'],
            'variants' => ['controls' => 'outline', 'effects' => 'none'],
        ], $registry->resolve([
            'preset' => 'light',
            'tokens' => [
                '--claire-accent' => 'var(--local-accent, #005c9f)',
                '--claire-bg-main' => 123,
                '--claire-unknown' => 'red',
                ':root' => 'body { color: red; }',
                'color' => 'red',
                0 => 'red',
            ],
            'variants' => ['controls' => 'outline', 'effects' => 'arbitrary', 'layout' => 'wide'],
            'css' => 'https://example.invalid/external.css',
            'css_inline' => 'body { display: none; }',
        ]));
        foreach ([null, false, 42, [], ['nested' => 'red']] as $invalid) {
            self::assertSame($registry->resolve('light'), $registry->resolve([
                'preset' => 'light', 'tokens' => ['--claire-accent' => $invalid],
                'variants' => ['effects' => $invalid],
            ]));
            self::assertSame($registry->resolve('light'), $registry->resolve([
                'preset' => 'light', 'tokens' => $invalid, 'variants' => $invalid,
            ]));
        }
        self::assertSame('light', $registry->resolve('light')['tokens']['--claire-accent']);
    }

    public function testInvalidReferencesFallBackWithoutReadingExternalPaths(): void
    {
        $registry = new ThemeRegistry($this->settings);
        foreach ([null, '', false, 42, [], new \stdClass(), ['preset' => []],
            'unknown', '../neon', 'https://example.invalid/neon.yaml', 'neon.yaml'] as $reference) {
            self::assertSame($registry->resolve('cyberpunk'), $registry->resolve($reference));
        }
        self::assertSame('pink', $registry->resolve([
            'preset' => 'unknown', 'tokens' => ['--claire-accent' => 'pink'],
        ])['tokens']['--claire-accent']);
        $remote = new ThemeRegistry(new Settings(['themes' => ['path' => 'https://example.invalid/themes']]));
        self::assertSame(['preset' => 'cyberpunk', 'tokens' => [], 'variants' => []], $remote->resolve('neon'));
    }

    #[DataProvider('invalidPresets')]
    public function testMalformedPresetsFallBack(?string $yaml): void
    {
        unlink($this->path . '/neon.yaml');
        if ($yaml !== null) {
            file_put_contents($this->path . '/neon.yaml', $yaml);
        }
        $registry = new ThemeRegistry($this->settings);
        self::assertSame($registry->resolve('cyberpunk'), $registry->resolve('neon'));
        file_put_contents($this->path . '/cyberpunk.yaml', 'tokens: [');
        self::assertSame(['preset' => 'cyberpunk', 'tokens' => [], 'variants' => []],
            new ThemeRegistry($this->settings)->resolve('neon'));
    }

    public static function invalidPresets(): iterable
    {
        yield 'missing' => [null];
        yield 'syntax' => ['tokens: ['];
        yield 'scalar' => ['invalid'];
        yield 'empty' => [''];
        yield 'missing variants' => ['tokens: {}'];
        yield 'wrong tokens type' => ["tokens: false\nvariants: {}"];
        yield 'list tokens' => ["tokens: [red]\nvariants: {}"];
        yield 'list variants' => ["tokens: {}\nvariants: [solid]"];
    }

    #[DataProvider('invalidContracts')]
    public function testMissingOrMalformedContractFailsClosed(?string $json): void
    {
        unlink($this->path . '/contract.json');
        if ($json !== null) {
            file_put_contents($this->path . '/contract.json', $json);
        }
        self::assertSame(['preset' => 'cyberpunk', 'tokens' => [], 'variants' => []],
            new ThemeRegistry($this->settings)->resolve([
                'preset' => 'neon', 'tokens' => ['--claire-accent' => 'red'],
                'variants' => ['effects' => 'glow'],
            ]));
    }

    public static function invalidContracts(): iterable
    {
        yield 'missing' => [null];
        yield 'invalid JSON' => ['{'];
        yield 'scalar' => ['true'];
        yield 'missing keys' => ['{}'];
        yield 'wrong tokens shape' => ['{"tokens":{"--claire-accent":true},"variants":{}}'];
        yield 'wrong variants type' => ['{"tokens":[],"variants":false}'];
    }

    public function testPresetAndContractEntriesAreFiltered(): void
    {
        file_put_contents($this->path . '/contract.json', json_encode([
            'tokens' => ['--claire-accent', ':root', 'color', false, ['nested']],
            'variants' => ['effects' => ['none', false], '.selector' => ['bad'], 'controls' => false],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->path . '/neon.yaml', Yaml::dump([
            'tokens' => ['--claire-accent' => 'red', ':root' => 'bad', 'color' => 'red', '--claire-bg-main' => false],
            'variants' => ['effects' => 'none', '.selector' => 'bad', 'controls' => 'solid'],
            'css' => 'https://example.invalid/external.css',
        ]));
        self::assertSame([
            'preset' => 'neon', 'tokens' => ['--claire-accent' => 'red'], 'variants' => ['effects' => 'none'],
        ], new ThemeRegistry($this->settings)->resolve('neon'));
    }

    public function testBrainMetadataNormalizesPhpAndYamlAndIgnoresLegacyCss(): void
    {
        foreach ([
            'scalar' => ['theme' => 'energy'],
            'object' => ['theme' => [
                'preset' => 'light', 'tokens' => ['--claire-accent' => 'pink', ':root' => 'bad'],
                'variants' => ['controls' => 'soft', 'effects' => 'invalid'],
            ]],
            'legacy' => ['css' => ['malformed'], 'css_inline' => ['malformed']],
            'unknown' => ['theme' => 'missing'],
        ] as $slug => $data) {
            file_put_contents($this->path . '/agents/' . $slug . '.yaml', Yaml::dump($data + [
                'name' => $slug, 'instruction' => "Keep instructions exactly.\n",
            ]));
        }
        $themes = new ThemeRegistry($this->settings);
        $brains = new BrainRegistry($this->settings, $this->createStub(ContainerInterface::class), $themes);
        self::assertSame('cyberpunk', BrainAvatar::THEME);
        self::assertFalse(defined(BrainAvatar::class . '::CSS'));
        self::assertSame($themes->resolve('cyberpunk'), $brains->getMeta('claire')['theme']);
        self::assertSame($themes->resolve('neon'), $brains->getMeta('einstein')['theme']);
        self::assertSame($themes->resolve('energy'), $brains->getMeta('scalar')['theme']);
        self::assertSame([
            'preset' => 'light', 'tokens' => ['--claire-accent' => 'pink', '--claire-bg-main' => '#123456'],
            'variants' => ['controls' => 'soft', 'effects' => 'none'],
        ], $brains->getMeta('object')['theme']);
        self::assertSame($themes->resolve('cyberpunk'), $brains->getMeta('legacy')['theme']);
        self::assertSame($themes->resolve('cyberpunk'), $brains->getMeta('unknown')['theme']);
        self::assertCount(6, $brains->list());
        foreach ($brains->list() as $brain) {
            self::assertSame($brain['theme'], $brains->getMeta($brain['slug'])['theme']);
            foreach (['css', 'css_inline', 'cssInline'] as $key) {
                self::assertArrayNotHasKey($key, $brain);
                self::assertArrayNotHasKey($key, $brains->getMeta($brain['slug']));
            }
        }
    }
}
