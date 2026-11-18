<?php

declare(strict_types = 1);

namespace Modules\Pages\Application\Nav;

use Modules\Core\Domain\Permissions;

/**
 * Pages' entry in the Mark nav — a content surface beside the Blog group.
 *
 * It was absent while the module had no catalogue slice and its link pointed at
 * a 404; the route is served today, so the entry is the module's again.
 */
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'Pages', href: '/mark/pages', permission: Permissions::CONTENT, order: 74, icon: 'file')]
final class PagesNavItems
{
}
