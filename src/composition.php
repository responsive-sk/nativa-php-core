<?php

declare(strict_types = 1);

/*
 * Module composition — the ONE home for wiring that discovery cannot express.
 *
 * `src/init.php` boots an application: autoloaders, env, errors, session,
 * timezone. It names no module, and neither may this file in any way the
 * closure scanner would count as an edge. What this file holds is not wiring
 * the kernel needs — it is wiring BETWEEN INSTALLED MODULES, declared under
 * `class_exists` guards so a kernel-only checkout boots exactly as before.
 *
 * WHY this file exists at all (Boot 16, measured):
 *
 *   `OutboxDrain::drain()` registers only the handler the Outbox module can
 *   see. The security handler lives in the Audit module, because its output IS
 *   an audit row, and Outbox reaching into Audit is a module dependency the
 *   closure scanner refuses. Neither module may know about the other, so the
 *   PAIRING has to be declared from outside — and while it lived inline in
 *   `public/index.php`, the registration existed in exactly one process: the
 *   one serving HTTP.
 *
 *   A handler registration that only the web process performs is not a pairing,
 *   it is a half-wired one. Any worker that drains in a CLI boot would find an
 *   empty handler map and park every message as
 *   `No handler registered for event type` while the HTTP responses still
 *   looked perfectly healthy — which is precisely how Boot 16 hid.
 *
 * So the rule this file states is:
 *
 *   Every process that DELIVERS must wire the handlers it delivers through,
 *   and there is one list, here. `src/http.php` calls it for the request (via
 *   `public/index.php`); `bin/cms` calls it for the worker commands. A new
 *   delivery path that forgets is a bug the CompositionWiringTest names.
 *
 * Naming: module classes appear FULLY QUALIFIED AND INLINE, never in a `use`
 * statement and never in a type position. Both are load-bearing, not style:
 *
 *   - a `use Modules\…` line is what `ModuleCatalog`'s closure scan counts, and
 *     `src/` is the kernel's — an edge here would be the kernel depending on a
 *     module it must not know (CONTRIBUTING.md, "Kernel boundary");
 *   - a type position (`function f(Foo $x)`, a return annotation) is resolved
 *     REGARDLESS of any guard, which is exactly what a kernel-only checkout
 *     with no `Modules\Audit` on disk caught.
 *
 * Idempotent by construction: every call is a map write, so calling it twice
 * in one process is a no-op rather than a duplicate-handler defect.
 */

/**
 * Register the handler pairings every delivery process needs.
 *
 * Safe to call in any SAPI, in any tree, with any subset of the catalogue
 * installed: each pairing is guarded by the classes it needs.
 */
function wire_module_handlers(): void
{
    if (!class_exists('Modules\Audit\Application\Services\SecurityEventHandler')
        || !class_exists('Modules\Outbox\Interfaces\HTTP\OutboxServices')
        || !class_exists('Modules\Audit\Interfaces\HTTP\AuditServices')
    ) {
        return;
    }

    \Modules\Audit\Application\Services\SecurityEventHandler::register(
        \Modules\Outbox\Interfaces\HTTP\OutboxServices::processor(),
        \Modules\Audit\Interfaces\HTTP\AuditServices::audit(),
    );

    wire_queue_handlers();
}

/**
 * The Queue module's handlers — currently one, and the tree's first real
 * producer's consumer (Boot 32).
 *
 * Named inline, never imported, for the same reason as the pairing above: a
 * `use Modules\…` here would be the kernel depending on a module it must not
 * know. The registry is `QueueServices::handlers()` — the PROCESS-WIDE cached
 * instance, which is the one `DatabaseQueue` was built around. Registering on a
 * fresh `HandlerRegistry` compiles, loads, and leaves every job unroutable,
 * which is Boot 16 in miniature.
 */
function wire_queue_handlers(): void
{
    if (!class_exists('Modules\Queue\Interfaces\HTTP\QueueServices')
        || !class_exists('Modules\Queue\Application\Services\HandlerRegistry')
        || !class_exists('Modules\Blog\Application\Services\CoverRenderHandler')
    ) {
        return;
    }

    \Modules\Blog\Application\Services\CoverRenderHandler::register(
        \Modules\Queue\Interfaces\HTTP\QueueServices::handlers(),
    );
}