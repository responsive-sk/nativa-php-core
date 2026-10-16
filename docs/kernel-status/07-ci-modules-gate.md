# Boot 26 — the gate that could not see the catalogue (2026-10-16)

The security work of §7–§11 landed behind a CI workflow that stayed **green if
any of it were deleted**. Every guard those rounds added lives in a module or
in a module's tests, and a fresh checkout carries none of the catalogue: job 3
(`tests`) proves the kernel-only shape, and in that shape the guards are
SKIPPED, not run. Measured on a fresh clone of this commit, with no module
installed:

| | kernel-only (fresh clone) | catalogue installed |
|---|---|---|
| tests | 318 | 318 |
| skipped | **243** | 1 |
| assertions reached | 364 | 1339 |

41 test classes skip in that tree. The ones that matter — each of them the
pinning test of a §7–§11 fix — all skip with it:

```
Modules.Comments.Tests.CommentHoneypotTest
Modules.Users.Tests.PasswordResetServiceTest      (the reset-token port)
Modules.Users.Tests.PasswordResetMailerTest       (the transport)
Modules.Users.Tests.AuthServiceTest               (policy + throttles)
Modules.Users.Tests.AdminAccessControlTest
Modules.Users.Tests.AdminRoutePolicyCoverageTest
Modules.Security.Tests.RateLimitMiddlewareTest
Modules.Users.Tests.UsersAuthProviderTest
```

A pull request could have deleted the honeypot, the port, the policy or the
throttles and this workflow would have printed `OK, but some tests were
skipped!` on every run. That is the one thing a gate may not do, so the
workflow grew a fourth job: **Modules — the SAME suite with the catalogue
INSTALLED**, which installs the catalogue the way an operator does, migrates,
builds the bundles, picks a theme, and then **fails on any test that did not
run** (the skip set is empty but for one kernel-only test whose own reason
string says why).

## The job could not pass, twice

### 1. The catalogue could not be installed at all

```
$ php bin/cms module:add Outbox
Cannot install 'Outbox': missing dependencies: Cardboard.
$ php bin/cms module:add Cardboard
Cannot install 'Cardboard': missing dependencies: Outbox, Security.
$ (the retry loop the job runs)
::error::no progress in round 2 — pending: Audit Blog Cardboard Comments
   Locales Media Outbox Pages Questions Queue Scheduler Security Users
```

`module:add` refuses an incomplete **closure** and copies nothing, so the
retry loop is a topological sort — and no topological order exists. The graph
at that commit held a three-module cycle:

```
Cardboard → Security, Settings, Validation
Security  → Outbox     (modules/Security/tests/RateLimitMiddlewareTest.php only:
                        `use Modules\Outbox\…\OutboxServices` for a reset)
Outbox    → Cardboard  (Application/Services/OutboxMetric.php,
                        Interfaces/HTTP/Actions/OutboxAdminAction.php)
```

