<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Application\Services;

use PDO;
use PDOException;
use RuntimeException;

/**
 * SqliteJournal — the WAL half of the bridge, stated once.
 *
 * `DatabaseConnectionManager` sets `journal_mode = WAL`, so a commit can sit
 * in `<file>-wal` while the database file itself has not changed. **Measured
 * 2026-10-31 on ClickHouse 26.11.1.973: the SQLite engine reads those pages**
 * — a row committed to the WAL was visible through the attached database, so
 * the page-level concern the proposal assumed does not apply to ClickHouse.
 * The warning stays for the other readers: a file copied or read without
 * SQLite does not see the WAL, and folding it in is still what makes the file
 * self-contained. `attach`/`status` must say the same thing, so the wording
 * and the checkpoint live here instead of in both commands.
 *
 * A checkpoint is explicit (proposal, Decision 6): it is `TRUNCATE`, it runs
 * through a read-only handle, and no default path calls it.
 */
final class SqliteJournal
{
    /**
     * The line an operator sees for a non-empty WAL, or null when there is
     * nothing to fold in.
     */
    public static function warning(string $databaseName, string $databasePath): ?string
    {
        $wal = $databasePath . '-wal';

        if (!is_file($wal)) {
            return null;
        }

        $size = filesize($wal);

        if (false === $size || 0 === $size) {
            return null;
        }

        return sprintf(
            '%s: %s holds %d bytes in the WAL; ClickHouse reads them through SQLite, but a file-level copy does not — clickhouse:attach --checkpoint folds them into the database file.',
            $databaseName,
            $wal,
            $size,
        );
    }

    /**
     * `PRAGMA wal_checkpoint(TRUNCATE)` through a read-only handle.
     *
     * @return bool false when the file is gone or the handle cannot be opened
     *
     * @throws RuntimeException when SQLite refuses the pragma
     */
    public static function checkpoint(string $databasePath): bool
    {
        if (!is_file($databasePath)) {
            return false;
        }

        try {
            $pdo = new PDO(
                'sqlite:' . $databasePath,
                null,
                null,
                [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY],
            );
            $pdo->query('PRAGMA query_only = ON');
            $pdo->query('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (PDOException $exception) {
            throw new RuntimeException(
                sprintf('Cannot checkpoint %s: %s', $databasePath, $exception->getMessage()),
                0,
                $exception,
            );
        }

        return true;
    }
}
