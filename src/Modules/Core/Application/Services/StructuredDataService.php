<?php

declare(strict_types = 1);

namespace Modules\Core\Application\Services;

/**
 * StructuredDataService — JSON-LD structured data builders (schema.org).
 *
 * Each method returns a ready-to-inline JSON string for the
 * <script type="application/ld+json"> tag in FrontendLayout. JSON is encoded
 * with JSON_HEX_TAG so a `</script>` sequence inside any user-provided
 * value can never break out of the script element (XSS-safe by
 * construction), plus JSON_UNESCAPED_SLASHES/UNICODE for readable output.
 *
 * Callers pass absolute URLs (Request::absoluteUrl) so the markup matches
 * the canonical/hreflang signals already emitted on the same page.
 */
final class StructuredDataService
{
    /**
     * Organization + WebSite for the homepage, including a SearchAction so
     * Google can offer site search rich results (target /blog?q=…).
     */
    public static function website(string $name, string $url): string
    {
        $searchUrl = $url . 'blog?q={search_term_string}';

        return self::encode([
            [
                '@context' => 'https://schema.org',
                '@type'    => 'Organization',
                'name'     => $name,
                'url'      => $url,
            ],
            [
                '@context'      => 'https://schema.org',
                '@type'         => 'WebSite',
                'name'          => $name,
                'url'           => $url,
                'potentialAction' => [
                    '@type'       => 'SearchAction',
                    'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => $searchUrl],
                    'query-input' => 'required name=search_term_string',
                ],
            ],
        ]);
    }

    /**
     * Article + BreadcrumbList for a blog article page. $published is the
     * front-matter date (YYYY-MM-DD), $image an absolute cover URL or null.
     */
    public static function article(
        string $title,
        string $description,
        string $url,
        string $published,
        ?string $image,
        string $authorName,
        string $siteName,
    ): string {
        $article = [
            '@context'         => 'https://schema.org',
            '@type'            => 'Article',
            'headline'         => $title,
            'description'      => $description,
            'datePublished'    => $published,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'author'           => ['@type' => 'Person', 'name' => $authorName],
            'publisher'        => ['@type' => 'Organization', 'name' => $siteName],
        ];

        if (null !== $image) {
            $article['image'] = $image;
        }

        // Article URLs always end with /{slug}, so the blog index is the
        // URL up to the last slash (https://host/blog/slug → https://host/blog,
        // https://host/en/blog/slug → https://host/en/blog).
        $blogUrl = (string) substr($url, 0, (int) strrpos($url, '/'));

        $breadcrumb = [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Blog', 'item' => $blogUrl],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $title],
            ],
        ];

        return self::encode([$article, $breadcrumb]);
    }

    /**
     * Person for an author profile page.
     */
    public static function person(string $name, string $url): string
    {
        return self::encode([
            '@context' => 'https://schema.org',
            '@type'    => 'Person',
            'name'     => $name,
            'url'      => $url,
        ]);
    }

    /**
     * JSON-encode with script-context safety flags.
     *
     * @param array<array-key, mixed> $data
     */
    private static function encode(array $data): string
    {
        return (string) json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        );
    }
}