**Boot 20 caused it.** The CardboardKit merge is what moved the metric
subsystem back into Cardboard, and the kit had been the leaf every other
module could depend on — so `Outbox → Cardboard` appeared while
`Cardboard → Outbox` (four bulk actions recording refused operations) stayed.
Boot 14 had broken exactly this class of edge by guarding the observability
call sites; the merge re-made it, and part 03's "Acyclic; `module:show` green"
was a claim nothing had measured — `module:show` prints a closure, it never
walks the graph. `CONTRIBUTING.md` states the rule plainly ("`module:show X` →
`closure:` must stay acyclic") and nothing enforced it, because nothing until
now had to install the catalogue in dependency order.

Both directions are observability, not function, so both were guarded the way
Boot 14 guarded Users ↔ Outbox: a `class_exists()` check with the class named
inline and **no `use` statement** for the closure scanner to count.

- four bulk actions in `modules/Cardboard/Interfaces/HTTP/Actions/`
  (`BulkPublishAction`, `BulkDeleteAction`, `UsersAction`, `RolesAction`) —
  eight call sites, each stating the guard and dropping only the event when
  the Outbox is absent;
- `modules/Security/tests/RateLimitMiddlewareTest.php` — its `OutboxServices`
  import existed to reset a static instance that only exists when the module
  is there; it is now a guarded helper.

Measured after: Cardboard's closure is `Security, Settings, Validation`;
Security is a leaf; the graph is acyclic (Kahn over all 19 closures). A fresh
clone installs **all 19 modules in four rounds**:

```
round 1: Erik Griffe Mono Security Settings Validation
round 2: Cardboard Locales Outbox Scheduler Users
round 3: Audit Media Pages Queue
round 4: Blog Comments Questions
```

### 2. One honest skip the assertion counted as a missing test

With the install fixed, the job still failed — on a skip that is the SEED
talking, not a defect:

```
::error::Modules.Erik.Tests.ErikThemeTest::liveSurfacesInlineTheActiveThemesOwnBuild
         skipped in a tree with the catalogue installed
```

The migrators seed `theme=kernel`, and the kernel theme ships **no bundle by
design** (it is hand-written critical CSS); the test asserts the ACTIVE
theme's own build is inlined on the live homepage, so with the kernel theme
active there is no build to inline and it skips. The workstation passes only
because an operator has picked `mono` in `/mark` → Design.

The job now makes that pick itself, through the module's own repository
(`SettingsRepository::set('theme', 'mono', 'design')`) — and asserts it took:
the step fails when the stored name resolves back to a bundle-less theme.
Negative control: with `Mono` removed, the step prints `active theme: kernel
(bundle available: no)` and exits 1.

## Also corrected in the workflow's own header

- `240 of 315` → the measured **243 of 318**: the count predated the three
  transport tests §11 added.
- the CSRF origin check was listed among the skipped guards. It is not:
  `CsrfMiddlewareTest` lives in `tests/`, so job 3 — the kernel-only one —
  already runs it. The claim is now the measured one.

## Verified (2026-10-16, all exit 0)

| Gate | Result |
|---|---|
| fresh clone, no module, `composer test` | 318 tests, 243 skipped — the hole the job exists to close |
| the whole job, replayed: every `run` block of the `modules` job, extracted from the workflow and executed in order against a fresh worktree | **exit 0** — `Installed (19)`, `active theme: mono (bundle available: yes)`, `318 tests, 1339 assertions, 1 skip`, and the assertion's `318 tests ran, 1 skipped, 38 catalogue test classes expected` |
| fresh worktree + the five edited catalogue files, install loop | **19 modules installed**, 4 rounds, `Installed (19)` |
| the job's migrate step, that tree | `✓ Migrated: audit, blog, comments, config, locales, media, outbox, questions, queue, users`; all 9 `.db` files present |
| the job's theme step, that tree | `active theme: mono (bundle available: yes)`, exit 0; with Mono removed → exit 1 |
| `composer test` on the installed tree | **318 tests, 1339 assertions, 1 skip** (the allowed kernel-only one) |
| the job's assertion, run on that JUnit log | `318 tests ran, 1 skipped, 38 catalogue test classes expected`, exit 0 |
| `composer test` / `phpstan` (L9) / `cs-fix-dry` / `rector-dry` on this tree | 318 / 1 skip; no errors; 0 of 94; Rector OK |
| `make lint` / `make lekalo-check` / `make extract-check` | clean; 23 rules, 0 errors; OK (222 identical, 66 recorded — unchanged, the slice does not carry the catalogue) |
| `module:show` over all 19 catalogue entries | acyclic; the loop converges |

## Boot 27 — the live sweep the suite cannot do

`php -S 127.0.0.1:8301`, `APP_ENV=production`, a real session and a real CSRF
token scraped from the real forms, and **every database a temp file**
(`DB_USERS`, `DB_OUTBOX`, `DB_CONFIG`, … → `/tmp/boot27/`): the working tree's
`storage/data/` was never opened (mtimes unchanged, no probe rows). The admin
account and the five permission rows came from the module's own repositories.

The call sites this round re-pointed at the Outbox are the bulk actions and the
user/role mutations, so the sweep drives exactly those and then reads the outbox
rows back:

| | result |
|---|---|
| admin login → `/mark`, `/mark/users` | 302 → 200, 200 |
| `POST /mark/users/create` → `…/{id}/delete` | 302 / 302, the row written then **gone** |
| `POST /mark/roles/create` → `…/{id}/delete` | 302 / 302, the row written then gone |
| outbox after the CRUD | `security.user.changed` ×2, `security.role.changed` ×2 — the guarded call sites, over HTTP |
| create with no token / cross-site `Origin` + valid token | 403 / 403 |
| **author** (`content.manage`, no `users.manage`) posting `entity=users` | 403 naming the missing permission, and `security.authz.denied` recorded — the refusal branch this round guarded |
| **admin** posting the same | 302 and **no** denial: the branch is reachable only without `users.manage` (worth pinning as the contrast) |
| the same flows with the Outbox **removed** (`module:remove Outbox --force`, server restarted) | login 302, `/mark` **200** (the dashboard degrades, no 500), user create/delete and role create/delete 302 with the rows really written and really deleted, the refusal still 403, **0 outbox rows added**, and no `PHP Fatal` / `[KERNEL] Error` in the log |
| `module:add Outbox` back | the next refused post records again (18 → 20 rows), which is the guard flipping both ways |

Two things the sweep taught that are worth keeping:

- **A green status is not a green effect.** The first run of the degraded probe
  checked a delete on a bogus id — `UID` is read-only in bash, so the id was
  empty, and `POST /mark/users//delete` still answers 302 because it redirects
  for an id it cannot find. Re-run with a real id, the row is gone and the
  check means what it says.
- **Three call sites still hard-import the Outbox** — Blog's publish toggle,
  Blog's inline edit, Audit's event handler. That is the declared graph, not a
  defect (the edges are acyclic), and it is why removing Outbox at all needs
  `--force`. The sweep claims only the flows this round touched.

Drift cured by the trip: `module:remove Outbox --force` + `module:add Outbox`
re-installed the slice verbatim from the catalogue, so the two stale test files
noted above now match it.

## Boot 28 — the panel, booted, as a gate

Boot 27's matrix was measured by hand, and a measurement nobody re-runs is a
memory. It is now `bin/build/admin-smoke.sh`, run by a fifth CI job
(`admin-smoke`) and by `make admin-smoke`: the script migrates its own temp
databases, seeds an admin and an author through the modules' own repositories
(the Users migrator seeds **no roles** — measured, so the seed lives here),
starts `php -S` with opcache off, walks the flows, reads the outbox rows back,
stops the server and cleans the temp directory. 29 checks, all of them fatal.

| group | checks |
|---|---|
| guest | `/` 200, `/login` 200, `/docs` 200, `/nope` 404, `/mark` **302**, `/mark/users` **302** |
| admin | login 302; `/mark`, `/mark/users`, `/mark/roles` 200; `POST /mark/users/create` then `…/{id}/delete` 302 with the **row** asserted present and gone; `POST /mark/roles/create` then `…/{id}/delete` 302, same |
| the guarded call sites | `security.user.changed` ≥ 2 and `security.role.changed` ≥ 2 in the outbox |
| CSRF | no token → 403; valid token + cross-site `Origin` → 403 |
| the refusal branch | author (no `users.manage`) `entity=users` → 403, body names the reason, `security.authz.denied` recorded |
| the log | no `PHP Fatal` / `[KERNEL] Error` |

The job deliberately carries **no `composer install`**: the modules come from
the committed catalogue and the kernel runtime has zero Composer dependencies,
so this is one more place that claim is either true or not (measured: the whole
job runs in a fresh clone with no `vendor/`).

The install loop the `modules` job used is now `bin/build/install-catalogue.sh`,
shared by both jobs and by `make modules-install` — two copies of a topological
sort is one copy too many. Two things changed in the move: the expected count is
derived from the catalogue (18 entries + `KernelTheme`) instead of the literal
19, and a module that is already installed is skipped so the target is
re-runnable on a workstation. Neither weakens the check: the count still
demands the whole catalogue, so a tree that cannot install an entry still fails
here rather than in the suite.

Verified by replaying both jobs' `run` blocks in order against a fresh
`git worktree` of this commit:

| | result |
|---|---|
| `admin-smoke`, no `vendor/` in the tree | **exit 0**, 29 checks, `storage/data/` still empty afterwards — the job never writes to the checkout |
| `modules` (build → install → migrate → theme → suite → assert) | **exit 0**: `Installed (19)` from 18 catalogue entries in four rounds, `active theme: mono (bundle available: yes)`, `318 tests, 1339 assertions, 1 skip`, assertion `318 tests ran, 1 skipped, 38 catalogue test classes expected` |
| `shellcheck` on both scripts / `actionlint` on the workflow | clean |

## Boot 29 — the gate reaches the content flows

The admin gate stopped at the panel's account surfaces, which is not where an
operator lives: the two editors they use every day are the article and the
page. Both are now in `admin-smoke.sh`, through the panel, with the FILE as the
assertion rather than the status:

| flow | checks |
|---|---|
| article | `GET /mark/articles` 200; `POST /mark/articles/create?locale=sk` 302; the `.md` exists; `GET /blog/<slug>` **200**; `GET /blog` 200; `POST /mark/articles/<slug>/delete` 302; the file is gone; `GET /blog/<slug>` **404** |
| page | `GET /mark/pages` 200; `POST /mark/pages/create?locale=sk` 302; the file exists; `GET /<slug>` **200**; delete 302; the file is gone; `GET /<slug>` **404** |

Two things writing the checks measured, both now commented where they bite:

- an article create **without `date`** is a 400 with the form re-rendered
  (`ArticlesCreateAction::validate` requires title, date and body);
- deleting an article that does not exist is a **403** from the ownership gate,
  not a 404 — which is exactly why the file, not the status, is the assertion in
  both flows.

**Content is the one thing the gate writes into the checkout.** Articles and
pages live under `storage/` and there is no root override the way there is for
the ten databases (`AppPaths::storage()` is the checkout, measured), so the
script snapshots `storage/` minus the volatile trees before the content section
and **fails** if the tree does not come back exactly as it found it. That is what
makes `make admin-smoke` safe on a workstation whose `storage/` holds a
developer's own posts; on a runner the checkout is disposable either way.

Verified: 45 checks, exit 0 on this tree and again on a fresh `git worktree`
(catalogue installed there, `storage/` byte-identical afterwards, no
`smoke-*` file left anywhere); `shellcheck` and `actionlint` clean.

## Boot 30 — the gate reaches the anonymous world, and the workers

Boot 29's gate stopped at the panel: every flow it walked needed an admin
session. The one mutation a stranger can make — a comment — and the two things
that consume a queue were measured by nothing but unit tests. Both are in
`admin-smoke.sh` now: **75 checks, was 45**.

### The public comment form

Walked end to end from the article page, on an article the run created:

| flow | checks |
|---|---|
| honeypot | POST with `comment_website` filled → 302, and **no row** (the answer is the very redirect an honest submission gets) |
| guest submit | POST (name, email, body) → 302; the row exists; `status = 'pending'` |
| pending | `GET /blog/<slug>` does **not** contain the body — from outside, a pending comment and one that was never submitted are indistinguishable |
| moderation | `GET /mark/comments` 200 and lists it; `POST …/approve` 302; `status = 'approved'` |
| published | `GET /blog/<slug>` now contains the body |
| deleted | `POST …/delete` 302; the row is gone; the body is gone from the page |

### The workers

Delivery is asserted where it is deterministic, and the operator command is
asserted where it is not — the two are not the same claim:

- **The outbox drain.** Every security event this run records has to have been
  consumed by the end-of-request drain `public/index.php` registers:
  `pending = 0`, `failed = 0`, `processed AND processed_at IS NOT NULL ≥ 4`,
  and — the assertion that gives the row count meaning — at least as many
  `security.%` **audit rows**, because the handler that consumes exactly those
  events IS the audit writer. Measured on this run: **7 delivered, 7 audit
  rows**.
- **`bin/cms schedule:run`.** The shipped timetable is *stateless* cron
  (`outbox:drain` on `*/5`, prune daily), so it is due one minute in five. The
  check is that it exits 0 against this queue and prints its outcome line;
  asserting delivery here would be asserting the minute, and the delivery is
  already asserted above.
- **The Queue module's worker.** This tree ships no producer — measured:
  `DatabaseQueue::push()` has no call site outside the module's own tests — so
  the job is pushed through the module's own API (the way the seed block above
  builds its accounts) and worked by `work()` **in its own process**, which is
  what a worker is. The handler is deliberately unregistered, so the job
  travels the real failure path: `UnknownHandlerException` → `failed_jobs` with
  the error → the panel lists it → `…/failed/{id}/retry` puts it back in `jobs`
  and empties `failed_jobs` → `…/{id}/delete` drops it, and both state changes
  leave `queue.%` audit rows. That is the queue module's admin half exercised
  through the panel rather than only through its repository.

