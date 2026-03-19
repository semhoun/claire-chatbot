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
    public const string NAME = '';

    public const string DESCRIPTION = '';

    public const string AVATAR = '';

    public const string CSS = '';
}
