<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

/**
 * DsnBuilder — the one place a target becomes a PDO DSN.
 *
 * The target means the same thing in both drivers: "the database this module
 * owns". SQLite reads it as a file path, PostgreSQL as the DSN body
 * (`host=…;port=…;dbname=…`), because a DSN body is what PDO expects after the
 * scheme. Nothing here decides read-only routing; that is a session setting in
 * `SessionSettings`.
 */
final class DsnBuilder
{
    public static function build(string $driver, string $target): string
    {
        Driver::assert($driver);

        return Driver::SQLITE === $driver
            ? 'sqlite:' . $target
            : 'pgsql:' . $target;
    }
}
