<?php

declare(strict_types = 1);

namespace Modules\Site\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Kernel;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Site\Application\SiteCopy;
use Modules\Site\Interfaces\HTTP\SiteHomepageSurface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shell\HomepageSurfaceRegistry;

/**
 * SiteHomepageTest — `/` answers the site's own page, in the visitor's language.
 *
 * Measured before this module existed (2026-11-19, the responsive.sk artifact):
 * `/` served "Keep PHP. Drop the framework." and "Under the hood of Nativa",
 * because the landing came from the theme and its copy is partly written into
 * the theme's code. Translating rows could not reach it.
 *
 * Two things this pins that a copy test alone would not:
 *
 *   1. the ORDER of the port — the surface is discovered and used at all, not
 *      merely constructible;
 *   2. the locale — `/en` answers the English copy, which is what makes the
 *      language switcher in the shell honest on the front door.
 */
final class SiteHomepageTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(SiteHomepageSurface::class)) {
            $this->markTestSkipped('Site is not installed — the landing lives in this module.');
        }

        HomepageSurfaceRegistry::reset();
    }

    protected function tearDown(): void
    {
        HomepageSurfaceRegistry::reset();
    }

    #[Test]
    public function theLandingIsTheSitesOwnPageAndNotTheThemesPitch(): void
    {
        $html = $this->page('/');

        self::assertStringContainsString('Weby a aplikácie', $html, 'The Slovak landing copy must render at `/`.');
        self::assertStringContainsString('id="kontakt"', $html, 'The closing call is the page\'s contact target.');
        self::assertStringNotContainsString('Drop the framework', $html, 'The framework pitch is not this site\'s home page.');
        self::assertStringNotContainsString('Under the hood of Nativa', $html);
    }

    #[Test]
    public function theHeadCarriesTheSitesTitleAndItsOwnDescription(): void
    {
        $html = $this->page('/');

        self::assertMatchesRegularExpression(
            '/<title>[^<]* ' . "\u{2014}" . ' weby a aplikácie na mieru<\/title>/',
            $html,
            'The landing title names what the site does, not only the site name.',
        );
        self::assertStringContainsString(
            '<meta name="description" content="Tvoríme weby, webové aplikácie a integrácie na mieru',
            $html,
            'The landing description comes from the surface, not from the site-wide fallback.',
        );
    }

    #[Test]
    public function theHeadDeclaresTheLanguageAlternatesAndTheShareImage(): void
    {
        $html = $this->page('/');

        self::assertStringContainsString(
            '<link rel="alternate" hreflang="sk"',
            $html,
            'A bilingual landing with no `sk` alternate is two languages a crawler cannot pair.',
        );
        self::assertStringContainsString('<link rel="alternate" hreflang="en"', $html);
        self::assertStringContainsString(
            '<link rel="alternate" hreflang="x-default"',
            $html,
            'x-default is what a visitor from an unlisted language is served.',
        );
        self::assertMatchesRegularExpression(
            '/<meta property="og:image" content="http:\/\/[^"]+\/og\/home\.png">/',
            $html,
            'A shared link without og:image shows no image at all.',
        );
    }

    #[Test]
    public function theLandingCarriesThePublishedContactAddress(): void
    {
        $html = $this->page('/');

        self::assertStringContainsString(
            'href="mailto:ahoj@responsive.sk"',
            $html,
            'The closing call is where a visitor writes; an empty contact block is a dead end.',
        );
    }

    /**
     * The stack answers "what would you build this in", so it has to nest:
     * backend in PHP, with Nativa or Laravel under that language. A visitor who
     * reads a flat row of chips learns the vocabulary and not the pairing.
     */
    #[Test]
    public function theStackIsReadTopDownGroupThenLanguageThenTools(): void
    {
        $html = $this->page('/');

        self::assertStringContainsString(
            '<h3 class="site-stack__group-title">Backend</h3>',
            $html,
            'The stack groups what belongs to a layer before naming tools.',
        );
        self::assertStringContainsString('<h3 class="site-stack__group-title">Frontend</h3>', $html);

        $php = self::stackLine($html, 'PHP 8.5');
        foreach (['Nativa', 'Laravel', 'Symfony', 'Nette'] as $tool) {
            self::assertStringContainsString($tool, $php, $tool . ' is a PHP framework and belongs under PHP.');
        }

        $typescript = self::stackLine($html, 'TypeScript');
        foreach (['React', 'Svelte', 'Vue'] as $tool) {
            self::assertStringContainsString($tool, $typescript, $tool . ' belongs under TypeScript, not beside it.');
        }

        self::assertStringContainsString(
            '<span class="site-stack__line-title">SQLite</span>',
            $html,
            'A tool with no family of its own still has to appear.',
        );
        self::assertStringContainsString(
            'Keď sa dáta zmestia do jedného súboru',
            $html,
            'A tool name alone says what we have used, not when we reach for it.',
        );
    }

    /**
     * The blog is the only place that says how a tool was actually used, so the
     * name of a tool the blog has written about is a door to those posts. A name
     * with nothing behind it stays plain text.
     */
    #[Test]
    public function aToolTheBlogHasWrittenAboutOpensThosePosts(): void
    {
        $html = $this->page('/');

        self::assertStringContainsString(
            '<a class="site-stack__item-title site-stack__link" href="/blog/tags/nativa">Nativa</a>',
            $html,
            'Nativa has twelve posts behind it; the name should reach them.',
        );
        self::assertStringContainsString(
            'href="/blog/tags/php">PHP 8.5</a>',
            $html,
            'PHP is the language those posts are written in, so it links too.',
        );
        self::assertStringContainsString(
            'href="/blog/tags/laravel"',
            $html,
            'The Laravel post exists, so the framework name reaches it.',
        );
        self::assertStringNotContainsString(
            'href="/blog/tags/symfony"',
            $html,
            'No post carries that tag; /blog/tags/symfony answers 404.',
        );
    }

    /**
     * The homepage context carries no `blogHref`, so the CTA defaulted to
     * `/blog` in every language and `/en` handed its readers the Slovak posts.
     * The English page has to stay inside `/en`.
     */
    #[Test]
    public function theEnglishLandingKeepsItsBlogLinksInEnglish(): void
    {
        $html = $this->page('/en');

        self::assertStringContainsString(
            'href="/en/blog"',
            $html,
            'The English CTA belongs on the English blog.',
        );
        self::assertStringNotContainsString(
            'class="site-button" href="/blog"',
            $html,
            'The surface\'s own CTA must not fall back to the Slovak blog.',
        );
        self::assertStringNotContainsString(
            '/blog/tags/',
            $html,
            'The English blog has no posts, so tagging nothing is the honest answer.',
        );
    }

    /**
     * A tag link is only as good as the posts behind it: `/blog/tags/<tag>` with
     * nothing tagged answers 404, so the day the last post of a tag is retagged
     * or removed this fails instead of shipping a dead link from the front door.
     */
    #[Test]
    public function everyStackLinkPointsAtATagTheBlogActuallyUses(): void
    {
        $posts = glob(APP_BASE_PATH . '/storage/blog/*.md') ?: [];

        if ([] === $posts) {
            $this->markTestSkipped('No blog posts on this install — the links have nothing to point at yet.');
        }

        $tags = [];
        foreach ($posts as $post) {
            $frontMatter = (string) file_get_contents($post);

            if (preg_match('/^tags:\s*\[(.*?)\]/m', $frontMatter, $match) !== 1) {
                continue;
            }

            foreach (explode(',', $match[1]) as $tag) {
                $tags[trim($tag, " \t\"'")] = true;
            }
        }

        preg_match_all('#href="/blog/tags/([^"]+)"#', $this->page('/'), $matches);
        $links = array_values(array_unique($matches[1]));

        self::assertNotSame([], $links, 'The stack should link the names the blog has written about.');

        foreach ($links as $tag) {
            self::assertArrayHasKey(
                urldecode($tag),
                $tags,
                'Nothing in storage/blog is tagged "' . $tag . '", so that link answers 404.',
            );
        }
    }

    #[Test]
    public function theShareImageTheSurfaceNamesActuallyExists(): void
    {
        self::assertFileExists(APP_BASE_PATH . '/public' . SiteCopy::shareImage(), 'The head points at a file the deployment must serve.');
    }

    /**
     * A translated landing is its own page. Before this, `/en` carried the same
     * canonical and og:url as `/`, so the English page told a crawler it WAS the
     * Slovak one — and a canonical that contradicts `hreflang` wins, taking the
     * English home out of the index while every gate stayed green.
     */
    #[Test]
    public function theCanonicalIsTheLanguagesOwnUrlAndNotTheDefaultOne(): void
    {
        $base = Request::create('GET', '/')->absoluteUrl('/');

        self::assertSame($base, self::canonical($this->page('/')), 'The default locale owns `/`.');
        self::assertSame($base, self::canonical($this->page('/sk')), '`/sk` is an alias of `/`, and says so.');
        self::assertSame(
            $base . 'en/',
            self::canonical($this->page('/en')),
            'The English landing must not claim to be the Slovak one.',
        );
        self::assertStringContainsString(
            '<meta property="og:url" content="' . $base . 'en/">',
            $this->page('/en'),
            'og:url mirrors the canonical; a scraper that disagrees with it shows the wrong language.',
        );
    }

    #[Test]
    public function theEnglishRootRendersTheEnglishCopy(): void
    {
        $html = $this->page('/en');

        self::assertStringContainsString('Websites and apps', $html, '`/en` must answer English copy.');
        self::assertStringNotContainsString('Weby a aplikácie', $html, '`/en` must not answer the Slovak group.');
        self::assertStringContainsString(
            '<html lang="en"',
            $html,
            'English copy under `<html lang="sk">` is a Slovak page as far as a crawler knows.',
        );
    }

    /**
     * The markup under one line title, up to the end of that line — enough to
     * tell whether a tool sits inside the language or somewhere else on the page.
     */
    #[Test]
    public function everyToolCarriesTheMomentWeReachForItInBothLanguages(): void
    {
        $sk = $this->page('/');
        $en = $this->page('/en');

        $slovakNote = 'Keď má aplikácia vydržať roky a nemá so sebou ťahať aj framework.';
        $englishNote = 'When the application has to last for years without dragging a framework along.';

        self::assertStringContainsString($slovakNote, $sk, 'Nativa needs its sentence in Slovak.');
        self::assertStringContainsString($englishNote, $en, 'Nativa needs its sentence in English.');
        self::assertStringNotContainsString($slovakNote, $en, 'The English page must not fall back to the Slovak copy.');
    }

    private static function stackLine(string $html, string $title): string
    {
        preg_match(
            '/<(?:span|a) class="site-stack__line-title[^"]*"(?: href="[^"]*")?>' . preg_quote($title, '/') . '<\/(?:span|a)>(.*?)<\/ul><\/li>/s',
            $html,
            $match,
        );

        self::assertArrayHasKey(1, $match, $title . ' must have a line of its own in the stack.');

        return $match[1];
    }

    private static function canonical(string $html): string
    {
        preg_match('/<link rel="canonical" href="([^"]*)">/', $html, $match);

        return $match[1] ?? '';
    }

    private function page(string $path): string
    {
        $kernel = new Kernel(new Router([APP_BASE_PATH . '/src/Modules']));
        $response = $kernel->handle(Request::create('GET', $path));

        self::assertSame(200, $response->getStatusCode(), $path . ' must render.');

        return $response->getContent();
    }
}
