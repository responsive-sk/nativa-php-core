<?php

declare(strict_types = 1);

namespace Modules\Locales\Interfaces\HTTP;

use Modules\Core\Domain\Contracts\ShellContextProvider;
use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Infrastructure\Attributes\AsShellContextProvider;
use Modules\Locales\Domain\Model\Locale;

/**
 * LocalesShellContextProvider — the LOCALE answers of the shell port,
 * owned by the module that owns locale knowledge.
 *
 * The Settings provider echoes the raw stored `locale` key (free text,
 * format-checked); this provider answers the RESOLVED default — the stored
 * value vetted against the available set, falling back to the built-in
 * default when the stored value is missing or unknown. First-non-null wins
 * by priority, so a stored valid code is echoed by Settings first and this
 * provider is the backstop that keeps `<html lang>` truthful either way.
 *
 * `languages()` is the available set itself (Locale::available, the single
 * source of truth) — the union the theme switchers render. It travels with
 * the slice like every provider, so removing the module removes the answers
 * and the switchers hide again instead of pointing at dead locales.
 */
#[AsShellContextProvider(priority: 0)]
final class LocalesShellContextProvider implements ShellContextProvider
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
        return LocalesServices::locale()->default()->code();
    }

    /**
     * @return list<string>
     */
    public function languages(): array
    {
        return array_keys(Locale::available());
    }

    public function htmlLang(): ?string
    {
        return LocalesServices::locale()->default()->language();
    }

    /**
     * @return list<ShellNavItem>
     */
    public function navigation(string $slot, string $currentPath): array
    {
        return [];
    }
}
