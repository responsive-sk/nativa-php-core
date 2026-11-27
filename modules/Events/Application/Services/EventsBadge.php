<?php

declare(strict_types = 1);

namespace Modules\Events\Application\Services;

use Modules\Events\Application\EventsServices;
use Modules\Navigation\Domain\Contracts\NavBadgeContract;
use Modules\Navigation\Infrastructure\Attributes\AsNavBadge;

/**
 * EventsBadge: pending reservations waiting for an organizer decision.
 *
 * The number is a `COUNT(*)` over pending rows, not a hydrated list. A missing
 * table answers zero: the sidebar renders before a module has migrated.
 */
#[AsNavBadge(priority: 80)]
final readonly class EventsBadge implements NavBadgeContract
{
    public const string NAV_HREF = '/mark/events';

    public function navHref(): string
    {
        return self::NAV_HREF;
    }

    public function count(): int
    {
        try {
            return EventsServices::reservations()->countPending();
        } catch (\Throwable) {
            return 0;
        }
    }
}
