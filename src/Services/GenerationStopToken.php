<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\StoppableHttpClient;

/** One user generation only; the predicate must capture its immutable SQL identity. */
final class GenerationStopToken
{
    private readonly Closure $predicate;

    private bool $stopped = false;

    public function __construct(callable $predicate)
    {
        $this->predicate = $predicate(...);
    }

    public function isRequested(): bool
    {
        return $this->stopped = $this->stopped || ($this->predicate)();
    }

    public function request(): void
    {
        $this->stopped = true;
    }

    public function check(): void
    {
        if ($this->isRequested()) {
            throw new GenerationStopped($this);
        }
    }

    public function httpClient(HttpClientInterface $httpClient): StoppableHttpClient
    {
        return new StoppableHttpClient($httpClient, $this->isRequested(...));
    }
}
