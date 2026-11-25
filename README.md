# Nativa PHP Core

The **kernel-only** tree. Two modules travel whole — `Modules\Core` and
`Modules\Database` — plus one theme. Everything else is a module: copied in from
`modules/` with `bin/cms module:add`, deleted with `bin/cms module:remove`, and
provably gone afterwards.

This tree exists to test that claim. The showcase
(`evan70/nativa-minimal`, retired on 2026-11-25 and archived in
`archive/nativa-minimal/`) is a full site; a site cannot demonstrate that
something is removable, because the thing it would demonstrate on is always
installed.

## Where this came from

The kernel slice was imported verbatim from `../nativa-minimal` on 2026-10-11:
`src/Modules/Core` (65 PHP files, 8 194 lines) and `src/Modules/Database` (2 files,
372 lines). Nothing in the slice was edited on import — an import that starts
"fixing things" is a fork, and a fork cannot tell you what the original did.

That import carries the showcase's coupling with it: **34 `use Modules\<X>\…`
statements across 14 files, naming 9 modules** (Blog, Cardboard, Locales, Media,
Outbox, Pages, Security, Settings, Users). The ledger — every edge, with the
answer it gets (`M`ove / `C`ontract / `G`uard), is
`tests/Architecture/KernelCouplingTest.php` +
`tests/Support/KernelCoupling.php`, ported from the showcase, and the measured
state of THIS tree is `docs/kernel-status.md`, the index
over the per-round boot log kept in `docs/kernel-status/`.

## The boundary rule

> A kernel file may not import a module. A module may import the kernel.

A surface that needs a module is a surface that belongs to a module. The kernel
owns: routing, the request/response pair, the middleware pipeline and its
discovery, the migrator runner, the database connection manager, the asset
resolver, markdown rendering, the error pages and the SEO text surfaces
(`/robots.txt`, `/sitemap.xml`) — degraded to what they can answer with nothing
installed.

## What it does today

`docs/kernel-status.md` records the measured state, with the status codes read
back off this tree rather than paraphrased from a plan. Measured on a freshly
started server: `/`, `/robots.txt` and `/sitemap.xml` answer **200**, `/docs`
and `/nope` answer **404** because Docs is a module, and a module installed
over HTTP answers `200` → `404` across `module:add` / `module:remove`.

`/` was a 500 for most of this kernel's life — `HomepageAction` reached for a
module that is not part of it. Boot 19 closed that, and the same boot found the
worst defect the tree had: `Router` discovery made each class's **parent** a
load-time dependency of routing, so a checkout with no `vendor/` answered 500 to
every request because three of Core's own classes extend `symfony/console`. The
repo's own [`docs/kernel-status.md`](docs/kernel-status.md) has the measurement
and the fix.

The shell port (`ShellContextProvider`, discovered by `#[AsShellContextProvider]`)
is the first of the 34 edges cut: the site name, the active theme, the default
locale, the language list and the navigation menu now come from a kernel-owned
port a module implements — and **absence is a first-class answer**, so this tree
renders with no provider installed at all.

## Run it

```sh
php -S 127.0.0.1:8000 -t public    # no vendor/ needed — the runtime is the point
```

`composer install` is for development only (phpstan, rector, php-cs-fixer,
PHPUnit). It is not a prerequisite: `src/init.php` installs a PSR-4 fallback
loader for the tree that has none, and the CI job called "Boot (kernel-only, no
vendor)" exists precisely to keep that claim honest.

The first request (or `php bin/cms migrate`) builds the databases or module
schemas EMPTY, so a fresh tree has no account and `/mark` answers 403. Create
the first administrator — the admin role and the five permissions with it — in
one line:

```sh
php bin/cms user:operator                                  # admin@example.test / admin1234
php bin/cms user:operator you@example.com 'your-password'  # your own credentials
```

## Database engines

SQLite is the default: one file per module under `storage/data/`.

PostgreSQL uses one database for every module. Each module gets a schema named
after it, created and selected by the manager on first connection, so
unqualified table names and the existing migrations stay unchanged.

```env
DB_DRIVER=pgsql
DB_DSN=host=127.0.0.1;port=5432;dbname=nativa;user=nativa;password=secret
```

The database role needs `CREATE` on the database to create the module schemas.
`php bin/cms migrate` builds every schema, and `db:export` / `db:import` keep
working one module at a time because each connection sees only its own schema.

MariaDB has no schema layer inside a database, so the isolation boundary is one
database per module. `DB_DSN` is the server DSN (no `dbname`); the manager
creates `<DB_DATABASE_PREFIX><module>` (default `nativa_`) on first connection
and selects it with the connection's `dbname`:

```env
DB_DRIVER=mariadb
DB_DSN=host=127.0.0.1;port=3306;user=nativa;password=secret
DB_DATABASE_PREFIX=nativa_
```

