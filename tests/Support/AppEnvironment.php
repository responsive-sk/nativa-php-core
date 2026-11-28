<?php

declare(strict_types = 1);

namespace Tests\Support;

use Modules\Core\Infrastructure\Env;

/**
 * AppEnvironment pins APP_ENV for a test whose subject is its answer.
 *
 * `Env::get()` reads its cache, then `$_ENV`, then `$_SERVER`, then the
 * process environment, and a local `.env` has already filled the first three.
 * A test that only calls `putenv()` keeps seeing the deployment's value, which
 * is how the same suite can be green on a checkout with a `.env` and red in an
 * export that carries none. Pinning all three layers makes the premise the
 * test's own, and `restore()` in `tearDown()` puts the process back.
 *
 * The guard matters: PHPUnit runs `tearDown()` after a skipped `setUp()`, so a
 * test that never pinned must not clear the environment it inherited.
 */
final class AppEnvironment
{
    /** The APP_ENV value before the test, or null when it was unset. */
    private static ?string $previous = null;

    /** Whether pin() ran, so a skipped setUp restores nothing. */
    private static bool $pinned = false;

    public static function pin(string $value = 'production'): void
    {
        if (self::$pinned) {
            return;
        }

        $previous = getenv('APP_ENV');
        self::$previous = false === $previous ? null : $previous;

        Env::reset();
        putenv('APP_ENV=' . $value);
        $_ENV['APP_ENV'] = $value;
        $_SERVER['APP_ENV'] = $value;
        self::$pinned = true;
    }

    public static function restore(): void
    {
        if (!self::$pinned) {
            return;
        }

        Env::reset();

        if (null === self::$previous) {
            putenv('APP_ENV');
            unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
        } else {
            putenv('APP_ENV=' . self::$previous);
            $_ENV['APP_ENV'] = self::$previous;
            $_SERVER['APP_ENV'] = self::$previous;
        }

        self::$pinned = false;
    }
}
