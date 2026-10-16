<?php

declare(strict_types = 1);

namespace Modules\Queue\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Queue\Infrastructure\Persistence\Migrations\QueueDatabaseMigrator;
use Modules\Queue\Interfaces\HTTP\QueueServices;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * QueueWorkCommandTest — the seam `bin/cms queue:work` drives, beside the code
 * it covers (module test contract: drives the INSTALLED copy, skips on a
 * kernel-only tree).
 *
 * `DatabaseQueue::work()` had NO caller in this tree before the command
 * existed — reachable only from an inline `php` heredoc inside the CI gate,
 * which is not something an operator can put in crontab. That is why the
 * semantics live HERE, on the module's own locator, and the CLI only prints:
 * exactly the split `SchedulerServices::run()` keeps for `schedule:run`.
 *
 * Every test points `DB_QUEUE` at a throwaway SQLite file and resets the three
 * singletons that cache the path — a test that delivered through the real
 * `storage/data/queue.db` would be a test that eats an operator's backlog.
 */
final class QueueWorkCommandTest extends TestCase
{
    private string $dbFile;

    private ?string $previousDbQueue = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Queue')) {
            $this->markTestSkipped('Queue is not installed.');
        }

        $this->previousDbQueue = getenv('DB_QUEUE') ?: null;
        $this->dbFile = sys_get_temp_dir() . '/queue-work-' . bin2hex(random_bytes(8)) . '.db';

        putenv('DB_QUEUE=' . $this->dbFile);
        AppPaths::resetInstance();
        DatabaseConnectionManager::resetInstance();
        QueueServices::reset();

        $null = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
        (new QueueDatabaseMigrator(DatabaseConnectionManager::instance()->connection('queue')))->run($null);
    }

    protected function tearDown(): void
    {
        QueueServices::reset();

        if (isset($this->dbFile) && is_file($this->dbFile)) {
            unlink($this->dbFile);
        }

        if (isset($this->dbFile) && is_file($this->dbFile . '-wal')) {
            unlink($this->dbFile . '-wal');
        }

        if (isset($this->dbFile) && is_file($this->dbFile . '-shm')) {
            unlink($this->dbFile . '-shm');
        }

        if (null === $this->previousDbQueue) {
            putenv('DB_QUEUE');
        } else {
            putenv('DB_QUEUE=' . $this->previousDbQueue);
        }

        AppPaths::resetInstance();
        DatabaseConnectionManager::resetInstance();

        parent::tearDown();
    }

    #[Test]
    public function aRoutableJobIsDeliveredAndTheRunExitsZero(): void
    {
        $seen = [];
        QueueServices::handlers()->register('smoke.known', static function (array $args) use (&$seen): void {
            $seen[] = $args;
        });

        $id = QueueServices::queue()->push('smoke.known', ['from' => 'admin-smoke']);

        $result = QueueServices::work();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, QueueServices::exitCode($result));
        $this->assertSame([['from' => 'admin-smoke']], $seen);
        $this->assertNull(QueueServices::queue()->findJob($id), 'a delivered job leaves `jobs`.');
    }

    #[Test]
    public function anUnroutableJobIsParkedAndTheRunExitsOne(): void
    {
        $id = QueueServices::queue()->push('smoke.unregistered', [], maxAttempts: 1);

        $result = QueueServices::work();

        $this->assertSame(0, $result['processed']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, QueueServices::exitCode($result));

        $parked = QueueServices::queue()->findFailed($id);
        $this->assertNotNull($parked, 'the job is parked, not left to be retried forever.');
        $this->assertStringContainsString('smoke.unregistered', (string) $parked['error']);
    }

    #[Test]
    public function aFailingJobBurnsItsAttemptsInsideOneBatchAndIsThenParked(): void
    {
        // Measured, and the reason this reads the way it does: `release()`
        // writes `available_at = now`, so the row is available again the next
        // loop iteration. A throwing handler is therefore retried WITHIN the
        // batch — not once per cron tick — until it exhausts max_attempts, and
        // then parked. What reaches `failed` is "parked in failed_jobs", which
        // is why the exit code is still the number an operator has to look at.
        QueueServices::handlers()->register('smoke.flaky', static function (array $args): void {
            throw new \RuntimeException('not yet');
        });

        $id = QueueServices::queue()->push('smoke.flaky', [], maxAttempts: 3);

        $result = QueueServices::work();

        $this->assertSame(0, $result['processed']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, QueueServices::exitCode($result));

        $parked = QueueServices::queue()->findFailed($id);
        $this->assertNotNull($parked);
        $this->assertSame(3, (int) $parked['attempts'], 'every attempt was spent, none silently skipped.');
        $this->assertSame('not yet', $parked['error']);
    }

    #[Test]
    public function aJobReleasedWithinTheBatchLimitStaysQueuedAndExitsZero(): void
    {
        // The counterpart, and the one the exit code turns on: while attempts
        // remain, a throwing job is a RETRY, not a failure. Reaching that state
        // needs a batch smaller than max_attempts, because the release is
        // immediate (see above) — so a red cron every tick for a retry would
        // train an operator to ignore the number that means something.
        QueueServices::handlers()->register('smoke.flaky', static function (array $args): void {
            throw new \RuntimeException('not yet');
        });

        $id = QueueServices::queue()->push('smoke.flaky', [], maxAttempts: 3);

        $result = QueueServices::work(null, 1);

        $this->assertSame(0, $result['processed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, QueueServices::exitCode($result));

        $job = QueueServices::queue()->findJob($id);
        $this->assertNotNull($job, 'the job is still queued for another attempt.');
        $this->assertNull($job['reserved_at'], 'and it is released, not left reserved by a worker that is gone.');
        $this->assertSame(1, (int) $job['attempts'], 'exactly one attempt was spent.');
    }

    #[Test]
    public function aNamedQueueIsWorkedAlone(): void
    {
        QueueServices::handlers()->register('smoke.known', static fn (array $args): null => null);

        $mail = QueueServices::queue()->push('smoke.known', ['q' => 'mail'], queue: 'mail');
        $other = QueueServices::queue()->push('smoke.known', ['q' => 'reports'], queue: 'reports');

        $result = QueueServices::work('mail');

        $this->assertSame(1, $result['processed']);
        $this->assertNull(QueueServices::queue()->findJob($mail));
        $this->assertNotNull(QueueServices::queue()->findJob($other), 'another queue is left alone.');
    }

    #[Test]
    public function aLimitBelowOneIsClampedRatherThanSilentlyDeliveringNothing(): void
    {
        // `max(1, $limit)` exists because `schedule:work`-style cron wiring and
        // a hand-typed `--limit=0` both reach this call; delivering nothing
        // would look like a healthy run that delivered nothing.
        QueueServices::handlers()->register('smoke.known', static fn (array $args): null => null);

        QueueServices::queue()->push('smoke.known');
        QueueServices::queue()->push('smoke.known');

        $result = QueueServices::work(null, 0);

        $this->assertSame(1, $result['processed']);
        $this->assertCount(1, QueueServices::queue()->listJobs());
    }

    #[Test]
    public function theWorkedQueueAndTheSharedRegistryAreTheCachedOnes(): void
    {
        // The Boot 16 failure mode in miniature: registering into a fresh
        // HandlerRegistry (or a fresh DatabaseQueue) leaves the instance the
        // worker actually uses with an empty map, and every job is then parked
        // as unroutable while the response looks fine.
        $this->assertSame(QueueServices::queue(), QueueServices::queue());
        $this->assertSame(QueueServices::handlers(), QueueServices::handlers());
    }
}