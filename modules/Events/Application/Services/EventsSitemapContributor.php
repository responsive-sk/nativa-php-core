<?php

declare(strict_types = 1);

namespace Modules\Events\Application\Services;

use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;
use Modules\Core\Domain\Contracts\SitemapContributor;
use Modules\Core\Domain\SitemapEntry;
use Modules\Core\Infrastructure\Attributes\AsSitemapContributor;
use Modules\Events\Application\EventsServices;

/**
 * EventsSitemapContributor: the programme's rows for `/sitemap.xml`.
 *
 * The listing is served for every known locale even when it is empty. A detail
 * is listed for every published event, including a past one: a past event is
 * still a readable record. A draft is absent.
 */
#[AsSitemapContributor(priority: 0)]
final readonly class EventsSitemapContributor implements SitemapContributor
{
    public function entries(): array
    {
        $mapper = ContentLocaleServices::mapper();
        $entries = [];

        foreach ($mapper->locales() as $locale) {
            $prefix = $mapper->urlPrefix($locale);
            $entries[] = new SitemapEntry($prefix . '/events', 'weekly', '0.8');

            foreach (EventsServices::events()->listForLocale($locale) as $event) {
                if (!$event->published) {
                    continue;
                }

                $entries[] = new SitemapEntry(
                    $prefix . '/events/' . $event->slug,
                    SitemapEntry::DEFAULT_CHANGEFREQ,
                    '0.6',
                );
            }
        }

        return $entries;
    }
}
