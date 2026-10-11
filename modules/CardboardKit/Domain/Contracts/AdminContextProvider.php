<?php

declare(strict_types = 1);

namespace Modules\CardboardKit\Domain\Contracts;

/**
 * AdminContextProvider — the admin shell's hurt locker, as a port.
 *
 * AdminLayout (the kit's presentation toolkit) renders the admin chrome for
 * every installed module's /mark/* pages, but the answers it needs — whose
 * nav tree, who is signed in, what they may see, which layout variant —
 * live in the admin app (Cardboard) and the user store. Importing either
 * would close an install-order cycle (the kit would need Cardboard, which
 * needs the kit), so the layout asks this port instead. Same shape as the
 * kernel's ShellContextProvider: discovered by attribute, first wins,
 * graceful absence (an admin page without a provider renders unpersonalized
 * chrome rather than fataling).
 *
 * Nav items are plain arrays (the shape AdminLayout::renderNavItem reads),
 * never another module's DTO — see ShellNavItem for why the kernel insists.
 */
interface AdminContextProvider
{
    /**
     * Hierarchical admin nav tree for the section, already permission
     * filtered (null $grantedPermissions = do not filter).
     *
     * @return list<array<string, mixed>>
     */
    public function navItems(string $activeSection, ?array $grantedPermissions): array;

    /** Signed-in user id, null for guests. */
    public function currentUserId(): ?string;

    /** Display name for the header ("Guest" when unknown — the impl decides). */
    public function userDisplayName(?string $userId): string;

    /**
     * Permission names for the nav filter. Null when unknown, which the nav
     * reads as "do not filter".
     *
     * @return list<string>|null
     */
    public function userPermissions(?string $userId): ?array;

    /** Admin layout variant ('sidebar' or 'compact'). */
    public function adminLayout(): string;
}
