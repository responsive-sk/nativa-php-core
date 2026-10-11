<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Middleware;

use Modules\Core\Infrastructure\Attributes\AsMiddleware;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;

/**
 * CSRF Protection Middleware.
 *
 * Rejects state-changing requests (POST, PUT, DELETE, PATCH) that carry no
 * valid CSRF token. Tokens are stored in the (lazily started) session and
 * validated with hash_equals.
 *
 * Template usage:
 *   <?= CsrfMiddleware::field() ?>
 * or the raw token via CsrfMiddleware::token().
 */
#[AsMiddleware(priority: 20)]
final class CsrfMiddleware implements MiddlewareInterface
{
    private const string TOKEN_KEY = '_token';

    private const string SESSION_KEY = 'csrf_token';

    private const string HEADER_KEY = 'x-csrf-token';

    public function handle(Request $request, callable $next): Response
    {
        if ($this->isStateChangingRequest($request) && !$this->isValidToken($request)) {
            return Response::html('CSRF token validation failed', 403);
        }

        return $next($request);
    }

    /**
     * Get the session CSRF token, generating it on first use.
     */
    public static function token(): string
    {
        self::startSession();

        if (!isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        /** @var string $token */
        $token = $_SESSION[self::SESSION_KEY];

        return $token;
    }

    /**
     * Hidden form field helper for templates.
     */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::TOKEN_KEY . '" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Verify a submitted token against the session token.
     */
    public static function verify(string $token): bool
    {
        return hash_equals(self::token(), $token);
    }

    /**
     * Regenerate the session token (call after login to prevent fixation).
     */
    public static function regenerateToken(): string
    {
        self::startSession();

        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));

        /** @var string $token */
        $token = $_SESSION[self::SESSION_KEY];

        return $token;
    }

    private static function startSession(): void
    {
        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }
    }

    private function isStateChangingRequest(Request $request): bool
    {
        return \in_array($request->getMethod(), ['POST', 'PUT', 'DELETE', 'PATCH'], true);
    }

    private function isValidToken(Request $request): bool
    {
        $token = $request->body(self::TOKEN_KEY);

        if (!is_string($token)) {
            $token = $request->header(self::HEADER_KEY);
        }

        if (!is_string($token) || '' === $token) {
            return false;
        }

        return hash_equals(self::token(), $token);
    }
}
