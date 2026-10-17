<?php

declare(strict_types = 1);

namespace Modules\Locales\Tests;

use Modules\Core\Application\Services\AuthProviderRegistry;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Kernel;
use Modules\Core\Interfaces\HTTP\Middleware\AuthMiddleware;
use Modules\Core\Interfaces\HTTP\Middleware\CsrfMiddleware;
use Modules\Core\Interfaces\HTTP\Middleware\MiddlewareRegistry;
use Modules\Core\Interfaces\HTTP\Middleware\SecurityHeadersMiddleware;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Locales\Application\Services\LocaleService;
use Modules\Locales\Interfaces\HTTP\LocalesServices;
use Modules\Locales\Interfaces\HTTP\Middleware\LocaleMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * LocaleUrlPrefixTest — the prefix contract: /sk, /sk_SK, /en and /en_US.
 *
 * Measured 2026-10-17: EVERY prefixed URL answered 404 — `/sk_SK/blog` (a link
 * the locale switcher renders itself), `/sk/docs` (linked from the `/docs`
 * index) and `/en/blog` alike. The cause was not the middleware, which handled
 * both prefix shapes correctly: it reached the pipeline NOWHERE. Discovery
 * accepts only `#[AsMiddleware]` classes it can build with `new $class()`, and
 * this one carried neither the attribute nor a zero-arg constructor, so a
 * documented URL contract was served by dead code and no test noticed — the
 * suite never requested a prefixed URL at all.
 *
 * Hence four assertions, in the order the defect would be reintroduced:
 *   1. the prefix is STRIPPED before routing, and the resolved locale plus the
 *      explicit-choice flag are recorded on the request;
 *   2. a path with no known prefix is left ALONE (the negative control: a
 *      middleware that strips the first segment unconditionally would pass (1)
 *      and 404 the whole site);
 *   3. the registry DISCOVERS this middleware, and places it after the security
 *      headers and before CSRF — cold scan and warm cache alike;
 *   4. a prefixed URL answers THROUGH THE KERNEL, which is the route the
 *      visitor's browser actually takes.
 *
 * Assertions (1) and (2) inject a resolver-free LocaleService, so they are
 * about the prefix and never about the settings database. (3) and (4) use the
 * real registry and the real pipeline, wired the way `public/index.php` wires
 * them.
 */
