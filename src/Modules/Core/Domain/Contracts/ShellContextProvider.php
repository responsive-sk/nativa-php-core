<?php

declare(strict_types = 1);

namespace Modules\Core\Domain\Contracts;

use Modules\Core\Domain\ShellNavItem;

/**
 * ShellContextProvider — the kernel-owned port the site shell asks instead of
 * reaching into a module.
 *
 * The kernel renders a document for every public surface, and a document needs
 * five facts that belong to whoever owns the site: its name, the active theme,
 * the default locale, the language list, and the navigation menu. Before this
 * port, `FrontendLayout`, `ThemeResolver` and `ContentServices` reached for
 * `SettingsServices` and `LocalesServices` directly, which made the shared
 * kernel unbuildable without two feature modules it does not own.
 *
 * **Absence is a first-class answer, not an error.** Every method may answer
 * null (or an empty list), and a kernel with no provider installed is a kernel
 * running as designed: it renders with no site name of its own, its own theme,
 * no locales and no menu. A provider that throws is a broken module, and the
 * caller is free to let that surface — never the whole pipeline.
 *
 * Implementations are discovered by attribute (`#[AsShellContextProvider]`) the
 * same way middleware is, so a module joins by existing rather than by being
 * registered in a list the kernel would have to keep.
 */
interface ShellContextProvider
{
    /** The site's display name, or null when the provider has no opinion. */
    public function siteName(): ?string;

    /**
     * The active public theme name, or null to let the kernel use its own.
     *
     * Null is not "no theme": it means "no preference", and the kernel resolves
     * its default. A provider that names a theme the tree does not have is a
     * configuration problem, and `ThemeKernel` answers it by falling back to
     * the kernel theme rather than by fataling on a missing class.
     */
    public function theme(): ?string;

    /**
     * The configured default locale code ('sk_SK'), or null when unset.
     *
     * The kernel never hardcodes which locale owns the content root — that is
     * what this method is for.
     */
    public function defaultLocale(): ?string;

    /**
     * Every language code the site offers ('sk', 'en'), in display order.
     *
     * @return list<string>
     */
    public function languages(): array;

    /**
     * The language code for `<html lang>`, or null to follow the default locale.
     */
    public function htmlLang(): ?string;

    /**
     * Navigation for one slot ('header' or 'footer'), already marked active
     * against the current path.
     *
     * @return list<ShellNavItem>
     */
    public function navigation(string $slot, string $currentPath): array;
}
