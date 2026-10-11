<?php

declare(strict_types = 1);

namespace Modules\Mono\Interfaces\HTTP;

use Modules\Core\Domain\Contracts\ShellContextProvider;
use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Infrastructure\Attributes\AsShellContextProvider;

/**
 * MonoShellContextProvider — the test fixture that makes the Mono theme
 * VISIBLE with zero other modules installed.
 *
 * Same shape as the Erik fixture: it answers exactly one question (`theme()`
 * → `mono`) and abstains from the rest, so the merge rule keeps every kernel
 * default and only the chrome changes. It travels with the slice, so removing
 * the module removes the vote.
 *
 * Never install two voting fixtures at once (Erik + Mono): the merge rule
 * takes the first non-null theme by priority and discovery order, and a site
 * whose theme depends on glob order is a site nobody chose. A real deployment
 * answers `theme()` from its settings module instead.
 */
#[AsShellContextProvider(priority: 0)]
final class MonoShellContextProvider implements ShellContextProvider
{
    public function siteName(): ?string
    {
        return null;
    }

    public function theme(): ?string
    {
        return 'mono';
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
