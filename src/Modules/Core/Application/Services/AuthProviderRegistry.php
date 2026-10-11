<?php

declare(strict_types = 1);

namespace Modules\Core\Application\Services;

use Modules\Core\Domain\Contracts\AuthProvider;
use Modules\Core\Infrastructure\Attributes\AsAuthProvider;
use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * AuthProviderRegistry — discovers `#[AsAuthProvider]` classes and answers
 * with the first one.
 *
 * Discovery is a directory scan, exactly like `MiddlewareRegistry` and
 * `ShellContextProviderRegistry`: a module joins the kernel by existing, and
 * a deleted module takes its provider with it. The scan looks for
 * `*AuthProvider.php` in each module's `Interfaces/HTTP` directory — a file
 * whose name does not say `AuthProvider` is not one, so the scan does not
 * load the whole tree to find out.
 *
 * First provider wins (priority, then discovery order): two modules answering
 * "who is this request" is not defense in depth, it is two answers, and the
 * composition root wires the middleware around exactly one of them.
 *
 * A provider that throws is NOT caught — same rule as the shell registry. A
 * missing badge is a missing badge; an authentication answer that silently
 * disappears is a gate nobody can trust, and the failure belongs to the
 * surface, loudly.
 */
final class AuthProviderRegistry
{
    /** @var list<AuthProvider>|null */
    private static ?array $providers = null;

    /**
     * Every installed provider, in priority order.
     *
     * @return list<AuthProvider>
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

        self::$providers = array_map(static fn (array $entry): AuthProvider => $entry[1], $found);

        return self::$providers;
    }

    /** Reset the cached providers (test isolation, module add/remove). */
    public static function reset(): void
    {
        self::$providers = null;
    }

    /**
     * The provider the middleware is wired around, or null when no module
     * owns authentication — in which case the middleware is not wired at all.
     */
    public static function first(): ?AuthProvider
    {
        return self::all()[0] ?? null;
    }

    /**
     * @return list<string>
     */
    private static function files(): array
    {
        $files = glob(AppPaths::instance()->src('Modules') . '/*/Interfaces/HTTP/*AuthProvider.php');

        return is_array($files) ? $files : [];
    }

    /**
     * @return array{int, AuthProvider}|null
     */
    private static function load(string $file): ?array
    {
        $content = file_get_contents($file);

        if (!is_string($content) || !str_contains($content, 'AsAuthProvider')) {
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

        if (!$reflection->implementsInterface(AuthProvider::class)) {
            return null;
        }

        $attributes = $reflection->getAttributes(AsAuthProvider::class);

        if ([] === $attributes) {
            return null;
        }

        if ($reflection->getConstructor()?->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        $attribute = $attributes[0]->newInstance();

        /** @var AuthProvider $instance */
        $instance = $reflection->newInstance();

        return [$attribute->priority, $instance];
    }

    private static function resolveFqcn(string $content): ?string
    {
        $namespace = '';
        $className = '';

        if (preg_match('/namespace\s+([\w\\\\\\\\]+)/', $content, $namespaceMatch)) {
            $namespace = $namespaceMatch[1];
        }

        if (preg_match('/class\s+(\w+)/', $content, $classNameMatch)) {
            $className = $classNameMatch[1];
        }

        if ('' === $namespace || '' === $className) {
            return null;
        }

        return $namespace . '\\' . $className;
    }
}
