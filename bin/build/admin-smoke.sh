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
# What it walks, in order: the guest surfaces, the admin account surfaces (users,
# roles, bulk refusal), the content editors (an article and a page, with the FILE
# as the assertion), the public comment form (honeypot, moderation, publication)
# and the two queues (the outbox drain that delivers the events this run records,
# and the Queue module's worker over a job nobody can route).
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
# The modules every section below needs. Deliberately not "whatever is
# installed": a tree missing one of these would silently skip checks instead of
# failing, and a gate that skips is the thing this job replaced.
for module in Audit Cardboard Comments Outbox Queue Scheduler Security Users; do
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

# ---------------------------------------------------------------------------
# The PUBLIC COMMENT FORM — the one mutation a stranger can make, and the
# moderation gate in front of it. The article from the section above is the
# host, so this runs before it is deleted.
# ---------------------------------------------------------------------------
GUEST_JAR="$TMP/guest.jar"
status -c "$GUEST_JAR" -b "$GUEST_JAR" "$BASE/blog/$ARTICLE" > /dev/null
GUEST_TOKEN="$(token)"
COMMENT_BODY="Smoke gate comment $RAND"

# The honeypot first: the field a bot fills in because it completes every field
# it finds. The answer is the very redirect a real submission gets — the
# assertion is the row that never appears, because the status cannot tell the
# two apart by design.
got="$(status -b "$GUEST_JAR" -c "$GUEST_JAR" -X POST "$BASE/blog/$ARTICLE/comments" \
    --data-urlencode 'guest_name=Spam Bot' \
    --data-urlencode 'guest_email=bot@example.test' \
    --data-urlencode "body=$COMMENT_BODY, honeypot" \
    --data-urlencode 'comment_website=https://spam.example' \
    --data-urlencode "_token=$GUEST_TOKEN" -H "Origin: $BASE")"
want 'honeypot POST /blog/{slug}/comments' 302 "$got"

trapped="$(dbq DB_COMMENTS "SELECT COUNT(*) FROM comments WHERE article_slug = '$ARTICLE'")"
want 'a honeypot submission stores no row' 0 "$trapped"

# A real guest comment: 302 back to the article, one row — and 'pending', which
# is what makes the next two checks mean something.
got="$(status -b "$GUEST_JAR" -c "$GUEST_JAR" -X POST "$BASE/blog/$ARTICLE/comments" \
    --data-urlencode 'guest_name=Smoke Guest' \
    --data-urlencode 'guest_email=guest@example.test' \
    --data-urlencode "body=$COMMENT_BODY" \
    --data-urlencode "_token=$GUEST_TOKEN" -H "Origin: $BASE")"
want 'guest POST /blog/{slug}/comments' 302 "$got"

state="$(dbq DB_COMMENTS "SELECT status FROM comments WHERE article_slug = '$ARTICLE'")"
want 'the submitted comment starts pending' 'pending' "$state"
COMMENT_ID="$(dbq DB_COMMENTS "SELECT id FROM comments WHERE article_slug = '$ARTICLE'")"

# The article page is the assertion: the row exists and the reader cannot see
# it. Nothing in the status distinguishes this from a comment that was never
# submitted at all, which is exactly the point.
status "$BASE/blog/$ARTICLE" > /dev/null
if grep -q "$COMMENT_BODY" "$TMP/last.out"; then
    bad 'a pending comment is visible on the article'
else
    ok 'a pending comment stays out of the article'
fi

# The moderation queue lists it; approving it publishes it.
check 'admin GET /mark/comments' '/mark/comments' 200 -b "$ADMIN_JAR" -c "$ADMIN_JAR"
ADMIN_TOKEN="$(token)"

if grep -q "$COMMENT_BODY" "$TMP/last.out"; then
    ok 'the moderation queue lists the submitted comment'
else
    bad 'the moderation queue does not list the submitted comment'
fi

