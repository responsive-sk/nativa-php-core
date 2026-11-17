<?php

declare(strict_types = 1);

namespace Modules\Docs\Tests;

use Modules\Docs\Application\Services\DocsCatalog;
use Modules\Core\Domain\MarkRoutePolicy;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Docs\Interfaces\HTTP\Actions\LlmsTxtAction;
use Modules\Core\Interfaces\HTTP\Actions\SitemapAction;
use Modules\Core\Interfaces\HTTP\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * DocsSurfaceDiscoveryTest — the two text surfaces that ADVERTISE URLs, checked
 * against the /docs publication boundary they sit beside.
 *
 * Measured 2026-10-22, before this: `/docs` answered 200 with an empty index
 * while `/llms.txt` advertised it, and `/sitemap.xml` listed neither. Both were
 * defensible on their own — the sitemap lists only INDEXABLE URLs and `/docs`
 * is `noindex`; llms.txt lists the kernel's surfaces and `/docs` is one — but
 * nothing said so anywhere a change would trip over it. This file states the
 * arrangement, so moving one of the three means moving the reason too.
 *
 * @internal
 *
 * @coversNothing
 */
final class DocsSurfaceDiscoveryTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(DocsCatalog::class)) {
            $this->markTestSkipped('Docs is not installed — the surface under test is a Docs module.');
        }
    }

    /**
     * `/docs` is deliberately NOT in the sitemap: the guide surface carries
     * `noindex` and no canonical, and the sitemap's own contract is "every
     * public INDEXABLE URL". A sitemap entry for a noindex page is a crawler
     * instruction that contradicts the page.
     */
    #[Test]
    public function theSitemapListsNoDocumentationUrl(): void
    {
        $body = self::render(new SitemapAction());

        self::assertStringNotContainsString(
            '/docs',
            $body,
            'The sitemap advertises a documentation URL, but /docs is noindex with no canonical.'
            . ' Either stop marking the surface noindex and give it a canonical, or keep it out of'
            . ' the sitemap — the two cannot disagree.',
        );
        self::assertStringContainsString(
            '<urlset',
            $body,
            'The sitemap no longer renders as a urlset, so the assertion above proves nothing.',
        );
    }

    /**
     * `llms.txt` DOES link `/docs`: it is a pointer for language models to a
     * surface the kernel serves, and `noindex` is a crawler-index statement
     * rather than a "do not read" one. The empty state is still a 200 page, so
     * the link never promises a 404.
     */
    #[Test]
    public function llmsTxtAdvertisesTheDocumentationSurface(): void
    {
        $body = self::render(self::llms());

        // The links are absolutized against the request host, so the check is on
        // the path suffix rather than on the markdown placeholder form.
        self::assertMatchesRegularExpression(
            '#\]\([^)\s]*/docs\)#',
            $body,
            'llms.txt no longer links /docs. The surface exists, it answers 200 in every'
            . ' deployment (the empty state is a rendered page, not a 404), and a model reading'
            . ' this file is exactly the reader it is for.',
        );
    }

    /**
     * `llms.txt` is still a kernel text surface with no module contribution: it
     * names no module route, because a kernel-only tree serves none.
     */
    #[Test]
    public function llmsTxtAdvertisesNoModuleRoute(): void
    {
        $body = self::render(self::llms());

        // The admin prefix is spelled by its owner, not retyped here: this
        // test reads the surface, and a second `'/mark'` in the tree is
        // exactly the duplication `NameOwnershipContractTest` ratchets.
        foreach (['/blog', MarkRoutePolicy::prefix(), '/authors'] as $moduleRoute) {
            self::assertStringNotContainsString(
                $moduleRoute,
                $body,
                sprintf('llms.txt advertises "%s", which is a module route.', $moduleRoute),
            );
        }
    }

    /**
     * The sitemap MAY name a module route now, but only because the installed
     * module contributes it. The two claims that remain true in both trees are
     * that the admin prefix is absent and that the noindex docs surface is absent.
     */
    #[Test]
    public function theSitemapNamesNoAdminOrDocumentationRoute(): void
    {
        $body = self::render(new SitemapAction());

        self::assertStringNotContainsString(MarkRoutePolicy::prefix(), $body);
        self::assertStringNotContainsString('/docs', $body);
    }

    /**
     * The catalogue's own keys are the only documentation URLs either surface
     * could legitimately name — asserted here so the test fails loudly if a
     * future map publishes a key the text surfaces were not updated for.
     */
    #[Test]
    public function theCatalogueStillPublishesGuidesWorthAdvertising(): void
    {
        $keys = (new DocsCatalog())->keys();

        self::assertNotSame([], $keys, 'PUBLISHED is empty — llms.txt would advertise a surface with nothing behind it.');
        self::assertContains('intro', $keys, 'The guide the index opens with is no longer published.');
    }

    /**
     * The llms.txt surface with this tree's routes, so the docs link is present
     * only because the Docs module is installed here.
     */
    private static function llms(): LlmsTxtAction
    {
        return new LlmsTxtAction(new Router([APP_BASE_PATH . '/src/Modules']));
    }

    private static function render(object $action): string
    {
        $method = new ReflectionMethod($action, 'handle');

        $response = $method->invoke($action, Request::create('GET', '/'));

        self::assertInstanceOf(Response::class, $response);

        return $response->getContent();
    }
}
