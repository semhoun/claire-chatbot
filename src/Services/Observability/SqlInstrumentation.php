<?php

declare(strict_types=1);

namespace App\Services\Observability;

use App\Services\Settings;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextKeyInterface;
use Throwable;

final class SqlInstrumentation
{
    private static ?ContextKeyInterface $contextKey = null;

    public function __construct(
        private readonly bool $enabled = false,
        private readonly int $maxQueryTextBytes = 16384,
    ) {
    }

    public static function fromSettings(Settings $settings): self
    {
        return new self(
            $settings->get('observability.sql.enabled', false) === true,
            max(0, (int) $settings->get('observability.sql.maxQueryTextBytes', 16384)),
        );
    }

    public static function contextKey(): ContextKeyInterface
    {
        return self::$contextKey ??= Context::createKey('claire.sql.context');
    }

    public function measure(string $sql, string $dbSystem, callable $execute): mixed
    {
        $span = null;
        $scope = null;
        try {
            if ($this->enabled) {
                $operation = null;
                $operationPattern = '/^\s*(SELECT|INSERT|UPDATE|DELETE|MERGE|CREATE|ALTER|DROP|TRUNCATE|'
                    . 'REPLACE|PRAGMA|EXPLAIN|VACUUM|ANALYZE)\b/i';
                if (preg_match($operationPattern, $sql, $match) === 1) {
                    $operation = strtoupper($match[1]);
                }

                // JSON's substitution repairs malformed input without depending on mbstring settings.
                $text = json_decode(
                    json_encode($sql, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
                    flags: JSON_THROW_ON_ERROR,
                );
                $truncated = strlen($text) > max(0, $this->maxQueryTextBytes);
                $text = mb_strcut($text, 0, max(0, $this->maxQueryTextBytes), 'UTF-8');
                $attributes = [
                    'db.system.name' => match ($dbSystem) {
                        'pgsql', 'pdo_pgsql' => 'postgresql',
                        'pdo_mysql', 'mysqli' => 'mysql',
                        'pdo_sqlite', 'sqlite3' => 'sqlite',
                        default => $dbSystem,
                    },
                    'db.query.text' => $text,
                    'claire.sql.query.truncated' => $truncated,
                ];
                if ($operation !== null) {
                    $attributes['db.operation.name'] = $operation;
                }

                $context = Context::getCurrent()->get(self::contextKey());
                $attributes['claire.sql.context'] = in_array($context, ['request', 'job', 'maintenance', 'cli'], true)
                    ? $context : (PHP_SAPI === 'cli' ? 'cli' : 'request');
                $span = Globals::tracerProvider()->getTracer('claire.sql')->spanBuilder($operation ?? 'SQL')
                    ->setSpanKind(SpanKind::KIND_CLIENT)->setAttributes($attributes)->startSpan();
                $scope = $span->activate();
            }
        } catch (Throwable) {
            // Telemetry setup must never prevent the one and only SQL execution below.
        }

        try {
            return $execute();
        } catch (Throwable $throwable) {
            try {
                $span?->setStatus(StatusCode::STATUS_ERROR);
                $span?->setAttribute('error.type', $throwable::class);
            } catch (Throwable) {
            }

            throw $throwable;
        } finally {
            try {
                $scope?->detach();
            } catch (Throwable) {
            }

            try {
                $span?->end();
            } catch (Throwable) {
            }
        }
    }
}
