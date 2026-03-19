<?php

declare(strict_types=1);

namespace App\Brain;

use NeuronAI\Agent\SystemPrompt;

final class YamlBrain extends Agent implements BrainAvatar
{
    use AgentTrait\Tools;

    private string $name;

    private string $description;

    private string $css;

    /** @var array<string> */
    private array $welcomes;

    private string $instruction;

    /**
     * @param array{name:string, description:string, css:string, welcomes:array<string>, instruction:string} $config
     */
    public function __construct(
        private array $config,
        \Psr\Container\ContainerInterface $container,
        \App\Services\Session\SessionInterface $session,
    ) {
        $this->name = $config['name'];
        $this->description = $config['description'];
        $this->css = $config['css'] ?? '';
        $this->welcomes = $config['welcomes'] ?? [];
        $this->instruction = $config['instruction'];

        parent::__construct($container, $session);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getCss(): string
    {
        return $this->css;
    }

    #[\Override]
    public function getOpeningText(): string
    {
        if ($this->welcomes === []) {
            return 'Bonjour ! Comment puis-je vous aider ?';
        }

        return $this->welcomes[array_rand($this->welcomes)];
    }

    #[\Override]
    protected function instructions(): string
    {
        return $this->instruction;
    }
}
