<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Markdown;

use Modules\Core\Infrastructure\Attributes\AsMarkdownBlock;
use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * MarkdownBlockRegistry — auto-discovers #[AsMarkdownBlock] classes and applies
 * them to rendered markdown.
 *
 * Deliberately the same shape as `MiddlewareRegistry`: scan two conventional
 * directories per module, keep what implements the contract and carries the
 * attribute with a zero-arg constructor, sort by priority, cache, and expose a
 * `reset()` for test isolation. Two extension points with two different
 * mechanisms would be one too many.
 *
 * A block that throws surfaces loudly — a swallowed expansion failure would
 * render an article with a raw `{{...}}` in it and nobody would look.
 */
final class MarkdownBlockRegistry
{
    /** @var list<MarkdownBlockInterface>|null */
    private static ?array $blocks = null;

    /**
     * Discovered blocks, sorted by priority.
     *
     * @return list<MarkdownBlockInterface>
     */
    public static function all(): array
    {
        if (null !== self::$blocks) {
            return self::$blocks;
        }

        $blocks = [];
        $base = AppPaths::instance()->src('Modules');

        foreach (['/*/Application/Markdown', '/*/Interfaces/HTTP/Markdown'] as $pattern) {
            $dirs = glob($base . $pattern);
            $files = [];

            foreach (is_array($dirs) ? $dirs : [] as $dir) {
                $found = glob($dir . '/*.php');

                foreach (is_array($found) ? $found : [] as $file) {
                    $files[] = $file;
                }
            }

            foreach ($files as $file) {
                self::scanFile($file, $blocks);
            }
        }

        uasort($blocks, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        self::$blocks = array_values(array_map(
            static fn (array $entry): MarkdownBlockInterface => $entry[1],
            $blocks,
        ));

        return self::$blocks;
    }

    /**
     * Run every block over `$html`, in priority order.
     */
    public static function expand(string $html, ?MarkdownBlockContext $context = null): string
    {
        $resolved = $context ?? MarkdownBlockContext::default();

        foreach (self::all() as $block) {
            $html = $block->expand($html, $resolved);
        }

        return $html;
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$blocks = null;
    }

    /**
     * @param array<string, array{int, MarkdownBlockInterface}> $blocks
     */
    private static function scanFile(string $file, array &$blocks): void
    {
        $content = file_get_contents($file);

        if (!is_string($content) || !str_contains($content, 'AsMarkdownBlock')) {
            return;
        }

        $fqcn = self::resolveFqcn($content);

        if (null !== $fqcn) {
            self::loadAndRegister($fqcn, $file, $blocks);
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
     * @param array<string, array{int, MarkdownBlockInterface}> $blocks
     */
    private static function loadAndRegister(string $fqcn, string $file, array &$blocks): bool
    {
        if (!class_exists($fqcn)) {
            require_once $file;
        }

        if (!class_exists($fqcn)) {
            return false;
        }

        $reflection = new \ReflectionClass($fqcn);

        if (!$reflection->implementsInterface(MarkdownBlockInterface::class)) {
            return false;
        }

        $attributes = $reflection->getAttributes(AsMarkdownBlock::class);

        if ([] === $attributes || $reflection->getConstructor()?->getNumberOfRequiredParameters() > 0) {
            return false;
        }

        /** @var AsMarkdownBlock $attr */
        $attr = $attributes[0]->newInstance();

        /** @var MarkdownBlockInterface $instance */
        $instance = $reflection->newInstance();
        $blocks[$attr->priority . '_' . $fqcn] = [$attr->priority, $instance];

        return true;
    }
}
