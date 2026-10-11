<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP;

use Themes\kernel\KernelTheme;
use Themes\ThemeModule;

/**
 * ThemeKernel connector — the ONLY place mapping a resolved theme name to its
 * `Themes\ThemeModule` implementation (explicit static match table).
 *
 * In this tree the table holds exactly one entry, the kernel's own theme, and
 * that is the correct size for a kernel-only tree rather than a gap: a theme
 * arrives with the module that ships it, and a module that is removed takes its
 * entry with it.
 *
 * **An unknown name falls back to the kernel theme, never to a theme the tree
 * does not have.** This repo has already paid for the other shape once:
 * `AssetManager::knownBundles()` served the default theme's stylesheet for a
 * theme it did not recognise, and the result — a page rendering another theme's
 * CSS — is indistinguishable from a page that works. Falling back to
 * `Themes\starter\StarterTheme` here would be the same bug with a fatal
 * instead of a wrong stylesheet.
 *
 * One-way dependency edge, Modules\Core → Themes, for this file alone; no real
 * runtime cycle, because a theme already depends on Core helpers
 * (`FrontendLayout::e`) — documented in ADR-0003.
 */
final class ThemeKernel
{
    /** @var array<string, class-string<ThemeModule>> */
    private const array MODULES = [
        ThemeResolver::KERNEL_THEME => KernelTheme::class,
    ];

    public static function for(string $theme): ThemeModule
    {
        $class = self::MODULES[$theme] ?? self::MODULES[ThemeResolver::KERNEL_THEME];

        // class_exists() rather than a bare `new`: a stale catalogue entry or a
        // theme class deleted by hand must degrade to the kernel theme, not
        // fatal in the middle of rendering a page.
        if (!class_exists($class)) {
            $class = self::MODULES[ThemeResolver::KERNEL_THEME];
        }

        return new $class();
    }
}
