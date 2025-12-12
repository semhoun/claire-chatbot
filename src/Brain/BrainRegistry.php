<?php

declare(strict_types=1);

namespace App\Brain;

use App\Services\Settings;
use Psr\Container\ContainerInterface;

final readonly class BrainRegistry
{
    public function __construct(
        private Settings $settings,
        private ContainerInterface $container,
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
            ];
        }

        return $out;
    }

    public function has(string $slug): bool
    {
        $brains = (array) $this->settings->get('llm.brains');
        $class = $brains[$slug] ?? null;
        return is_string($class) && class_exists($class) && is_subclass_of($class, BrainAvatar::class);
    }

    public function get(string $slug): BrainAvatar
    {
        $brains = (array) $this->settings->get('llm.brains');
        $class = (string) ($brains[$slug] ?? '');
        if ($class === '' || ! class_exists($class) || ! is_subclass_of($class, BrainAvatar::class)) {
            throw new \InvalidArgumentException('Assistant inconnu: ' . $slug);
        }

        // Récupération via le container pour bénéficier de l'injection de dépendances
        /** @var BrainAvatar $instance */
        return $this->container->get($class);
    }

    /**
     * @return array{name:string, description:string, avatar:string, class:string}
     */
    public function getMeta(string $slug): array
    {
        $brains = (array) $this->settings->get('llm.brains');
        $class = (string) ($brains[$slug] ?? '');
        if ($class === '' || ! class_exists($class) || ! is_subclass_of($class, BrainAvatar::class)) {
            throw new \InvalidArgumentException('Assistant inconnu: ' . $slug);
        }

        /** @var class-string<BrainAvatar> $class */
        return [
            'name' => $class::NAME,
            'description' => $class::DESCRIPTION,
            'avatar' => $class::AVATAR,
            'class' => $class,
        ];
    }
}
