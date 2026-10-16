<?php

declare(strict_types = 1);

namespace Tests\Http;

use Modules\Audit\Infrastructure\Persistence\Migrations\AuditDatabaseMigrator;
use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Core\Domain\AuthIdentity;
use Modules\Core\Domain\Contracts\AuthProvider;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\HTTP\Middleware\AuthMiddleware;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Outbox\Domain\SecurityEvents;
use Modules\Outbox\Infrastructure\Persistence\Migrations\OutboxDatabaseMigrator;
use Modules\Outbox\Interfaces\HTTP\OutboxServices;
use Modules\Users\Interfaces\HTTP\UsersAuthProvider;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AuthMiddlewareDenialTest — a refused admin path, from the 403 to the audit row.
 *
 * Measured 2026-10-16, while naming a real event for `authorization.yaml`'s
 * `error_ref` values: the middleware handed the bare string `authz.denied` to
 * `AuthProvider::noteEvent()`, the provider forwards what it is given, and the
 * drain dispatches by event type while `SecurityEvents::all()` holds
 * `security.authz.denied`. Both emits were run through the real processor, each
 * into a throwaway outbox:
 *
 *     BulkPublishAction  → security.authz.denied → processed, 1 audit_log row
 *     AuthMiddleware     → authz.denied          → FAILED, "No handler registered
 *                                                  for event type: authz.denied",
 *                                                  NO audit row
 *
 * So every refused admin path wrote a message the queue could never deliver: five
 * retries, then parked — while the middleware's own docblock promises the refusal
 * is "the one class of thing the application most needs to reconstruct after the
 * fact", and `SecurityEvents` promises that "no emit site spells an event type of
 * its own". The middleware now names `SecurityEvents::AUTHZ_DENIED`, inline behind
 * a `class_exists` guard exactly the way `RateLimitMiddleware` names its own.
 *
 * Three claims are pinned here, and the pair of the first two is the point:
 *
 *  1. **The refusal arrives.** A 403 from the middleware leaves one `processed`
 *     outbox message and one `audit_log` row, with the actor, the path and the
 *     missing permission in it — recorded through the composition root's handler
 *     pairing, not through a stub.
 *  2. **A name outside the vocabulary is refused, not parked.** The provider seam
 *     still takes a string, so it is the seam that must refuse `authz.denied` —
 *     otherwise the same defect returns the day somebody calls it again. The
 *     second half of that test writes the owned name and shows it lands, so the
 *     guard cannot pass by refusing everything.
 *  3. **An inactive identity is still refused without an event.** That is the one
 *     refusal the middleware answers before any policy is consulted; the model
 *     event's description says so, and this is what keeps that sentence true.
 *
 * Skips on a tree without Outbox, Audit or Users — the same module-dependency
 * contract the module tests use, because the claim is about the PAIRING between
 * them and the kernel middleware.
 */
final class AuthMiddlewareDenialTest extends TestCase
{
    private const string CLIENT_IP = '203.0.113.9';

    private const string ADMIN_PATH = '/mark/users';

    private const string REQUIRED = 'users.manage';

    private string $dir = '';

    private ?string $previousDbOutbox = null;

    private ?string $previousDbAudit = null;

