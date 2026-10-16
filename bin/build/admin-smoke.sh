#!/usr/bin/env bash
# admin-smoke.sh — the admin flows over real HTTP, as a GATE.
#
# Why it exists: every guard the security rounds added lives behind an admin
# session — the CSRF origin check, the throttles, the `/mark` access control, the
# bulk-refusal security events — and the suite proves them a class at a time, not
# through a booted server with a real session and a real token. Boot 27 measured
# these flows by hand; this is that measurement made enforceable, so a change
# that 500s the panel or drops a security event fails a job instead of a
# reviewer's memory.
#
# It is deliberately NOT a substitute for the suite: it asserts the statuses,
# the rows and the recorded events, and it seeds its own accounts through the
# modules' own repositories because the Users migrator seeds no roles.
#
# Every DB_* points at a temp directory and the server is started and stopped
# here, so the working tree's storage/data/ is never opened (Boot 27's rule).
#
# CONTENT is the one exception, and it is stated rather than hidden: articles
# and pages live under `storage/` with no root override the way the databases
# have one, so the content section writes there — and then fails the run if the
# tree does not come back exactly as it found it (tree_files, compared before
# and after). On a workstation that means a `smoke-*` post exists for the
# duration of the run; in CI the checkout is disposable anyway.
#
# Usage: bin/build/admin-smoke.sh          (needs the catalogue installed)
#        SMOKE_PORT=8399 bin/build/admin-smoke.sh
set -euo pipefail

cd "$(dirname "$0")/../.."

PORT="${SMOKE_PORT:-8399}"
BASE="http://127.0.0.1:${PORT}"
TMP="$(mktemp -d)"
FAILED=0

export APP_ENV=production
export SMOKE_ADMIN_EMAIL='smoke-admin@example.test'
export SMOKE_ADMIN_PASSWORD='Smoke-Admin-2026!'
export SMOKE_AUTHOR_EMAIL='smoke-author@example.test'
export SMOKE_AUTHOR_PASSWORD='Smoke-Author-2026!'
export SMOKE_USER_EMAIL='smoke-user@example.test'
export SMOKE_USER_PASSWORD='Smoke-User-2026!'

for db in users outbox config audit blog comments locales media questions queue; do
    export "DB_$(printf '%s' "$db" | tr '[:lower:]' '[:upper:]')=$TMP/$db.db"
done

ok() { printf 'ok    %s\n' "$1"; }
bad() { printf '::error::%s\n' "$1"; FAILED=1; }