The role needs `CREATE` on the server. MariaDB connections run with
`time_zone = '+00:00'` and SERIALIZABLE transactions, because the application
stores UTC text and the outbox drain relies on the strongest isolation.
`db:export` / `db:import` work unchanged; the snapshot still has one entry per
module.

Switching back is `DB_DRIVER=sqlite` (or removing the lines): the three engines
are one seam, and nothing else in the tree reads the driver.

The same choice can live in `storage/config/database.json`, which the Settings
panel writes (Settings → Database):

```json
{
  "driver": "pgsql",
  "dsn": "host=127.0.0.1;port=5432;dbname=nativa;user=nativa;password=secret",
  "prefix": "nativa_"
}
```

Precedence is the process environment, then `.env`, then the file: a deployment
that exports `DB_DRIVER` or `DB_DSN` always wins, so the file is the default for
the installation rather than an override. `driver` takes `sqlite`, `pgsql` or
`mariadb` (`mysql` means `mariadb`); an unknown key fails the boot instead of
being ignored, and `dsn` is refused for SQLite, which reads files. The file is
written atomically with mode `0600` because it holds a password, and it is
gitignored with the rest of `storage/`. A save applies on the next request —
the request that saved it already holds its connections — and
`php bin/cms migrate` builds the new target's tables (the panel refuses an
empty server only until the save is explicitly confirmed).

| Engine | What the role needs | `DB_DSN` | `db:export` / `db:import` |
| --- | --- | --- | --- |
| SQLite (default) | nothing — a file per module under `storage/data/`, `DB_<NAME>` overrides one path | not read | one entry per module, as a file each; import into a target with rows needs `--force` |
| PostgreSQL | `CREATE` on the shared database, and `dbname=` must already exist | `host=…;port=5432;dbname=nativa;user=…;password=…` | one entry per module, each connection seeing only its schema; the import runs the migrators first and needs `--force` to replace rows |
| MariaDB | `CREATE` on the server — the manager creates `<DB_DATABASE_PREFIX><module>` | server DSN, no `dbname` | one entry per module, same as PostgreSQL; the import leaves an empty append-only `audit_log` alone and needs `--force` to replace rows |

`make test` runs SQLite only — `tests/Postgres` and `tests/MariaDb` describe a
real server and are excluded from that suite, and PHPUnit skips them silently
when `DB_DRIVER` says otherwise. Their one-command run carries the driver and
DSN itself, migrates first, and treats a skip as a failure. The MariaDB run
also refuses a target whose module databases already hold tables the migrators
do not create, so the prefix cannot quietly point at another application's
namespace:

```sh
make test-postgres                                        # PG_DSN=… to point elsewhere
make test-mariadb MARIADB_DSN="unix_socket=/run/mysqld/mysqld.sock;user=root;password="
```

Commit messages carry no agent footer (the nativa-minimal convention) —
after cloning, enable the enforcer: `cp bin/git-hooks/commit-msg .git/hooks/commit-msg`.

## Build a deployable tree

```sh
make assets                    # theme bundles → public/dist/ (gitignored)
bin/build/build-dist.sh        # → dist/ : assets, migrated databases, smoke-tested
make dist-check                # same build, but from a clean clone of HEAD
make dist-modules-check       # clean clone + modules-install + module-set artifact
```

`make dist-check` is the gate CI runs: it clones `HEAD` into a temp directory —
tracked bytes only, no `vendor/`, no locally installed module slices, no
uncommitted edits — builds the artifact there, then asserts the smoke test
passed, the artifact carries no `vendor/`, and `.build-info` agrees. A green
local `dist/` that a clone cannot reproduce fails there. `make dist-profile-test`
covers the three identity branches — Settings absent, `--no-profile`, and a
profile that is wrong while Settings IS installed (that one must fail the
build) — each in its own clone, with no HTTP smoke run.

`make dist-modules-check` is the prod build the kernel-only clone cannot prove:
it installs the catalogue in a clean clone, builds the artifact, and requires
the artifact to carry the same installed module count and no second `modules/`
catalogue. Use it before deploying a module-backed site.

`dist/` carries no `vendor/`, no `Frontend/` sources, no dev configs, no
`fixtures/` (the theme-gallery inputs a build-time script reads) and **one**
`Modules` tree — the installed copies, not a second copy of the catalogue
(`--with-catalogue` ships that too, for a target that must install modules
itself). It keeps `bin/cms`: that is the production tool for `migrate` and
`module:remove`, and it is plain PHP by design. The script migrates the artifact
in place and then boots it over HTTP, so a broken build fails where it is built
rather than on the server.

