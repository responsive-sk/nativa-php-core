<?php

declare(strict_types = 1);

namespace Modules\Scheduler\Tests;

use DateTimeImmutable;
use Modules\Scheduler\Interfaces\HTTP\SchedulerServices;
use Modules\Scheduler\Scheduling\FileTaskMutex;
use Modules\Scheduler\Scheduling\RunnerOutput;
use Modules\Scheduler\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * ScheduleRunCommandTest — the exit-code seam `bin/cms schedule:run`
 * drives (SchedulerServices::run), beside the code it covers (module test
 * contract: drives the INSTALLED copy, skips on a kernel-only tree).
 *
 * The CLI branch itself stays thin (build timetable, adapt output, exit);
 * what is asserted here is its contract: per-task lines reach the output,
 * a clean timetable exits 0, a throwing due task exits 1. The mutex is a
 * throwaway temp dir — never the site's storage/.
 */
final class ScheduleRunCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Scheduler')) {
            $this->markTestSkipped('Scheduler is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/scheduler-cmd-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->dir);

        SchedulerServices::reset();

        parent::tearDown();
    }

    private function collector(): RunnerOutput
    {
        return new class implements RunnerOutput {
            /** @var list<string> */
            public array $lines = [];

            public function writeLine(string $message): void
            {
                $this->lines[] = $message;
            }
        };
    }

    #[Test]
    public function cleanTimetableExitsZeroWithPerTaskLines(): void
    {
        $schedule = new Schedule();
        $schedule->call(static fn (): null => null)->everyMinute()->description('tidy');
        $schedule->call(static fn (): null => null)->daily()->description('not-due');

        $output = $this->collector();

        $exit = SchedulerServices::run(
            $schedule,
            $output,
            new DateTimeImmutable('2026-01-01 00:07:00'),
            new FileTaskMutex($this->dir),
        );

        $this->assertSame(0, $exit);
        $this->assertContains('Executed: tidy', $output->lines);
        $this->assertContains('Executed 1 scheduled tasks.', $output->lines);
    }

    #[Test]
    public function throwingDueTaskExitsOne(): void
    {
        $schedule = new Schedule();
        $schedule->call(static function (): void {
            throw new RuntimeException('cron blew up');
        })->everyMinute()->description('unlucky');

        $output = $this->collector();

        $exit = SchedulerServices::run(
            $schedule,
            $output,
            new DateTimeImmutable('2026-01-01 00:07:00'),
            new FileTaskMutex($this->dir),
        );

        $this->assertSame(1, $exit);
        $this->assertContains('Failed: unlucky - cron blew up', $output->lines);
    }

    #[Test]
    public function shippedTimetableCarriesOutboxEntries(): void
    {
        if (!class_exists(\Modules\Outbox\Application\Services\OutboxDrain::class)) {
            $this->markTestSkipped('Outbox is not installed.');
        }

        $schedule = SchedulerServices::schedule();
        $descriptions = array_map(
            static fn ($task): ?string => $task->getDescription(),
            $schedule->tasks(),
        );

        $this->assertContains('outbox:drain', $descriptions);
        $this->assertContains('outbox:prune', $descriptions);
    }
}
