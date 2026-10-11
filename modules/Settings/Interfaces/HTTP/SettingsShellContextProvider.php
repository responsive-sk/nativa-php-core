<?php

declare(strict_types = 1);

namespace Modules\Settings\Interfaces\HTTP;

use Modules\Core\Domain\Contracts\ShellContextProvider;
use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Infrastructure\Attributes\AsShellContextProvider;
use Modules\Settings\Application\DTOs\MenuItemDTO;

/**
 * SettingsShellContextProvider — the MENU half of the shell port, answered by
 * the module that owns the `menu_items` table.
 *
 * The port exists because the kernel used to reach for `SettingsServices` to
 * build the header and footer nav; navigation moved behind the port and nothing
 * answered it, so every public page rendered a shell with no menu at all. Not
 * an error, not a log line — a navbar with a brand and nothing to click.
 * Measured on the Mono theme, whose navbar has no links of its own to fall back
 * on: `mo-navbar__menu` was absent from the document entirely.
 *
 * Only `navigation()` is implemented, and that is deliberate. The same module
 * owns the site name and the active theme, but a theme slice ships a fixture
 * provider that answers those, and a second answer would be a second source.
 * This provider says the one thing nothing else in the tree says.
 *
 * (Phrased without the type keyword in prose on purpose: the registry resolves
 * the FQCN from the FIRST occurrence of that keyword followed by a name, so a
 * docblock that mentions one resolves to a type that does not exist, the file
 * is skipped without a log line and the menu silently disappears. Measured
 * here; the reason this note exists at all.)
 *
 * The slot answer comes straight from `MenuService::menu()`, which already
 * does the three things a nav needs: filters `is_active`, nests children (three
 * levels, capped there), and marks the item under the current path active —
 * which is what makes `mo-navbar__link--active` light up on the page you are
 * actually reading.
 *
 * A slot with no active rows answers `[]`, the port's own "no menu" answer.
 * A provider that THROWS is not caught (see `ShellContextProviderRegistry`):
 * a table the deployment has not migrated is a broken deployment, and a menu
 * that silently disappears is one its owner cannot see. `bin/cms migrate` —
 * or the first request after a fresh checkout — is what creates the table.
 */
#[AsShellContextProvider(priority: 0)]
final class SettingsShellContextProvider implements ShellContextProvider
{
    public function siteName(): ?string
    {
        return null;
    }

    public function theme(): ?string
    {
        return null;
    }

    public function defaultLocale(): ?string
    {
        return null;
    }

    /**
     * @return list<string>
     */
    public function languages(): array
    {
        return [];
    }

    public function htmlLang(): ?string
    {
        return null;
    }

    /**
     * @return list<ShellNavItem>
     */
    public function navigation(string $slot, string $currentPath): array
    {
        $items = SettingsServices::menu()->menu($slot, $currentPath);

        return array_map(self::navItem(...), $items);
    }

    private static function navItem(MenuItemDTO $item): ShellNavItem
    {
        return new ShellNavItem(
            label: $item->label(),
            href: $item->href(),
            active: $item->active(),
            external: $item->external(),
            children: array_map(self::navItem(...), $item->children()),
        );
    }
}
