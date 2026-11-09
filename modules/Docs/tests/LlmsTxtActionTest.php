<?php

declare(strict_types = 1);

namespace Modules\Docs\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Actions\RobotsAction;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Docs\Interfaces\HTTP\Actions\LlmsTxtAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * LlmsTxtActionTest — llms.txt must not advertise a route the tree does not have.
 *
 * Docs is a module. A kernel-only export carries no Docs slice, so `/docs` is a
 * 404 there; a link in llms.txt would teach a language model to fetch it. The
 * action asks the router instead of assuming the module is present.
 *
 * @internal
 *
 * @coversNothing
 */
final class LlmsTxtActionTest extends TestCase
{
    #[Test]
    public function aTreeWithoutTheDocsRouteDoesNotAdvertiseIt(): void
    {
        $body = self::render(new Router([], false));

        self::assertStringNotContainsString(
            '/docs',
            $body,
            'llms.txt advertised /docs in a tree with no Docs module — the link is a 404.',
        );
        self::assertStringContainsString('/sitemap.xml', $body, 'The fixed surfaces left the document.');
    }

    #[Test]
    public function aTreeWithTheDocsRouteAdvertisesIt(): void
    {
        $router = new Router([], false);
        $router->register(new Route(path: '/docs', method: 'GET', name: 'docs-index'), \stdClass::class);

        $body = self::render($router);

        self::assertStringContainsString(
            '/docs',
            $body,
            'The Docs route is installed, so llms.txt must point a model at it.',
        );
    }

    #[Test]
    public function robotsOmitsLlmsWhenTheDocsModuleIsNotInstalled(): void
    {
        $body = (new RobotsAction(new Router([], false)))
            ->handle(Request::create('GET', '/robots.txt'))
            ->getContent();

        self::assertStringNotContainsString(
            'Allow: /llms.txt',
            $body,
            'robots.txt advertised /llms.txt while the route was absent.',
        );
        self::assertStringContainsString('Allow: /sitemap.xml', $body, 'The fixed surfaces left robots.txt.');
    }

    #[Test]
    public function robotsAdvertisesLlmsWhenTheDocsModuleIsInstalled(): void
    {
        $router = new Router([], false);
        $router->register(new Route(path: '/llms.txt', method: 'GET', name: 'llms-txt'), \stdClass::class);

        $body = (new RobotsAction($router))
            ->handle(Request::create('GET', '/robots.txt'))
            ->getContent();

        self::assertStringContainsString('Allow: /llms.txt', $body);
    }

    private static function render(Router $router): string
    {
        $response = (new LlmsTxtAction($router))->handle(Request::create('GET', '/llms.txt'));

        return $response->getContent();
    }
}
