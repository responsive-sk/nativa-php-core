<?php

declare(strict_types = 1);

// EARLY ERROR LOGGING — capture crashes before bootstrap runs. Writes to
// storage/logs/ (created below if missing).
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
$_earlyLogDir = dirname(__DIR__) . '/storage/logs';
if (!is_dir($_earlyLogDir)) {
    @mkdir($_earlyLogDir, 0755, true);
}
if (is_dir($_earlyLogDir)) {
    ini_set('log_errors', '1');
    ini_set('error_log', $_earlyLogDir . '/php-error.log');
    ini_set('display_errors', '0');
}
unset($_earlyLogDir);

/*
 * Public entry point — the KERNEL composition root.
 *
 * This file is the kernel's half of what nativa-minimal's public/index.php
 * does. Most of the pipeline assembles itself; the ONE stage left that needs
 * a dependency discovery cannot supply is wired below, behind one
 * `class_exists` guard.
 *
 *   - The kernel discovers its own middleware (security headers, CSRF)
 *     through #[AsMiddleware], and the admin gate through #[AsAuthProvider]
 *     (`AuthProviderRegistry`) — no call is needed for those, and no module
 *     class is named here for auth.
 *   - Rate limiting takes a constructor argument (`RateLimitMiddleware` a
 *     limiter factory), so the composition root adds it explicitly: present
 *     when its module is installed, inert when it is not. Each block prints
 *     one line ([rate] … / [auth] …), always exactly one, so a deployment can
 *     tell "not installed" from "broken" without reading code. In a
 *     kernel-only tree the guard fails and the registry finds no provider,
 *     nothing is wired, and /mark answers 404 — never an unguarded 200.
 *   - Locale prefixes and the page cache remain a module's job; a module is
 *     added through `bin/cms module:add`.
 *
 * A line added here is a module dependency the kernel cannot have. If one is
 * needed it must be behind `class_exists`; if the surface itself needs a
 * module, the surface belongs to a module (docs/kernel-status.md lists what
 * moved and why).
 *
 * The one exception is a PAIRING, not a dependency: two installed modules that
 * must not know about each other. It lives in `src/composition.php` and is
 * CALLED from here, because a pairing declared in this file existed only in
 * this process — see the note at the call.
 */

use Modules\Core\Application\Services\AuthProviderRegistry;
use Modules\Core\Application\Services\DiscoveryCache;
use Modules\Core\Application\Services\DisposalRegistry;
use Modules\Core\Infrastructure\Bootstrap\Bootstrapper;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Kernel;
use Modules\Core\Interfaces\HTTP\Middleware\AuthMiddleware;
use Modules\Core\Interfaces\HTTP\Middleware\MiddlewareRegistry;
use Modules\Core\Interfaces\HTTP\Middleware\RateLimitMiddleware;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Security\Application\Services\RateLimiter as SecurityRateLimiter;

// ============================================================================
// BOOTSTRAP — autoloaders (Composer + PSR-4 fallback), environment,
// error/exception handlers, session, timezone, module composition
// ============================================================================

require_once __DIR__ . '/../src/init.php';
require_once __DIR__ . '/../src/composition.php';

// Migrations — every module that ships an #[AsMigrator] runs it here,
// idempotently. With no feature module installed this creates the kernel's own
// tables and nothing else.
(new Bootstrapper())->boot();

// ============================================================================
// OUTBOX HANDLERS — the one pairing discovery cannot express.
//
// The Audit↔Outbox pairing is declared in `src/composition.php`, not here, and
// this file CALLS it rather than repeating it. It used to live inline below,
// which meant it existed in exactly one process — the one serving HTTP — while
// every CLI boot (`bin/cms`, a worker) drained an empty handler map and parked
// each message as `No handler registered for event type` with the HTTP
// responses still looking perfectly healthy (Boot 16). The pairing is the same
// line of code either way; what changed is that it now has ONE home, which the
// worker shares.
wire_module_handlers();

// ============================================================================
// ROUTER — scan module action directories for #[Route] attributes
// ============================================================================

// ROUTER — scan module action directories for #[Route] attributes, through
// the fingerprint-validated discovery cache when it is fresh. Any cache
// failure degrades to a direct scan: a corrupt cache costs one scan, never
// a 500 (RFC-1).
$actionDirs = [
    APP_BASE_PATH . '/src/Modules',
];

try {
    $discovery = DiscoveryCache::fromAppPaths();
    $payload = $discovery->build(
        static fn (): array => (new Router($actionDirs))->toEntries(),
        static fn (): array => MiddlewareRegistry::discoveredClasses(),
    );
    $router = Router::fromEntries($actionDirs, $payload['routes']);
    MiddlewareRegistry::prime($payload['middleware']);
} catch (\Throwable $e) {
    error_log('[discovery] cache fallback to direct scan: ' . $e->getMessage());
    MiddlewareRegistry::reset();
    $router = new Router($actionDirs);
}

// ============================================================================
// KERNEL — middleware pipeline + dispatch
// ============================================================================

$kernel = new Kernel($router);

// ============================================================================
// WIRING — the pipeline stages auto-discovery cannot construct.
//
// Order IS the pipeline: the kernel runs the list front to back, so rate
// limiting is added before auth and therefore sits outside it — a flood is
// counted before a session is looked at — and both sit inside the discovered
// headers/CSRF. Each block logs exactly one line, always, so a deployment
// can tell "not installed" from "broken" without reading code.
// ============================================================================

// The factory yields an `object` because that is RateLimitMiddleware's own
// declared shape (`\Closure(): object`) — its product is verified at runtime
// by isLimiter(), never trusted at compile time. Nothing about the module's
// class appears in a TYPE position here on purpose: a class_exists guard
// protects the `new` below, but a return-type annotation is resolved
// regardless of any guard, which is exactly what a kernel-only checkout
// (no Modules\Security on disk) caught.
if (class_exists(SecurityRateLimiter::class)) {
    $kernel->addMiddleware(new RateLimitMiddleware(
        static fn (): object => new SecurityRateLimiter(
            DatabaseConnectionManager::instance()->connection('users'),
        ),
    ));
    error_log('[rate] wired RateLimit');
} else {
    error_log('[rate] absent — no Security module');
}

// The admin gate: the provider is DISCOVERED (`#[AsAuthProvider]`), so this
// file names no module class for auth at all. `first()` returns null in a tree
// with no provider module, and null wires nothing — absence removes the
// middleware, not the request (the port's own rule), which is what keeps /mark
// a 404 kernel-only instead of an unguarded 200. The kernel-level tests ask the
// same registry the same question, so the pipeline the suite proves and the
// pipeline this file runs cannot drift apart.
$authProvider = AuthProviderRegistry::first();

if (null !== $authProvider) {
    $kernel->addMiddleware(new AuthMiddleware($authProvider));
    error_log('[auth] wired ' . $authProvider::class);
} else {
    error_log('[auth] absent — no provider module');
}

// ============================================================================
// DISPATCH — handle the incoming request and send the response
// ============================================================================

$response = $kernel->handle(Request::createFromGlobals());
$response->send();

// Disposal — run cleanup callbacks (best-effort, all run even if one throws)
DisposalRegistry::disposeAll();
