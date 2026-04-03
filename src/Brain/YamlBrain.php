<?php

declare(strict_types=1);

namespace App\Brain;

use App\Services\Session\SessionInterface;
use Psr\Container\ContainerInterface;

final class YamlBrain extends Agent implements BrainAvatar
{
    use AgentTrait\Tools;

    private array $welcomes;

    private string $instruction;

    /**
     * @param array{welcomes:array<string>, instruction:string} $config
     */
    public function __construct(
        private readonly array $config,
        ContainerInterface $container,
        SessionInterface $session,
    ) {
        $this->welcomes = $config['welcomes'] ?? [];
        $this->instruction = $config['instruction'];

        parent::__construct($container, $session);
    }

    #[\Override]
    public function getOpeningText(): string
    {
        if ($this->welcomes === []) {
            return parent::getOpeningText();
        }

        return $this->welcomes[array_rand($this->welcomes)];
    }

    #[\Override]
    protected function instructions(): string
    {
        return $this->instruction;
    }
}
