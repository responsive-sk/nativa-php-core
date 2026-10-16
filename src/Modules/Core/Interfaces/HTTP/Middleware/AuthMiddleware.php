<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Middleware;

use Modules\Core\Domain\AuthIdentity;
use Modules\Core\Domain\Contracts\AuthProvider;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;

/**
 * AuthMiddleware — protects the admin area through the kernel-owned auth port.
 *
 * Before the port, this file imported the auth service, the signed-in user
 * entity and the admin path table from `Users`, and the security-event
 * emitter from `Outbox` — six edges that made the shared middleware
 * unloadable without two feature modules it does not own. Now it asks an
 * `AuthProvider` and nothing else: who the request is (mapped to a
 * kernel-owned identity by whoever owns the session), which paths are
 * protected and what they require, and which paths are guest-only.
 *
 * The refusal itself is recorded as `SecurityEvents::AUTHZ_DENIED` — named
 * inline, never imported, behind a `class_exists` guard, exactly the way
 * `RateLimitMiddleware` records its 429s. Without the outbox the event is
 * dropped, not the refusal.
 *
 * The middleware is wired only when a provider exists (no provider → no admin
 * surface to protect → `/mark` is 404, never 403). Session handling,
 * remember-me renewal and the permission vocabulary stay the module's
 * business entirely; the middleware owns the decision shape alone.
 *
 * Authorization is per-path permission, NOT a role-name check. An admin path
 * with no policy row is refused rather than allowed — a new route nobody
 * classified must cost access, never leak it.
 */
final readonly class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private AuthProvider $auth)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $path = $request->getPathInfo();

        if ($this->auth->isProtectedPath($path)) {
            $identity = $this->auth->identity($request);

            if (null === $identity) {
                return Response::redirect('/login');
            }

            $denied = $this->authorize($path, $identity);

            if (null !== $denied) {
                return $denied;
            }

            return $next($request);
        }

        // Authenticated identities should not see auth pages.
        if (in_array($path, $this->auth->guestOnlyPaths(), true)
            && null !== $this->auth->identity($request)
        ) {
            return Response::redirect('/mark');
        }

        return $next($request);
    }

    /**
     * Authorization for the admin area: null means ALLOWED, a Response means
     * the request is refused and that response is the answer.
     */
    private function authorize(string $path, AuthIdentity $identity): ?Response
    {
        if (!$identity->active) {
            return Response::html('Forbidden — your account is not active.', 403);
        }

        $required = $this->auth->requiredPermission($path);

        if (null === $required) {
            $this->recordDenial($path, $identity, 'no-policy');

            return Response::html('Forbidden — no permission policy covers this path.', 403);
        }

        if (!$identity->hasPermission($required)) {
            $this->recordDenial($path, $identity, $required);

            return Response::html(sprintf('Forbidden — the %s permission is required.', $required), 403);
        }

        return null;
    }

    /**
     * Why a request was refused, at DEBUG.
     *
     * A 403 with no trace is the hardest kind of bug report to act on: the
     * administrator cannot tell "this identity was never granted the
     * permission" from "the path is missing from the policy table", and those
     * two have opposite fixes.
     */
    private function logDenial(string $path, AuthIdentity $identity, string $required): void
    {
        if ('debug' !== strtolower((string) (getenv('LOG_LEVEL') ?: 'info'))) {
            return;
        }

        error_log(sprintf(
            '[authz] 403 path=%s user=%s required=%s',
            $path,
            $identity->id,
            $required,
        ));
    }

    /**
     * Record the refusal as a security event, then log it at DEBUG.
     *
     * The event is unconditional: a DEBUG line is a development aid that
     * disappears in production, but a refused authorization is the one class
     * of thing the application most needs to reconstruct after the fact.
     *
     * The type is NAMED, not spelled. Measured 2026-10-16: this call used to
     * hand the bare string `authz.denied` to the provider, which forwards what
     * it is given — so every refused admin path wrote an outbox message under a
     * type no handler in `SecurityEvents::all()` claims, the processor failed it
     * with `No handler registered for event type: authz.denied`, and NO audit
     * row was ever written for a refusal. The `roles` key is empty because the
     * kernel identity carries permissions rather than role names: the audit line
     * names no roles, the shape the bulk gate's refusals already write.
     */
    private function recordDenial(string $path, AuthIdentity $identity, string $required): void
    {
        $this->logDenial($path, $identity, $required);

        if (!class_exists(\Modules\Outbox\Interfaces\HTTP\OutboxServices::class)
            || !class_exists(\Modules\Outbox\Domain\SecurityEvents::class)
        ) {
            return;
        }

        \Modules\Outbox\Interfaces\HTTP\OutboxServices::securityEvent(
            \Modules\Outbox\Domain\SecurityEvents::AUTHZ_DENIED,
            [
                'path'     => $path,
                'required' => $required,
                'userId'   => $identity->id,
                'roles'    => [],
                'ip'       => (string) (getenv('REMOTE_ADDR') ?: ''),
            ],
        );
    }
}
