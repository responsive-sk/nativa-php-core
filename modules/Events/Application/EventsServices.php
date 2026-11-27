<?php

declare(strict_types = 1);

namespace Modules\Events\Application;

use Modules\Core\Application\Services\ShellContextProviderRegistry;
use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Events\Application\Services\EventsMailer;
use Modules\Events\Application\Services\ReservationService;
use Modules\Events\Infrastructure\Persistence\Repositories\EventRepository;
use Modules\Events\Infrastructure\Persistence\Repositories\ReservationRepository;
use Modules\Security\Application\Services\RateLimiter;

/**
 * EventsServices: lazy cached service factories for the Events module.
 *
 * The kernel constructs route actions without dependency injection, so the
 * shared connection and its repositories are resolved here. One connection is
 * kept for the repositories and the capacity service, because a transaction
 * only protects the writes made through the same handle.
 */
final class EventsServices
{
    private static ?ConnectionInterface $connection = null;

    private static ?EventRepository $events = null;

    private static ?ReservationRepository $reservations = null;

    private static ?ReservationService $reservationService = null;

    private static ?EventsMailer $mailer = null;

    private static ?RateLimiter $limiter = null;

    public static function connection(): ConnectionInterface
    {
        return self::$connection ??= DatabaseConnectionManager::instance()->connect('events');
    }

    public static function events(): EventRepository
    {
        return self::$events ??= new EventRepository(self::connection());
    }

    public static function reservations(): ReservationRepository
    {
        return self::$reservations ??= new ReservationRepository(self::connection());
    }

    public static function reservationsService(): ReservationService
    {
        return self::$reservationService ??= new ReservationService(
            self::connection(),
            self::events(),
            self::reservations(),
        );
    }

    public static function mailer(): EventsMailer
    {
        return self::$mailer ??= EventsMailer::create();
    }

    public static function limiter(): RateLimiter
    {
        return self::$limiter ??= new RateLimiter(
            DatabaseConnectionManager::instance()->connect('users'),
        );
    }

    /**
     * The public copy for one locale, owned by the Locales seed.
     *
     * @return array<string, string>
     */
    public static function copy(string $locale): array
    {
        return ShellContextProviderRegistry::copy('events', $locale);
    }

    /**
     * Test seam: replace the mailer without opening a transport.
     */
    public static function setMailer(?EventsMailer $mailer): void
    {
        self::$mailer = $mailer;
    }

    /**
     * Reset cached instances and close the module connection (test isolation).
     */
    public static function reset(): void
    {
        self::$connection = null;
        self::$events = null;
        self::$reservations = null;
        self::$reservationService = null;
        self::$mailer = null;
        self::$limiter = null;

        DatabaseConnectionManager::instance()->close('events');
    }
}
