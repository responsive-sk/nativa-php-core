<?php

declare(strict_types = 1);

namespace Tests\Http;

use Modules\Core\Application\Services\DocsCatalog;
use Modules\Core\Application\Services\DocsSearchService;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Actions\DocsIndexAction;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * DocsSearchTest — `GET /docs?q=` answers from the published guides, and the
 * header's field points at it while a reader is inside `/docs`.
 *
 * The surface had no search until now, so the header's field — which is the
 * theme's markup and searches `storage/blog` — sent a reader looking for a guide
 * into the articles, where the guide is not a document at all. Two things are
 * checked here because two things can regress separately: the answer (which
 * documents come back) and the destination (which surface the field submits to).
 *
 * @internal
 *
 * @coversNothing
 */
final class DocsSearchTest extends TestCase
{
    /** The theme-test idiom: the theme is named once, as every theme test names it. */
    private const string THEME = 'boson';

    /**
     * A word from a guide's body comes back with that guide's own URL, and
     * nothing that is not in `PUBLISHED` can appear in the list: the search and
     * the index are two views of one publication boundary, not two lists.
     */
    #[Test]
    public function aQueryAnswersFromThePublishedGuides(): void
    {
        $body = self::search('guide');

        self::assertMatchesRegularExpression(
            '#href="/docs/[a-z0-9/._-]+"#',
            $body,
            'The query returned no guide links at all, so the page proves nothing about the search.',
        );

        preg_match_all('#href="/docs/([a-z0-9/._-]+)"#', $body, $matches);

        $published = (new DocsCatalog())->keys();

        foreach ($matches[1] as $key) {
            self::assertContains(
                $key,
                $published,
                sprintf(
                    '"%s" came back from a query but is not a published key. A document nobody'
                    . ' published cannot be a page — and it cannot be a search hit either.',
                    $key,
                ),
            );
        }
    }

    /**
     * A source that matches nothing says so in its own words. The theme's index
     * empty state ("No guides are published in this deployment.") is true of an
     * empty deployment and false of a miss, which is why the results are composed
     * in the kernel's own vocabulary.
     */
    #[Test]
    public function aQueryWithNoHitsSaysSo(): void
    {
        $body = self::search('xyzzyplughqqq');

        self::assertStringContainsString('No guide matches', $body);
        self::assertStringNotContainsString(
            'No guides are published in this deployment.',
            $body,
            'A miss rendered the empty-deployment sentence, which tells the reader the guides are'
            . ' gone instead of that their word is not in them.',
        );
    }

    /**
     * The field comes back with the query in it, escaped. A search box that
     * renders its own input unescaped is an injection point with a form around
     * it, and a box that drops the query makes a reader retype it to narrow a
     * miss.
     */
    #[Test]
    public function theFieldKeepsTheQueryAndEscapesIt(): void
    {
        $body = self::search('prose"><script>alert(1)</script>');

        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
        self::assertMatchesRegularExpression(
            '#<input[^>]*name="q"[^>]*value="[^"]*prose#',
            $body,
            'The field lost the query it was asked to narrow.',
        );
    }

    /**
     * The tokens are extracted here, so a query full of FTS syntax is a query of
     * words and nothing more: it neither throws nor turns into "match
     * everything", which is what an operator reaching the parser would do.
     */
    #[Test]
    public function syntaxInTheQueryIsOnlyPunctuation(): void
    {
        $service = new DocsSearchService(new DocsCatalog());

        self::assertSame(
            count($service->search('guide plugin')),
            count($service->search('"* OR (NOT guide) AND plugin')),
            'A query with FTS syntax and the same words in plain prose must answer the same;'
            . ' anything else means an operator reached something that parsed it.',
        );
        self::assertNotSame(0, count($service->search('guide')), 'The control query finds nothing, so the comparison above is between two empty lists.');
    }