Verified: **75 checks, exit 0** on this tree and again on a fresh `git worktree`
with no `vendor/` (catalogue installed there, `storage/` byte-for-byte as it
found it, no `smoke-*` file anywhere, no server left listening); `shellcheck` on
the script and `actionlint` on the workflow clean.

## What this round did not close

- **CI `Extract-check` is still NOT RUN** (Boot 20): the upstream checkout is
  private. Unchanged by this round.
- ~~**The Queue module still has no producer.**~~ **CLOSED in Boot 32.** The
  article cover: `ArticlesCreateAction`, `ArticlesEditAction` and
  `BlogInlineEditAction` each queue a `blog.cover` job next to the cache flush
  they already do, and `CoverRenderHandler` renders it through the operator's
  own `generate-images` command. Deleted rather than reworded, as Boot 30
  promised — the note said it would be, and it was.
- **The refusals that need a forged client address** (the register and reset
  throttles, the rate-limit 429) are not in the gate: loopback is exempt from
  throttling by design, and a `php -S` probe cannot forge `REMOTE_ADDR`. Those
  stay pinned by `AuthServiceTest` / `PasswordResetServiceTest` /
  `RateLimitMiddlewareTest` under the `modules` job.

### Measured drift, and its cure

On this workstation `src/Modules/Outbox/tests/OutboxAdminListTest.php` and
`OutboxMetricTest.php` were OLDER than the catalogue's copies (the catalogue
guards on `class_exists(CardboardRegistry::class)`, the installed copies did
not). The suite reads the catalogue's tests, so nothing failed, and the new job
installs verbatim — a fresh tree cannot be in this state. `module:add` never
overwrites an installed slice, so the cure is a remove-and-add, not an edit —
and the degraded-tree probe needed exactly that, so it left the tree cured:
`diff -rq modules/Outbox src/Modules/Outbox` is empty again.

## Boot 31 — the worker an operator can actually run

Boot 30 closed with a note it promised to **delete rather than reword** when a
producer landed. The producer did not land — but the measurement behind the
note turned out to be hiding a second, worse gap, and this round is about that.

### The gap: a worker only the gate could run

