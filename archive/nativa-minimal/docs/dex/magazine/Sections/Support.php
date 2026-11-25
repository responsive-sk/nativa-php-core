<?php

declare(strict_types = 1);

namespace Themes\magazine\Sections;

use Modules\Blog\Domain\Entities\Article;
use Modules\Locales\Interfaces\HTTP\LocalesServices;

/**
 * Shared rendering vocabulary for the magazine section classes —
 * translation accessor, date/locale helpers and tag collection.
 *
 * Kept internal to the theme: the public API surface stays on the Renderer
 * facade, which delegates here one-to-one.
 */
final class Support
{
    /**
     * Magazine UI copy — translation system with an EN fallback default
     * (the seeded mag.* keys live in locales.db; the default keeps pages
     * readable when a key is missing).
     */
    public static function t(string $key, string $default): string
    {
        return LocalesServices::t($key, $default);
    }

    /**
     * Locale-neutral date format (numeric — no English month names on
     * Slovak pages). Falls back to the raw value when unparseable.
     */
    public static function formatDate(string $date): string
    {
        $ts = strtotime($date);

        return false === $ts ? $date : date('j. n. Y', $ts);
    }

    public static function articleHref(Article $article): string
    {
        return ('en_US' === $article->locale ? '/en' : '') . '/blog/' . $article->slug;
    }

    /**
     * Unique tags across articles, sorted by name.
     *
     * @param list<Article> $articles
     *
     * @return list<string>
     */
    public static function allTags(array $articles): array
    {
        $tags = [];

        foreach ($articles as $article) {
            foreach ($article->tags as $tag) {
                $tags[$tag] = true;
            }
        }

        $list = array_keys($tags);
        sort($list, SORT_STRING | SORT_FLAG_CASE);

        return $list;
    }
}
