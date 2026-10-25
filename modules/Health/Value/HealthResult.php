<?php

declare(strict_types = 1);

namespace Modules\Health\Value;

/**
 * HealthResult — what one check found, and the only shape /health serializes.
 *
 * The constructor keeps `$exception`, because a failing check without the
 * failure behind it is a post-mortem with the cause removed; `toArray()`
 * deliberately drops it. That split is the reason this value exists rather than
 * an array: the payload is public, the exception is for `storage/logs`, and no
 * check can leak a path, a driver message or a DSN into a response by
 * formatting its own failure.
 *
 * `duration` is measured by the check around its own work, in seconds. Nobody
 * sums it; an operator reads it when a probe is slow, and a check that
 * produces the same status in 300 ms as in 3 ms is telling two different
 * stories.
 *
 * marko's `metadata` field is not ported: nothing in that package ever filled
 * it, and an always-empty field in a public payload is a promise the module
 * does not keep.
 */
final readonly class HealthResult
{
    public function __construct(
        public string $name,
        public HealthStatus $status,
        public string $message,
        public float $duration,
        public ?\Throwable $exception = null,
    ) {
    }

    public function isHealthy(): bool
    {
        return HealthStatus::Healthy === $this->status;
    }

    public function isDegraded(): bool
    {
        return HealthStatus::Degraded === $this->status;
    }

    public function isUnhealthy(): bool
    {
        return HealthStatus::Unhealthy === $this->status;
    }

    /**
     * The public projection — name, status, message, duration, nothing else.
     *
     * @return array{name: string, status: string, message: string, duration: float}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status->value,
            'message' => $this->message,
            'duration' => $this->duration,
        ];
    }
}
