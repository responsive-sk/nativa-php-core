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
 * Only STATUS codes are asserted: which theme renders a 200 is
 * ThemeKernel's business, not the boot contract's.
 *
 * `/mark` is the one path whose expected status depends on operator state,
 * and the dependency is written down instead of assumed: the admin app is a
 * module, so the kernel-only tree answers 404 (no route — not 403, not 500)
 * and a tree with Cardboard installed answers 200. Both cases assert the
 * same kernel rule separately below: a path the kernel has no route for is
 * never Forbidden.
 */
final class KernelOnlyBootTest extends TestCase
{
    private const ADMIN_MODULE = 'Cardboard';

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function surfaces(): iterable
    {
        $adminInstalled = is_dir(APP_BASE_PATH . '/src/Modules/' . self::ADMIN_MODULE);

        yield 'homepage'   => ['GET', '/',            200];
        yield 'sitemap'    => ['GET', '/sitemap.xml', 200];
        yield 'robots'     => ['GET', '/robots.txt',  200];
        yield 'docs index' => ['GET', '/docs',        200];
        yield 'unknown'    => ['GET', '/nope',        404];
        yield 'admin'      => ['GET', '/mark',        $adminInstalled ? 200 : 404];
    }

    #[Test]
    #[DataProvider('surfaces')]
    public function surfaceAnswersThroughTheKernel(string $method, string $uri, int $expected): void
    {
        $kernel = new Kernel(new Router([APP_BASE_PATH . '/src/Modules']));

        $response = $kernel->handle(Request::create($method, $uri));

        $this->assertSame($expected, $response->getStatusCode(), sprintf('%s %s', $method, $uri));
    }

    /**
     * The kernel-only promise, independent of what is installed: a route the
     * tree does not have is NOT FOUND. Answering 403 would mean the tree
     * knows about admin it cannot protect, which is the shape task 2.3 exists
     * to rule out.
     */
    #[Test]
    public function unroutedAdminPathIsNeverForbidden(): void
    {
        if (is_dir(APP_BASE_PATH . '/src/Modules/' . self::ADMIN_MODULE)) {
            $this->markTestSkipped(self::ADMIN_MODULE . ' is installed — the admin path IS routed here.');
        }

        $kernel = new Kernel(new Router([APP_BASE_PATH . '/src/Modules']));
        $response = $kernel->handle(Request::create('GET', '/mark'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNotSame(403, $response->getStatusCode());
    }
}
