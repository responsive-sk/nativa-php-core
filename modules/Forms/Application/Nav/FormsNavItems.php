<?php

declare(strict_types = 1);

namespace Modules\Forms\Application\Nav;

use Modules\Core\Domain\Permissions;

/**
 * Forms' entry in the Mark nav — the page `FormsBadge::NAV_HREF` counts into.
 */
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'Forms', href: '/mark/forms', permission: Permissions::CONTENT, order: 78, icon: 'inbox')]
final class FormsNavItems
{
}
