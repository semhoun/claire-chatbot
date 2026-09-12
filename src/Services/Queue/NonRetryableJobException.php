<?php

declare(strict_types=1);

namespace App\Services\Queue;

final class NonRetryableJobException extends \RuntimeException
{
}
