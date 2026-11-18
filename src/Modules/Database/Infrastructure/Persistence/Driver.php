<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

/**
 * Driver — the connection flavours this tree can speak.
 *
 * Two, and both are PHP extensions: `pdo_sqlite` (the default, one file per
 * module) and `pdo_pgsql` (one DSN body per module). Keeping the list here
 * means an unknown `DB_DRIVER` is refused before a connection is attempted,
 * with the supported names in the message, instead of surfacing as a DSN parse
 * error from deep inside PDO.
 */
final class Driver
{
    public const string SQLITE = 'sqlite';

    public const string PGSQL = 'pgsql';

    /**
     * @return list<string>
     */
    public static function supported(): array
    {
        return [self::SQLITE, self::PGSQL];
    }

    /**
     * @throws \InvalidArgumentException when the driver is not supported
     */
    public static function assert(string $driver): string
    {
        if (!in_array($driver, self::supported(), true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported DB_DRIVER "%s"; supported: %s',
                $driver,
                implode(', ', self::supported()),
            ));
        }

        return $driver;
    }
}