status() { curl -s -o "$TMP/last.out" -w '%{http_code}' --max-time 15 "$@"; }
token() { sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' "$TMP/last.out" | head -1; }
# Read one value back out of a module database (the temp ones above).
# shellcheck disable=SC2016 # PHP, not shell: the single quotes are php -r's own, $argv is PHP's
#
# The shellcheck directive is not a gate being silenced — one quoted PHP program
# is exactly what is wanted here, and the alternative (a variable holding the
# code) is a shell-injection shape for no gain.
dbq() { php -r 'echo (new PDO("sqlite:" . getenv($argv[1])))->query($argv[2])->fetchColumn();' "$1" "$2"; }

# One assertion, one report line: `want <label> <expected> <actual>`.
want() {
    if [ "$3" = "$2" ]; then
        ok "$1 → $3"
    else
        bad "$1 expected $2, got $3"
    fi
}

# The same, for a count that must be at least <expected>.
want_at_least() {
    if [ "$3" -ge "$2" ]; then
        ok "$1 → $3"
    else
        bad "$1 expected ≥ $2, got $3"
    fi
}

# Every content file the checkout carries, minus the volatile trees. Content has
# no root override the way the databases do (AppPaths::storage() is the checkout),
# so the content section below WRITES into storage/ and this is how it proves it
# left the tree exactly as it found it — a leftover fails the run instead of
# quietly surviving it.
tree_files() {
    find storage -type f \
        -not -path 'storage/data/*' \
        -not -path 'storage/logs/*' \
        -not -path 'storage/cache/*' \
        -not -path 'storage/sessions/*' \
        | sort
}

check() {
    local label="$1" path="$2" want="$3"
    shift 3
    local got
    got="$(status "$@" "$BASE$path")"
    if [ "$got" = "$want" ]; then
        ok "$label → $got"
    else
        bad "$label expected $want, got $got"
    fi
}

# Invoked by the trap below; shellcheck cannot see through `trap`.
# shellcheck disable=SC2329
cleanup() {
    if [ -n "${SERVER_PID:-}" ]; then
        kill "$SERVER_PID" 2>/dev/null || true
    fi
    rm -rf "$TMP"
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
# Preconditions — this gate runs against a tree with the catalogue installed
# ---------------------------------------------------------------------------
for module in Cardboard Outbox Users Security; do
    [ -d "src/Modules/$module" ] || {
        echo "::error::$module is not installed — run bin/build/install-catalogue.sh first"
        exit 1
    }
done

# ---------------------------------------------------------------------------
# A temp tree: migrate, then the accounts (the migrator seeds no roles, so the
# admin role and the five permissions are built through the module's own
# repositories — Permissions::all() stays the one owner of that vocabulary)
# ---------------------------------------------------------------------------
php bin/cms migrate > "$TMP/migrate.log" 2>&1 || { cat "$TMP/migrate.log"; bad "bin/cms migrate failed"; exit 1; }
grep -q 'Migrated:' "$TMP/migrate.log" || { cat "$TMP/migrate.log"; bad "no migrators ran"; exit 1; }

php <<'PHP'
<?php
require 'src/init.php';

use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Security\Application\Services\PasswordHasher;
use Modules\Users\Domain\Permissions;
use Modules\Users\Infrastructure\Persistence\Repositories\RoleRepository;
use Modules\Users\Infrastructure\Persistence\Repositories\UserRepository;

$pdo = DatabaseConnectionManager::instance()->connection('users');
$roles = new RoleRepository($pdo);
$users = new UserRepository($pdo);
$hasher = new PasswordHasher();

$roles->createRole('smoke00000000000000000000000001', 'admin', 'Smoke admin');
$roles->createRole('smoke00000000000000000000000002', 'author', 'Smoke author');

// One owner for the vocabulary: the permission names come from the class the
// migrator and the admin UI both read.
foreach (Permissions::all() as $i => $name) {
    $id = sprintf('smokeperm%022d', $i);

    $pdo->prepare('INSERT OR IGNORE INTO permissions (id, name, description, group_name) VALUES (?, ?, ?, ?)')
        ->execute([$id, $name, 'Smoke: ' . $name, 'general']);

    // admin: every permission. author: exactly what a content editor holds.
    $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)')
        ->execute(['smoke00000000000000000000000001', $id]);

    if (in_array($name, [Permissions::MARK_ACCESS, Permissions::CONTENT], true)) {
        $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)')
            ->execute(['smoke00000000000000000000000002', $id]);
    }
}

$admin = $users->insert([
    'id'       => 'smoke00000000000000000000000003',
    'name'     => 'Smoke Admin',
    'email'    => (string) getenv('SMOKE_ADMIN_EMAIL'),
    'password' => $hasher->hash((string) getenv('SMOKE_ADMIN_PASSWORD')),
]);
$users->assignRole($admin->id(), 'smoke00000000000000000000000001');

$author = $users->insert([
    'id'       => 'smoke00000000000000000000000004',
    'name'     => 'Smoke Author',
    'email'    => (string) getenv('SMOKE_AUTHOR_EMAIL'),
    'password' => $hasher->hash((string) getenv('SMOKE_AUTHOR_PASSWORD')),
]);
$users->assignRole($author->id(), 'smoke00000000000000000000000002');

echo "seeded admin + author\n";
PHP

# ---------------------------------------------------------------------------
# The server. opcache off: a long-lived `php -S` serving code that no longer
# exists is measured in Boot 18, and this script must observe what it just
# migrated.
# ---------------------------------------------------------------------------
php -d opcache.enable_cli=0 -S "127.0.0.1:$PORT" -t public/ > "$TMP/server.log" 2>&1 &
SERVER_PID=$!

ready=0
for _ in $(seq 1 30); do
    if curl -fsS -o /dev/null --max-time 5 "$BASE/" 2>/dev/null; then
        ready=1
        break
    fi
    sleep 1
done

if [ "$ready" -ne 1 ]; then
    cat "$TMP/server.log"
    bad "the server never came up on $BASE"
    exit 1
fi
ok "server up on $BASE (temp DBs in $(basename "$TMP"))"

# ---------------------------------------------------------------------------
# A guest: the public surfaces answer, the admin does not (302 to the login)
# ---------------------------------------------------------------------------
check 'guest GET /' '/' 200
check 'guest GET /login' '/login' 200
check 'guest GET /docs' '/docs' 200
check 'guest GET /nope' '/nope' 404
check 'guest GET /mark' '/mark' 302
check 'guest GET /mark/users' '/mark/users' 302

# ---------------------------------------------------------------------------
# The admin
# ---------------------------------------------------------------------------
# The token is scraped from the FORM the browser would fill in — status() writes
# the body to last.out, which is what token() reads.
ADMIN_JAR="$TMP/admin.jar"
status -c "$ADMIN_JAR" "$BASE/login" > /dev/null
ADMIN_TOKEN="$(token)"

