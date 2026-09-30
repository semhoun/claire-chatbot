<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Internal control flow, never a provider/tool error or a retry instruction. */
final class GenerationStopped extends RuntimeException
{
    public function __construct(public readonly GenerationStopToken $token)
    {
        parent::__construct('Generation stopped cooperatively.');
    }
}
