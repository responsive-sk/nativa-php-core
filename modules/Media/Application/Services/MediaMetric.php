<?php

declare(strict_types = 1);

namespace Modules\Media\Application\Services;

use Modules\CardboardKit\Domain\Model\Card;
use Modules\CardboardKit\Domain\Model\CardLink;
use Modules\CardboardKit\Domain\Model\Period;
use Modules\CardboardKit\Domain\Model\Trend;
use Modules\CardboardKit\Domain\Contracts\MetricContract;
use Modules\CardboardKit\Infrastructure\Attributes\AsMetricProvider;
use Modules\CardboardKit\Infrastructure\Registry\CardboardRegistry;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;

/**
 * MediaMetric — aggregated media uploads metric cards for the dashboard.
 *
 * Registers 1 card:
 * 1. Media — total files + new uploads in period + trend
 *
 * Data sources: media.db → assets (created_at).
 */
#[AsMetricProvider(priority: 70)]
final readonly class MediaMetric implements MetricContract
{
    public function registerCards(Period $period): void
    {
        try {
            $pdo = DatabaseConnectionManager::instance()->connection('media');

            $totalStmt = $pdo->query('SELECT COUNT(*) FROM assets');
            $total = false !== $totalStmt ? (int) $totalStmt->fetchColumn() : 0;
            $newInPeriod = $this->countInPeriod($pdo, $period->sqlInterval());
            $newPreviously = $this->countBetweenPeriods(
                $pdo,
                $period->previousSqlInterval(),
                $period->sqlInterval(),
            );

            $trend = Trend::make($newInPeriod, $newPreviously);

            CardboardRegistry::register(
                Card::make('media', 'Media files', $total)
                    ->withTrend($trend)
                    ->withSubtitle('uploads in ' . $period->label())
                    ->withActions(
                        CardLink::make('List', '/mark/media'),
                        CardLink::make('Upload', '/mark/media/upload'),
                    )
                    ->withPriority(65),
            );
        } catch (\Throwable) {
            // Database not ready — skip silently.
        }
    }

    private function countInPeriod(\PDO $pdo, string $interval): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM assets WHERE created_at >= datetime(\'now\', :interval)',
        );
        $stmt->execute([':interval' => $interval]);

        return (int) $stmt->fetchColumn();
    }

    private function countBetweenPeriods(\PDO $pdo, string $fromInterval, string $toInterval): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM assets WHERE created_at >= datetime(\'now\', :from) AND created_at < datetime(\'now\', :to)',
        );
        $stmt->execute([':from' => $fromInterval, ':to' => $toInterval]);

        return (int) $stmt->fetchColumn();
    }
}
