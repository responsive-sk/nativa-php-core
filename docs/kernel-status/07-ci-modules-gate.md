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
- **The Queue module still has no producer.** The gate can only push through the
  module's own API because nothing in this tree enqueues a job (`push()` is
  called by tests and by nothing else). When a real producer lands — mail,
  notifications — the push step in the gate becomes a real one and this note is
  deleted rather than reworded.
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