final class LocaleUrlPrefixTest extends TestCase
{
    private const string MODULE = 'Locales';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the prefix contract ships with this module.');
        }

        LocalesServices::reset();
        MiddlewareRegistry::reset();

        // The URL prefix is checked FIRST, but cookie and session are the next
        // two sources in the same priority list. A value left behind by another
        // test would answer for the unprefixed cases below.
        unset($_COOKIE['locale'], $_COOKIE[session_name()]);

        if (isset($_SESSION)) {
            unset($_SESSION['locale']);
        }
    }

    protected function tearDown(): void
    {
        MiddlewareRegistry::reset();

        // `setUp()` marks this class skipped when the module is not installed,
        // and a skipped test still runs tearDown — so the unguarded reset below
        // reached for `LocalesServices` on a kernel-only checkout and raised
        // "Class ... not found" once per test, 16 times over, all of them from
        // teardown rather than from any assertion. Same shape as the guard in
        // setUp: the module has to be there for its own teardown.
        if (class_exists(LocalesServices::class)) {
            LocalesServices::reset();
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function prefixedPaths(): iterable
    {
        // Both shapes the middleware accepts, because both are in use: the
        // switcher renders the full code (/sk_SK/), the docs links and the
        // ContentLocaleMapper emit the short one (/sk/docs).
        yield 'full code, nested path'    => ['/sk_SK/blog', '/blog', 'sk_SK'];
        yield 'full code, trailing slash' => ['/sk_SK/',     '/',     'sk_SK'];
        yield 'short code, nested path'   => ['/sk/blog',    '/blog', 'sk_SK'];
        yield 'short code, trailing slash' => ['/sk/',       '/',     'sk_SK'];
        yield 'second locale, full code'  => ['/en_US/blog', '/blog', 'en_US'];
        yield 'second locale, short code' => ['/en/blog',    '/blog', 'en_US'];
    }

    #[Test]
    #[DataProvider('prefixedPaths')]
    public function thePrefixIsStrippedAndTheLocaleIsRecorded(string $uri, string $expectedPath, string $expectedLocale): void
    {
        $request = $this->throughMiddleware(new LocaleMiddleware($this->resolverFreeService()), $uri);

        $this->assertSame(
            $expectedPath,
            $request->getPathInfo(),
            'the router must see the path WITHOUT the prefix — this is the 404 that started it',
        );

        $this->assertSame($expectedLocale, $request->getAttribute('locale'));
        $this->assertTrue(
            $request->getAttribute('locale_explicit'),
            'a prefix is an explicit choice: the blog tells /blog from /en/blog by this flag',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unprefixedPaths(): iterable
    {
        yield 'root'                   => ['/'];
        yield 'plain path'             => ['/blog'];
        yield 'unknown language first' => ['/zz/blog'];
        yield 'not a language at all'  => ['/mark'];
    }

    #[Test]
    #[DataProvider('unprefixedPaths')]
    public function aPathWithoutAKnownPrefixIsLeftAlone(string $uri): void
    {
        $service = $this->resolverFreeService();
        $request = $this->throughMiddleware(new LocaleMiddleware($service), $uri);

        $this->assertSame(
            $uri,
            $request->getPathInfo(),
            'no available locale matches the first segment, so nothing may be stripped',
        );

        $this->assertSame($service->default()->code(), $request->getAttribute('locale'));
        $this->assertFalse(
            $request->getAttribute('locale_explicit'),
            'nothing was chosen, so the site default answered',
        );
    }

    #[Test]
    public function theRegistryDiscoversThisMiddlewareBeforeCsrf(): void
    {
        $order = $this->discoveredOrder();

        $this->assertContains(
            LocaleMiddleware::class,
            $order,
            'an unannotated middleware reaches the pipeline nowhere, and every prefixed URL 404s — the measured defect',
        );

        $locale = array_search(LocaleMiddleware::class, $order, true);
        $csrf = array_search(CsrfMiddleware::class, $order, true);
        $headers = array_search(SecurityHeadersMiddleware::class, $order, true);

        $this->assertIsInt($locale, 'the middleware must be IN the discovered list to have a position');
        $this->assertIsInt($csrf);
        $this->assertIsInt($headers);

        $this->assertGreaterThan($headers, $locale, 'the prefix is stripped after the security headers');
        $this->assertLessThan(
            $csrf,
            $locale,
            'the prefix must be stripped BEFORE CSRF and before routing — the order its own docblock documents',
        );
    }

    /**
     * A warm discovery cache rehydrates the class list instead of rescanning,
     * and instantiation SKIPS a class it cannot build. So a middleware that
     * needs a constructor argument silently vanishes from a primed pipeline
     * even with the attribute present — which is why the cold scan and the
     * cache path are both pinned here.
     */
    #[Test]
    public function theDiscoveryCachePathAlsoYieldsThisMiddleware(): void
    {
        MiddlewareRegistry::reset();
        MiddlewareRegistry::prime(MiddlewareRegistry::discoveredClasses());

        $this->assertContains(
            LocaleMiddleware::class,
            $this->classNames(MiddlewareRegistry::all()),
            'a zero-arg constructor is what lets a warm cache rebuild this middleware',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function prefixedKernelPaths(): iterable
    {
        yield 'unprefixed'        => ['/docs'];
        yield 'short prefix'      => ['/sk/docs'];
        yield 'full code prefix'  => ['/sk_SK/docs'];
        yield 'second locale'     => ['/en/docs'];
    }

    #[Test]
    #[DataProvider('prefixedKernelPaths')]
    public function aPrefixedUrlReachesTheRouteThroughTheKernel(string $uri): void
    {
        $response = $this->kernelAsTheCompositionRootWiresIt()->handle(Request::create('GET', $uri));

        $this->assertSame(
            200,
            $response->getStatusCode(),
            sprintf('%s must reach the docs route — before 2026-10-17 every prefixed URL answered 404', $uri),
        );
    }

    /**
     * @return list<class-string>
     */
    private function discoveredOrder(): array
    {
        return $this->classNames(MiddlewareRegistry::all());
    }

    /**
     * @param list<object> $middleware
     *
     * @return list<class-string>
     */
    private function classNames(array $middleware): array
    {
        return array_map(static fn (object $entry): string => $entry::class, $middleware);
    }

    /**
     * Run a request through the middleware alone and return the request the
     * NEXT stage receives: the path the router will match plus the attributes
     * the layout and the blog read.
     */
    private function throughMiddleware(LocaleMiddleware $middleware, string $uri): Request
    {
        $seen = null;

        $middleware->handle(
            Request::create('GET', $uri),
            static function (Request $request) use (&$seen): Response {
                $seen = $request;

                return Response::html('ok');
            },
        );

        $this->assertInstanceOf(Request::class, $seen, 'the middleware must call the next stage exactly once');

        return $seen;
    }

    /**
     * A LocaleService whose resolver answers null without touching the
     * settings database, so these cases turn on the prefix alone.
     */
    private function resolverFreeService(): LocaleService
    {
        return new LocaleService(static fn (): ?string => null);
    }

    /**
     * The pipeline under test is the pipeline the composition root builds:
     * auto-discovered middleware, then the auth stage wrapped around whatever
     * provider a module shipped — the same two lines `public/index.php` and
     * KernelOnlyBootTest make.
     */
    private function kernelAsTheCompositionRootWiresIt(): Kernel
    {
        $kernel = new Kernel(new Router([APP_BASE_PATH . '/src/Modules']));

        $provider = AuthProviderRegistry::first();

        if (null !== $provider) {
            $kernel->addMiddleware(new AuthMiddleware($provider));
        }

        return $kernel;
    }
}