Boot 30 wrote it plainly: "`DatabaseQueue::push()` has no call site outside the
module's own tests", so the gate pushes a job through the module's own API and
works it through `work()`. Reading the same file again, the honest half of that
sentence is one level down: **`work()` itself had no call site outside a `php`
heredoc inside `bin/build/admin-smoke.sh`.** So the queue shipped a worker that

- no operator could put in crontab (it is not a command),
- no support answer could point at (there is nothing to point at),
- and no documentation could tell you how to run (there was nothing to tell).

A module with a worker and no way to run the worker is half a module, and the
gap had been documented as a *producer* problem when it was also a *surface*
problem — one that a producer landing would not have fixed.

### What changed

`bin/cms queue:work [<name>] [--limit=N]`, shaped on the command that already
existed next door:

```
$ php bin/cms queue:work
Queue: processed=0 failed=0 (queue: (any), limit 10)
$ php bin/cms queue:work --limit=3 mail
Queue: processed=0 failed=0 (queue: mail, limit 3)
```

- refuses with exit 1 when Queue is not installed, like `schedule:run`;
- prints its counts, and when anything is parked, where to deal with it;
- **exit 1 when something is parked in `failed_jobs`, else 0** — the contract
  `outbox:process` already states, so a cron or a CI probe notices a poison job
  instead of silently rotating past it.

The semantics live on `QueueServices::work()` / `::exitCode()`, not in the CLI,
which is exactly the split `SchedulerServices::run()` keeps for `schedule:run`:
the module test asserts what the command does, and what the suite proves and
what the operator runs cannot drift apart.

It also joins the timetable — **in the Scheduler module, which is the one
place the first attempt at this could not go.** It was written in `bin/cms`
first, and the kernel-only PHPStan gate said no:

```
Parameter $schedule of function withQueueWork() has invalid type
  Modules\Scheduler\Scheduling\Schedule.
  🪪 class.notFound
Call to method call() on an unknown class
  Modules\Scheduler\Scheduling\Schedule.
  🪪 class.notFound
```

`bin/cms` is analysed in a tree with no module installed, so annotating that
parameter — or calling a method on the value — is `class.notFound` at level 9.
That is the trap this tree's own comments keep naming: **a type position is
resolved REGARDLESS of the guard**, which is what a kernel-only checkout with
no `Modules\Scheduler` on disk catches. `cmdScheduleRun()` had been surviving it
only by never touching `$schedule` beyond handing it straight back to
`SchedulerServices::run()`.

So the entry moved to `SchedulerServices::withQueueWork()`, and the two
remaining reasons it costs the closure nothing are the ones Boot 26 already
measured:

- the scan counts `use Modules\…` lines only, so `QueueServices` is named
  inline and never imported — the same escape hatch as the Cardboard bulk
  actions. Measured after: `module:show Scheduler` → `closure: Cardboard,
  Outbox`, **Queue absent**;
- `$report` is a `callable(string): void`, so the one line the skip prints goes
  to `bin/cms`'s `out()` and the module itself prints nothing.

Measured:

```
$ php bin/cms schedule:run                      # migrated
Executed: queue:work
Executed 1 scheduled tasks.                      # exit 0

$ DB_QUEUE=/tmp/fresh.db php bin/cms schedule:run
queue:work skipped — /tmp/fresh.db has no `jobs` table yet (run: php bin/cms migrate).
No scheduled tasks are due.                      # exit 0
```

Without that guard a cron firing before the first `bin/cms migrate` would fail
a task every minute and redden every run, for a reason the failure line does
not carry. The entry is `everyMinute()` with a five-minute overlap guard, so a
slow batch is not landed on by the next tick.

Writing the test for the skip also corrected a second mistake of the same kind:
`putenv('DB_QUEUE=…')` is not enough on its own. `DatabaseConnectionManager`
**caches the open PDO per name**, so the "the entry must not register" case
passed anyway — it was asking the previous test's migrated handle. Both
singletons are reset now, with the reason written at the call.

### The parity fix, which is the part that mattered

Writing the command ran into something the Boot 16 write-up had described but
not finished. The Audit↔Outbox handler pairing was declared **inline in
`public/index.php`**, which means it existed in exactly one process — the one
serving HTTP. A worker in any CLI boot would have drained an empty handler map
and parked every message as `No handler registered for event type`, while the
HTTP responses kept looking perfectly healthy. That is the Boot 16 failure
verbatim, one process over, and it was still live.

So the pairing moved to **`src/composition.php`**, a file with no class in it
(KernelShapeTest's allow-list is untouched, and the closure scan never counts
`src/`), and **both** entry points call it:

| entry point | before | after |
|---|---|---|
| `public/index.php` | declared the pairing inline | calls `wire_module_handlers()` |
| `bin/cms` (`queue:work`, `schedule:run`) | nothing | calls `wire_module_handlers()` |

Module classes appear fully qualified and inline there, never in a `use` and
never in a type position — both are load-bearing: the `use` line is what the
closure scan counts, and a type position is resolved *regardless of the guard*,
which is what a kernel-only checkout with no `Modules\Audit` on disk catches.

### A claim in the note was wrong, and the tests now pin the truth

Boot 30's note says the gate pushes through the module's API "because nothing
in this tree enqueues a job" — still true. But writing a test for the exit
code exposed a second thing nobody had written down: **`work()` does not spread
retries over time.** A throwing handler is caught and the row goes back through
`release($id)`, which writes `available_at = now`; the next loop iteration finds
it available again. Measured, with `max_attempts = 3` and the default limit of
10:

```
Queue: processed=0 failed=1     # one call, three attempts spent, row parked with attempts = 3
```

`release()` takes a `$delaySeconds` exactly to allow a backoff and `work()`
never passed one. So `max_attempts` bounded *retries*, not *minutes*.

**Boot 32 closed this.** `work()` now releases with `30 * 2 ** (attempts - 1)`
seconds, capped at 3600 — the cap because `max_attempts` has no upper bound (a
producer sets it per job) and an uncapped power would schedule a row years out,
turning a poison handler into something nobody looks at again. The formula is
Media's, so the tree's two retry paths read alike.

The tests pin the timing on an **injected clock**, not by sleeping:
`DatabaseQueue` has accepted `?callable $now` since it shipped and **no test had
ever passed it**, so before this the retry path could not be observed at all —
which is why the defect survived. `FileTaskMutexTest` was already the pattern.

