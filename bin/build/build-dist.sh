#!/bin/bash
#
# =============================================================================
# build-dist.sh — a deployable tree in dist/, with no vendor/ at all.
# =============================================================================
# Same job as upstream's `bin/build/build-dist.sh` (an artifact you can upload,
# not a git checkout you have to prune on the server), with three differences
# that are the kernel's, not a style choice:
#
#   1. `bin/cms` STAYS. Upstream deletes its console from the dist because the
#      console extends symfony/console, a dev dependency. Here it is the
#      production tool — `module:add/remove`, `migrate` — and it is plain PHP by
#      design, so a dist that drops it cannot manage its own modules.
#   2. The frontend is NOT rebuilt here. The pnpm workspace is committed and
#      `make assets` is the one build path, so this script delegates to it
#      rather than keeping a second copy of the pnpm invocation. `--skip-assets`
#      uses whatever is already in `public/dist/` and refuses to guess.
#   3. Databases are MIGRATED, never seeded or copied. The kernel ships no demo
#      data — it has no seeders at all — and the developer's own `storage/data/`
#      is local state that must not leak into an artifact. `php bin/cms migrate`
#      runs INSIDE the dist, so the artifact leaves here with a real schema and
#      with proof that it boots without `vendor/`.
#
# Usage:
#   bin/build/build-dist.sh                    # assets if missing → assemble → migrate → smoke
#   bin/build/build-dist.sh --skip-assets      # use the existing public/dist
#   bin/build/build-dist.sh --skip-migrate     # ship the storage skeleton, no databases
#   bin/build/build-dist.sh --skip-smoke       # skip the HTTP smoke test
#   bin/build/build-dist.sh --with-env         # copy .env.example to dist/.env
#   bin/build/build-dist.sh --with-catalogue  # also ship modules/ (module:add works in the artifact)
#   bin/build/build-dist.sh --to <dir>         # write somewhere else (default: dist/)
#
# On `modules/` — and why the artifact carries the name "Modules" ONCE.
#
#   modules/<Name>/         the CATALOGUE: slices waiting to be installed
#   src/Modules/<Name>/     the INSTALLED copies: what actually runs
#
# Both live in the repo; a deployment needs only the second one. Measured in
# this tree: 12 of the 14 installed slices are BYTE-IDENTICAL to their catalogue
# counterpart — 815 KiB, shipped twice, in a 5.3 MB artifact. The first look at
# a dist built that way asks the same question it asked here ("modules twice?"),
# and the honest answer was "yes, on purpose" the moment the catalogue was
# wanted for `module:add` on the target. It is not wanted by default: a
# deployment runs what is installed, and installing happens in the repo (or
# through `--with-catalogue`, which restores the ability and reports the
# duplication it brings — identical / drifted / not installed, per slice).
#
# No dependencies beyond bash + coreutils + tar: like `bin/cms` and
# `extract-kernel.sh`, what builds the kernel must run where the kernel's own
# `vendor/` may not exist.
# =============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DIST_DIR="$ROOT/dist"
SMOKE_PORT="${SMOKE_PORT:-8137}"
SMOKE_HOST="127.0.0.1"
BUILD_TIME="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
VERSION="$(git -C "$ROOT" describe --tags --always --dirty 2>/dev/null || echo 'dev')"
COMMIT="$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo 'unknown')"

SKIP_ASSETS=false
SKIP_MIGRATE=false
SKIP_SMOKE=false
WITH_ENV=false
WITH_CATALOGUE=false

