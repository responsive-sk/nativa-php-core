<?php

declare(strict_types = 1);

namespace Modules\Audit\Application\Services;

use Modules\Outbox\Application\Services\OutboxProcessor;
use Modules\Outbox\Domain\Model\OutboxMessage;
use Modules\Outbox\Domain\SecurityEvents;

/**
 * SecurityEventHandler — turns recorded security events into audit rows.
 *
 * ## Idempotency is the contract, not a nicety
 *
 * Delivery here is at-least-once, by design and by limitation: `outbox.db` is a
 * separate file from `audit.db`, so an event cannot be written in the same
 * transaction as its audit row. The compensating property is that this handler
 * must be safe to run TWICE for one event.
 *
 * It keys the audit row's `id` on the outbox message id, which
 * `OutboxMessage::id()` already guarantees to be unique. A replay therefore
 * collides on the primary key and is discarded — deterministically, and without
 * having to guess whether two events "look the same". Content-based dedupe
 * would be the tempting alternative and it is wrong: two genuinely distinct
 * denials on the same path by the same user would collapse into one, and the
 * second attempt is exactly what an investigation wants to see.
 *
 * ## Why this class lives in the Audit module
 *
 * Its entire job is to write an audit row, and `audit_log` is Audit's table.
 * Putting it in Outbox would have meant Outbox reaching into
 * `Audit\Interfaces` to get a service, which `ModuleIsolationTest` correctly
 * refuses. Wiring lives in the composition root instead (`public/index.php`),
 * which is where cross-module knowledge belongs.
 *
 * ## Insert only
 *
 * `audit_log` carries `BEFORE UPDATE` and `BEFORE DELETE` triggers that abort
 * the statement — the log is append-only by construction. All processing
 * bookkeeping ("this was handled") therefore stays in `outbox_messages`, which
 * is exactly why the two are separate databases rather than one clever table.
 *
 * @internal
 *
 * @coversNothing
 */
final readonly class SecurityEventHandler
{
    public function __construct(private AuditService $audit)
    {
    }

    /**
     * Bind every security event type on the processor.
     */
    public static function register(OutboxProcessor $processor, AuditService $audit): void
    {
        $handler = new self($audit);

        foreach (SecurityEvents::all() as $type) {
            $processor->registerHandler($type, static function (OutboxMessage $m) use ($handler): void {
                $handler->handle($m);
            });
        }
    }

    /**
     * Write the audit row for one event.
     *
     * A collision on the primary key means this exact event was already
     * recorded — the expected outcome of a replay, not an error. Anything else
     * propagates, so `OutboxProcessor` leaves the message retryable instead of
     * marking a security record as delivered when it was not.
     */
    public function handle(OutboxMessage $message): void
    {
        $payload = $message->payload();

        try {
            $this->audit->logWithId(
                $message->id(),
                $message->eventType(),
                self::detail($message->eventType(), $payload),
                self::actor($payload, $message),
                self::target($message->eventType(), $payload),
                self::ip($payload),
            );
        } catch (\PDOException $e) {
            if (self::isDuplicate($e)) {
                error_log(sprintf(
                    '[outbox] security event %s already recorded (id=%s), discarding replay',
                    $message->eventType(),
                    $message->id(),
                ));

                return;
            }

            throw $e;
        }

        if ('debug' === strtolower((string) (\getenv('LOG_LEVEL') ?: 'info'))) {
            error_log(sprintf(
                '[outbox] security handler wrote audit=%s for %s',
                $message->id(),
                $message->eventType(),
            ));
        }
    }

    /**
     * Who acted. Falls back to the metadata the emit site attached, then to
     * 'system' — an unattributed security row is still worth having.
     *
     * @param array<string, mixed> $payload
     */
    private static function actor(array $payload, OutboxMessage $message): string
    {
        $userId = $payload['userId'] ?? null;

        if (is_string($userId) && '' !== $userId) {
            return $userId;
        }

        $email = $payload['email'] ?? null;
        if (is_string($email) && '' !== $email) {
            return $email;
        }

        $metadata = $message->metadata();
        $actor = is_array($metadata) ? ($metadata['actor'] ?? null) : null;

        return is_string($actor) && '' !== $actor ? $actor : 'system';
    }

    /**
     * What the event was about — the path, the role, the rate-limit key.
     *
     * @param array<string, mixed> $payload
     */
    private static function target(string $eventType, array $payload): string
    {
        return match ($eventType) {
            SecurityEvents::AUTHZ_DENIED      => self::str($payload['path']),
            SecurityEvents::USER_CHANGED      => self::str($payload['userId']),
            SecurityEvents::ROLE_CHANGED      => self::str($payload['roleId']),
            SecurityEvents::AUTH_ATTEMPT      => self::str($payload['email']),
            SecurityEvents::RATELIMIT_TRIPPED => self::str($payload['key']),
            default                          => '',
        };
    }

    /**
     * The human-readable part, one line per event so the audit viewer shows
     * something a person can act on without cross-referencing the payload.
     *
     * @param array<string, mixed> $payload
     */
    private static function detail(string $eventType, array $payload): string
    {
        return match ($eventType) {
            SecurityEvents::AUTHZ_DENIED      => sprintf(
                'Refused %s (needs %s, roles: %s)',
                self::str($payload['path']),
                self::str($payload['required']),
                self::roles($payload['roles'] ?? []),
            ),
            SecurityEvents::USER_CHANGED      => sprintf('User %s', self::str($payload['operation'])),
            SecurityEvents::ROLE_CHANGED      => sprintf('Role %s', self::str($payload['operation'])),
            SecurityEvents::AUTH_ATTEMPT      => sprintf(
                'Authentication %s',
                self::str($payload['outcome']),
            ),
            SecurityEvents::RATELIMIT_TRIPPED => sprintf(
                'Rate limit tripped (%s)',
                self::str($payload['scope']),
            ),
            default                          => '',
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function ip(array $payload): ?string
    {
        $ip = $payload['ip'] ?? null;

        return is_string($ip) && '' !== $ip ? $ip : null;
    }

    /**
     * @param mixed $value
     */
    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param mixed $roles
     *
     * @return string
     */
    private static function roles(mixed $roles): string
    {
        if (!is_array($roles)) {
            return '-';
        }

        $names = array_values(array_filter(array_map(self::str(...), $roles)));

        return [] === $names ? '-' : implode(',', $names);
    }

    /**
     * SQLite reports a primary-key collision as a constraint failure; the
     * message text is the only signal available, so match it narrowly rather
     * than swallowing every PDO error.
     */
    private static function isDuplicate(\PDOException $e): bool
    {
        return str_contains(strtolower($e->getMessage()), 'unique')
            && str_contains(strtolower($e->getMessage()), 'audit_log');
    }
}