    private ?string $previousRemoteAddr = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Outbox', 'Audit', 'Users'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped(sprintf('%s is not installed.', $module));
            }
        }

        $this->dir = sys_get_temp_dir() . '/authz-denial-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);

        $this->previousDbOutbox = getenv('DB_OUTBOX') ?: null;
        $this->previousDbAudit = getenv('DB_AUDIT') ?: null;
        $this->previousRemoteAddr = getenv('REMOTE_ADDR') ?: null;

        putenv('DB_OUTBOX=' . $this->dir . '/outbox.db');
        putenv('DB_AUDIT=' . $this->dir . '/audit.db');
        putenv('REMOTE_ADDR=' . self::CLIENT_IP);

        AppPaths::resetInstance();
        DatabaseConnectionManager::resetInstance();
        OutboxServices::reset();
        AuditServices::reset();

        $null = new class () implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };

        (new OutboxDatabaseMigrator(DatabaseConnectionManager::instance()->connection('outbox')))->run($null);
        (new AuditDatabaseMigrator(DatabaseConnectionManager::instance()->connection('audit')))->run($null);

        // The real pairing, from the one home that declares it. A handler map
        // assembled here by hand would prove the handler works and nothing about
        // the wiring that is supposed to install it.
        if (!function_exists('wire_module_handlers')) {
            require_once APP_BASE_PATH . '/src/composition.php';
        }

        \wire_module_handlers();
    }

    protected function tearDown(): void
    {
        OutboxServices::reset();
        AuditServices::reset();
        AppPaths::resetInstance();
        DatabaseConnectionManager::resetInstance();

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        $this->restoreEnv('DB_OUTBOX', $this->previousDbOutbox);
        $this->restoreEnv('DB_AUDIT', $this->previousDbAudit);
        $this->restoreEnv('REMOTE_ADDR', $this->previousRemoteAddr);

        parent::tearDown();
    }

    #[Test]
    public function aRefusedAdminPathReachesTheAuditLog(): void
    {
        $response = $this->middleware(new AuthIdentity('u-42', true, ['content.manage']))
            ->handle(Request::create('GET', self::ADMIN_PATH), self::passThrough());

        self::assertSame(403, $response->getStatusCode(), 'An identity without the permission must be refused.');

        $result = OutboxServices::processor()->process();

        self::assertSame(
            ['processed' => 1, 'failed' => 0],
            $result,
            'The refusal must be DELIVERED. A message the queue cannot route is the defect'
            . ' this test exists for: it retried five times, parked, and left no audit row.',
        );

        $message = $this->outboxRows()[0] ?? null;

        self::assertNotNull($message, 'A refused admin path recorded nothing at all.');
        self::assertSame('security.authz.denied', $message['event_type']);
        self::assertSame('processed', $message['status']);

        $audit = $this->auditRows();

        self::assertCount(1, $audit, 'The handler pairing must turn the event into an audit row.');
        self::assertSame('security.authz.denied', $audit[0]['action']);
        self::assertSame(self::ADMIN_PATH, $audit[0]['target']);
        self::assertSame('u-42', $audit[0]['actor_name']);
        self::assertSame(self::CLIENT_IP, $audit[0]['ip_address']);

        $detail = $audit[0]['detail'];
        self::assertIsString($detail);
        self::assertStringContainsString(self::REQUIRED, $detail);
    }

    #[Test]
    public function theRecordedPayloadCarriesExactlyTheDeclaredKeys(): void
    {
        $this->middleware(new AuthIdentity('u-42', true, []))
            ->handle(Request::create('GET', self::ADMIN_PATH), self::passThrough());

        $message = $this->outboxRows()[0] ?? null;

        self::assertNotNull($message, 'A refused admin path recorded nothing at all.');

        $encoded = $message['event_payload'];
        self::assertIsString($encoded);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($encoded, true);
        $keys = array_keys($payload);
        sort($keys);

        $declared = SecurityEvents::payloadKeys(SecurityEvents::AUTHZ_DENIED);
        sort($declared);

        // `SecurityEvents::payloadKeys()` exists so "an emit site cannot quietly
        // invent one the handler will not read" — this is that guard's caller for
        // the one emit site that builds its payload by hand.
        self::assertSame(
            $declared,
            $keys,
            'The emitted payload and the declared keys must be the same set: a key the handler'
            . ' does not read is a fact the audit viewer silently drops, and a declared key that'
            . ' is never sent is a contract nobody honours.',
        );

        self::assertSame(self::REQUIRED, $payload['required']);
    }

    #[Test]
    public function aNameOutsideTheOutboxVocabularyIsRefusedNotParked(): void
    {
        // The exact shape the defect took: the provider seam takes a string, so a
        // string that no handler claims must be refused at the seam.
        (new UsersAuthProvider())->noteEvent('authz.denied', [
            'path'     => self::ADMIN_PATH,
            'required' => self::REQUIRED,
            'userId'   => 'u-42',
            'ip'       => self::CLIENT_IP,
        ]);

        self::assertSame(
            [],
            $this->outboxRows(),
            'A name outside SecurityEvents::all() must not be written: it becomes a message the'
            . ' drain fails with "No handler registered for event type", which is how every'
            . ' refused admin path used to end.',
        );

        // The other half: the guard must not refuse the events that ARE ours.
        (new UsersAuthProvider())->noteEvent(SecurityEvents::AUTHZ_DENIED, [
            'path'     => self::ADMIN_PATH,
            'required' => self::REQUIRED,
            'userId'   => 'u-42',
            'roles'    => [],
            'ip'       => self::CLIENT_IP,
        ]);

        self::assertCount(1, $this->outboxRows(), 'The owned name must still be recorded.');
    }

    #[Test]
    public function anInactiveIdentityIsRefusedWithoutASecurityEvent(): void
    {
        $response = $this->middleware(new AuthIdentity('u-42', false, ['users.manage']))
            ->handle(Request::create('GET', self::ADMIN_PATH), self::passThrough());

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('not active', $response->getContent());

        // Documented, not a second defect to fix here: the middleware refuses an
        // inactive identity before any policy is consulted, so there is no policy
        // decision to record. The model event says so in its own description.
        self::assertSame([], $this->outboxRows());
    }

    private function middleware(AuthIdentity $identity): AuthMiddleware
    {
        return new AuthMiddleware(new readonly class ($identity, self::REQUIRED) implements AuthProvider {
            public function __construct(
                private AuthIdentity $identity,
                private string $required,
            ) {
            }

            // Answers, not decisions: the middleware is the subject here, so the
            // provider says who the request is and what the path costs.
            public function identity(Request $request): AuthIdentity
            {
                return $this->identity;
            }

            public function isProtectedPath(string $path): bool
            {
                return true;
            }

            public function requiredPermission(string $path): string
            {
                return $this->required;
            }

            public function guestOnlyPaths(): array
            {
                return [];
            }

            public function noteEvent(string $event, array $context): void
            {
            }
        });
    }

    private static function passThrough(): callable
    {
        return static fn (Request $request): Response => Response::html('next', 200);
    }

    private function restoreEnv(string $key, ?string $previous): void
    {
        if (null === $previous) {
            putenv($key);

            return;
        }

        putenv($key . '=' . $previous);
    }

    /** @return list<array<string, mixed>> */
    private function outboxRows(): array
    {
        return $this->query('outbox', 'SELECT event_type, status, event_payload, error_message FROM outbox_messages');
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(): array
    {
        return $this->query('audit', 'SELECT action, target, detail, actor_name, ip_address FROM audit_log');
    }

    /** @return list<array<string, mixed>> */
    private function query(string $connection, string $sql): array
    {
        $statement = DatabaseConnectionManager::instance()->connection($connection)->query($sql);

        self::assertNotFalse($statement, sprintf('Query failed: %s', $sql));

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }
}