What changed for an operator: a worker that will RETRY now exits **0**, and one
that has parked something exits **1**. Before, a single retry could redden a run
— which is how a "1" stops meaning anything.

### The gate now runs the command an operator runs

The queue section of `admin-smoke.sh` called `work()` through an inline heredoc.
It now runs `php bin/cms queue:work`, because **a gate that proves the worker by
calling the module's API proves a worker nobody can run.** Four checks, 78
total:

| check | asserts |
|---|---|
| exits 0 on an empty queue | the cron path — a worker that reddened every tick with nothing to do is a worker whose `1` means nothing |
| reports its counts | `processed=0 failed=0` reaches stdout |
| exits 1 on a parked job | the poison-job contract |
| counts the parked job | `failed=1`, and the row is really in `failed_jobs` |

### Verified (2026-10-16, all exit 0)

| Gate | Result |
|---|---|
| `make admin-smoke` | **78 checks**, exit 0; `storage/` back byte-for-byte |
| `composer test` | 331 tests, 1384 assertions, **1 skip** (the kernel-only one) |
| `make lint` | clean |
| `phpstan` (L9) / `rector-dry` / `cs-fix-dry` | no errors; OK; 0 of 95 |
| `shellcheck` (both scripts) / `actionlint` (workflow) | clean |
| `bin/cms queue:work` live: empty queue / poisoned job / named queue + limit | `0/0` exit 0; `0/1` exit 1 with the parked-job hint; `queue: mail, limit 3` |
| `schedule:run` migrated / unmigrated | `Executed: queue:work` exit 0; skip line naming the real path, exit 0 |
| a CLI-only boot (`src/init.php` + `src/composition.php` only) | `wire_module_handlers()` exits 0 with no modules on disk |
| `module:remove Queue --force` + `module:add Queue` | 15 files, slice re-synced from the catalogue |

## What this round did not close

- ~~**The Queue module still has no producer.**~~ **CLOSED in Boot 32.** The
  article cover: `ArticlesCreateAction`, `ArticlesEditAction` and
  `BlogInlineEditAction` each queue a `blog.cover` job next to the cache flush
  they already do, and `CoverRenderHandler` renders it through the operator's
  own `generate-images` command. Deleted rather than reworded, as Boot 30
  promised — the note said it would be, and it was.
- **The obvious producer is a security decision, not a feature.** Making the
  reset link a job would put the plaintext token in `queue.db/jobs.payload`,
  again in `failed_jobs.payload` on exhaustion, and on screen at
  `/mark/queue/{id}` — the second copy of a credential in a second database
  that `PasswordResetService`'s port was written to make impossible (audit
  finding #1 was the same token in a log). The token cannot be reconstructed
  from the stored `sha256`, so the options are "accept and document", "redact
  sensitive payloads in the panel", or "don't" — an operator decision, not one
  to smuggle in with a worker command.
- **Comments and Media have nothing to defer.** Comments have a `pending` flag
  and no notification fan-out; Media has no image variants. Queueing either
  would mean inventing the feature first.
- **Audit must not be queued.** `logWithId()` keyed on the outbox message id is
  the only reason the async audit path is idempotent; the queue has no dedupe,
  so a deferred audit write duplicates rows in an append-only log — and it
  would break the gate's own synchronous `audit_log` counts.
- **`work()` has no backoff** (above), and `#[AsCommand]` commands a module
  ships — `outbox:process`, `generate-images` — are still **not dispatched by
  `bin/cms`**: the switch has no case for them, so `php bin/cms
  outbox:process` prints the usage text. Out of scope here; recorded because
  `docs/modules.md` quotes `outbox:process`'s exit contract as if it were
  reachable.

## Boot 32 — the first real producer, and a model that could not check it

Boot 30 wrote a note to be **deleted rather than reworded** when a producer
landed. This is that round.

> **One number above is corrected rather than appended.** Boot 31 recorded **79**
> checks; the gate as it stands in `master` emits **78**, measured by replaying
> it from a worktree at `75d50a9` — whose `bin/build/admin-smoke.sh` is
> byte-identical to `master`'s, so the count is the merged one and not a
> worktree artefact. The whole chain is now measured rather than assumed:
> **78** (master) → **81** (Boot 32's backoff checks) → **85** (the cover
> checks). A log whose subject is measured states cannot carry an off-by-one
> that nobody re-counted, and the reason is worth naming: the number was taken
> from a filtered run rather than by counting what the script emits.

### The trigger was measured, and the measurement overruled the plan

The obvious place to hang a producer is the outbox — this tree's established
async mechanism. It is the wrong place, and one measurement says why:

| action | outbox event | cache flush |
|---|:---:|:---:|
| `ArticlesCreateAction` | no | yes |
| `ArticlesEditAction` | no | yes |
| `BlogInlineEditAction` | no | yes |
| `ArticlesDeleteAction` | no | yes |
| `ArticlePublishToggleAction` | **yes** | yes |

`article.published` / `article.unpublished` are recorded in exactly ONE place.
A cover tracks **content**, so the publish toggle is wrong twice over: it fires
on unpublish, when nothing is served at all, and it misses a later edit of an
already-published article — which is exactly when a stale cover appears on the
page. The producer therefore sits in the three content-write actions, at the
`CacheInvalidator::flush()` they already call.

### The payload is two scalars, and that is the property worth keeping

`{slug, locale}` — identifiers, not content. No token, no body, no file handle,
no rendered SVG. `jobs.payload` and `failed_jobs.payload` both live in
`storage/data/queue.db`, and the payload is rendered on screen at
`/mark/queue/{id}`, so a credential in a payload is a credential in a second
database and on a page — which is what the audit found when a reset token
turned up in `app.log`.

