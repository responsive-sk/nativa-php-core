<?php

declare(strict_types = 1);

namespace Modules\Events\Application\Nav;

use Modules\Core\Domain\Permissions;

/**
 * Events' entry in the Mark nav.
 */
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'Events', href: '/mark/events', permission: Permissions::CONTENT, order: 78, icon: 'file-text')]
final class EventsNavItems
{
}