got="$(status -c "$ADMIN_JAR" -b "$ADMIN_JAR" -X POST "$BASE/login" \
    --data-urlencode "email=$SMOKE_ADMIN_EMAIL" \
    --data-urlencode "password=$SMOKE_ADMIN_PASSWORD" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /login' 302 "$got"

check 'admin GET /mark' '/mark' 200 -b "$ADMIN_JAR" -c "$ADMIN_JAR"
check 'admin GET /mark/users' '/mark/users' 200 -b "$ADMIN_JAR" -c "$ADMIN_JAR"
check 'admin GET /mark/roles' '/mark/roles' 200 -b "$ADMIN_JAR" -c "$ADMIN_JAR"
ADMIN_TOKEN="$(token)"

# A user is created and deleted through the panel: the row is the assertion, not
# the status — 302 is also the answer for an id that does not exist (Boot 27).
got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/users/create" \
    --data-urlencode 'name=Smoke User' \
    --data-urlencode "email=$SMOKE_USER_EMAIL" \
    --data-urlencode "password=$SMOKE_USER_PASSWORD" \
    --data-urlencode 'role_id=smoke00000000000000000000000002' \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/users/create' 302 "$got"

created="$(dbq DB_USERS "SELECT COUNT(*) FROM users WHERE email = '$SMOKE_USER_EMAIL'")"
want 'the created user row exists' 1 "$created"

USER_ID="$(dbq DB_USERS "SELECT id FROM users WHERE email = '$SMOKE_USER_EMAIL'")"
got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/users/$USER_ID/delete" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/users/{id}/delete' 302 "$got"

left="$(dbq DB_USERS "SELECT COUNT(*) FROM users WHERE email = '$SMOKE_USER_EMAIL'")"
want 'the deleted user row is gone' 0 "$left"

got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/roles/create" \
    --data-urlencode 'name=smoke-role' --data-urlencode 'description=Smoke' \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/roles/create' 302 "$got"

ROLE_ID="$(dbq DB_USERS "SELECT id FROM roles WHERE name = 'smoke-role'")"
if [ -n "$ROLE_ID" ]; then ok 'the created role row exists'; else bad 'the created role row is missing'; fi

got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/roles/$ROLE_ID/delete" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/roles/{id}/delete' 302 "$got"

gone="$(dbq DB_USERS "SELECT COUNT(*) FROM roles WHERE name = 'smoke-role'")"
want 'the deleted role row is gone' 0 "$gone"

# The mutations above are the guarded call sites: with the Outbox installed the
# events must land (Boot 26 made them optional, not absent).
events="$(dbq DB_OUTBOX "SELECT COUNT(*) FROM outbox_messages WHERE event_type = 'security.user.changed'")"
want_at_least 'security.user.changed events' 2 "$events"

events="$(dbq DB_OUTBOX "SELECT COUNT(*) FROM outbox_messages WHERE event_type = 'security.role.changed'")"
want_at_least 'security.role.changed events' 2 "$events"

# ---------------------------------------------------------------------------
# CSRF: no token and a cross-site Origin are both refused
# ---------------------------------------------------------------------------
check 'POST /mark/users/create with no token' '/mark/users/create' 403 \
    -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST \
    --data-urlencode 'name=X' --data-urlencode 'email=no-token@example.test' \
    --data-urlencode 'password=Smoke-User-2026!'

check 'POST with a valid token and a cross-site Origin' '/mark/users/create' 403 \
    -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST \
    --data-urlencode "name=X" --data-urlencode "email=$SMOKE_USER_EMAIL" \
    --data-urlencode "password=$SMOKE_USER_PASSWORD" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H 'Origin: http://evil.example'

# ---------------------------------------------------------------------------
# The refusal branch: an author may not post a bulk operation for `users`, and
# the refusal rides the Outbox (the call site Boot 26 re-pointed).
# ---------------------------------------------------------------------------
AUTHOR_JAR="$TMP/author.jar"
status -c "$AUTHOR_JAR" "$BASE/login" > /dev/null
AUTHOR_TOKEN="$(token)"

got="$(status -c "$AUTHOR_JAR" -b "$AUTHOR_JAR" -X POST "$BASE/login" \
    --data-urlencode "email=$SMOKE_AUTHOR_EMAIL" \
    --data-urlencode "password=$SMOKE_AUTHOR_PASSWORD" \
    --data-urlencode "_token=$AUTHOR_TOKEN" -H "Origin: $BASE")"
want 'author POST /login' 302 "$got"