The artifact's identity comes from `config/deploy.php` — the settings a build
STARTS as (`theme`, `site_name`, …), applied by `bin/cms settings:profile` right
after `migrate`. Migrating alone seeds the kernel defaults and copies none of
the developer's `storage/data/`, so without the profile a rebuilt responsive.sk
dist came up as the kernel's own site (the theme's copy looked deleted). Build
with `--no-profile` to ship the kernel defaults on purpose; afterwards every
value is still the operator's to change in `/mark` → Settings. A kernel-only
checkout has the profile file but no `Settings` module to apply it with, so that
build keeps the kernel defaults and says so — the failure that would matter
(the module is installed, the profile is wrong) still fails the build.

## Update a running deployment

An update replaces the CODE. It never replaces `storage/`, because that is where
the site is: `storage/pages/`, `storage/blog/`, `storage/blog-images/`,
`storage/uploads/` and one SQLite file per module under `storage/data/`. The
artifact carries a migrated `storage/` skeleton for a first install, so copying
it over a running site deletes the content and leaves a database-shaped shell
behind. Measured 2026-10-17: that is exactly what made a rebuilt responsive.sk
look deleted while nothing had touched the content.

```sh
# 1. A release is a directory with a provenance stamp, built from HEAD.
bin/build/build-dist.sh --skip-assets --to releases/2026-11-19
cat releases/2026-11-19/.build-info                             # VERSION, COMMIT, VENDOR=absent
( cd releases && find 2026-11-19 -type f -print0 | sort -z | xargs -0 sha256sum > 2026-11-19.sha256 )
( cd releases && sha256sum -c 2026-11-19.sha256 --quiet )

# 2. Snapshot first, on the server, before anything moves.
php bin/cms db:export storage/backups/2026-11-19
tar czf storage/backups/2026-11-19-files.tgz \
    storage/pages storage/blog storage/blog-images storage/uploads

# 3. Optionally take the site out of service (/mark → Settings → maintenance_mode).

# 4. Code only — rsync with an exclude, never `cp -R`.
rsync -a --delete --exclude 'storage/' --exclude '.build-info' \
    releases/2026-11-19/ /var/www/responsive.sk/
install -m 644 releases/2026-11-19/.build-info /var/www/responsive.sk/.build-info

# 5. Schema on the target (idempotent; the first request would run it anyway).
php bin/cms migrate
```

Then hand `storage/` to the PHP user the web server runs as — `chgrp -R www-data
storage` (or `nginx`, `_www`). An rsync user that owns `storage/` and a SAPI user
that cannot write it is a 500 on every request, or a 503 once the per-request
re-migration it triggers hits the timeout.

Verify the update, not just the upload: `curl -s /health` answers 200 with every
probe healthy, `/` answers 200, and the file counts under `storage/pages`,
`storage/uploads` and the number of `storage/data/*.db` files are the same as
before the rsync. A count that dropped is a copy that included `storage/`.

**Do not run `settings:profile` on an update.** `config/deploy.php` describes
what a *build* starts as; on a running site it would write the build's
`site_name`, `theme`, `site_description` and `timezone` over whatever the
operator set in `/mark` → Settings. It belongs to `bin/build/build-dist.sh`
(which runs it inside the artifact, before the site exists), and nowhere else.

Rollback is the previous release directory plus the snapshot: rsync the older
`releases/<date>/` back the same way (code only), then
`php bin/cms db:import storage/backups/<date> --force` if the schema moved. That
is why the release directory is kept and why it is gitignored rather than
rebuilt on demand (`/releases/`, next to `/dist/`).## The generated trees

`responsive-sk/nativa-core` is **generated** from a commit of this tree: the
kernel, the module catalogue and the Frontend sources, without the boot log,
the article corpus, the Lekalo model or the plans. It is a starting point for a
site, not a second source. A change is made here and exported:

```sh
make core-export-check                     # the gate: assemble, lint, validate, run its suite
make core-export CORE_TO=../nativa-core    # write the tree from HEAD
make publish-core PUBLISH_ARGS=--dry-run   # what a publish would push
```

`responsive-sk/nativa-resp` is the **private client port**: the same kernel
with the measured 28-module site set, Boson, and the tracked content seed. It
is generated from `bin/export/resp/`; a clone boots after `migrate` and one
`settings:profile` run.

```sh
make resp-export-check                       # assemble, migrate, apply the profile, run its suite
make resp-export RESP_TO=../nativa-resp      # write the private tree from HEAD
make publish-resp RESP_PUBLISH_ARGS=--dry-run
```

The profiles are `bin/export/core/` and `bin/export/resp/`: each carries its
prune list with a reason per path, the Makefile and CI the export ships, the
PHPUnit exclusions, and the README and `AGENTS.md` the tree carries.
`docs/kernel-status/12-the-tree-we-publish-from.md` records the core export;
`docs/kernel-status/36-the-client-port.md` records the client port.
