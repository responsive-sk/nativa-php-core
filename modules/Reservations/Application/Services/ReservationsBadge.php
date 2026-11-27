<?php

declare(strict_types = 1);

namespace Modules\Reservations\Application\Services;

use Modules\Navigation\Domain\Contracts\NavBadgeContract;
use Modules\Navigation\Infrastructure\Attributes\AsNavBadge;
use Modules\Reservations\Application\ReservationsServices;

/**
 * ReservationsBadge - the number of orders waiting for an organizer.
 *
 * The badge is queue depth: confirming or cancelling an order clears it.
 * A missing database answers 0, because the sidebar renders before a module
 * has migrated on a fresh deployment.
 */
#[AsNavBadge(priority: 86)]
final readonly class ReservationsBadge implements NavBadgeContract
{
    public function navHref(): string
    {
        return '/mark/reservations';
    }

    public function count(): int
    {
        try {
            return ReservationsServices::service()->countNew();
        } catch (\Throwable) {
            return 0;
        }
    }
}
