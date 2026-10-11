<?php

declare(strict_types = 1);

namespace Modules\Core\Infrastructure\Http;

use Modules\Core\Infrastructure\Env;

/**
 * HTTP Request — immutable-ish wrapper around PHP globals.
 */
final class Request
{
    /** @var array<string, mixed> Attributes set by middleware (e.g. csp_nonce). */
    private array $attributes = [];

    /** @var array<string, string> Route parameters from the matched route. */
    private array $routeParams = [];

    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     * @param array<string, mixed>  $server
     */
    private function __construct(
        private readonly string $method,
        private string $pathInfo,
        private readonly array $query,
        private readonly array $body,
        private readonly array $headers,
        private readonly array $server = [],
    ) {
    }

    public static function createFromGlobals(): self
    {
        $rawMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $method = strtoupper(is_string($rawMethod) ? $rawMethod : 'GET');

        $rawUri = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = is_string($rawUri) ? $rawUri : '/';

        $path = parse_url($uri, PHP_URL_PATH);
        $pathInfo = rawurldecode(is_string($path) ? $path : '/');

        return new self($method, $pathInfo, $_GET, $_POST, self::headersFromServer($_SERVER), $_SERVER);
    }

    /**
     * Create a request for testing / internal dispatch.
     *
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, mixed>  $server
     * @param array<string, string> $headers
     */
    public static function create(string $method, string $path, array $query = [], array $body = [], array $server = [], array $headers = []): self
    {
        // Default to loopback so rate limiting is skipped in tests/CLI.
        $server += ['REMOTE_ADDR' => '127.0.0.1'];

        return new self(strtoupper($method), $path, $query, $body, $headers, $server);
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPathInfo(): string
    {
        return $this->pathInfo;
    }

    /**
     * Rewrite the request path (used by LocaleMiddleware to strip the
     * locale URL prefix before routing).
     */
    public function setPathInfo(string $pathInfo): void
    {
        $this->pathInfo = $pathInfo;
    }

    /** @return array<string, mixed> */
    public function getQueryParams(): array
    {
        return $this->query;
    }

    /** @return array<string, mixed> */
    public function getBodyParams(): array
    {
        return $this->body;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function body(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function header(string $key, ?string $default = null): ?string
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

    /**
     * Absolute URL (scheme + host) for a site path, loopback-safe fallback
     * so absolute links (feeds, hreflang alternates) work on the built-in
     * dev server too.
     *
     * Host header poisoning guard: when TRUSTED_HOSTS is set (comma-separated
     * hostnames, same pattern as TRUSTED_PROXIES) the Host header is validated
     * against the allowlist and fails closed to the first allowed host on
     * mismatch. Unset = unchanged behaviour (backward compatible) — production
     * deployments should set TRUSTED_HOSTS to their public hostnames.
     */
    public function absoluteUrl(string $path): string
    {
        $scheme = Env::isHttps() ? 'https' : 'http';
        $host = $this->header('host');
        $host = is_string($host) && '' !== $host ? $host : 'localhost';
        $allowed = $this->trustedHosts();

        if (null !== $allowed && !$this->isTrustedHost($host, $allowed)) {
            $host = $allowed[0];
        }

        return $scheme . '://' . $host . $path;
    }

    /**
     * Parse the TRUSTED_HOSTS environment variable into a hostname list, or
     * null when unset/empty (allowlist disabled).
     *
     * @return list<string>|null
     */
    private function trustedHosts(): ?array
    {
        $raw = Env::get('TRUSTED_HOSTS', '');
        if (!is_string($raw) || '' === trim($raw)) {
            return null;
        }

        $hosts = [];

        foreach (explode(',', $raw) as $entry) {
            $entry = strtolower(trim($entry));
            if ('' !== $entry && !str_contains($entry, '/')) {
                $hosts[] = $entry;
            }
        }

        return [] === $hosts ? null : $hosts;
    }

    /**
     * Match the request Host header against the allowlist. The port (if any)
     * is stripped before comparing, so TRUSTED_HOSTS entries are plain
     * hostnames (e.g. nativa.example, localhost).
     *
     * @param list<string> $allowed
     */
    private function isTrustedHost(string $host, array $allowed): bool
    {
        $name = strtolower(trim(explode(':', $host, 2)[0]));

        return in_array($name, $allowed, true);
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Resolve the client IP address.
     *
     * Forwarded headers (X-Forwarded-For / X-Real-IP) are spoofable, so they
     * are honoured ONLY when the direct peer (REMOTE_ADDR) is a proxy listed
     * in the TRUSTED_PROXIES environment variable (comma-separated IPs or
     * CIDRs). Without a trusted proxy the direct socket address is returned
     * — a client can never spoof its IP to bypass rate limiting.
     */
    public function getClientIp(): string
    {
        $remoteAddr = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!is_string($remoteAddr) || '' === $remoteAddr) {
            $remoteAddr = '0.0.0.0';
        }

        if (!$this->isTrustedProxy($remoteAddr)) {
            return $remoteAddr;
        }

        // Walk the X-Forwarded-For chain from the closest hop (rightmost)
        // until an address outside the trusted proxies is found — that is
        // the client. Empty or malformed hops are skipped; if no usable
        // client IP can be resolved the direct peer is returned (fail closed).
        $forwarded = $this->header('x-forwarded-for');
        if (is_string($forwarded) && '' !== $forwarded) {
            foreach (array_reverse(array_map(trim(...), explode(',', $forwarded))) as $hop) {
                if ('' === $hop || !$this->isValidIp($hop)) {
                    continue;
                }
                if (!$this->isTrustedProxy($hop)) {
                    return $hop;
                }
            }
        }

        $realIp = $this->header('x-real-ip');

        if (is_string($realIp) && '' !== $realIp && $this->isValidIp($realIp) && !$this->isTrustedProxy($realIp)) {
            return $realIp;
        }

        return $remoteAddr;
    }

    /**
     * Whether the given value is a syntactically valid IP address.
     */
    private function isValidIp(string $ip): bool
    {
        return false !== inet_pton($ip);
    }

    /**
     * Whether the given peer address is a configured trusted proxy.
     */
    private function isTrustedProxy(string $ip): bool
    {
        $trusted = Env::get('TRUSTED_PROXIES', '');
        if (!is_string($trusted) || '' === trim($trusted)) {
            return false;
        }

        foreach (explode(',', $trusted) as $entry) {
            $entry = trim($entry);
            if ('' !== $entry && $this->ipMatches($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match an IP against a trusted-proxy entry (exact address or CIDR).
     */
    private function ipMatches(string $ip, string $entry): bool
    {
        if (!str_contains($entry, '/')) {
            $ipBin = inet_pton($ip);
            $entryBin = inet_pton($entry);
            if (false === $ipBin || false === $entryBin || strlen($ipBin) !== strlen($entryBin)) {
                return false;
            }

            return hash_equals($entryBin, $ipBin);
        }

        [$subnet, $prefixRaw] = explode('/', $entry, 2);
        if (!is_numeric($prefixRaw)) {
            return false;
        }

        $prefix = (int) $prefixRaw;
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton(trim($subnet));
        if (false === $ipBin || false === $subnetBin || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainderBits = $prefix % 8;

        if (0 !== strncmp($subnetBin, $ipBin, $fullBytes)) {
            return false;
        }

        if (0 === $remainderBits) {
            return true;
        }

        if ($fullBytes >= strlen($ipBin)) {
            return false;
        }

        $mask = 0xFF << (8 - $remainderBits);

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** @param array<string, string> $params */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    /** @return array<string, string> */
    public function getRouteParams(): array
    {
        return $this->routeParams;
    }

    public function routeParam(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /**
     * @param array<string, mixed> $server
     *
     * @return array<string, string>
     */
    private static function headersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = is_string($value) ? $value : '';
            }
        }

        if (isset($server['CONTENT_TYPE']) && is_string($server['CONTENT_TYPE'])) {
            $headers['content-type'] = $server['CONTENT_TYPE'];
        }
        if (isset($server['CONTENT_LENGTH']) && is_string($server['CONTENT_LENGTH'])) {
            $headers['content-length'] = $server['CONTENT_LENGTH'];
        }

        return $headers;
    }
}
