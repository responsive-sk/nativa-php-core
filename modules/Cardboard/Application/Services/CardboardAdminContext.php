<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Application\Services;

use Modules\CardboardKit\Domain\Contracts\AdminContextProvider;
use Modules\CardboardKit\Infrastructure\Attributes\AsAdminContextProvider;
use Modules\Cardboard\Interfaces\HTTP\CardboardServices;

/**
 * CardboardAdminContext — the admin app answers for its own chrome.
 *
 * AdminLayout (kit) asks the AdminContextProvider port; this class is the
 * answer, delegating to CardboardServices the way the layout used to call
 * it directly. The direction is what matters: the kit names the port, the
 * admin app implements it, and no use-statement points from the kit back
 * at Cardboard — which is what keeps the install-order graph acyclic.
 */
#[AsAdminContextProvider(priority: 0)]
final class CardboardAdminContext implements AdminContextProvider
{
    public function navItems(string $activeSection, ?array $grantedPermissions): array
    {
        return CardboardServices::adminNav()->hierarchicalItems($activeSection, $grantedPermissions);
    }

    public function currentUserId(): ?string
    {
        return CardboardServices::auth()->currentUserId();
    }

    public function userDisplayName(?string $userId): string
    {
        if (null === $userId) {
            return 'Guest';
        }

        $user = CardboardServices::users()->findById($userId);

        return null === $user ? 'Guest' : $user->name();
    }

    public function userPermissions(?string $userId): ?array
    {
        if (null === $userId) {
            return null;
        }

        $user = CardboardServices::users()->findById($userId);

        return null === $user ? null : $user->permissions();
    }

    public function adminLayout(): string
    {
        try {
            $all = CardboardServices::settings()->all();
            $layout = is_string($all['admin_layout'] ?? null) ? $all['admin_layout'] : 'sidebar';

            return in_array($layout, ['sidebar', 'compact'], true) ? $layout : 'sidebar';
        } catch (\Throwable) {
            return 'sidebar';
        }
    }
}
