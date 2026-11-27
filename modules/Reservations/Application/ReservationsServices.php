<?php

declare(strict_types = 1);

namespace Modules\Reservations\Application;

use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Reservations\Application\Services\ReservationService;
use Modules\Reservations\Infrastructure\Persistence\Repositories\ReservationRepository;

/**
 * ReservationsServices - lazy cached service factories.
 *
 * The kernel constructs route actions without dependency injection, so the
 * shared connection and its repository are resolved here. The handler and the
 * admin actions use the same handle, which is what makes a confirmed row
 * visible to the next inbox request.
 */
final class ReservationsServices
{
    private static ?ConnectionInterface $connection = null;

    private static ?ReservationRepository $reservations = null;

    private static ?ReservationService $service = null;

    public static function connection(): ConnectionInterface
    {
        return self::$connection ??= DatabaseConnectionManager::instance()->connect('reservations');
    }

    public static function reservations(): ReservationRepository
    {
        return self::$reservations ??= new ReservationRepository(self::connection());
    }

    public static function service(): ReservationService
    {
        return self::$service ??= new ReservationService(self::reservations());
    }

    public static function reset(): void
    {
        self::$connection = null;
        self::$reservations = null;
        self::$service = null;

        DatabaseConnectionManager::instance()->close('reservations');
    }
}
