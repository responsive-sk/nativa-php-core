<?php

declare(strict_types = 1);

namespace Modules\CardboardKit\Application\Services;

use Modules\CardboardKit\Domain\Contracts\AdminContextProvider;
use Modules\CardboardKit\Infrastructure\Attributes\AsAdminContextProvider;
use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * AdminContextRegistry — discovers #[AsAdminContextProvider] classes the
 * same way the kernel discovers #[AsShellContextProvider] ones: one glob
 * over a filename convention, text pre-filter, reflection validation,
 * priority sort, first wins. The mechanics are deliberately the fourth
 * copy of this shape (middleware, shell, badges, metrics) rather than a
 * shared abstraction — a shared discoverer would be kernel code depending
 * on module attributes, which is the cycle this file exists to avoid.
 */
final class AdminContextRegistry
{
    /** @var list<AdminContextProvider>|null */
    private static ?array $providers = null;

    /**
     * @return list<AdminContextProvider>
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

        self::$providers = array_map(static fn (array $entry): AdminContextProvider => $entry[1], $found);

        return self::$providers;
    }

    /** First provider, or null when no module answers for the admin shell. */
    public static function first(): ?AdminContextProvider
    {
        $all = self::all();

        return $all[0] ?? null;
    }

    /** Reset cached providers (test isolation). */
    public static function reset(): void
    {
        self::$providers = null;
    }

    /**
     * @return array{int, AdminContextProvider}|null
     */
    private static function load(string $file): ?array
    {
        $content = file_get_contents($file);

        if (!is_string($content) || !str_contains($content, 'AsAdminContextProvider')) {
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

        if (!$reflection->implementsInterface(AdminContextProvider::class)) {
            return null;
        }

        $attributes = $reflection->getAttributes(AsAdminContextProvider::class);

        if ([] === $attributes) {
            return null;
        }

        if ($reflection->getConstructor()?->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        $attribute = $attributes[0]->newInstance();

        /** @var AdminContextProvider $instance */
        $instance = $reflection->newInstance();

        return [$attribute->priority, $instance];
    }

    /** Namespace + class declaration → FQCN, without loading the file. */
    private static function resolveFqcn(string $content): ?string
    {
        $namespace = '';
        $className = '';

        if (preg_match('/namespace\s+([\w\\\\]+)/', $content, $namespaceMatch)) {
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

    /**
     * Candidate provider files, one glob — a file whose name does not say
     * `AdminContext` is not one, and this is what keeps the scan from
     * loading the whole tree.
     *
     * @return list<string>
     */
    private static function files(): array
    {
        $files = glob(AppPaths::instance()->src('Modules') . '/*/Application/Services/*AdminContext.php');

        return is_array($files) ? $files : [];
    }
}
