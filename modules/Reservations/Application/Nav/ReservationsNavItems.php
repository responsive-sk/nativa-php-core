<?php

declare(strict_types = 1);

namespace Modules\Reservations\Application\Nav;

use Modules\Core\Domain\Permissions;

/**
 * Reservations' entry in the Mark nav.
 */
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'Reservations', href: '/mark/reservations', permission: Permissions::CONTENT, order: 79, icon: 'calendar')]
final class ReservationsNavItems
{
}
