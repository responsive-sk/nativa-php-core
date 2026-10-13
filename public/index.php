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
 * does, and the difference is the point: there is nothing here to wire.
 *
 *   - The kernel discovers its own middleware (security headers, CSRF) through
 *     #[AsMiddleware], so no addMiddleware() call appears below.
 *   - Every other concern — locale prefixes, rate limiting, page cache, auth —
 *     belongs to a module that is NOT installed in this tree. A kernel-only
 *     tree answers what Modules\Core + Modules\Database can answer on their
 *     own; everything else is a module's job, and a module is added through
 *     `bin/cms module:add`.
 *
 * A line added here is a module dependency the kernel cannot have. If a
 * surface needs one, the surface belongs to a module (docs/kernel-status.md
 * lists what moved and why).
 *
 * The one exception below is a PAIRING, not a dependency: two installed modules
 * that must not know about each other, declared under class_exists guards so a
 * tree without either of them boots exactly as before.
 */

use Modules\Audit\Application\Services\SecurityEventHandler;
use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Core\Application\Services\DiscoveryCache;
use Modules\Core\Application\Services\DisposalRegistry;
use Modules\Core\Infrastructure\Bootstrap\Bootstrapper;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Kernel;
use Modules\Core\Interfaces\HTTP\Middleware\MiddlewareRegistry;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Outbox\Interfaces\HTTP\OutboxServices;

// ============================================================================
// BOOTSTRAP — autoloaders (Composer + PSR-4 fallback), environment,
// error/exception handlers, session, timezone
// ============================================================================

require_once __DIR__ . '/../src/init.php';

// Migrations — every module that ships an #[AsMigrator] runs it here,
// idempotently. With no feature module installed this creates the kernel's own
// tables and nothing else.
(new Bootstrapper())->boot();

// ============================================================================
// OUTBOX HANDLERS — the one pairing discovery cannot express.
//
// `OutboxDrain::drain()` (end of request) registers only the handler the Outbox
// module can see — the article-publish cache flush. The security handler lives
// in the Audit module, because its output IS an audit row, and Outbox reaching
// into Audit would be a module dependency ModuleIsolationTest refuses. Neither
// module may know about the other, so the pairing is declared here.
//
// Registered on the cached `OutboxServices::processor()` — the same instance
// drain() uses. Register it anywhere else and the handler is silently dropped,
// which is exactly what happened: sign-in recorded its `security.auth.attempt`
// and then logged `No handler registered for event type` on every drain
// (Boot 16), while the response looked fine.
//
// Guarded rather than `use`d: the kernel-only tree (no Audit, no Outbox) has to
// boot with no classes to wire, and a tree with only one of the two must not
// fatal on the other.
// ============================================================================

if (class_exists(SecurityEventHandler::class)
    && class_exists(OutboxServices::class)
    && class_exists(AuditServices::class)
) {
    SecurityEventHandler::register(
        OutboxServices::processor(),
        AuditServices::audit(),
    );
}

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
// DISPATCH — handle the incoming request and send the response
// ============================================================================

$response = $kernel->handle(Request::createFromGlobals());
$response->send();

// Disposal — run cleanup callbacks (best-effort, all run even if one throws)
DisposalRegistry::disposeAll();
