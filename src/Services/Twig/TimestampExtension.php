<?php

declare(strict_types=1);

namespace App\Services\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class TimestampExtension extends AbstractExtension
{
    /**
     * @return array<int, TwigFilter>
     */
    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('format_timestamp', $this->formatTimestamp(...)),
        ];
    }

    /**
     * Format an ISO 8601 timestamp to H:i format.
     *
     * @param string|null $timestamp ISO 8601 timestamp
     *
     * @return string Formatted time (H:i) or empty string if invalid
     */
    public function formatTimestamp(?string $timestamp): string
    {
        if ($timestamp === null || $timestamp === '') {
            return '';
        }

        try {
            $dateTime = new \DateTimeImmutable($timestamp);
            return $dateTime->format('H:i');
        } catch (\Exception) {
            return '';
        }
    }
}
