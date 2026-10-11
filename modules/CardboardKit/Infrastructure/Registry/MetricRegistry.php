<?php

declare(strict_types = 1);

namespace Modules\CardboardKit\Infrastructure\Registry;

use Modules\CardboardKit\Domain\Contracts\MetricContract;
use Modules\CardboardKit\Infrastructure\Attributes\AsMetricProvider;
use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * MetricRegistry - auto-discovers #[AsMetricProvider] classes and provides
 * sorted metric provider instances for the dashboard.
 *
 * @see AsMetricProvider
 * @see MetricContract
 */
final class MetricRegistry
{
    /** @var list<MetricContract>|null */
    private static ?array $metrics = null;

    /**
     * Discover and return all #[AsMetricProvider] instances, sorted by priority (descending).
     *
     * @return list<MetricContract>
     */
    public static function all(): array
    {
        if (null !== self::$metrics) {
            return self::$metrics;
        }

        $metrics = [];
        $base = AppPaths::instance()->src('Modules');

        $dirs = glob($base . '/*/Application/Services');
        if (!is_array($dirs)) {
            self::$metrics = [];

            return [];
        }

        foreach ($dirs as $dir) {
            $files = glob($dir . '/*Metric.php');
            if (!is_array($files)) {
                continue;
            }

            foreach ($files as $file) {
                self::scanFile($file, $metrics);
            }
        }

        usort($metrics, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        self::$metrics = array_map(
            static fn (array $entry): MetricContract => $entry[1],
            $metrics,
        );

        return self::$metrics;
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$metrics = null;
    }

    /**
     * @param array<string, array{int, MetricContract}> $metrics
     */
    private static function scanFile(string $file, array &$metrics): void
    {
        $content = file_get_contents($file);
        if (!is_string($content) || !str_contains($content, 'AsMetricProvider')) {
            return;
        }

        $fqcn = self::resolveFqcn($content);
        if (null === $fqcn || !self::loadAndRegister($fqcn, $file, $metrics)) {
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
     * Load the class, validate the MetricContract + #[AsMetricProvider]
     * contract (zero-arg constructor) and register an instance by priority.
     *
     * @param array<string, array{int, MetricContract}> $metrics
     */
    private static function loadAndRegister(string $fqcn, string $file, array &$metrics): bool
    {
        if (!interface_exists($fqcn) && !class_exists($fqcn)) {
            require_once $file;
        }

        if (!interface_exists($fqcn) && !class_exists($fqcn)) {
            return false;
        }

        $reflection = new \ReflectionClass($fqcn);

        if (!$reflection->implementsInterface(MetricContract::class)) {
            return false;
        }

        $attributes = $reflection->getAttributes(AsMetricProvider::class);
        if ([] === $attributes) {
            return false;
        }

        if ($reflection->getConstructor()?->getNumberOfRequiredParameters() > 0) {
            return false;
        }

        /** @var AsMetricProvider $attr */
        $attr = $attributes[0]->newInstance();

        /** @var MetricContract $instance */
        $instance = $reflection->newInstance();
        $metrics[$attr->priority . '_' . $fqcn] = [$attr->priority, $instance];

        return true;
    }
}
