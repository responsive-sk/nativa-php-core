<?php

declare(strict_types = 1);

namespace Modules\Forms\Application\Services;

use FilesystemIterator;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Forms\Domain\Contracts\FormSubmissionHandler;
use Modules\Forms\Domain\Entities\Form;
use Modules\Forms\Domain\Entities\FormSubmission;
use Modules\Forms\Infrastructure\Attributes\AsFormSubmissionHandler;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Throwable;

/**
 * FormSubmissionHandlerRegistry - discovers handlers in installed modules.
 *
 * The scan is lazy and request-scoped: it runs only when a form submission is
 * accepted. A handler is loaded from an installed slice, so removing the
 * module removes its handler with it and Forms needs no module name.
 *
 * A handler that cannot be loaded or throws is logged and skipped. The form is
 * already stored by then, and one derived record must not turn a successful
 * submission into an error page.
 */
final class FormSubmissionHandlerRegistry
{
    /** @var list<FormSubmissionHandler>|null */
    private ?array $handlers;

    /**
     * @param list<FormSubmissionHandler>|null $handlers null means discover from installed modules
     */
    private function __construct(?array $handlers = null)
    {
        $this->handlers = $handlers;
    }

    public static function fromModules(): self
    {
        return new self();
    }

    /**
     * A fixed registry for tests and explicit wiring.
     *
     * @param list<FormSubmissionHandler> $handlers
     */
    public static function fromHandlers(array $handlers): self
    {
        return new self($handlers);
    }

    public function handle(Form $form, FormSubmission $submission): void
    {
        foreach ($this->handlers() as $handler) {
            try {
                $handler->handle($form, $submission);
            } catch (Throwable $exception) {
                self::logSkipped($handler::class, $exception->getMessage());
            }
        }
    }

    /**
     * @return list<FormSubmissionHandler>
     */
    private function handlers(): array
    {
        return $this->handlers ??= $this->discover();
    }

    /**
     * @return list<FormSubmissionHandler>
     */
    private function discover(): array
    {
        $found = [];

        foreach (self::files() as $file) {
            try {
                $handler = self::load($file);
            } catch (Throwable $exception) {
                self::logSkipped($file, $exception->getMessage());

                continue;
            }

            if (null !== $handler) {
                $found[] = $handler;
            }
        }

        usort(
            $found,
            static fn (array $a, array $b): int => [$a[0], $a[1]::class] <=> [$b[0], $b[1]::class],
        );

        return array_map(static fn (array $entry): FormSubmissionHandler => $entry[1], $found);
    }

    /**
     * Candidate files under every installed module's Application tree. The
     * filename is the filter; no other file is loaded.
     *
     * @return list<string>
     */
    private static function files(): array
    {
        $root = AppPaths::instance()->src('Modules');
        $directories = glob($root . '/*/Application', GLOB_ONLYDIR);

        if (!is_array($directories)) {
            return [];
        }

        $files = [];

        foreach ($directories as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo
                    && $file->isFile()
                    && str_ends_with($file->getFilename(), 'FormSubmissionHandler.php')
                ) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return array{int, FormSubmissionHandler}|null
     */
    private static function load(string $file): ?array
    {
        $content = file_get_contents($file);

        if (!is_string($content) || !str_contains($content, 'AsFormSubmissionHandler')) {
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

        $reflection = new ReflectionClass($fqcn);

        if (!$reflection->implementsInterface(FormSubmissionHandler::class)) {
            return null;
        }

        $attributes = $reflection->getAttributes(AsFormSubmissionHandler::class);

        if ([] === $attributes) {
            return null;
        }

        if ($reflection->getConstructor()?->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        $attribute = $attributes[0]->newInstance();

        /** @var FormSubmissionHandler $instance */
        $instance = $reflection->newInstance();

        return [$attribute->priority, $instance];
    }

    /**
     * Extract the FQCN from a file body, never from prose.
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

    private static function logSkipped(string $class, string $reason): void
    {
        error_log(sprintf('[forms] form submission handler skipped: %s: %s', $class, $reason));
    }
}
