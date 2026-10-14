<?php

declare(strict_types = 1);

namespace Modules\Queue\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Queue\Application\Services\DatabaseQueue;
use Modules\Queue\Application\Services\HandlerRegistry;
use Modules\Queue\Infrastructure\Persistence\Migrations\QueueDatabaseMigrator;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * DatabaseQueueTest — beside the code it covers (module test contract:
 * drives the INSTALLED copy, skips on a kernel-only tree). Covers the
 * HandlerRegistry too (Task 7 needs no new file): registered handlers run
 * with args, unknown handlers fail the job instead of fataling.
 *
 * Every test runs against a throwaway SQLite file — never a real module
 * database.
 */
final class DatabaseQueueTest extends TestCase
{
    private string $dbFile;

    private PDO $pdo;

    private HandlerRegistry $handlers;

    private DatabaseQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Queue')) {
            $this->markTestSkipped('Queue is not installed.');
        }

        $this->dbFile = sys_get_temp_dir() . '/queue-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->dbFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $null = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
        (new QueueDatabaseMigrator($this->pdo))->run($null);

        $this->handlers = new HandlerRegistry();
        $this->queue = new DatabaseQueue($this->pdo, $this->handlers);
    }

    protected function tearDown(): void
    {
        unset($this->queue, $this->pdo);

        if (isset($this->dbFile) && is_file($this->dbFile)) {
            unlink($this->dbFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function pushReserveDeleteRoundTrip(): void
    {
        $this->handlers->register('mail.send', static fn (array $args): string => $args['to']);

        $id = $this->queue->push('mail.send', ['to' => 'a@example.com']);

        $this->assertSame(32, strlen($id));

        $job = $this->queue->reserve();

        $this->assertNotNull($job);
        $this->assertSame($id, $job->id);
        $this->assertSame('mail.send', $job->handler);
        $this->assertSame(['to' => 'a@example.com'], $job->args);
        $this->assertSame(1, $job->attempts);

        $this->queue->delete($job->id);

        $this->assertNull($this->queue->reserve());
        $this->assertSame(0, $this->countRows('jobs'));
    }

    #[Test]
    public function failingHandlerRetriesToMaxThenLandsInFailedJobs(): void
    {
        $calls = 0;
        $this->handlers->register('always.fails', static function () use (&$calls): void {
            $calls++;

            throw new RuntimeException('boom');
        });

        $this->queue->push('always.fails', [], maxAttempts: 2);

        $result = $this->queue->work();

        $this->assertSame(['processed' => 0, 'failed' => 1], $result);
        $this->assertSame(2, $calls, 'handler ran once per attempt');
        $this->assertSame(0, $this->countRows('jobs'));

        /** @var array<string, mixed>|false $failed */
        $failed = $this->pdo->query('SELECT * FROM failed_jobs')->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($failed);
        $this->assertSame('always.fails', $failed['handler']);
        $this->assertSame(2, (int) $failed['attempts']);
        $this->assertSame('boom', $failed['error']);
    }

    #[Test]
    public function secondReserveRefusedWhileHeld(): void
    {
        $this->queue->push('mail.send', ['to' => 'a@example.com']);

        $first = $this->queue->reserve();

        $this->assertNotNull($first);

        // Same row, second contender: the guarded UPDATE matches zero rows.
        $this->assertNull($this->queue->reserve());

        $this->queue->release($first->id);

        $again = $this->queue->reserve();

        $this->assertNotNull($again);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(2, $again->attempts, 'attempts stay authoritative across release');
    }

    #[Test]
    public function delayedJobInvisibleUntilAvailable(): void
    {
        $this->queue->push('mail.send', [], delaySeconds: 3600);

        $this->assertNull($this->queue->reserve());
        $this->assertSame(0, $this->queue->work()['processed']);
        $this->assertSame(1, $this->countRows('jobs'), 'delayed job waits, not lost');
    }

    #[Test]
    public function unknownHandlerFailsTheJobNeverFatals(): void
    {
        $this->assertFalse($this->handlers->has('nope.missing'));

        $this->queue->push('nope.missing', ['x' => 1], maxAttempts: 1);

        $result = $this->queue->work();

        $this->assertSame(['processed' => 0, 'failed' => 1], $result);

        /** @var array<string, mixed>|false $failed */
        $failed = $this->pdo->query('SELECT * FROM failed_jobs')->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($failed);
        $this->assertStringContainsString('nope.missing', (string) $failed['error']);
    }

    #[Test]
    public function successfulHandlerDeletesAndCounts(): void
    {
        $seen = [];
        $this->handlers->register('track.seen', static function (array $args) use (&$seen): void {
            $seen[] = $args['n'];
        });

        $this->queue->push('track.seen', ['n' => 1]);
        $this->queue->push('track.seen', ['n' => 2]);

        $result = $this->queue->work();

        $this->assertSame(['processed' => 2, 'failed' => 0], $result);
        $this->assertSame([1, 2], $seen);
        $this->assertSame(0, $this->countRows('jobs'));
        $this->assertSame(0, $this->countRows('failed_jobs'));
    }

    #[Test]
    public function corruptPayloadParkedWithoutBlockingTheRest(): void
    {
        $this->handlers->register('ok.handler', static fn (): null => null);
        $this->queue->push('ok.handler');

        $poisonId = bin2hex(random_bytes(16));
        $this->pdo->prepare(
            "INSERT INTO jobs (id, queue, handler, payload, attempts, max_attempts, available_at)"
            . " VALUES (:id, 'default', 'ok.handler', '{not-json', 0, 3, '2000-01-01 00:00:00')",
        )->execute([':id' => $poisonId]);

        $result = $this->queue->work(limit: 10);

        // The good job processed, the poison row parked — the loop never spun.
        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $this->countRows('jobs'));

        /** @var array<string, mixed>|false $failed */
        $failed = $this->pdo->query("SELECT * FROM failed_jobs WHERE id = '{$poisonId}'")->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($failed);
        $this->assertStringContainsString('Corrupt payload', (string) $failed['error']);
    }

    private function countRows(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
}
