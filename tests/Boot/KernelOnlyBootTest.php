<?php

declare(strict_types = 1);

namespace Tests\Boot;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Kernel;
use Modules\Core\Interfaces\HTTP\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * KernelOnlyBootTest (task 2.3) — the six content surfaces, dispatched
 * through the Kernel, never by calling an action directly.
 *
 * Only STATUS codes are asserted: with Mono installed / answers with Mono's
 * shell, kernel-only with the kernel homepage — both are 200, and which
 * theme renders is ThemeKernel's business, not the boot contract's.
 */
final class KernelOnlyBootTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function surfaces(): iterable
    {
        yield 'homepage'   => ['GET', '/',            200];
        yield 'sitemap'    => ['GET', '/sitemap.xml', 200];
        yield 'robots'     => ['GET', '/robots.txt',  200];
        yield 'docs index' => ['GET', '/docs',        200];
        yield 'unknown'    => ['GET', '/nope',        404];
        yield 'module 404' => ['GET', '/mark',        404];
    }

    #[Test]
    #[DataProvider('surfaces')]
    public function surfaceAnswersThroughTheKernel(string $method, string $uri, int $expected): void
    {
        $kernel = new Kernel(new Router([APP_BASE_PATH . '/src/Modules']));

        $response = $kernel->handle(Request::create($method, $uri));

        $this->assertSame($expected, $response->getStatusCode(), sprintf('%s %s', $method, $uri));
    }
}
