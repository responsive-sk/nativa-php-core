<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Middleware;

use Modules\Core\Infrastructure\Attributes\AsMiddleware;
use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * MiddlewareRegistry - auto-discovers #[AsMiddleware] classes and provides
 * sorted middleware instances for the Kernel pipeline.
 */
final class MiddlewareRegistry
{
    /** @var list<MiddlewareInterface>|null */
    private static ?array $middlewares = null;

    /**
     * Discover and return all #[AsMiddleware] instances, sorted by priority.
     *
     * @return list<MiddlewareInterface>
     */
    public static function all(): array
    {
        if (null !== self::$middlewares) {
            return self::$middlewares;
        }

        $middlewares = [];
        $base = AppPaths::instance()->src('Modules');

        $dirs = glob($base . '/*/Interfaces/HTTP/Middleware');
        if (!is_array($dirs)) {
            self::$middlewares = [];

            return [];
        }

        foreach ($dirs as $dir) {
            $files = glob($dir . '/*.php');
            if (!is_array($files)) {
                continue;
            }

            foreach ($files as $file) {
                self::scanFile($file, $middlewares);
            }
        }

        uasort($middlewares, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        self::$middlewares = array_values(array_map(
            static fn (array $entry): MiddlewareInterface => $entry[1],
            $middlewares,
        ));

        return self::$middlewares;
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$middlewares = null;
    }

    /**
     * @param array<string, array{int, MiddlewareInterface}> $middlewares
     */
    private static function scanFile(string $file, array &$middlewares): void
    {
        $content = file_get_contents($file);
        if (!is_string($content) || !str_contains($content, 'AsMiddleware')) {
            return;
        }

        $fqcn = self::resolveFqcn($content);
        if (null === $fqcn || !self::loadAndRegister($fqcn, $file, $middlewares)) {
            return;
        }
    }

    /**
     * Extract the FQCN from a file body (namespace + class declaration).
     */
    private static function resolveFqcn(string $content): ?string
    {
        $namespace = '';
        $className = '';

        if (preg_match('/namespace\s+([\w\\\\]+)/', $content, $nsMatch)) {
            $namespace = $nsMatch[1];
        }
        if (preg_match('/class\s+(\w+)/', $content, $cnMatch)) {
            $className = $cnMatch[1];
        }

        if ('' === $namespace || '' === $className) {
            return null;
        }

        return $namespace . '\\' . $className;
    }

    /**
     * Load the class, validate the MiddlewareInterface + #[AsMiddleware]
     * contract (zero-arg constructor) and register an instance by priority.
     *
     * @param array<string, array{int, MiddlewareInterface}> $middlewares
     */
    private static function loadAndRegister(string $fqcn, string $file, array &$middlewares): bool
    {
        if (!class_exists($fqcn)) {
            require_once $file;
        }

        if (!class_exists($fqcn)) {
            return false;
        }

        $reflection = new \ReflectionClass($fqcn);

        if (!$reflection->implementsInterface(MiddlewareInterface::class)) {
            return false;
        }

        $attributes = $reflection->getAttributes(AsMiddleware::class);
        if ([] === $attributes) {
            return false;
        }

        if ($reflection->getConstructor()?->getNumberOfRequiredParameters() > 0) {
            return false;
        }

        /** @var AsMiddleware $attr */
        $attr = $attributes[0]->newInstance();

        /** @var MiddlewareInterface $instance */
        $instance = $reflection->newInstance();
        $middlewares[$attr->priority . '_' . $fqcn] = [$attr->priority, $instance];

        return true;
    }
}
