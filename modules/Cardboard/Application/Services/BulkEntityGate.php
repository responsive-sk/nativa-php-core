<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Application\Services;

use Modules\Core\Infrastructure\Http\Response;
use Modules\Users\Domain\AdminRoutePolicy;
use Modules\Users\Domain\Entities\User;

/**
 * BulkEntityGate — may this user run this bulk operation on this entity?
 *
 * `/mark/bulk-publish` and `/mark/bulk-delete` are ONE route each, but the
 * POST body names the entity they act on. That made the path-level permission
 * table insufficient on its own: an author holding `content.manage` passed the
 * bulk route's row and then posted `entity=users`, reaching user deletion
 * through a content permission. Verified live before this gate existed.
 *
 * The rule is therefore two-layered, cheapest first:
 *
 *  1. the path row (`content.manage` for both bulk routes) — "may reach a
 *     bulk endpoint at all";
 *  2. this gate — "may run it on THIS entity".
 *
 * Refusal is a 403 naming the permission, matching `AuthMiddleware`'s wording
 * so one message shape covers every authorization failure in the panel.
 *
 * @internal
 *
 * @coversNothing
 */
final class BulkEntityGate
{
    /**
     * Refuse the request, or return null when the caller may proceed.
     *
     * Called BEFORE any id is parsed or acted on: a check that runs after the
     * first delete is not a check.
     *
     * The caller passes the session user rather than this class resolving it:
     * `AuthServices` lives in `Users\Interfaces`, and reaching across module
     * boundaries from an Application-layer service is exactly what
     * `ModuleIsolationTest` bans. The HTTP layer may do it; this layer cannot.
     */
    public static function refuse(string $entity, string $action, ?User $user): ?Response
    {
        $required = AdminRoutePolicy::permissionForEntity($entity);

        if (null === $required) {
            // An unrecognised entity is not "probably fine" — a bulk form for a
            // new entity type must be classified here before it can be run.
            self::log($action, $entity, 'unknown-entity', $user?->id());

            return self::forbidden($entity, 'no permission policy covers this entity');
        }

        if (null !== $user && $user->hasPermission($required)) {
            return null;
        }

        self::log($action, $entity, $required, $user?->id());

        return self::forbidden($entity, sprintf('the %s permission is required', $required));
    }

    private static function log(string $action, string $entity, string $required, ?string $userId): void
    {
        error_log(sprintf(
            '[bulk-authz] refused %s entity=%s required=%s user=%s',
            $action,
            $entity,
            $required,
            $userId ?? 'anonymous',
        ));
    }

    private static function forbidden(string $entity, string $because): Response
    {
        $safe = htmlspecialchars($entity, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return Response::html(sprintf(
            'Forbidden — bulk operation on "%s" is not permitted: %s.',
            $safe,
            $because,
        ), 403);
    }
}