    /**
     * Inside `/docs` the header's field submits to `/docs`; everywhere else it
     * keeps the destination the theme shipped with. The theme is a copy of
     * `nativa-themes` (`make themes-check`), so this routing lives in the kernel.
     */
    #[Test]
    public function theHeaderFieldFollowsTheSection(): void
    {
        $docsPath = FrontendLayout::render('t', 'c', currentPath: '/docs', theme: self::THEME);
        $blogPath = FrontendLayout::render('t', 'c', currentPath: '/blog', theme: self::THEME);

        self::assertStringContainsString('action="/docs" role="search"', $docsPath);
        self::assertStringNotContainsString('action="/blog" role="search"', $docsPath);
        self::assertStringContainsString('action="/blog" role="search"', $blogPath);
        self::assertStringNotContainsString('action="/docs" role="search"', $blogPath);
    }

    /**
     * The hits come straight after the count, under one heading.
     *
     * The theme's `docsIndex()` is the INDEX: it opens with a page head that
     * reads "Documentation" whatever was typed, and its block carries the page's
     * top padding, the head's own padding and the list's margin. Composed under
     * the kernel's summary that put a second `<h1>` over the results and more than
     * three hundred pixels of air between the count and the first row, so a reader
     * had to scroll to learn whether the word matched at all. This pins the
     * opposite: one heading on the page, and the first hit inside the summary's
     * own section.
     */
    #[Test]
    public function theHitsFollowTheCountUnderOneHeading(): void
    {
        $body = self::search('guide');

        self::assertSame(
            1,
            substr_count($body, '<h1'),
            'The search page carries a second top-level heading, which is the theme index composed under the summary.',
        );
        self::assertStringContainsString('Search</h1>', $body);
        self::assertStringContainsString('guides match', $body);
        // The class names are asserted with their `class="…"` context: the
        // theme's stylesheet is inlined into the page, so a bare `.bo-page` or
        // `bo-docs__item` search would hit the CSS rather than the markup.
        self::assertStringNotContainsString('class="bo-page bo-docs"', $body, 'The theme page head is the index surface, not a search page.');
        self::assertStringNotContainsString('class="bo-docs__item"', $body, 'The index cards are the index surface, not a search page.');

        $summary = strpos($body, 'guides match');
        $firstHit = strpos($body, '<div class="bo-article__body"><ul>');

        self::assertIsInt($summary);
        self::assertIsInt($firstHit);
        self::assertGreaterThan($summary, $firstHit, 'The hits render before the count.');
    }

    /**
     * The index carries the field too, so the search is discoverable from the
     * page a reader lands on rather than only from the header above it.
     */
    #[Test]
    public function theIndexCarriesItsOwnField(): void
    {
        $method = new ReflectionMethod(DocsIndexAction::class, 'handle');
        $response = $method->invoke(new DocsIndexAction(), Request::create('GET', '/docs'));

        self::assertInstanceOf(Response::class, $response);

        $body = $response->getContent();

        self::assertStringContainsString('action="/docs" role="search"', $body);
        self::assertStringContainsString(
            '<style nonce="__CSP_NONCE_PLACEHOLDER__">',
            $body,
            'The index trims its own gaps with an inline rule set; without the nonce placeholder,
            style-src blocks it and the block is 40 px lower than measured.',
        );
        self::assertStringContainsString(
            '<div data-docs-index>',
            $body,
            'The rule set is keyed on this wrapper, so dropping it leaves the rules matching nothing.',
        );
        self::assertMatchesRegularExpression(
            '#<input[^>]*name="q"#',
            $body,
            'The index rendered no field of its own, so the guides are only searchable from the'
            . ' header — which a reader on a phone has to open a menu to reach.',
        );
    }

    /** The query as the action answers it, through its own route. */
    private static function search(string $query): string
    {
        $method = new ReflectionMethod(DocsIndexAction::class, 'handle');
        $response = $method->invoke(
            new DocsIndexAction(),
            Request::create('GET', '/docs', ['q' => $query]),
        );

        self::assertInstanceOf(Response::class, $response);

        return $response->getContent();
    }
}
