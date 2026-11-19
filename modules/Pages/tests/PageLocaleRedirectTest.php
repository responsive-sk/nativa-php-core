<?php

declare(strict_types = 1);

namespace Modules\Pages\Tests;

use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Pages\Application\Services\PageCrudService;
use Modules\Pages\Interfaces\HTTP\Actions\PageViewAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PageLocaleRedirectTest — the canonical slug, asked for under the other
 * language, answers the page's OWN slug there.
 *
 * A menu row is stored once — `/referencie` — and the shell renders it under
 * the prefix of the page the reader is on (`LocalizedNav`). The localized URL
 * of a translated page is NOT the prefixed canonical slug: the pair lives in
 * front matter (`translation:`), and `/referencie` is paired with
 * `/en/references`. Prefixing the canonical slug alone produced a 404, so a
 * menu pointing at a page would have been a dead link in the other language.
 *
 * The negatives are half the contract: only the canonical slug redirects, and
 * only to a PUBLISHED translation — a 301 that lands on a 404 is a broken
 * promise.
 */
final class PageLocaleRedirectTest extends TestCase
{
    private PageCrudService $pages;

    /** @var list<array{string, string}> */
    private array $saved = [];

    private string $canonical;

    private string $other;

    protected function setUp(): void
    {
        if (!class_exists(PageCrudService::class)) {
            $this->markTestSkipped('Pages is not installed — the redirect ships with this module.');
        }

        $this->pages = new PageCrudService();
        $this->canonical = ContentLocaleServices::mapper()->defaultLocale();

        foreach (ContentLocaleServices::mapper()->locales() as $code) {
            if ($code !== $this->canonical) {
                $this->other = $code;

                break;
            }
        }

        if (!isset($this->other)) {
            $this->markTestSkipped('A single-locale site has no second language to redirect into.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as [$slug, $locale]) {
            $this->pages->delete($slug, $locale);
        }
    }

    #[Test]
    public function theCanonicalSlugRedirectsToItsTranslatedSlug(): void
    {
        [$canonicalSlug, $translatedSlug] = $this->pair(published: true);

        $response = $this->view($canonicalSlug, $this->other);

        $this->assertSame(301, $response->getStatusCode(), 'The canonical slug must move the reader, not 404.');
        $this->assertSame(
            ContentLocaleServices::mapper()->urlPrefix($this->other) . '/' . $translatedSlug,
            $response->getHeader('Location'),
            'The redirect must land on the paired slug, not on the prefixed canonical one.',
        );
    }

    #[Test]
    public function anUnpublishedTranslationIsNotAdvertised(): void
    {
        [$canonicalSlug] = $this->pair(published: false);

        $response = $this->view($canonicalSlug, $this->other);

        $this->assertSame(404, $response->getStatusCode(), 'A 301 that lands on a 404 is a broken promise.');
        $this->assertNull($response->getHeader('Location'));
    }

    #[Test]
    public function aPageWithoutAPairStillDoesNotExistThere(): void
    {
        $slug = 'locale-probe-' . bin2hex(random_bytes(4));
        $this->saved[] = [$slug, $this->canonical];

        $this->pages->save($slug, ['title' => 'Unpaired page', 'published' => true], 'Body.', $this->canonical);

        $response = $this->view($slug, $this->other);

        $this->assertSame(404, $response->getStatusCode(), 'A page with no translation does not exist in the other language.');
        $this->assertNull($response->getHeader('Location'));
    }

    /**
     * @return array{string, string} [canonical slug, slug in the other locale]
     */
    private function pair(bool $published): array
    {
        $suffix = bin2hex(random_bytes(4));
        $canonicalSlug = 'locale-probe-' . $suffix;
        $translatedSlug = 'locale-probe-en-' . $suffix;

        $this->saved = [[$canonicalSlug, $this->canonical], [$translatedSlug, $this->other]];

        $this->pages->save($canonicalSlug, [
            'title'       => 'Locale probe',
            'published'   => true,
            'translation' => $translatedSlug,
        ], 'Canonical body.', $this->canonical);

        $this->pages->save($translatedSlug, [
            'title'       => 'Locale probe',
            'published'   => $published,
            'translation' => $canonicalSlug,
        ], 'Translated body.', $this->other);

        return [$canonicalSlug, $translatedSlug];
    }

    /**
     * The action as the router calls it: the locale middleware has already
     * stripped the prefix, so the path is the bare slug and the resolved
     * locale travels as an attribute.
     */
    private function view(string $slug, string $locale): \Modules\Core\Infrastructure\Http\Response
    {
        $request = Request::create('GET', '/' . $slug);
        $request->setAttribute('locale', $locale);
        $request->setRouteParams(['slug' => $slug]);

        return (new PageViewAction())->handle($request);
    }
}
