<?php

declare(strict_types = 1);

namespace Modules\Core\Application\Services;

use Modules\Core\Domain\AdminRoutePolicy;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Middleware\CsrfMiddleware;

/**
 * PageCacheService — file-based HTML page cache for anonymous public pages.
 *
 * Cached pages are stored in storage/cache/pages/ as JSON files keyed by
 * SHA256(method + path + query + locale). `PageCacheMiddleware` populates it
 * on every cacheable GET response and serves it back while the entry is
 * fresh; `RateLimitMiddleware` reads the same entries as its fallback when a
 * client trips the rate limit, which is why the cache predates being read on
 * the ordinary path.
 *
 * The TTL is the staleness contract (`DEFAULT_TTL`, 300 seconds) and an edit
 * in the admin does not wait for it: every content write flushes the cache
 * through `CacheInvalidator`. What a visitor can therefore see is a page up
 * to five minutes old when nobody touched it, and never an old page after
 * somebody did.
 *
 * Cached content retains the __CSP_NONCE_PLACEHOLDER__ string —
 * SecurityHeadersMiddleware replaces it with a fresh nonce on every request,
 * including cached responses.
 */
final readonly class PageCacheService
{
    private const int DEFAULT_TTL = 300;

    /**
     * Canonical cache directory (relative to the app base path) — the single
     * shared contract between the middleware pipeline wiring, admin services
     * and the invalidator, so the path cannot drift between consumers.
     */
    public const string DEFAULT_DIR = 'storage/cache/pages';

    private const int MAX_CACHE_SIZE = 1_048_576;

    /**
     * Path prefixes whose responses are never written to the cache or served
     * from it.
     *
     * `/docs` belongs here because a guide IS the file: a 300-second-old copy
     * of the exact paragraph a developer came to read is worse than one more
     * file read, and an edit to a guide must be visible on the next reload.
     * Skipping the render is not a reason to serve a stale guide.
     *
     * The admin surface is not listed: it is answered at runtime through
     * `AdminRoutePolicy::isAdminPath()`, which knows both the configured
     * prefix and the internal `/mark`, so a deployment running `/admin` cannot
     * have an admin page cached for the next visitor.
     *
     * @var list<string>
     */
    private const array BYPASS_PREFIXES = ['/debug', '/storage', '/docs'];

    /** @var list<string> */
    private const array BYPASS_PATHS = ['/login', '/register', '/forgot-password', '/reset-password'];

    /**
     * Query parameters whose presence means the HTML depends on THIS visitor's
     * session, and must therefore never be written to a cache every other
     * visitor can read.
     *
     * The POST-redirect-GET markers of the embedded form questions blocks (`forms.*`, `questions.*`): after a submission the page renders that visitor's thank-you state, their validation errors and the text they just typed.
     * Caching that under a shared key would hand one person's form state to
     * the next person who loads the same URL — a cross-visitor data leak, not
     * a stale-content annoyance.
     *
     * Key names live in Core because Core owns the cache policy; the values
     * are agreed with the Forms module (`FormSubmitAction`) and the Questions
     * module the same way the bypass paths are agreed with the auth pages.
     *
     * @var list<string>
     */
    private const array BYPASS_QUERY_PARAMS = ['form-sent', 'form-invalid', 'question-sent'];

    public function __construct(
        private string $cacheDir,
    ) {
        $this->ensureDir();
    }

    /**
     * Read a cached response entry — lazy-prunes expired files on read.
     *
     * @return array{content: string, statusCode: int, headers: array<string, string>}|null
     */
    public function get(string $key): ?array
    {
        $this->lazyPrune();

        $file = $this->cacheDir . '/' . $key . '.json';

        if (!is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);

        if (false === $raw) {
            return null;
        }

        /** @var mixed $entry */
        $entry = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($entry) || !isset($entry['cachedAt'], $entry['ttl'])) {
            return null;
        }

        if ($entry['cachedAt'] + $entry['ttl'] < time()) {
            @unlink($file);

            return null;
        }

        return [
            'content' => $entry['content'],
            'statusCode' => $entry['statusCode'],
            'headers' => $entry['headers'],
        ];
    }

    /**
     * Write a response to the cache.
     */
    public function set(string $key, Response $response, int $ttl = self::DEFAULT_TTL): void
    {
        $content = $response->getContent();

        if ('' === $content || strlen($content) > self::MAX_CACHE_SIZE) {
            return;
        }

        $data = [
            'content' => $content,
            'statusCode' => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
            'cachedAt' => time(),
            'ttl' => $ttl,
        ];

        $file = $this->cacheDir . '/' . $key . '.json';
        file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * Delete all cached pages. Returns the number of files deleted.
     */
    public function flush(): int
    {
        $count = 0;
        $files = glob($this->cacheDir . '/*.json');

        if (!is_array($files)) {
            return 0;
        }

        foreach ($files as $file) {
            if (is_file($file) && @unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Determine whether a request+response pair should be stored.
     */
    public function isCacheable(Request $request, Response $response): bool
    {
        if (!$this->isCacheableRequest($request)) {
            return false;
        }

        if (200 !== $response->getStatusCode()) {
            return false;
        }

        $contentType = (string) ($response->getHeader('Content-Type') ?? '');

        if (!str_starts_with($contentType, 'text/html')) {
            return false;
        }

        if ($this->renderedASessionToken($response)) {
            return false;
        }

        return '' !== trim($response->getContent());
    }

    /**
     * Whether this body carries the visitor's CSRF token.
     *
     * A form's hidden `_token` is minted per SESSION, so a body containing it is
     * not a page: stored under a key every visitor shares, it hands the next
     * visitor a credential that fails their own submission. That is not a
     * theory — it is what `bin/build/admin-smoke.sh` caught the first time this
     * cache was read on the ordinary path: the guest's comment on an article
     * page came back `403`, because the token in the served form belonged to
     * whoever rendered the page first.
     *
     * Read through `CsrfMiddleware::sessionToken()`, which mints nothing and
     * starts no session: a page that printed no form carries no token, and a
     * visitor who never used one must not acquire a session for being cached.
     *
     * The consequence is deliberate: the surfaces that print a form (the
     * comment section, the questions block) are never cached, and the pages
     * that do not (the landing page, the blog index) still are. Serving them
     * would mean substituting the field like the CSP nonce is substituted, a
     * change to the auth surface this cache has no business making on its own.
     */
    private function renderedASessionToken(Response $response): bool
    {
        $token = CsrfMiddleware::sessionToken();

        return null !== $token && str_contains($response->getContent(), $token);
    }

    /**
     * The request half of the rule, on its own — the question the middleware
     * has to answer BEFORE it renders, because that is when a cache lookup
     * happens. `isCacheable()` asks it too, so the store path and the serve
     * path cannot drift: a request that may not be written is a request that
     * may not be served, and the other way round.
     *
     * Nothing here looks at a response. The response half (200, text/html,
     * non-empty) is what `isCacheable()` adds, and no cached entry can carry a
     * value that half would have refused — entries are only ever written
     * through it.
     */
    public function isCacheableRequest(Request $request): bool
    {
        if ('GET' !== $request->getMethod()) {
            return false;
        }

        $path = $request->getPathInfo();

        if (AdminRoutePolicy::isAdminPath($path)) {
            return false;
        }

        foreach (self::BYPASS_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        if (in_array($path, self::BYPASS_PATHS, true)) {
            return false;
        }

        if ($this->hasSessionScopedQuery($request)) {
            return false;
        }

        return !$this->renderedForASignedInVisitor();
    }

    /**
     * Does this request carry a parameter that makes the response
     * session-scoped?
     */
    private function hasSessionScopedQuery(Request $request): bool
    {
        $params = $request->getQueryParams();

        return array_any(self::BYPASS_QUERY_PARAMS, fn ($name) => array_key_exists($name, $params));
    }

    /**
     * Whether this response was rendered for a signed-in visitor.
     *
     * The page a signed-in editor is served is not the page an anonymous
     * reader gets: the shell chrome names the admin surface for exactly one of
     * them (`FrontendLayout::sessionHasAuth()` and, through the shell port, the
     * Settings provider's own menu filter). A cache key is method + path +
     * query + locale — shared by every visitor — so storing an editor's render
     * under it hands the next reader a link to a surface they cannot open.
     * That is not a staleness annoyance: `RateLimitMiddleware` serves this cache
     * to whoever trips the limit, not to whoever triggered the write, so the
     * leak escapes the session that produced it.
     *
     * Read exactly as the kernel reads it elsewhere, guard included: no session
     * cookie, no session started — a cache policy has no business handing a
     * session to a visitor who never logged in.
     */
    private function renderedForASignedInVisitor(): bool
    {
        $sessionName = session_name();

        if (false === $sessionName || !isset($_COOKIE[$sessionName])) {
            return false;
        }

        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }

        /** @var array{auth?: array{user_id?: string}} $session */
        $session = $_SESSION;

        return isset($session['auth']['user_id']);
    }

    /**
     * Generate a cache key from the request and locale.
     */
    public function cacheKey(Request $request, string $locale = ''): string
    {
        $data = $request->getMethod()
            . '|' . $request->getPathInfo()
            . '|' . http_build_query($request->getQueryParams())
            . '|' . $locale;

        return hash('sha256', $data);
    }

    private function ensureDir(): void
    {
        $this->validatePath();

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Reject paths containing traversal segments. Defense-in-depth —
     * callers should already provide safe paths, but this prevents
     * misconfiguration or injection via constructor parameter.
     */
    private function validatePath(): void
    {
        if (str_contains($this->cacheDir, '..')) {
            throw new \RuntimeException('PageCacheService: path traversal detected in cacheDir');
        }
    }

    private const int PRUNE_INTERVAL = 300;

    /**
     * Lazy pruning — at most once per PRUNE_INTERVAL seconds.
     * Runs on get() so expired entries are cleaned when they are
     * actually looked up, not on every write.
     *
     * Uses a file-based timestamp marker instead of a static property
     * because readonly classes cannot hold mutable static state.
     */
    private function lazyPrune(): void
    {
        $now = time();
        $marker = $this->cacheDir . '/.last_prune';

        if (is_file($marker)) {
            $last = (int) file_get_contents($marker);
            if ($now - $last < self::PRUNE_INTERVAL) {
                return;
            }
        }

        file_put_contents($marker, (string) $now);
        $this->pruneExpired();
    }

    private function pruneExpired(): void
    {
        $files = glob($this->cacheDir . '/*.json');

        if (!is_array($files)) {
            return;
        }

        $now = time();

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $raw = file_get_contents($file);

            if (false === $raw) {
                continue;
            }

            /** @var array{cachedAt?: int, ttl?: int}|null $entry */
            $entry = json_decode($raw, true);

            if (!is_array($entry) || !isset($entry['cachedAt'], $entry['ttl'])) {
                @unlink($file);

                continue;
            }

            if ($entry['cachedAt'] + $entry['ttl'] < $now) {
                @unlink($file);
            }
        }
    }
}
