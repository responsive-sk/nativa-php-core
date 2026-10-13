<?php

declare(strict_types = 1);

namespace Modules\Scheduler\Tests;

use Modules\Cardboard\Domain\Model\Period;
use Modules\Cardboard\Infrastructure\Registry\CardboardRegistry;
use Modules\Scheduler\Application\Services\ScheduleMetric;
use Modules\Scheduler\Scheduling\FileTaskMutex;
use Modules\Scheduler\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ScheduleMetricTest — beside the code it covers (module test contract:
 * drives the INSTALLED copy, skips on a kernel-only tree).
 *
 * A synthetic timetable (never the shipped one) plus a throwaway mutex
 * dir — the real outbox closures never run, `storage/` is never touched.
 */
final class ScheduleMetricTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Scheduler')) {
            $this->markTestSkipped('Scheduler is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/scheduler-metric-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0o775, true);

        CardboardRegistry::reset();
    }

    protected function tearDown(): void
    {
        CardboardRegistry::reset();

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->dir);

        parent::tearDown();
    }

    #[Test]
    public function registersScheduleCardForSyntheticTimetable(): void
    {
        $schedule = new Schedule();
        $schedule->call(static fn (): null => null)->everyMinute()->description('due-now');
        // Midnight-only: not due at any daytime run.
        $schedule->call(static fn (): null => null)->daily()->description('later');
        $guarded = $schedule->call(static fn (): null => null)
            ->everyMinute()->description('guarded')->withoutOverlapping(60);

        $mutex = new FileTaskMutex($this->dir);
        // Another process holds the guarded task's lock.
        $this->assertTrue($mutex->acquire($guarded, 3600));

        (new ScheduleMetric($schedule, new FileTaskMutex($this->dir)))
            ->registerCards(Period::SEVEN_DAYS);

        $mutex->release($guarded);

        $cards = CardboardRegistry::all();
        $this->assertCount(1, $cards);

        $card = $cards[0];
        $this->assertSame('schedule', $card->id);
        $this->assertSame('/mark/schedule', $card->actions[0]->href ?? null);
        // everyMinute is due at any wall time; daily (midnight) is due
        // only at 00:xx — so the value is 1 or 2 depending on the clock.
        // The subtitle is fully deterministic: 3 entries, 1 held.
        $this->assertContains($card->value, [1, 2]);
        $this->assertSame('3 entries · 1 overlap-held', $card->subtitle);
        $this->assertNull($card->trend);
    }

    #[Test]
    public function emptyTimetableRegistersZeroCard(): void
    {
        (new ScheduleMetric(new Schedule(), new FileTaskMutex($this->dir)))
            ->registerCards(Period::SEVEN_DAYS);

        $cards = CardboardRegistry::all();
        $this->assertCount(1, $cards);
        $this->assertSame(0, $cards[0]->value);
        $this->assertSame('0 entries · 0 overlap-held', $cards[0]->subtitle);
    }
}