**The queued reset link is therefore still NOT a producer**, and stays an
operator decision ("accept and document", "redact sensitive payloads in the
panel", or "don't"), unchanged from Boot 31. The cover was chosen because its
payload needed no such decision.

Ids also mean a job that runs late renders the article as it IS rather than as
it was when queued — which is what an operator wants from a derived artefact,
and the reason no snapshot is stored.

### The edge is declared, not guarded

`QueueServices` is imported, so `module:show Blog` gains Queue (closure: 8 → 9
entries, graph still acyclic — Queue's closure is `Audit, Cardboard`) and
`module:add Blog` refuses without it, exactly as it already refused without
Outbox. The `class_exists`-without-`use` hatch was available and deliberately
**not** used: it exists for dependencies genuinely absent without a module, and
this one is real. A dependency hidden from `module:show` is a dependency an
operator cannot see.

`CoverRenderHandler` wraps the OPERATOR'S COMMAND rather than copying the
rendering logic — if it carried its own copy of the front-matter extraction, the
locale collision rule and the raster fallbacks, the two would drift, and the
copy nobody runs by hand is the one that quietly stops matching.

### The gate asserts the producer end to end

Four checks, where they had to go: in the **content section**, because that is
the last point in the script where the article exists — the handler renders from
the markdown file, so a cover job worked after the delete would be asserting on
a slug that is gone. 81 → **85** checks.

| check | asserts |
|---|---|
| writing the article queued exactly one cover job | the producer fires on the content write |
| `queue:work` exits 0 | the worker renders rather than parking |
| the cover file exists and is non-empty | **the file is the assertion**, for the reason the article's own check is the file and not the 302 |
| the queue is empty again | the job is deleted on success |

Wiring is the thing being proved, so it was proved both ways: wired, a pushed
`blog.cover` renders a 2 553-byte SVG; **without the wiring the same job parks
with `No queue handler registered under "blog.cover"`**.

### What the round found by accident: the model lies and nothing catches it

Adding the gate checks meant finding out what article delete actually does.
`BlogCrudService::delete()` is a single `unlink()` of the markdown file — the
cover is orphaned when its article goes. And `lekalo/modules/blog/commands.yaml`
describes that same command as deleting "an article file (and its tracked cover
if owned)".

**The model states a thing the code does not do, and nothing in the pipeline
notices** — because `lekalo.lock` reports `adapters 0`, so no PHP adapter
compares the model to the implementation. The gate removes the orphan itself,
because the storage snapshot exists to prove *this script* leaves no trace, and
folding a product gap into that check would make it mean two things at once.

`docs/modules.md` gained a section saying so up front: **the model is
documentation with a schema, and the gate keeps the documentation well-formed
(it does not make it true).** That section is not an abstraction — this is the
second measured instance of the failure it describes.

### Verified (2026-10-16, all exit 0)

| Gate | Result |
|---|---|
| `make admin-smoke` | **85 checks**; storage back byte-for-byte |
| `composer test` | 352 tests, 1471 assertions, 1 skip |
| `make lint` / `phpstan` (L9) / `rector-dry` / `cs-fix-dry` | clean / no errors / OK / 0 of 96 |
| `shellcheck` (both scripts) / `actionlint` | clean |
| `make lekalo-check` | 23 rules, 0 errors — and, in CI (run 38933437965), both the pinned install and the gate green |

## What this round did not close

- **Deleting an article orphans its cover.** Measured above; the gate covers for
  it in the script, the product does not. `BlogCrudService::delete()` would have
  to unlink the cover too, which is a behaviour change to a delete — and the
  model's own description says that is what it already does, so the model and
  the code have to be reconciled either way. Not done here: it is not this
  round's change to make silently.
- **The queued reset link stays an operator decision** (above), and the token
  cannot be reconstructed from the stored `sha256`, so the options remain
  "accept and document", "redact sensitive payloads in the panel", or "don't".
- **The model has no adapter**, so nothing checks it against the code, and
  seven of twenty-one modules are modelled at all (14 are stubs, including
  `core` and `database`). `docs/modules.md` now says what the gate buys and what
  it does not; the adapter is the thing that would close the gap.

---

## Boot 33 — two branches the reconciliation never closed

The 2026-10-16 01:22 theme-branch reconciliation ended with a measured merge
attempt and one sentence: *"Verdicts are the user's."* The attempt was made, the
numbers were taken, and no verdict was ever given — 904 rows sat unmerged across
two branches whose tips date from 2026-10-12, while every other branch in the repo
got swept.

They are closed here. Re-measured against today's `master` first: the patch was
**13 commits stale**, and a stale measurement is a guess wearing a citation.

| | erik (#8) | griffe (#9) |
|---|---|---|
| `git merge-tree --write-tree master <branch>` | **exit 0**, 0 conflicted paths | exit 1, **1** conflicted path |
| real merge in a worktree | automatic, no edits | resolved, no other conflict |
| the module's own tests | **11 tests / 91 assertions** | **30 tests / 126 assertions** |
| full suite on the merged tree | 359 / 1535, exit 0 | 353 / 1473, exit 0 |
| rows into `master` | **+802** | **+108** |

**Both assertion counts match the reconciliation patch exactly** — 91 and 126.
That is the check that a measurement taken the same morning, against a `master`
13 commits older, still holds today — and it is the only reason the patch's
numbers were worth re-running rather than re-typing.

### The conflict resolution, and why it is not a coin flip

`GriffeTheme::heroSlide()` was the single conflicted path. The two sides are not
two answers to one question, so there was nothing to choose between:

- the **branch** adds a decorative stage — `heroVisual()` and an eyebrow kicker;
- **master** changed `revealLines()` to take a second argument so the hero can
  pre-paint revealed.

The merge takes both: the branch's composition with **master's argument**
(`revealLines($title, true)`). Taking the branch's one-argument call would have
merged cleanly and **silently undone master's animation change** — which is
precisely why the conflict exists at all. That asymmetry is now a comment in
the code, not a claim in a patch nobody reads.

### The risk profile differed, and it was measured rather than felt

| | erik | griffe |
|---|---|---|
| `*Theme.php` on `master` since the branch point | **0 commits** (untouched) | **+68 / −44** |
| `*ThemeTest.php` on `master` | 1 commit | **7 commits** |
| `*.css` on `master` | 2 commits | 4 commits |

Erik is additive into a file nobody edited. Griffe lands a hero next to an
animation `master` had since rewritten. Both suites are green, and **"the suite
passes" is a claim about correctness, not about design** — so the Griffe PR says
so in its own body rather than leaving it as a surprise after the merge.

### The round's own defect, which is the reason this entry is longer

The first re-measurement produced a **green suite from a broken environment**.
The probe worktree was seeded with `cp -a "$MAIN/src/Modules" .`, and a fresh
`git worktree add` of a repo whose module slices are gitignored already *has* a
`src/Modules` — so `cp -a` copied the directory into itself as
`src/Modules/Modules`. Five installed modules instead of 22, **267 tests
skipped**, and every module test reporting as OK because most of them never ran.

The tell was not the suite output; it was `git status` showing an untracked
`Modules/`. The fix is the trailing `/.`, which copies contents.

This is the **third** trap in the same family as the two the reconciliation
patch paid for — symlinked `vendor/` breaking `ModuleLifecycleTest`'s tree hash,
and reinstalling every module in `ls` order, which destroys the install set
because Cardboard ↔ Outbox are circular. What makes this one worth more than the
other two is that **the patch recommended it**: its `Reproduce` block prescribed
`cp -a <main>/src/Modules .`, verbatim, and following a patch exactly is the one
move that cannot catch you being wrong. The patch now carries all three, with the
`ls src/Modules | wc -l` check the reproducer lacks. The suite being green was
the least informative signal available throughout.

A fourth, smaller one: `master` had an unpushed commit — this retrospective,
written at 19:21 and still local while the round it describes was closing. The
record of the round that closed the last round's open decision was readable only
on the machine that wrote it — the same failure as `evolutions/` being empty and
`patches/` being gitignored, at a scale of one commit.

### Verified (2026-10-16, all exit 0)

| Gate | Result |
|---|---|
| `composer test` | **360 tests, 1557 assertions**, 1 skip (theme bundle, built in CI) |
| `make admin-smoke` | **85 checks** — unchanged; the themes add no admin surface |
| `make lint` / `phpstan` (L9) | clean / no errors |
| `make lekalo-check` | 23 rules, 0 errors |
| CI on both PRs, and on `master` after the merges | 8/8 green each; `master` run `38942306768` green |

## What this round did not close

- **Griffe's hero is correct and green, not chosen.** No test can decide whether
  the decorative stage belongs beside the animation `master` rewrote. That call
  is still open, and it is the reviewer's, not the suite's.
- The three items Boot 32 did not close are unchanged and still open: the
  orphaned cover on delete, the queued reset link's security decision, and the
  model's missing adapter.

---

## Boot 34 — the delete the model described, performed

Boot 32 closed this round's record by naming a defect and refusing to fix it in
the same breath: *"Deleting an article orphans its cover … the model's own
description says that is what it already does, so the model and the code have
to be reconciled either way. Not done here: it is not this round's change to
make silently."*

Making the code true was the right direction. A description nobody can check is
documentation; an orphan nobody notices is a leak.

### What it was, measured before anything changed

Reproduced through `BlogCrudService::delete()` — the call the admin action
makes — with a real article and both cover shapes on disk:

```
clanok              false          <- gone
flat obalka         true           <- orphaned
kvalifikovana       true           <- orphaned
delete() vratil     true
```

Not a stale file on a developer's disk. `ServeBlogImageAction` serves
`/storage/blog-images/*` with `max-age=31536000`, so every article ever deleted
left an image reachable at a URL nothing links to any more.

### A fix that unlinks harder would have broken two things

The obvious repair — unlink the cover in `delete()` — passes "the cover is
gone" and breaks live articles. Both of these are real, not hypothetical:

- **The flat `blog-images/{slug}.svg` carries no locale.** It is written for a
  slug only one locale holds, but a locale that gains an article *afterwards*
  owns a file that is not its own. Removing it on that article's delete blanks
  the other article's image.
- **`resolveCover()` ranks an explicit `image:` above the generated file.** An
  author can deliberately point one article at another's cover, and generation
  being slug-keyed, that reference is the only way two articles can share one.

So `CoverPathResolver::existingCovers()` treats the two shapes unequally — the
locale-qualified path is unambiguous and always removable, the flat one is a
candidate only while `collides()` says no other locale holds the slug — and
`BlogCrudService::delete()` scans the surviving articles for `image:` before
unlinking anything. `lekalo/modules/blog/commands.yaml` now says so.

### The gate caught a defect the tests could not

The unit tests passed from the first run. The **admin-smoke gate failed**, and
the reason is worth more than the fix:

```
:::error::the cover outlived the article it belongs to
> storage/blog-images/smoke-article-2144512940.svg
```

Every test passed the locale as `sk`, because that is what the generator's
`localeFor()` returns. The admin delete goes through `AdminLocale::canonical()`,
which returns **`sk_SK`**. Handed `sk_SK`, `collides()` compares by language
code, its `continue` never fires, the article matches **itself** as "another
locale's", and the flat cover is excluded from its own delete. The article
vanished, its cover stayed, and the whole module suite was green.

This is the same defect `CoverPathResolver::localeFor()`'s own docblock warns
about — *"the mapper's locale codes and the code of the article being written
have to mean the same thing, or the article matches itself"* — reached through
the other door. `existingCovers()` now normalises through `codeFor()`, so no
caller has to know which spelling it is holding, and a test asserts through
`AdminLocale::canonical()` rather than through the spelling that happens to
work.

### The gate stopped tidying up after the code

`admin-smoke.sh` carried a `rm -f "storage/blog-images/$ARTICLE.svg"` and a
`KNOWN GAP` comment explaining why the gate was cleaning up after the product.
The product now does it, so the `rm` is gone and the assertion sits where the
delete happens. A gate that removes the evidence is a gate that would have
hidden this fix as readily as the regression.

`coverRemovalIsNotLoadBearingWithoutTheReferenceScan()` in the new test is not a
behaviour test either: it pins the shape, so the next simplification has to
delete a test on purpose rather than quietly reintroduce the over-delete.

### Verified (2026-10-16, all exit 0)

| Gate | Result |
|---|---|
| `composer test` | **367 tests, 1581 assertions**, 1 skip — `ArticleCoverDeletionTest`, 7 tests / 24 assertions |
| `make admin-smoke` | **86 checks** (85 + the cover assertion), exit 0 |
| `make lint` / `phpstan` (L9) / `shellcheck` | clean / no errors / clean |
| `make lekalo-check` | 23 rules, 0 errors; lock digest unchanged, as measured — descriptions do not enter it |
| Negative control, old `delete()` restored | **3 of 7 fail** — the test is load-bearing |
| Negative control, reference scan disabled | the borrowed-cover case fails — the scan is load-bearing |

## What this round did not close

- **The model still has no adapter.** Boot 36 measured what one would cost —
  ~700 KB of PHP against a protocol upstream calls experimental — and
  recommended against building it. Boot 34 made one description true by
  changing the code; the other twenty-odd stay verified for well-formedness
  only. That is a stated cost, not an oversight.
- **Griffe's hero is still correct and green, not chosen** (Boot 33).

---

## Boot 35 — the queued reset link, measured instead of parked

Boot 32 recorded this as *"the queued reset link stays an operator decision …
the options remain 'accept and document', 'redact sensitive payloads in the
panel', or 'don't'"*, and two boots carried it forward unchanged. A decision
nobody can act on is an open item that has stopped looking, so this round
measured what queuing it would actually cost.

### Where the token lives, today

| | retention | plaintext? |
|---|---|---|
| `password_reset_tokens.token` | 1 h TTL | **no** — `hash('sha256', $token)` |
| the recipient's mailbox | forever | yes, by design |
| `storage/logs/app.log` | — | **no**; this was audit finding #1, and the mailers log the address and the endpoint, never the token |
| `jobs.payload` | until success or attempts exhausted | would be **yes** |
| `failed_jobs.payload` | **until an admin clicks "Drop this job"** | would be **yes** |
| `/mark/queue/{id}` | rendered verbatim, pretty-printed | would be visible to every admin |
| `/mark/queue` (the list) | payload not among the columns | not exposed |

The invariant the audit established is therefore: **the plaintext token exists
only in transit to the recipient.** Queuing the reset link breaks it in three
places at once, and the third has no bound — `failed_jobs` is only ever emptied
by `DatabaseQueue::forget()`, an explicit admin action. There is no reaper, no
TTL and no retention policy; measured, not assumed.

### The handler cannot look it up instead

Queuing the *token row* instead of the token does not work either, and this is
deliberate rather than an oversight: `password_reset_tokens` stores only the
SHA-256, so a handler running an hour later cannot reconstruct the plaintext
that belongs in the link. Any design that retries delivery has to carry the
secret across the delay.

### And the cost is not only risk — the gain is smaller than it looks

`HttpPasswordResetMailer::sendResetLink()` sends synchronously, **catches
`\Throwable`, logs, and returns**. A non-2xx is logged, not raised. So a
transient mail failure today means the user sees the usual "if that was not
you, ignore this" response and simply never receives the mail — which is the
real argument for queuing, and it is a genuine one.

### What this is, then

Not an open question with three options. A measured conflict between a
guarantee an operator can buy (retry on transient failure) and an invariant
the audit already established (the plaintext token is not at rest anywhere).
Whether to relax the second is a security posture call, and it is the
operator's — but it should be made knowing that `failed_jobs` has no retention
and that the panel renders payloads verbatim.

**Not done here, deliberately:** relaxing the invariant, encrypting queue
payloads, adding panel redaction, and adding a `failed_jobs` retention sweep.
The last one is worth doing on its own merits — payloads accumulate forever
regardless of what is in them — and is a change to a queue that currently has
one producer, so it should be a deliberate round rather than a rider here.

**Decided 2026-10-16: the invariant stands.** The reset link is not queued. What
is lost is named rather than hidden: a transient mail failure means the user
sees the ordinary "if that was not you, ignore this" response and the mail never
arrives. That is the honest cost, and it is the smaller of the two.

---

## Boot 36 — what a Lekalo adapter would actually cost

`lekalo doctor` reports two **blocked** checks, both `nextAction:
resolve-adapters`: `adapters.inventory` (`targets-without-adapters`) and
`capabilities.profiles` (`targets-without-profile`). The target is declared —
`lekalo/targets/php-nativa.yaml` — and has no adapter. Boot 32 recorded the
consequence and Boot 34 hit it in the flesh. This round measured the fix
instead of estimating it.

### The API exists, and it is published

`lekalo --help` carries `adapter`, `scan`, `bindings`, `observe`, `contract`,
`generate`, `verify`. An adapter is **a spawnable program**, not a library —
`lekalo adapter test` and `lekalo scan` both take `[PROGRAM_ARGS]...` and pass
them through with no shell.

`lekalo adapter install` sounds like a package registry, but
`lekalo adapter discover --help` states that **`release` and `registry` records
do not exist in v1** — only `path:` and `exec:`. So an adapter is not something
Lekalo ships; it is something a project builds. The protocol is documented at
`ichinya/lekalo` → `docs/target-protocol.md`.

### A reference PHP adapter exists — and it is not a starting point

`adapters/php-laravel`, and it sizes the work exactly:

| | |
|---|---|
| source | **20 files, 644 KB**, `kernel.php` alone 162 KB |
| tests | **56 KB** (protocol, analyzer, process) |
| built artefact | `adapter.php`, **661 KB**, via `build.php` + Mago |
| operations | **10** — `bind`, `clean`, `describe`, `generate`, `lint`, `plan-clean`, `plan-native`, `scan`, `validate`, `verify` |
| named capabilities | **14** — incl. `generate.openapi`, `generate.ui`, `generate.zod`, `generate.routes`, `verify.scenarios`, `verify.transport-http` |
| conformance | protocol `0.3.2`/`0.6.4`, IR `0.2.16` |

Two of those named capabilities are Vue and Zod. **`php-laravel` is a
Laravel-plus-Vue adapter** — it maps Eloquent models, Laravel routes,
migrations and generated screens. This tree has none of those: plain PHP
services, markdown content files, one SQLite per module, and a runtime with no
Composer dependency at all. So `php-nativa` is not an adaptation of the Laravel
adapter; it is a **second PHP adapter written from scratch against the same
protocol**, and it would have to stay conformant as the protocol versions move.

The battery does test only the *declared* surface — which is the one piece of
good news: a minimal adapter claiming `describe`, `bind` and `validate` faces a
much smaller battery than the 24 checks this binary names.

### And upstream calls the path we would plug into experimental

The project's own README: *"**Experimental** brownfield scan integration and
partial target capabilities have explicit limits."* Binding existing code is the
brownfield path. Building ~700 KB of PHP to plug into an integration upstream
labels experimental is a bet on a moving protocol.

### The recommendation, and what it costs to ignore

**Do not build it now.** The cheaper route is the one Boot 34 already took:
make the code true, so a model's falsehood is visible in review rather than
caught by a gate. `lekalo validate` keeps the 23 structural rules; what remains
unchecked is the ~20 descriptions, and the one that was *demonstrably* wrong is
now correct because the code changed.

What that leaves, stated plainly:

- the model remains documentation with a schema, and `docs/modules.md` says so;
- a description can still drift from the code without anything failing;
- the Makefile comment — *"Re-add the strict line once an adapter exists"* —
  stays accurate, and nothing waits on it.

**This is a cost, not a free choice.** If the tree's value ever comes to
depending on the model being enforced rather than reviewed, the adapter stops
being optional and this round's recommendation is wrong.
