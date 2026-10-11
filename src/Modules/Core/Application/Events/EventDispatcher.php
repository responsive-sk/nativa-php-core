<?php

declare(strict_types = 1);

namespace Modules\Core\Application\Events;

/**
 * EventDispatcher — tiny synchronous event bus.
 *
 * Modules dispatch named events (e.g. 'user.registered') with a payload
 * array; listeners registered via listen() receive the payload. Kept
 * deliberately minimal (zero dependencies) — no async, no middleware.
 */
final class EventDispatcher
{
    /** @var array<string, list<callable(array<string, mixed>): void>> */
    private array $listeners = [];

    /**
     * Register a listener for an event name.
     *
     * @param callable(array<string, mixed>): void $listener
     */
    public function listen(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /**
     * Dispatch an event to all registered listeners.
     *
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $event, array $payload = []): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($payload);
        }
    }
}
