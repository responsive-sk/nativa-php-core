<?php

declare(strict_types = 1);

namespace Modules\Core\Domain\Contracts;

use Modules\Core\Domain\SitemapEntry;

/**
 * SitemapContributor — a module's own rows for the kernel sitemap.
 *
 * The interface deliberately has no request argument: a contributor answers
 * from the content it owns and the locale mapping it already renders with.
 * `SitemapAction` owns the absolute URL, the XML shape and the response.
 */
interface SitemapContributor
{
    /**
     * Every public, indexable path this module serves.
     *
     * @return list<SitemapEntry>
     */
    public function entries(): array;
}