check 'author GET /mark' '/mark' 200 -b "$AUTHOR_JAR" -c "$AUTHOR_JAR"
AUTHOR_TOKEN="$(token)"

got="$(status -b "$AUTHOR_JAR" -c "$AUTHOR_JAR" -X POST "$BASE/mark/bulk-publish" \
    --data-urlencode 'entity=users' --data-urlencode 'ids[]=1' --data-urlencode 'published=1' \
    --data-urlencode "_token=$AUTHOR_TOKEN" -H "Origin: $BASE")"
want 'author bulk-publish entity=users' 403 "$got"

if grep -q 'not permitted' "$TMP/last.out"; then ok 'the refusal names the reason'; else bad 'the refusal body carries no reason'; fi

denied="$(dbq DB_OUTBOX "SELECT COUNT(*) FROM outbox_messages WHERE event_type = 'security.authz.denied'")"
want_at_least 'security.authz.denied events' 1 "$denied"

# ---------------------------------------------------------------------------
# Content: an ARTICLE is written, served and deleted through the panel
# ---------------------------------------------------------------------------
tree_files > "$TMP/tree-before.txt"
RAND="$RANDOM$RANDOM"
ARTICLE="smoke-article-$RAND"

check 'admin GET /mark/articles' '/mark/articles' 200 -b "$ADMIN_JAR" -c "$ADMIN_JAR"
ADMIN_TOKEN="$(token)"

# `date` is required alongside title and body (ArticlesCreateAction::validate),
# and its absence is a 400 with the form re-rendered — measured.
got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/articles/create?locale=sk" \
    --data-urlencode "title=Smoke article $RAND" \
    --data-urlencode "slug=$ARTICLE" \
    --data-urlencode "date=$(date +%Y-%m-%d)" \
    --data-urlencode 'body=Written by the admin smoke gate.' \
    --data-urlencode 'published=1' --data-urlencode 'locale=sk_SK' \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/articles/create' 302 "$got"

if find storage -name "$ARTICLE.md" | grep -q .; then ok 'the article file exists'; else bad 'no article file was written'; fi
check 'the published article is served' "/blog/$ARTICLE" 200
check 'the article list is served' '/blog' 200
got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/articles/$ARTICLE/delete" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/articles/{slug}/delete' 302 "$got"
if find storage -name "$ARTICLE.md" | grep -q .; then bad 'the article file survived the delete'; else ok 'the article file is gone'; fi
check 'the deleted article is 404' "/blog/$ARTICLE" 404

# ---------------------------------------------------------------------------
# Content: a PAGE, the same way — and then the tree is put back
# ---------------------------------------------------------------------------
PAGE="smoke-page-$RAND"
check 'admin GET /mark/pages' '/mark/pages' 200 -b "$ADMIN_JAR" -c "$ADMIN_JAR"
ADMIN_TOKEN="$(token)"

got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/pages/create?locale=sk" \
    --data-urlencode "title=Smoke page $RAND" \
    --data-urlencode "slug=$PAGE" \
    --data-urlencode 'body=A page written by the admin smoke gate.' \
    --data-urlencode 'published=1' --data-urlencode 'locale=sk_SK' \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/pages/create' 302 "$got"

if find storage -name "$PAGE.md" | grep -q .; then ok 'the page file exists'; else bad 'no page file was written'; fi
check 'the published page is served' "/$PAGE" 200
got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/pages/$PAGE/delete" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/pages/{slug}/delete' 302 "$got"
if find storage -name "$PAGE.md" | grep -q .; then bad 'the page file survived the delete'; else ok 'the page file is gone'; fi
check 'the deleted page is 404' "/$PAGE" 404

tree_files > "$TMP/tree-after.txt"
if diff -q "$TMP/tree-before.txt" "$TMP/tree-after.txt" > /dev/null; then
    ok 'the checkout storage came back exactly as it was'
else
    diff "$TMP/tree-before.txt" "$TMP/tree-after.txt" | head -10
    bad 'the content flows left files behind in the checkout storage'
fi

# ---------------------------------------------------------------------------
# Nothing fatal behind it all
# ---------------------------------------------------------------------------
if grep -E 'PHP Fatal|\[KERNEL\] Error|Uncaught' "$TMP/server.log" | head -5 | grep -q .; then
    grep -E 'PHP Fatal|\[KERNEL\] Error|Uncaught' "$TMP/server.log" | head -5
    bad "the server log carries a fatal"
else
    ok "no fatal / kernel error in the server log"
fi

echo
if [ "$FAILED" -eq 0 ]; then
    echo "admin-smoke: all checks passed"
else
    echo "admin-smoke: FAILURES present"
fi

exit "$FAILED"
