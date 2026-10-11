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
 *   - Every other concern — locale prefixes, rate limiting, page cache, auth,
 *     outbox handlers — belongs to a module that is NOT installed in this tree.
 *     A kernel-only tree answers what Modules\Core + Modules\Database can answer
 *     on their own; everything else is a module's job, and a module is added
 *     through `bin/cms module:add`.
 *
 * A line added here is a module dependency the kernel cannot have. If a
 * surface needs one, the surface belongs to a module (docs/kernel-status.md
 * lists what moved and why).
 */

use Modules\Core\Application\Services\DisposalRegistry;
use Modules\Core\Infrastructure\Bootstrap\Bootstrapper;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Kernel;
use Modules\Core\Interfaces\HTTP\Router;

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
// ROUTER — scan module action directories for #[Route] attributes
// ============================================================================

$router = new Router([
    APP_BASE_PATH . '/src/Modules',
]);

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