got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/comments/$COMMENT_ID/approve" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/comments/{id}/approve' 302 "$got"

state="$(dbq DB_COMMENTS "SELECT status FROM comments WHERE id = '$COMMENT_ID'")"
want 'the moderated comment is approved' 'approved' "$state"

status "$BASE/blog/$ARTICLE" > /dev/null
if grep -q "$COMMENT_BODY" "$TMP/last.out"; then
    ok 'the approved comment is served on the article'
else
    bad 'the approved comment is not served on the article'
fi

got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/comments/$COMMENT_ID/delete" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/comments/{id}/delete' 302 "$got"

gone="$(dbq DB_COMMENTS "SELECT COUNT(*) FROM comments WHERE id = '$COMMENT_ID'")"
want 'the deleted comment row is gone' 0 "$gone"

status "$BASE/blog/$ARTICLE" > /dev/null
if grep -q "$COMMENT_BODY" "$TMP/last.out"; then
    bad 'the deleted comment is still served'
else
    ok 'the deleted comment is no longer served'
fi
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
# The WORKER side. Two queues are exercised here: the outbox, whose drain runs
# at the end of every request above, and the Queue module's own worker over a
# job nobody can route.
# ---------------------------------------------------------------------------
# Delivery is the assertion, not the row count. Every security event recorded
# above has to have been consumed by the end-of-request drain, and the audit row
# is the proof: the handler that consumes exactly those events IS the audit
# writer (registered in the composition root, `public/index.php`).
pending="$(dbq DB_OUTBOX "SELECT COUNT(*) FROM outbox_messages WHERE status = 'pending'")"
want 'outbox messages left pending' 0 "$pending"

queued_failed="$(dbq DB_OUTBOX "SELECT COUNT(*) FROM outbox_messages WHERE status = 'failed'")"
want 'outbox messages failed' 0 "$queued_failed"

delivered="$(dbq DB_OUTBOX "SELECT COUNT(*) FROM outbox_messages WHERE status = 'processed' AND processed_at IS NOT NULL")"
want_at_least 'outbox messages delivered' 4 "$delivered"

audited="$(dbq DB_AUDIT "SELECT COUNT(*) FROM audit_log WHERE action LIKE 'security.%'")"
want_at_least 'security events that became audit rows' 4 "$audited"

# The operator's own worker command. The shipped timetable is stateless cron
# (`*/5` matches, at most, one minute in five), so what is asserted is that it
# runs clean against this queue and reports its outcome — the delivery itself is
# asserted above, where it is deterministic instead of minute-dependent.
#
# ORDERING IS LOAD-BEARING, and this is the line that has to say so. Since
# Boot 31 the timetable also carries `queue:work`
# (`SchedulerServices::withQueueWork`), so `schedule:run` DRAINS THE QUEUE. It
# is green below because nothing has been pushed yet — the first push is the
# unroutable job below, and it is worked explicitly so its exit code can be
# asserted separately. Move a push above this block and `schedule:run` starts
# exiting 1 on a poison job, and the gate fails here rather than where the push
# actually belongs.
if php bin/cms schedule:run > "$TMP/schedule.log" 2>&1; then
    ok 'bin/cms schedule:run exits 0'
else
    cat "$TMP/schedule.log"
    bad 'bin/cms schedule:run failed'
fi

if grep -q 'scheduled task' "$TMP/schedule.log"; then
    ok 'schedule:run reported its outcome'
else
    cat "$TMP/schedule.log"
    bad 'schedule:run printed no outcome line'
fi

# The queue's own operator command, on the path a cron takes: an empty queue
# must exit 0 and report zeroes. A worker that reddened every tick because it
# had nothing to do is a worker whose "1" means nothing, and this is the line
# that says so.
if php bin/cms queue:work > "$TMP/queue-empty.log" 2>&1; then
    ok 'bin/cms queue:work exits 0 on an empty queue'
