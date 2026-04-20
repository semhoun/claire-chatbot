<?php

declare(strict_types=1);

namespace App\Brain;

use App\Services\Session\SessionInterface;
use App\Services\Settings;
use Psr\Container\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

final class BrainRegistry
{
    /** @var array<string, array{name:string, description:string, avatar:string, css:string, welcomes:array<string>, instruction:string}>|null */
    private ?array $yamlBrainsCache = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly ContainerInterface $container,
    ) {
    }

    /**
     * Retourne la liste complète des assistants disponibles avec leurs métadonnées.
     *
     * @return array<int, array{slug:string, class:string, name:string, description:string, avatar:string}>
     */
    public function list(): array
    {
        $brains = (array) $this->settings->get('llm.brains');
        $out = [];
        foreach ($brains as $slug => $class) {
            if (! is_string($slug)) {
                continue;
            }

            if (! is_string($class)) {
                continue;
            }

            if (! class_exists($class)) {
                continue;
            }

            // Valider l'implémentation
            if (! is_subclass_of($class, BrainAvatar::class)) {
                continue;
            }

            /** @var class-string<BrainAvatar> $class */
            $out[] = [
                'slug' => $slug,
                'class' => $class,
                'name' => $class::NAME,
                'description' => $class::DESCRIPTION,
                'avatar' => $class::AVATAR,
                'css' => $class::CSS,
                'css_inline' => '',
            ];
        }

        // Charger les brains YAML
        $yamlBrains = $this->loadYamlBrains();
        foreach ($yamlBrains as $slug => $config) {
            $out[] = [
                'slug' => $slug,
                'class' => YamlBrain::class,
                'name' => $config['name'],
                'description' => $config['description'],
                'avatar' => $config['avatar'] ?? '',
                'css' => $config['css'] ?? '',
                'css_inline' => $config['css_inline'] ?? '',
            ];
        }

        return $out;
    }

    public function has(string $slug): bool
    {
        $brains = (array) $this->settings->get('llm.brains');
        $class = $brains[$slug] ?? null;
        if (is_string($class) && class_exists($class) && is_subclass_of($class, BrainAvatar::class)) {
            return true;
        }

        // Vérifier si c'est un brain YAML
        $yamlBrains = $this->loadYamlBrains();
        return isset($yamlBrains[$slug]);
    }

    public function get(string $slug, SessionInterface $session, ?string $threadId = null): Agent
    {
        $brains = (array) $this->settings->get('llm.brains');
        $class = (string) ($brains[$slug] ?? '');
        if ($class === '' || ! class_exists($class) || ! is_subclass_of($class, BrainAvatar::class)) {
            // Vérifier si c'est un brain YAML
            $yamlBrains = $this->loadYamlBrains();
            if (isset($yamlBrains[$slug])) {
                $instance = new YamlBrain($yamlBrains[$slug], $this->container, $session, $threadId);
                assert($instance instanceof Agent);
                return $instance;
            }

            throw new \InvalidArgumentException('Assistant inconnu: ' . $slug);
        }

        $instance = new $class($this->container, $session, $threadId);
        assert($instance instanceof Agent);
        return $instance;
    }

    /**
     * @return array{name:string, description:string, avatar:string, class:string, css:string, css_inline:string}
     */
    public function getMeta(string $slug): array
    {
        $brains = (array) $this->settings->get('llm.brains');
        $class = (string) ($brains[$slug] ?? '');
        if ($class === '' || ! class_exists($class) || ! is_subclass_of($class, BrainAvatar::class)) {
            // Vérifier si c'est un brain YAML
            $yamlBrains = $this->loadYamlBrains();
            if (isset($yamlBrains[$slug])) {
                $config = $yamlBrains[$slug];
                return [
                    'name' => $config['name'],
                    'description' => $config['description'],
                    'avatar' => $config['avatar'] ?? '',
                    'class' => YamlBrain::class,
                    'css' => $config['css'] ?? '',
                    'css_inline' => $config['css_inline'] ?? '',
                ];
            }

            throw new \InvalidArgumentException('Assistant inconnu: ' . $slug);
        }

        /** @var class-string<BrainAvatar> $class */
        return [
            'name' => $class::NAME,
            'description' => $class::DESCRIPTION,
            'avatar' => $class::AVATAR,
            'class' => $class,
            'css' => $class::CSS,
            'css_inline' => '',
        ];
    }

    /**
     * @return array<string, array{name:string, description:string, avatar:string, css:string, css_inline:string, welcomes:array<string>, instruction:string}>
     */
    private function loadYamlBrains(): array
    {
        if ($this->yamlBrainsCache !== null) {
            return $this->yamlBrainsCache;
        }

        $this->yamlBrainsCache = [];
        $files = $this->findYamlBrainFiles();

        foreach ($files as $file) {
            $brain = $this->parseBrainFile($file);
            if ($brain !== null) {
                $this->yamlBrainsCache[$brain['slug']] = $brain['data'];
            }
        }

        return $this->yamlBrainsCache;
    }

    /**
     * @return array<int, string>
     */
    private function findYamlBrainFiles(): array
    {
        $path = $this->settings->get('llm.yamlBrains.path');

        if (! is_dir($path)) {
            return [];
        }

        $files = glob($path . '/*.yaml');

        return $files !== false ? $files : [];
    }

    /**
     * @return array{slug:string, data:array<string, mixed>}|null
     */
    private function parseBrainFile(string $file): ?array
    {
        try {
            $data = Yaml::parseFile($file);
            if (! is_array($data)) {
                return null;
            }

            $slug = basename($file, '.yaml');

            return [
                'slug' => $slug,
                'data' => [
                    'name' => (string) ($data['name'] ?? $slug),
                    'description' => (string) ($data['description'] ?? ''),
                    'avatar' => (string) ($data['avatar'] ?? ''),
                    'css' => (string) ($data['css'] ?? ''),
                    'css_inline' => (string) ($data['css_inline'] ?? ''),
                    'welcomes' => (array) ($data['welcomes'] ?? []),
                    'instruction' => (string) ($data['instruction'] ?? ''),
                ],
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
