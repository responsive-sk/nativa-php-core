<?php

declare(strict_types = 1);

namespace Modules\Blog\Application\Services;

use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\HTTP\ContentServices;

/**
 * CoverPathResolver — which locale an article belongs to, and where its
 * cover file goes.
 *
 * Two rules live here, and both used to live as private helpers of the
 * `generate-images` command, where nothing could test them:
 *
 *  1. The locale of a content file comes from the mapper. The family root is
 *     owned by `settings.locale` and every other locale gets a `{lang}`
 *     subdirectory, so "which locale is this file?" is a question only the
 *     mapper can answer. The command used to answer it with
 *     `in_array($dirLocale, ['sk', 'en'], true) ? $dirLocale : 'sk'` — a
 *     hardcoded Slovak default that would silently mislabel a cover the day a
 *     third locale appears.
 *
 *  2. The flat `blog-images/{slug}.svg` can hold exactly one locale's
 *     identity, so a slug that exists in two locales gets one file per
 *     locale under `blog-images/{lang}/`. Every other slug stays flat. The
 *     collision check compared `mapper()->locales()` (which returns full
 *     codes like `sk_SK`) against the directory code (`sk`), so the
 *     `continue` never fired, the article's OWN directory counted as "another
 *     locale", and every cover in the repo was written locale-qualified —
 *     quietly defeating the rule the method documents.
 */
final class CoverPathResolver
{
    /**
     * The locale code (`sk`, `en`, …) a content file belongs to.
     *
     * A file in the family root is the configured default locale; a file in a
     * `{lang}` subdirectory is that language. An unknown directory falls back
     * to the default rather than guessing.
     */
    public function localeFor(string $file): string
    {
        $mapper = ContentServices::mapper();
        $directory = dirname($file);

        foreach ($mapper->locales() as $locale) {
            if ($this->directory($locale) === $directory) {
                return $mapper->languageCode($locale);
            }
        }

        return $mapper->languageCode($mapper->defaultLocale());
    }

    /**
     * Collision-proof output path: flat for a unique slug, one file per
     * locale for a slug two locales share.
     */
    public function pathFor(string $fileSlug, string $locale): string
    {
        $root = AppPaths::instance()->storage('blog-images');

        return $this->collides($fileSlug, $locale)
            ? $root . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . $fileSlug . '.svg'
            : $root . DIRECTORY_SEPARATOR . $fileSlug . '.svg';
    }

    /**
     * Does this slug exist in another locale's content directory?
     *
     * The comparison is by LANGUAGE CODE on both sides: the mapper's locale
     * codes and the code of the article being written have to mean the same
     * thing, or the article matches itself.
     */
    public function collides(string $fileSlug, string $locale): bool
    {
        $mapper = ContentServices::mapper();

        foreach ($mapper->locales() as $candidate) {
            if ($mapper->languageCode($candidate) === $locale) {
                continue;
            }

            $file = $this->directory($candidate) . DIRECTORY_SEPARATOR . $fileSlug . '.md';

            if (is_file($file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Absolute content directory of one locale.
     */
    private function directory(string $locale): string
    {
        return AppPaths::instance()->storage(
            str_replace('/', DIRECTORY_SEPARATOR, ContentServices::mapper()->directory('blog', $locale)),
        );
    }
}