# Paths tar must not carry. Deliberately a deny-list with a reason per line,
# because each of these would be a bug in the artifact rather than clutter:
#
#   ./dist             the output directory itself
#   ./.git ./.github   history and CI are not deployable
#   ./tests             the harness is not the application
#   ./Frontend          theme SOURCES: the artifact ships built bundles only
#   ./vendor            the kernel runtime has zero Composer packages — this is
#                       the claim the smoke test below is about to check
#   ./bin/build         the tools that BUILT this artifact, including itself
#   dev tool configs    phpstan/phpunit/rector/cs-fixer/Makefile/composer.* say
#                       how to develop here, and shipping them invites someone
#                       on the server to run `composer install` against a tree
#                       that does not need it
#   .env and friends    local secrets. NOTE: `.env.*` also matches the
#                       `.env.example` this artifact DOES need — it arrives via
#                       the explicit copy below, not through this tar pass.
#   ./storage/data|logs|cache|sessions|uploads
#                       runtime state (SQLite files, sessions, article views).
#                       The skeleton is recreated below; the databases are
#                       rebuilt by migrate inside the dist.
#   ./.lekalo ./.freebuff  local tool caches
#   ./lekalo* ./.gitignore  the Lekalo IR model and the repo's ignore rules are
#                       how this tree is DEVELOPED, not how it runs
EXCLUDES=(
    "./dist"
    "./.git"
    "./.github"
    "./tests"
    "./Frontend"
    "./vendor"
    "./bin/build"
    "./.env"
    "./.env.*"
    "./.lekalo"
    "./.freebuff"
    "./lekalo"
    "./lekalo.lock"
    "./.gitignore"
    "./.php-cs-fixer.cache"
    "./.php-cs-fixer.dist.php"
    "./.phpunit.result.cache"
    "./.rector.cache"
    "./Makefile"
    "./composer.json"
    "./composer.lock"
    "./phpstan.neon"
    "./phpunit.xml"
    "./rector.php"
    "./storage/data"
    "./storage/logs"
    "./storage/cache"
    "./storage/sessions"
    "./storage/uploads"
)

# The runtime skeleton a fresh deployment needs (the same five directories
# `extract-kernel.sh` calls a structural part of the tree).
STORAGE_DIRS=(
    "data"
    "logs"
    "cache"
    "sessions"
    "uploads"
)

# Content directories that are CONTENT, not state: an article written in the
# studio has to arrive on the server, and a cover image its front matter points
# at has to resolve. Copied wholesale when present, like upstream does.
CONTENT_DIRS=(
    "blog"
    "blog-images"
    "pages"
)

usage() {
    awk 'NR>1 && /^set -euo pipefail$/{exit} NR>1{print}' "$0" | sed 's/^# \?//'
}

fail() {
    # $1 is the message and $2 the exit code — `$*` would print both, which
    # shipped a stray " 2" at the end of every refusal until it was read back.
    echo "build-dist: $1" >&2
    exit "${2:-1}"
}

step() {
    echo "==> $*"
}

while [ $# -gt 0 ]; do
    case "$1" in
        --skip-assets)  SKIP_ASSETS=true ;;
        --skip-migrate) SKIP_MIGRATE=true ;;
        --skip-smoke)   SKIP_SMOKE=true ;;
        --with-env)     WITH_ENV=true ;;
        --with-catalogue) WITH_CATALOGUE=true ;;
        --to)           DIST_DIR="${2:-}"; [ -n "$DIST_DIR" ] || fail "--to needs a directory"; shift ;;
        --help|-h)      usage; exit 0 ;;
        *) fail "unknown argument: $1 (see --help)" ;;
    esac
    shift
done

# --- Assets ------------------------------------------------------------------
# `make assets` is the only build path (pnpm workspace under Frontend/packages).
# Its output is gitignored by design, so `--skip-assets` on a tree that has
# never built is not "use the previous build", it is a dist with no stylesheet
# — reported rather than produced.
if [ "$SKIP_ASSETS" = true ]; then
    [ -s "$ROOT/public/dist/moonshine/manifest.json" ] \
        || fail "--skip-assets needs a built public/dist (run: make assets)" 2
    step "Using the existing public/dist (--skip-assets)"
else
    step "Building the theme bundles (make assets)"
    command -v pnpm >/dev/null 2>&1 \
        || fail "pnpm not found — install it, or build elsewhere and use --skip-assets" 2
    (cd "$ROOT" && make assets)
fi

# --- Refuse to write into a foreign non-empty directory ----------------------
# `dist/` itself is a build output and is replaced wholesale; any OTHER target
# must be empty, because deleting someone's directory is not this script's job.
if [ "$DIST_DIR" != "$ROOT/dist" ] && [ -e "$DIST_DIR" ] && [ -n "$(ls -A "$DIST_DIR" 2>/dev/null)" ]; then
    fail "refusing to write into non-empty dir: $DIST_DIR (only ./dist is replaced automatically)" 2
