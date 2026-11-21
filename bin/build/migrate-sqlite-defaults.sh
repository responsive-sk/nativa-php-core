#!/usr/bin/env bash
# migrate-sqlite-defaults.sh — migrate the SQLite files the pinned tests read.
#
# Module tests pin SQLite and a page render in one module reads the default
# databases another module owns. In the PostgreSQL and MariaDB jobs DB_DSN
# points at the real server, so a plain `php bin/cms migrate` builds THAT
# schema and leaves `storage/data/*.db` unmigrated — the first render then dies
# on `no such table: questions`. The suite in those jobs still runs the pinned
# SQLite tests, so the files have to exist and be migrated here.
#
# The file list is the gate, not the log line: one migrator writing its schema
# says nothing about the other module databases the suite will open.
#
# Usage: bin/build/migrate-sqlite-defaults.sh   (from anywhere; it finds the root)
set -euo pipefail

cd "$(dirname "$0")/../.."

# `env -u` drops the job's DSN explicitly: an exported DB_DSN would make the
# migrate run against PostgreSQL/MariaDB instead of the files checked below.
env -u DB_DSN -u DB_DRIVER php bin/cms migrate | tee /tmp/sqlite-migrate.log

grep -q 'Migrated:' /tmp/sqlite-migrate.log \
    || { echo '::error::no migrators ran in a tree with the catalogue installed'; exit 1; }

for db in audit blog comments config locales media outbox questions users; do
    test -f "storage/data/$db.db" \
        || { echo "::error::storage/data/$db.db was not created"; exit 1; }
done

echo 'ok  the module databases exist and are migrated'
