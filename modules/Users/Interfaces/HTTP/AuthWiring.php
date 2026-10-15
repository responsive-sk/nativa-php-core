<?php

declare(strict_types = 1);

namespace Modules\Users\Interfaces\HTTP;

use Modules\Core\Interfaces\HTTP\Kernel;
use Modules\Core\Interfaces\HTTP\Middleware\AuthMiddleware;
use Modules\Users\Domain\UsersAuthProvider;

/**
 * AuthWiring — switch the admin gate on, in one place.
 *
 * `AuthMiddleware` takes an `AuthProvider` in its constructor, so the kernel's
 * `#[AsMiddleware]` discovery cannot build it (`MiddlewareRegistry`
 * instantiates with `new $class()` and no arguments) — the wiring is
 * explicit, by decision, not a missing attribute. What this class adds on top
 * of writing the two lines in `public/index.php` is a single ADDRESS for
 * them: the composition root calls `wire()` behind one `class_exists` guard,
 * and the kernel-level tests call the very same method, so the pipeline the
 * suite proves and the pipeline production runs cannot drift apart. Two
 * copies of an auth gate is one copy too many — a divergence would be found
 * by a live request, not by the suite.
 *
 * Absence still removes the middleware, not the request: when this file is
 * not installed, `class_exists` fails, nothing is wired, and `/mark` answers
 * 404 (kernel-only tree) — the same absence-is-an-answer rule the port's
 * docblock pins for providers.
 *
 * Attribute discovery (`#[AsAuthProvider]` on `UsersAuthProvider`) remains
 * the documented future shape and the attribute already sits there waiting;
 * it is deferred until the registry can construct a middleware with
 * dependencies, which needs a kernel mechanism one implementor does not
 * justify (docs/kernel-status.md).
 *
 * @internal
 *
 * @coversNothing
 */
final class AuthWiring
{
    /**
     * Add the auth gate to this kernel's pipeline — last in, so it decides
     * closest to dispatch: headers and CSRF already wrapped the request by
     * the time a guest meets the admin gate.
     */
    public static function wire(Kernel $kernel): void
    {
        $kernel->addMiddleware(new AuthMiddleware(new UsersAuthProvider()));
    }
}
