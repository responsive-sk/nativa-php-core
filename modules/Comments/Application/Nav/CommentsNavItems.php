<?php

declare(strict_types = 1);

namespace Modules\Comments\Application\Nav;

use Modules\Core\Domain\Permissions;

/**
 * Comments' entry in the Mark nav.
 *
 * Moderation is where the inbox links land (`/mark/comments` is the href every
 * comment notification carries), so the page has to be reachable from the panel
 * itself as well — a notification is not a menu.
 */
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'Comments', href: '/mark/comments', permission: Permissions::CONTENT, order: 76, icon: 'message')]
final class CommentsNavItems
{
}
