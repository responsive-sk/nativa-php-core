<?php

declare(strict_types = 1);

namespace Modules\Comments\Application\Services;

use Modules\Cardboard\Domain\Model\Card;
use Modules\Cardboard\Domain\Model\CardLink;
use Modules\Cardboard\Domain\Model\Charts\Donut;
use Modules\Cardboard\Domain\Model\Period;
use Modules\Cardboard\Domain\Model\Trend;
use Modules\Cardboard\Domain\Contracts\MetricContract;
use Modules\Cardboard\Infrastructure\Attributes\AsMetricProvider;
use Modules\Cardboard\Infrastructure\Registry\CardboardRegistry;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;

/**
 * CommentsMetric — aggregated comment metric cards for the dashboard.
 *
 * Registers 1 card:
 * 1. Comments — total in period + trend + breakdown by status (pending/approved/rejected) via Donut
 *
 * Data sources: comments.db → comments (created_at, status).
 */
#[AsMetricProvider(priority: 80)]
final readonly class CommentsMetric implements MetricContract
{
    public function registerCards(Period $period): void
    {
        try {
            $pdo = DatabaseConnectionManager::instance()->connection('comments');

            $total = $this->countInPeriod($pdo, $period->sqlInterval());
            $previous = $this->countBetweenPeriods(
                $pdo,
                $period->previousSqlInterval(),
                $period->sqlInterval(),
            );

            $trend = Trend::make($total, $previous);

            $statusCounts = $this->countByStatus($pdo, $period->sqlInterval());
            $donut = new Donut([
                ['value' => $statusCounts['pending'], 'color' => 'var(--m-warning)'],
                ['value' => $statusCounts['approved'], 'color' => 'var(--m-success)'],
                ['value' => $statusCounts['rejected'], 'color' => 'var(--m-error)'],
            ]);

            $breakdown = $statusCounts['pending'] . ' pending · '
                . $statusCounts['approved'] . ' approved · '
                . $statusCounts['rejected'] . ' rejected';

            CardboardRegistry::register(
                Card::make('comments', 'Comments', $total)
                    ->withChart($donut)
                    ->withTrend($trend)
                    ->withSubtitle('in ' . $period->label() . ' — ' . $breakdown)
                    ->withActions(
                        CardLink::make('Moderate', '/mark/comments'),
                    )
                    ->withPriority(75),
            );
        } catch (\Throwable) {
            // Database not ready — skip silently.
        }
    }

    private function countInPeriod(\PDO $pdo, string $interval): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM comments WHERE created_at >= datetime(\'now\', :interval)',
        );
        $stmt->execute([':interval' => $interval]);

        return (int) $stmt->fetchColumn();
    }

    private function countBetweenPeriods(\PDO $pdo, string $fromInterval, string $toInterval): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM comments WHERE created_at >= datetime(\'now\', :from) AND created_at < datetime(\'now\', :to)',
        );
        $stmt->execute([':from' => $fromInterval, ':to' => $toInterval]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{pending: int, approved: int, rejected: int}
     */
    private function countByStatus(\PDO $pdo, string $interval): array
    {
        $stmt = $pdo->prepare(
            'SELECT status, COUNT(*) as cnt FROM comments WHERE created_at >= datetime(\'now\', :interval) GROUP BY status',
        );
        $stmt->execute([':interval' => $interval]);

        $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $status = (string) $row['status'];
            if (isset($counts[$status])) {
                $counts[$status] = (int) $row['cnt'];
            }
        }

        return $counts;
    }
}
