<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Middleware;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Outbox\Domain\SecurityEvents;
use Modules\Outbox\Interfaces\HTTP\OutboxServices;
use Modules\Outbox\Interfaces\HTTP\SecurityEventServices;
use Modules\Users\Application\Services\AuthService;
use Modules\Users\Domain\AdminRoutePolicy;
use Modules\Users\Domain\Entities\User;

/**
 * AuthMiddleware — protects the /mark/* admin area.
 *
 * Unauthenticated requests to protected paths are redirected to /login.
 * Remember-me cookies are honoured when present.
 *
 * Authorization is `AdminRoutePolicy::permits()` — a per-path permission,
 * NOT a role-name check. The policy table lives in the Users module because
 * the permission vocabulary belongs with the roles that carry it; Core reads
 * it and owns nothing about what a permission means.
 *
 * The AuthService factory is only invoked for protected/guest paths, so
 * public pages never open the users.db connection.
 */
final readonly class AuthMiddleware implements MiddlewareInterface
{
    /**
     * @param \Closure(): AuthService $authFactory
     */
    public function __construct(private \Closure $authFactory)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $path = $request->getPathInfo();

        if ($this->isProtected($path)) {
            $auth = ($this->authFactory)();

            if (!$auth->isAuthenticated()) {
                $user = $this->resumeFromCookie($auth, $request);

                if (null === $user) {
                    return Response::redirect('/login');
                }
            } else {
                /** @var User|null $user */
                $user = $auth->currentUser();
            }

            // Admin area requires the PERMISSION this path needs, not a role
            // name. `admin` still passes every row, but by holding all five
            // permissions rather than by this gate skipping the check — so an
            // author role configured in /mark/roles is finally real.
            $denied = self::authorize($path, $user);

            if (null !== $denied) {
                return $denied;
            }

            return $next($request);
        }

        // Authenticated users should not see auth pages.
        if ($this->isGuestOnly($path)) {
            $auth = ($this->authFactory)();

            if ($auth->isAuthenticated()) {
                return Response::redirect('/mark');
            }
        }

        return $next($request);
    }

    /**
     * Re-establish a session from the remember-me cookie.
     *
     * A cookie standing in for a password is the path an attacker would abuse
     * to borrow someone else's session, so the reuse is recorded rather than
     * treated as an ordinary login. Null means the cookie did not yield an
     * active user, and the caller redirects to /login.
     */
    private function resumeFromCookie(AuthService $auth, Request $request): ?User
    {
        $user = $auth->authenticateFromRememberCookie();

        if (null === $user || !$user->isActive()) {
            return null;
        }

        SecurityEventServices::authAttempt(
            $user->email(),
            SecurityEvents::OUTCOME_REMEMBER,
            $request->getClientIp(),
        );

        $auth->establishSession($user, $request->getClientIp());

        return $auth->currentUser();
    }

    /**
     * Authorization for the admin area: null means ALLOWED, a Response means
     * the request is refused and that response is the answer.
     *
     * An admin path with no policy row is refused rather than allowed — a new
     * route nobody classified must cost access, never leak it.
     */
    private static function authorize(string $path, ?User $user): ?Response
    {
        if (null === $user || !$user->isActive()) {
            return Response::html('Forbidden — your account is not active.', 403);
        }

        $required = AdminRoutePolicy::requiredPermission($path);

        if (null === $required) {
            self::recordDenial($path, $user, 'no-policy');

            return Response::html('Forbidden — no permission policy covers this path.', 403);
        }

        if (!$user->hasPermission($required)) {
            self::recordDenial($path, $user, $required);

            return Response::html(sprintf('Forbidden — the %s permission is required.', $required), 403);
        }

        return null;
    }

    private function isProtected(string $path): bool
    {
        return AdminRoutePolicy::isAdminPath($path);
    }

    /**
     * Why a request was refused, at DEBUG.
     *
     * A 403 with no trace is the hardest kind of bug report to act on: the
     * administrator cannot tell "this role was never granted the permission"
     * from "the path is missing from the policy table", and those two have
     * opposite fixes. Logging the path, the user and the resolved permission
     * makes the difference legible without reproducing the request.
     */
    private static function logDenial(string $path, User $user, string $required): void
    {
        if ('debug' !== strtolower((string) (\getenv('LOG_LEVEL') ?: 'info'))) {
            return;
        }

        \error_log(sprintf(
            '[authz] 403 path=%s user=%s roles=%s required=%s',
            $path,
            $user->id(),
            implode(',', $user->roles()) ?: '-',
            $required,
        ));
    }

    /**
     * Record the refusal as a durable security event, and log it at DEBUG.
     *
     * The event is unconditional: a DEBUG line is a development aid that
     * disappears in production, but a refused authorization is the one class
     * of thing this application most needs to be able to reconstruct after the
     * fact. The outbox makes the record at-least-once rather than
     * best-effort-inline — see `SecurityEvents` for what that does and does
     * not guarantee.
     */
    private static function recordDenial(string $path, User $user, string $required): void
    {
        OutboxServices::securityEvent(
            SecurityEvents::AUTHZ_DENIED,
            [
                'path'     => $path,
                'required' => $required,
                'userId'   => $user->id(),
                'roles'    => $user->roles(),
                'ip'       => (string) (\getenv('REMOTE_ADDR') ?: ''),
            ],
        );

        self::logDenial($path, $user, $required);
    }

    private function isGuestOnly(string $path): bool
    {
        return in_array($path, ['/login', '/register', '/forgot-password', '/reset-password'], true);
    }
}