else
    cat "$TMP/queue-empty.log"
    bad 'bin/cms queue:work failed on an empty queue'
fi

if grep -q 'processed=0 failed=0' "$TMP/queue-empty.log"; then
    ok 'bin/cms queue:work reports its counts'
else
    cat "$TMP/queue-empty.log"
    bad 'bin/cms queue:work printed no counts'
fi

# The Queue module's worker. This tree ships no producer (measured: `push()` has
# no call site outside the module's own tests), so the job is pushed through the
# module's own API — the way the seed block above builds its accounts — and then
# worked by the OPERATOR'S COMMAND, not by an inline heredoc: a gate that proves
# the worker by calling the module's API proves a worker nobody can put in
# crontab. The handler is deliberately unregistered, so the job travels the real
# failure path: UnknownHandlerException → `failed_jobs` with the error, where
# the panel retries it.
QUEUE_JOB="$(php <<'PHP'
<?php
require 'src/init.php';

echo \Modules\Queue\Interfaces\HTTP\QueueServices::queue()->push(
    'smoke.unregistered',
    ['from' => 'admin-smoke'],
    maxAttempts: 1,
);
PHP
)"

if php bin/cms queue:work > "$TMP/queue-work.log" 2>&1; then
    cat "$TMP/queue-work.log"
    bad 'bin/cms queue:work exited 0 with an unroutable job in the queue'
else
    ok 'bin/cms queue:work exits 1 on a parked job'
fi

if grep -q 'failed=1' "$TMP/queue-work.log"; then
    ok 'bin/cms queue:work counts the parked job'
else
    cat "$TMP/queue-work.log"
    bad 'bin/cms queue:work reported no failure for a job it could not route'
fi

parked="$(dbq DB_QUEUE "SELECT COUNT(*) FROM failed_jobs WHERE id = '$QUEUE_JOB'")"
want 'the unroutable job is parked in failed_jobs' 1 "$parked"

check 'admin GET /mark/queue' '/mark/queue' 200 -b "$ADMIN_JAR" -c "$ADMIN_JAR"
ADMIN_TOKEN="$(token)"

if grep -q 'smoke.unregistered' "$TMP/last.out"; then
    ok 'the panel lists the failed job'
else
    bad 'the panel does not list the failed job'
fi

# Retry is the reversible half: the row leaves failed_jobs and is queued again
# under its own id (failed_jobs carries no max_attempts, so a retried job gets
# the default — documented in DatabaseQueue, not hidden).
got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/queue/failed/$QUEUE_JOB/retry" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/queue/failed/{id}/retry' 302 "$got"

requeued="$(dbq DB_QUEUE "SELECT COUNT(*) FROM jobs WHERE id = '$QUEUE_JOB'")"
want 'the retried job is queued again' 1 "$requeued"

left_failed="$(dbq DB_QUEUE "SELECT COUNT(*) FROM failed_jobs WHERE id = '$QUEUE_JOB'")"
want 'the retried job left failed_jobs' 0 "$left_failed"

got="$(status -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE/mark/queue/$QUEUE_JOB/delete" \
    --data-urlencode "_token=$ADMIN_TOKEN" -H "Origin: $BASE")"
want 'admin POST /mark/queue/{id}/delete' 302 "$got"

dropped="$(dbq DB_QUEUE "SELECT COUNT(*) FROM jobs WHERE id = '$QUEUE_JOB'")"
want 'the dropped job row is gone' 0 "$dropped"

# Both state changes are audit rows with an actor — the panel's write path, not
# just the row it moved.
queue_audited="$(dbq DB_AUDIT "SELECT COUNT(*) FROM audit_log WHERE action LIKE 'queue.%'")"
want_at_least 'audit rows for the queue state changes' 2 "$queue_audited"

check 'admin GET /mark/outbox' '/mark/outbox' 200 -b "$ADMIN_JAR" -c "$ADMIN_JAR"

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