fi

step "Cleaning $DIST_DIR"
rm -rf "$DIST_DIR"
mkdir -p "$DIST_DIR"

# --- Assemble ----------------------------------------------------------------
# tar rather than rsync: it is already required by nothing else here, and its
# --exclude semantics are the ones the list above is written against. Paths are
# relative to the repo root so the exclude patterns read like the tree.
step "Copying the tree (without: history, tests, Frontend sources, vendor, dev configs)"
tar_args=( -C "$ROOT" )
for path in "${EXCLUDES[@]}"; do
    tar_args+=( --exclude="$path" )
done
[ "$WITH_CATALOGUE" = false ] && tar_args+=( --exclude="./modules" )

( cd "$ROOT" && tar "${tar_args[@]}" -cf - . ) | ( cd "$DIST_DIR" && tar -xf - )

# --- The two Modules trees ---------------------------------------------------
# Reported, not assumed: how much of the catalogue is a second copy of what is
# already running, and how many slices are installed but no longer match it.
if [ "$WITH_CATALOGUE" = true ]; then
    step "Catalogue in the artifact (module:add works here)"
    identical=0
    drifted=0
    uninstalled=0
    for slice in "$DIST_DIR/modules"/*/; do
        name="$(basename "$slice")"
        installed="$DIST_DIR/src/Modules/$name"
        if [ ! -d "$installed" ]; then
            uninstalled=$((uninstalled + 1))
        elif diff -rq "$slice" "$installed" >/dev/null 2>&1; then
            identical=$((identical + 1))
        else
            drifted=$((drifted + 1))
            echo "    note: $name — installed copy DIFFERS from the catalogue"
        fi
    done
    echo "    $identical slice(s) byte-identical to the installed copy (a second copy of what runs)"
    echo "    $drifted drifted, $uninstalled not installed"
else
    step "Catalogue left out (the default): the artifact carries Modules once — src/Modules/"
    echo "    module:add is unavailable here by design; rebuild --with-catalogue to ship it"
fi

# --- Storage skeleton + content ---------------------------------------------
step "Creating the storage skeleton"
for dir in "${STORAGE_DIRS[@]}"; do
    mkdir -p "$DIST_DIR/storage/$dir"
done

for dir in "${CONTENT_DIRS[@]}"; do
    if [ -d "$ROOT/storage/$dir" ]; then
        mkdir -p "$DIST_DIR/storage/$dir"
        cp -R "$ROOT/storage/$dir/." "$DIST_DIR/storage/$dir/"
        count="$(find "$ROOT/storage/$dir" -type f | wc -l | tr -d ' ')"
        step "  storage/$dir/: $count content file(s) copied"
    fi
done

# --- Environment -------------------------------------------------------------
step "Writing the environment template"
cp "$ROOT/.env.example" "$DIST_DIR/.env.example"
if [ "$WITH_ENV" = true ]; then
    cp "$ROOT/.env.example" "$DIST_DIR/.env"
    echo "    .env written from .env.example — set APP_KEY and the database paths there"
fi

# --- Migrate inside the dist -------------------------------------------------
if [ "$SKIP_MIGRATE" = true ]; then
    step "Skipping migrate (--skip-migrate): the dist arrives with an empty schema"
else
    step "Migrating the dist (php bin/cms migrate — outside the source tree, on purpose)"
    # The binary is selected BY PATH, not by cd: APP_BASE_PATH is
    # `dirname(__DIR__)` from src/init.php, so the databases this creates belong
    # to the artifact and never to the developer's storage/data/, whatever the
    # working directory happens to be.
    if ! php "$DIST_DIR/bin/cms" migrate; then
        fail "migrate failed inside the dist — the artifact would ship without a schema" 1
    fi
fi

# --- Build metadata ----------------------------------------------------------
step "Writing build metadata"
{
    echo "BUILD_TIME=$BUILD_TIME"
    echo "VERSION=$VERSION"
    echo "COMMIT=$COMMIT"
    echo "VENDOR=absent"
} > "$DIST_DIR/.build-info"

# --- Smoke test --------------------------------------------------------------
# Boots the ARTIFACT (not the source tree) with `php -S` and checks the kernel
# surfaces over HTTP. opcache is disabled on purpose: measured on a long-lived
# CLI server, it serves code that no longer exists.
if [ "$SKIP_SMOKE" = true ]; then
    step "Skipping the smoke test (--skip-smoke)"
else
    step "Smoke-testing the artifact (php -S on $SMOKE_HOST:$SMOKE_PORT)"

    if [ -d "$DIST_DIR/vendor" ]; then
        fail "vendor/ exists in the dist — the zero-dependency claim is untested here" 1
    fi
    echo "    no vendor/ in the artifact"

    # -t points the docroot at the ARTIFACT's public/, and the app root follows
    # from public/index.php's own location, so no cd is needed and the PID below
    # is the server itself rather than a subshell around it.
    php -d opcache.enable_cli=0 -S "$SMOKE_HOST:$SMOKE_PORT" -t "$DIST_DIR/public" > /tmp/build-dist-server.log 2>&1 &
    server_pid=$!
    trap 'kill "${server_pid:-0}" 2>/dev/null || true' EXIT

    ready=false
    for _ in $(seq 1 40); do
        if curl -fsS -o /dev/null "http://$SMOKE_HOST:$SMOKE_PORT/" 2>/dev/null; then
            ready=true
            break
        fi
        sleep 0.25
    done

    if [ "$ready" != true ]; then
        echo "---- server log ----" >&2
        tail -20 /tmp/build-dist-server.log >&2 || true
        fail "the artifact never answered on http://$SMOKE_HOST:$SMOKE_PORT/" 1
    fi

    check() {
        local got
        got="$(curl -s -o /dev/null -w '%{http_code}' "http://$SMOKE_HOST:$SMOKE_PORT$1")"
        if [ "$got" != "$2" ]; then
            echo "::error::GET $1 expected $2, got $got" >&2
            return 1
        fi
        echo "    ok  $1 → $got"
    }

    # The kernel's own surfaces. `/mark` is NOT asserted: the dist carries the
    # modules that are installed in this tree, and whether the admin answers
    # 404 or redirects depends on which of them are — printed, not claimed.
    check /            200
    check /sitemap.xml 200
    check /robots.txt  200
    check /docs        200
    check /nope        404

    mark_status="$(curl -s -o /dev/null -w '%{http_code}' "http://$SMOKE_HOST:$SMOKE_PORT/mark")"
    echo "    --  /mark → $mark_status (depends on the installed modules; not asserted)"

    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
    trap - EXIT

    if grep -qE 'PHP Fatal error|\[KERNEL\] Error' "$DIST_DIR/storage/logs/php-error.log" 2>/dev/null; then
        cat "$DIST_DIR/storage/logs/php-error.log" >&2
        fail "the smoke test wrote a fatal error to storage/logs/php-error.log" 1
    fi
    echo "    error log is clean"
fi

# --- Permissions -------------------------------------------------------------
step "Setting permissions"
find "$DIST_DIR" -type d -exec chmod 755 {} \;
find "$DIST_DIR" -type f -exec chmod 644 {} \;
[ -f "$DIST_DIR/bin/cms" ] && chmod 755 "$DIST_DIR/bin/cms" || true

# --- Summary -----------------------------------------------------------------
size="$(du -sh "$DIST_DIR" | cut -f1)"
files="$(find "$DIST_DIR" -type f | wc -l | tr -d ' ')"
echo
echo "============================================================================="
echo "  Nativa PHP Core — dist built"
echo "============================================================================="
echo "  Version:    $VERSION ($COMMIT)"
echo "  Build time: $BUILD_TIME"
echo "  Output:     $DIST_DIR  ($size, $files files)"
echo "  vendor/:    absent — the runtime has zero Composer packages"
if [ "$WITH_CATALOGUE" = true ]; then
    echo "  modules/:   catalogue INCLUDED (--with-catalogue) — slices exist twice, see above"
else
    echo "  modules/:   not shipped — the artifact runs what is installed; no second copy"
fi
echo ""
echo "  Deploy with:"
echo "    rsync -az --delete $DIST_DIR/ user@host:/var/www/nativa-php-core/"
echo "    (every file is a real copy — no symlinks, so a plain FTP upload works)"
echo "============================================================================="
