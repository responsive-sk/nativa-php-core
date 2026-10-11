<?php

declare(strict_types = 1);

namespace Modules\Erik\Interfaces\HTTP;

use Modules\Core\Domain\Contracts\ShellContextProvider;
use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Infrastructure\Attributes\AsShellContextProvider;

/**
 * ErikShellContextProvider — the test fixture that makes the Erik theme
 * VISIBLE with zero other modules installed.
 *
 * A theme the port never names is a theme no surface renders: with no
 * Settings/Locales module the provider answers are all null and the kernel
 * theme wins every time, so installing Erik alone would change nothing anyone
 * can curl. This provider answers exactly one question — the theme — and
 * abstains from the rest (null site name, null locale, no languages, no menu),
 * so the merge rule keeps every kernel default and only the chrome changes.
 *
 * It travels WITH the Erik slice deliberately: removing the module removes
 * the vote, and the site falls back to the kernel theme on the next request.
 * A real deployment answers `theme()` from its settings module instead.
 */
#[AsShellContextProvider(priority: 0)]
final class ErikShellContextProvider implements ShellContextProvider
{
    public function siteName(): ?string
    {
        return null;
    }

    public function theme(): ?string
    {
        return 'erik';
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
        return [];
    }
}
