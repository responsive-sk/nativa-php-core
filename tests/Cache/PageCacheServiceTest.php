<?php

declare(strict_types = 1);

namespace Tests\Cache;

use Modules\Core\Application\Services\PageCacheService;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Middleware\PageCacheMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PageCacheServiceTest (Boot 24) — who a cached page belongs to.
 *
 * The page cache is keyed by method + path + query + locale, which is to say by
 * nothing that distinguishes one visitor from another. It is written by
 * `PageCacheMiddleware` on every cacheable response and read by
 * `RateLimitMiddleware` when a client trips the rate limit — so the cache is
 * served to WHOEVER trips the limit, never to whoever triggered the write.
 *
 * That makes any session-dependent render a cross-visitor leak the moment it is
 * stored, and one such render exists by design: the shell chrome advertises the
 * admin surface to a signed-in editor and to nobody else (`FrontendLayout`
 * through `sessionHasAuth()`, and the Settings shell provider's menu filter).
 * An editor's `/` stored under the shared key is an anonymous reader being
 * handed a link to `/mark` — measured on the live server before this rule
 * existed, in the rate-limit fallback path.
 *
 * The rule pinned here: a response rendered for a signed-in visitor is never
 * cacheable, and the anonymous render of the same URL still is. Both halves
 * matter — a policy that quietly stopped caching the site would also silence
 * this test's failure.
 *
 * The tests drive the MIDDLEWARE for the write path (what actually populates the
 * cache) and the service for the bypass table around the new rule, so an edit
 * that moves the rule out of `isCacheable()` and leaves future writers to
 * remember it fails here.
 */
final class PageCacheServiceTest extends TestCase
{
    private string $cacheDir = '';

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/nativa-page-cache-' . bin2hex(random_bytes(6));

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $this->signOut();

        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->cacheDir);
    }

    #[Test]
    public function anAnonymousPageIsWrittenToTheCache(): void
    {
        $this->assertCount(0, $this->cacheFiles(), 'Precondition: nothing is cached yet.');

        $this->writeThroughMiddleware(Request::create('GET', '/blog'));

        $this->assertCount(
            1,
            $this->cacheFiles(),
            'The anonymous render must still be cached — the cache exists for the rate-limit fallback.',
        );
    }

    #[Test]
    public function anEditorsPageIsNeverWrittenToTheCache(): void
    {
        $this->signIn();

        $this->writeThroughMiddleware(Request::create('GET', '/'));

        $this->assertSame(
            [],
            $this->cacheFiles(),
            'A page rendered for a signed-in visitor entered a cache every visitor can read.',
        );
    }

    #[Test]
    public function signingOutMakesTheSamePageCacheableAgain(): void
    {
        $this->signIn();
        $this->writeThroughMiddleware(Request::create('GET', '/'));
        $this->assertSame([], $this->cacheFiles());

        $this->signOut();
        $this->writeThroughMiddleware(Request::create('GET', '/'));

        $this->assertCount(
            1,
            $this->cacheFiles(),
            'The rule must cost the editor their own cache entry, not the whole site its cache.',
        );
    }

    /**
     * The bypass table the new rule sits inside — pinned so that an edit to
     * `isCacheable()` cannot trade one leak for another.
     *
     * @param array<string, string> $query
     */
    #[Test]
    #[DataProvider('uncacheableRequests')]
    public function theExistingBypassRulesStillHold(string $method, string $path, int $status, array $query): void
    {
        $request = Request::create($method, $path, $query);
        $response = Response::html('<html>ok</html>', $status);

        $this->assertFalse(
            new PageCacheService($this->cacheDir)->isCacheable($request, $response),
            sprintf('%s %s must not be cacheable.', $method, $path),
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: int, 3: array<string, string>}>
     */
    public static function uncacheableRequests(): iterable
    {
        yield 'a POST is never a page' => ['POST', '/', 200, []];

        yield 'the admin surface' => ['GET', '/mark', 200, []];

        yield 'a guide is the file' => ['GET', '/docs/kernel', 200, []];

        yield 'an error page' => ['GET', '/', 404, []];

        yield 'a submission state' => ['GET', '/blog', 200, ['form-sent' => '1']];
    }

    /**
     * The real write path: the middleware captures a response only when the
     * service calls it cacheable.
     */
    private function writeThroughMiddleware(Request $request): void
    {
        $middleware = new PageCacheMiddleware(new PageCacheService($this->cacheDir));

        $middleware->handle($request, static fn (): Response => Response::html('<html>ok</html>'));
    }

    /**
     * @return list<string>
     */
    private function cacheFiles(): array
    {
        return glob($this->cacheDir . '/*.json') ?: [];
    }

    /**
     * The session the app itself would have: started, stamped with the key the
     * kernel's shell chrome reads, and announced by cookie — which is what the
     * guard requires before `$_SESSION` is looked at at all.
     */
    private function signIn(): void
    {
        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }

        $_SESSION['auth'] = ['user_id' => 'test-editor'];
        $_COOKIE[session_name()] = session_id();
    }

    private function signOut(): void
    {
        $_SESSION = [];

        if (false !== session_name()) {
            unset($_COOKIE[session_name()]);
        }

        if (PHP_SESSION_ACTIVE === session_status()) {
            session_destroy();
        }
    }
}
