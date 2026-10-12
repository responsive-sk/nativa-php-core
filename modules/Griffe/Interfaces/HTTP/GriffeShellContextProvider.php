<?php

declare(strict_types = 1);

namespace Modules\Griffe\Interfaces\HTTP;

use Modules\Core\Domain\Contracts\ShellContextProvider;
use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Infrastructure\Attributes\AsShellContextProvider;

/**
 * GriffeShellContextProvider — the test fixture that makes the Griffe theme
 * VISIBLE with zero other modules installed.
 *
 * Same shape as the Mono/Erik fixtures: it answers exactly one question
 * (`theme()` → `griffe`) and abstains from the rest, so the merge rule keeps
 * every kernel default and only the chrome changes. It travels with the
 * slice, so removing the module removes the vote.
 *
 * Never install two voting fixtures at once (Erik + Mono + Griffe): the merge
 * rule takes the first non-null theme by priority and discovery order, and a
 * site whose theme depends on glob order is a site nobody chose. A real
 * deployment answers `theme()` from its settings module instead (the Settings
 * provider now votes the stored theme at priority -100, so it wins over this
 * fixture whenever the admin picks one).
 */
#[AsShellContextProvider(priority: 0)]
final class GriffeShellContextProvider implements ShellContextProvider
{
    public function siteName(): ?string
    {
        return null;
    }

    public function theme(): string
    {
        return 'griffe';
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
