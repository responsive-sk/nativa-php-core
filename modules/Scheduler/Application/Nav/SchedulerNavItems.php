<?php

declare(strict_types = 1);

namespace Modules\Scheduler\Application\Nav;

use Modules\Core\Domain\Permissions;

/**
 * Scheduler's entry in the Mark nav — the timetable `ScheduleMetric` links to.
 *
 * Same row as the queue and the outbox: a schedule is operator state, and the
 * permission here is the one `MarkRoutePolicy` asks for the path.
 */
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'Schedule', href: '/mark/schedule', permission: Permissions::ROLES, order: 112, icon: 'clipboard')]
final class SchedulerNavItems
{
}
