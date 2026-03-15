<?php

declare(strict_types=1);

namespace App\Brain;

/**
 * Interface for AI assistants.
 *
 * Implementations should be instantiated via the BrainRegistry
 * to ensure correct dependency injection of:
 * - Doctrine\DBAL\Connection
 * - App\Services\Settings
 * - App\Services\Session\SessionInterface
 */
interface BrainAvatar
{
    public const string NAME = 'undefined';

    public const string DESCRIPTION = 'undefined';

    public const string AVATAR = 'undefined';

    public const string CSS = '';

    public function getOpeningText(): string;
}
