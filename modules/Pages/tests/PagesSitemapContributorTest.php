<?php

declare(strict_types = 1);

namespace Modules\Pages\Tests;

use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;
use Modules\Pages\Application\Services\PageCrudService;
use Modules\Pages\Application\Services\PagesSitemapContributor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Seo\SitemapEntry;

final class PagesSitemapContributorTest extends TestCase
{
    private PageCrudService $pages;

    /** @var list<array{string, string}> */
    private array $saved = [];

    private string $locale;

    protected function setUp(): void
    {
        if (!class_exists(PageCrudService::class)) {
            $this->markTestSkipped('Pages is not installed — the contributor ships with this module.');
        }

        $this->pages = new PageCrudService();
        $this->locale = ContentLocaleServices::mapper()->defaultLocale();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as [$slug, $locale]) {
            $this->pages->delete($slug, $locale);
        }
    }

    #[Test]
    public function itListsPublishedPages(): void
    {
        self::assertContains(
            ContentLocaleServices::mapper()->urlPrefix($this->locale) . '/about',
            self::paths(),
        );
    }

    #[Test]
    public function aPublishedPageIsListedButItsAliasAndDraftAreNot(): void
    {
        $slug = 'sitemap-probe-' . bin2hex(random_bytes(4));
        $draft = 'sitemap-draft-' . bin2hex(random_bytes(4));
        $alias = 'sitemap-old-' . bin2hex(random_bytes(4));
        $english = 'sitemap-en-' . bin2hex(random_bytes(4));

        $this->saved = [[$slug, $this->locale], [$draft, $this->locale], [$english, 'en_US']];
        $this->pages->save($slug, [
            'title'     => 'Sitemap probe',
            'published' => true,
            'aliases'   => [$alias],
        ], 'Probe body.', $this->locale);
        $this->pages->save($draft, [
            'title'     => 'Sitemap draft',
            'published' => false,
        ], 'Draft body.', $this->locale);
        $this->pages->save($english, [
            'title'     => 'Sitemap English page',
            'published' => true,
        ], 'English body.', 'en_US');

        $prefix = ContentLocaleServices::mapper()->urlPrefix($this->locale);
        $paths = self::paths();

        self::assertContains($prefix . '/' . $slug, $paths);
        self::assertContains('/en/' . $english, $paths);
        self::assertNotContains($prefix . '/' . $alias, $paths);
        self::assertNotContains($prefix . '/' . $draft, $paths);
    }

    /**
     * @return list<string>
     */
    private static function paths(): array
    {
        return array_map(
            static fn (SitemapEntry $entry): string => $entry->path,
            (new PagesSitemapContributor())->entries(),
        );
    }
}
