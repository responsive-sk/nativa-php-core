<?php

declare(strict_types = 1);

namespace Modules\Core\Application\Services;

use Modules\Core\Domain\Contracts\ShellContextProvider;
use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Infrastructure\Attributes\AsShellContextProvider;
use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * ShellContextProviderRegistry — discovers `#[AsShellContextProvider]` classes
 * and answers the shell's questions with ONE merge rule instead of a list the
 * caller has to interpret.
 *
 * Discovery is a directory scan, exactly like `MiddlewareRegistry`: a module
 * joins the kernel by existing, and a module that is deleted takes its provider
 * with it. The scan looks for `*ShellContextProvider.php` in each module's
 * `Interfaces/HTTP` directory — a provider is an HTTP-surface concern, and the
 * filename says what the file is, so the scan does not have to load every class
 * in the tree to find out. (The pattern is spelled once, in `files()`, and not
 * here: a `star-slash` pair written inside a docblock closes the comment.)
 *
 * ## The merge rule
 *
 * Two modules legitimately answer different halves of this port: the module that
 * owns site settings answers the name, the theme and the menu; the module that
 * owns locales answers the default locale, the language list and `<html lang>`.
 * So the answers merge, and each one merges the only way it can mean anything:
 *
 * | Question | Rule |
 * |---|---|
 * | `siteName`, `theme`, `defaultLocale`, `htmlLang` | first non-null wins (priority, then discovery order) |
 * | `languages` | union, in provider order, de-duplicated |
 * | `navigation` | first provider that returns a non-empty list for that slot |
 *
 * Navigation is the one that cannot merge: a header built from two menus is not
 * a menu, it is two menus glued together, and no provider could have known the
 * order of the other's items. First non-empty wins, so a module that owns
 * navigation owns all of it.
 *
 * A provider that throws is NOT caught. A missing badge is a missing badge; a
 * navigation menu that silently disappears is a site that looks broken to
 * whoever deployed it and fine to the log. The failure belongs to the surface.
 */
final class ShellContextProviderRegistry
{
    /** @var list<ShellContextProvider>|null */
    private static ?array $providers = null;

    /**
     * Every installed provider, in priority order.
     *
     * @return list<ShellContextProvider>
     */
    public static function all(): array
    {
        if (null !== self::$providers) {
            return self::$providers;
        }

        $found = [];

        foreach (self::files() as $file) {
            $provider = self::load($file);

            if (null === $provider) {
                continue;
            }

            $found[] = $provider;
        }

        usort($found, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        self::$providers = array_map(static fn (array $entry): ShellContextProvider => $entry[1], $found);

        return self::$providers;
    }

    /** Reset the cached providers (test isolation). */
    public static function reset(): void
    {
        self::$providers = null;
    }

    /** First provider that names the site, or null when no module does. */
    public static function siteName(): ?string
    {
        return self::firstString(static fn (ShellContextProvider $p): ?string => $p->siteName());
    }

    /** First provider that names a theme, or null — which means "use the kernel's". */
    public static function theme(): ?string
    {
        return self::firstString(static fn (ShellContextProvider $p): ?string => $p->theme());
    }

    /** First provider that names a default locale, or null when unset. */
    public static function defaultLocale(): ?string
    {
        return self::firstString(static fn (ShellContextProvider $p): ?string => $p->defaultLocale());
    }

    /** First provider that names a `<html lang>`, or null to follow the locale. */
    public static function htmlLang(): ?string
    {
        return self::firstString(static fn (ShellContextProvider $p): ?string => $p->htmlLang());
    }

    /**
     * The union of every provider's languages, in provider order.
     *
     * @return list<string>
     */
    public static function languages(): array
    {
        $languages = [];

        foreach (self::all() as $provider) {
            foreach ($provider->languages() as $language) {
                if (!in_array($language, $languages, true)) {
                    $languages[] = $language;
                }
            }
        }

        return $languages;
    }

    /**
     * The first non-empty navigation for a slot — see the merge rule above.
     *
     * @return list<ShellNavItem>
     */
    public static function navigation(string $slot, string $currentPath): array
    {
        foreach (self::all() as $provider) {
            $items = $provider->navigation($slot, $currentPath);

            if ([] !== $items) {
                return $items;
            }
        }

        return [];
    }

    /**
     * @param \Closure(ShellContextProvider): ?string $ask
     */
    private static function firstString(\Closure $ask): ?string
    {
        foreach (self::all() as $provider) {
            $answer = $ask($provider);

            if (null !== $answer && '' !== $answer) {
                return $answer;
            }
        }

        return null;
    }

    /**
     * Candidate provider files, one glob — a file whose name does not say
     * `ShellContextProvider` is not one, and this is what keeps the scan from
     * loading the whole tree.
     *
     * @return list<string>
     */
    private static function files(): array
    {
        $files = glob(AppPaths::instance()->src('Modules') . '/*/Interfaces/HTTP/*ShellContextProvider.php');

        return is_array($files) ? $files : [];
    }

    /**
     * @return array{int, ShellContextProvider}|null
     */
    private static function load(string $file): ?array
    {
        $content = file_get_contents($file);

        if (!is_string($content) || !str_contains($content, 'AsShellContextProvider')) {
            return null;
        }

        $fqcn = self::resolveFqcn($content);

        if (null === $fqcn) {
            return null;
        }

        if (!class_exists($fqcn)) {
            require_once $file;
        }

        if (!class_exists($fqcn)) {
            return null;
        }

        $reflection = new \ReflectionClass($fqcn);

        if (!$reflection->implementsInterface(ShellContextProvider::class)) {
            return null;
        }

        $attributes = $reflection->getAttributes(AsShellContextProvider::class);

        if ([] === $attributes) {
            return null;
        }

        if ($reflection->getConstructor()?->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        $attribute = $attributes[0]->newInstance();

        /** @var ShellContextProvider $instance */
        $instance = $reflection->newInstance();

        return [$attribute->priority, $instance];
    }

    /**
     * Extract the FQCN from a file body: its namespace declaration and its type
     * declaration, and nothing else.
     *
     * A declaration starts a LINE (after optional whitespace and the modifiers
     * PHP allows on a type) or a STATEMENT (after a `;`), so a compact
     * `namespace Demo; class Thing {}` file still resolves — the failure mode
     * here is silence, not an error, and a heuristic that is too strict trades
     * one silent skip for another.
     *
     * What cannot be a declaration is PROSE. This used to take the FIRST mention
     * of the keyword anywhere in the file, so a docblock sentence like "This
     * class says the one thing..." resolved a type named `says`: the class did
     * not exist, the file was skipped with no log line, and the provider silently
     * disappeared — measured on the Settings shell provider, whose nav menu
     * vanished exactly that way (docs/kernel-status.md, Boot 23).
     *
     * tests/Discovery/TextFirstFqcnTest.php pins both halves, for all four
     * registries that share this shape.
     */
    private static function resolveFqcn(string $content): ?string
    {
        $namespace = '';
        $className = '';

        if (preg_match('/(?:^|;)\s*namespace\s+([\w\\\\]+)\s*;/m', $content, $namespaceMatch)) {
            $namespace = $namespaceMatch[1];
        }

        if (preg_match('/(?:^|;)\s*(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $content, $classNameMatch)) {
            $className = $classNameMatch[1];
        }

        if ('' === $namespace || '' === $className) {
            return null;
        }

        return $namespace . '\\' . $className;
    }
}
