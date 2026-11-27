<?php

declare(strict_types = 1);

namespace Modules\Pages\Application\Services;

use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;
use Modules\Core\Infrastructure\Attributes\AsSitemapContributor;
use Modules\Core\Domain\Contracts\SitemapContributor;
use Modules\Core\Domain\SitemapEntry;

/**
 * PagesSitemapContributor — the published pages' rows for `/sitemap.xml`.
 *
 * It reads the same page list the public catch-all resolves and keeps only
 * `published: true`. An alias is a 301 source, not a canonical URL, and is
 * therefore never listed; only the page's current slug is.
 */
#[AsSitemapContributor(priority: 0)]
final readonly class PagesSitemapContributor implements SitemapContributor
{
    /**
     * @return list<SitemapEntry>
     */
    public function entries(): array
    {
        $mapper = ContentLocaleServices::mapper();
        $service = new PageCrudService();
        $entries = [];

        foreach ($mapper->locales() as $locale) {
            $prefix = $mapper->urlPrefix($locale);

            foreach ($service->list($locale) as $page) {
                if (true !== $page['published']) {
                    continue;
                }

                $entries[] = new SitemapEntry(
                    $prefix . '/' . $page['slug'],
                    SitemapEntry::DEFAULT_CHANGEFREQ,
                    '0.6',
                );
            }
        }

        return $entries;
    }
}
