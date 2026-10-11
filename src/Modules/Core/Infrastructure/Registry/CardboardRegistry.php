<?php

declare(strict_types = 1);

namespace Modules\Core\Infrastructure\Registry;

use Modules\Cardboard\Domain\Model\Card;

/**
 * CardboardRegistry — static registry for dashboard stat cards.
 *
 * Modules register their cards here; DashboardAction iterates all()
 * to render the stat grid. Simple static array — no DI, no reflection.
 *
 * Lives in Core (shared kernel) because multiple modules register cards.
 */
final class CardboardRegistry
{
    /** @var list<Card> */
    private static array $cards = [];

    public static function register(Card $card): void
    {
        self::$cards[] = $card;
    }

    /**
     * All registered cards sorted by priority (descending).
     *
     * @return list<Card>
     */
    public static function all(): array
    {
        $cards = self::$cards;
        usort($cards, static fn (Card $a, Card $b): int => $b->priority <=> $a->priority);

        return $cards;
    }

    public static function reset(): void
    {
        self::$cards = [];
    }
}
