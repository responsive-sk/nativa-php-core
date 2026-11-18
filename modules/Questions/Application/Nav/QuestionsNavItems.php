<?php

declare(strict_types = 1);

namespace Modules\Questions\Application\Nav;

use Modules\Core\Domain\Permissions;

/**
 * Questions' entry in the Mark nav — the inbox `QuestionsBadge` counts.
 *
 * The badge resolves for a nav ITEM, so without a declaration the count was
 * being resolved for a link the sidebar never carried.
 */
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'Questions', href: '/mark/questions', permission: Permissions::CONTENT, order: 77, icon: 'bell')]
final class QuestionsNavItems
{
}
