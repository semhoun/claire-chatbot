<?php

declare(strict_types=1);

namespace App\Services;

use Symfony\Component\Yaml\Yaml;

final class ComfyUIWorkflowRegistry
{
    public const string SESSION_KEY = 'comfyui_workflow';

    /** @var array<string, array{type:string, label:string, workflow:string}>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly Settings $settings,
    ) {
    }

    /**
     * @return array<int, array{slug:string, type:string, label:string}>
     */
    public function list(): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $workflows = [];
        foreach ($this->loadWorkflows() as $slug => $workflow) {
            $workflows[] = [
                'slug' => $slug,
                'type' => $workflow['type'],
                'label' => $workflow['label'],
            ];
        }

        return $workflows;
    }

    public function has(string $slug): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        return isset($this->loadWorkflows()[$slug]);
    }

    public function getMeta(string $slug): array
    {
        if (! $this->has($slug)) {
            throw new \InvalidArgumentException('Workflow ComfyUI inconnu: ' . $slug);
        }

        return $this->loadWorkflows()[$slug];
    }

    public function getWorkflow(string $slug): string
    {
        return $this->getMeta($slug)['workflow'];
    }

    public function getDefaultSlug(): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $workflows = $this->loadWorkflows();
        $configuredDefault = trim((string) $this->settings->get('comfyui.default_workflow'));

        if ($configuredDefault !== '' && isset($workflows[$configuredDefault])) {
            return $configuredDefault;
        }

        $firstSlug = array_key_first($workflows);

        return is_string($firstSlug) ? $firstSlug : null;
    }

    private function isEnabled(): bool
    {
        return $this->settings->get('comfyui.enabled') === true;
    }

    /**
     * @return array<string, array{type:string, label:string, workflow:string}>
     */
    private function loadWorkflows(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $this->cache = [];
        $path = (string) $this->settings->get('comfyui.workflows_path');

        if (! is_dir($path)) {
            return $this->cache;
        }

        $files = glob($path . '/*.{yaml,yml,json}', GLOB_BRACE);
        if ($files === false) {
            return $this->cache;
        }

        sort($files);

        foreach ($files as $file) {
            $slug = pathinfo($file, PATHINFO_FILENAME);

            try {
                $data = $this->parseFile($file);
            } catch (\Throwable) {
                continue;
            }

            $type = trim((string) ($data['type'] ?? ''));
            $label = trim((string) ($data['label'] ?? ''));
            $workflow = $data['workflow'] ?? null;
            if ($type === '') {
                continue;
            }

            if ($label === '') {
                continue;
            }

            if (is_array($workflow)) {
                $workflow = json_encode($workflow, JSON_THROW_ON_ERROR);
            }

            if (! is_string($workflow)) {
                continue;
            }

            if (trim($workflow) === '') {
                continue;
            }

            $this->cache[$slug] = [
                'type' => $type,
                'label' => $label,
                'workflow' => $workflow,
            ];
        }

        return $this->cache;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFile(string $file): array
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if ($extension === 'json') {
            $content = file_get_contents($file);
            if ($content === false) {
                throw new \RuntimeException('Impossible de lire le workflow ComfyUI');
            }

            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : [];
        }

        $data = Yaml::parseFile($file);

        return is_array($data) ? $data : [];
    }
}
