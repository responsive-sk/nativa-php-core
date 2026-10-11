<?php

declare(strict_types = 1);

namespace Modules\Core\Application\Services\View;

use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * Paths — src/-relative resolution for the view helpers.
 *
 * One place where the view helpers resolve asset/template files, so the
 * test suite can redirect the base without touching AppPaths (whose base is
 * the immutable APP_BASE_PATH bootstrap constant).
 */
final class Paths
{
    /** @internal test seam — base directory override (null = real AppPaths) */
    private static ?string $override = null;

    /** @internal test seam — redirect resolution to a sandbox base. */
    public static function overrideBase(?string $base): void
    {
        self::$override = $base;
    }

    /**
     * Absolute path of a src/-relative file (e.g. 'Themes/erik/assets/
     * critical/hero.css').
     */
    public static function src(string $srcRelative): string
    {
        $base = self::$override ?? AppPaths::instance()->base();

        return $base . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $srcRelative);
    }
}
