# Kernel contracts > the shape that can shrink but not silently grow (2026-10-15)

`src/Modules/Core` + `src/Modules/Database` became a **contract kernel plus a
minimal runtime**: pure interfaces and their discovery attributes as the surface
modules build against, a small allow-listed runtime behind it, and nothing in
the kernel the kernel itself does not use. Size is a consequence, not the
target — what replaced the old "573 KB" number is a shape with three parts: a
baseline a script reproduces, an inventory that classifies every file, and an
allow-list a test fails to grow. Plan: `.ai-factory/plans/feature-kernel-contracts.md`;
inventory: `docs/kernel-shape.md`.

| | Baseline | After | Movement |
|---|---:|---:|---:|
| `Core` + `Database`, `.php` | 338 192 B / 79 files | **301 231 B / 71 files** | −36 961 B / −8 files |
| Core concrete classes | 67 | **59** | −8 |
| Core interfaces | 10 | 10 | — (published FQCNs never moved) |

## 1 > Measure before touching anything

`bin/build/kernel-size.sh` + `make kernel-size` print the per-layer bytes, the
file counts and the interface/concrete split on demand, with `baseline:
338192 B / 79 files` as a footer so any run diffs against the plan in one line.
`docs/kernel-shape.md` carries one row per Core file — bytes, layer, type,
importers, bucket (`contract` / `runtime` / `tooling` / `dead`) — and the bucket
column is what the shape guard reads as the approval record.

## 2 > The guard that did not exist here

`KernelCouplingTest` + `Tests\Support\KernelCoupling` were **upstream-only**;
`find`/`grep` found zero hits in this tree, so "no kernel file imports a module"
was untested. Ported (not copied): the ledger starts **empty**, because this
tree's target state is zero edges — so the first run failed on the two real ones,
which is the point (a guard never seen failing has never been seen).

Closed in `RateLimitMiddleware`: the two `use Modules\Outbox\…` imports became
fully-qualified references at the call site behind the existing `class_exists`
guard — the security event still records with Outbox installed, the request is
still answered without it, and the file's own docblock ("No `use` statement
names the module") is true again. `KERNEL_MODULES` now also carries `Tooling`,
which both treats tooling → Core as kernel self-talk and makes the scanner walk
the one directory added after it was written.

## 3 > What the kernel never calls

**11 793 B left outright**, each deletion re-proven by grep rather than inferred:

| File | B | Why |
|---|---:|---|
| `Application/Services/ServiceContainer.php` | 2 247 | referenced only by `EventDispatcher`, which referenced only it |
| `Application/Events/EventDispatcher.php` | 1 069 | see above |
| `Application/Services/AuthProviderRegistry.php` | 5 366 | named only by a docblock and by prose in this file |
| `Interfaces/CLI/Command/MigrateCommand.php` | 921 | `bin/cms migrate` runs `DatabaseMigrator` itself; no `Console\Application` exists |
| `Interfaces/CLI/Command/ServeCommand.php` | 2 190 | no `serve` branch anywhere; symfony/console is a **dev** dependency |

`Router::mayDeclareRoute()` stays: `modules/Blog` and `modules/Outbox` still
ship route-less symfony commands, and `$actionDirs` is all of `src/Modules`.
Its comment (and this file's Boot 19 prose, and `ci.yml`) now cite those as the
live reason rather than the kernel's own, which are deleted.

## 4 > The tooling leaves `Modules\Core`

**26 167 B relocated**, not deleted: `ModuleCatalog`, `ModuleInstaller` and
`ModuleManifest` now live in `src/Modules/Tooling/` under `Modules\Tooling`.
They *stay in the exported slice* — `bin/cms` is a `SLICE_FILES` member, so its
classes must travel with it — which is why the move clears 26 KB from the module
while the slice total keeps them.

Everything that named them by their old home followed: `.gitignore` (a third
`!src/Modules/Tooling` negation, or the move is invisible to git), `bin/cms`'s
three of five kernel imports, the three tests that import `ModuleInstaller`,
`phpstan.neon` `paths:` (level 9 would otherwise silently drop 26 KB),
`SLICE_FILES` + an `OWNED src/Modules/Tooling` divergence row, and
`KERNEL_MODULES` (so `module:list` cannot list it and `module:remove` cannot
delete it).

Measured:

```
$ php bin/cms module:list
Installed (19): Audit, Blog, Cardboard, Comments, Erik, Griffe, KernelTheme,
Locales, Media, Mono, Outbox, Pages, Questions, Queue, Scheduler, Security,
Settings, Users, Validation        ← no Tooling
$ php bin/cms module:remove Tooling
cms: Module 'Tooling' is the kernel itself — it cannot be removed.   (exit 1)
```

`Router::scanDirectory()` still walks `src/Modules` and now sees the directory:
harmless, it carries no `#[Route]`, and `mayDeclareRoute()` skips what cannot
declare one.

## 5 > Locking the shape

`tests/Architecture/KernelShapeTest.php` — two rules, both proven failing on a
real violation before being trusted:

1. every `.php` under `src/Modules/Core` is an `interface`, `enum` or `trait`, or
   a path on `ALLOW_LIST` **that also has its row in `docs/kernel-shape.md`**;
   unapproved, unreadable, declaration-less and stale rows are all reported with
   path + layer + where to get approval.
2. `count(ALLOW_LIST) <= 59` — the ceiling is a separate assertion so raising it
   is its own red line in review, not a line that hides inside an approval
   commit.

Both were driven to failure on purpose: a probe class under
`Application/Services/` reported `UNAPPROVED … (layer Application)`, and deleting
the `AssetManager` inventory row reported `UNINVENTORIED`. Probes removed.

## Verified (2026-10-15, all exit 0)

| Gate | Result |
|---|---|
| `composer test` | 264 tests, 979 assertions, 1 skip |
| `make lint` | clean |
| `composer phpstan` | level 9, no errors (incl. `src/Modules/Tooling`) |
| `composer cs-fix-dry` / `composer rector-dry` | 0 fixable / Rector OK |
| `make lekalo-check` | 23 rules, 0 errors |
| `make extract-check` | OK — 224 identical, 64 recorded divergences |
| `make kernel-size` | 301 231 B / 71 files, delta −36 961 B |

CI's `Extract-check` job is the one gate that cannot run anywhere but here: the
upstream repo is private (Boot 20).

## 6 > The gate nobody wired (2026-10-15, `fix/mark-access-control`)

The port existed, the middleware existed, the policy table existed — and
nothing joined them, because **`Kernel::addMiddleware()` had no callers**.
Measured before any change, with `php -S` and a request carrying no cookies:

| Path | Before | After |
|---|---|---|
| anon `GET /mark` | **200** — full dashboard | **302 → `/login`**, 0 B |
| anon `GET /mark/users` | **200** — 324 KB user table, e-mails, delete forms, live CSRF token | **302 → `/login`**, 0 B |
| `POST /login` (form + CSRF) | — | 302 → `/mark` |
| auth (`mark.access`) `GET /mark` | 200 | **200** (34 649 B) |
| auth (no permission) `GET /mark` | 200 | **403** — `Forbidden — the mark.access permission is required.` |
| auth `GET /login` | 200 | **302 → `/mark`** (guest-only) |
| anon `GET /` | 200 | 200 — public surface untouched |

Static causes, each verified rather than assumed: `addMiddleware()` had zero
callers (`public/index.php:26` said so in a comment); `AuthMiddleware` had
zero importers and its constructor requires an `AuthProvider` that **no class
in the tree implemented**; only `CsrfMiddleware` and `SecurityHeadersMiddleware`
carried `#[AsMiddleware]`, so the live pipeline was headers + CSRF — the
kernel docblock's `headers → session → CSRF → rate limiting → auth → dispatch`
was aspirational; `RateLimitMiddleware` also had zero importers (the audit's
Medium finding).

What shipped, in order (commits `f4e6899`, `a72387a`, `d089a48`, `3542236`):

1. **`modules/Users/Domain/UsersAuthProvider.php`** — the port's first
   implementation: identity from the module's own session, path questions
   delegated to `AdminRoutePolicy` (no `/mark` literal duplicated),
   `noteEvent()` through an inline `class_exists` string so Users' install
   closure never names Outbox, marked `#[AsAuthProvider]` for the deferred
   discovery. **`AdminRoutePolicyCoverageTest`** walks the router scan and
   pins all **77 registered `/mark` routes** to a permission (floor of 50 so
   the walk cannot go quietly empty), plus the inverse for the public set.
2. **The red run came before the wiring.** `AdminAccessControlTest` was
   written first and failed **4 of 5** with `got 200, expected 302/403` —
   the evidence the assertions can see the hole at all — then went green
   when the wiring landed. The test drives the real login→`establishSession`
   chain against a temp `DB_USERS` built by the module's own migrator.
3. **Wiring: one address, `AuthWiring::wire()`.** *(Superseded by §10: the
   helper is deleted and the same two lines are discovered from the provider's
   own attribute — the shared address is now `AuthProviderRegistry::first()`.)*
   The composition root calls
   it behind one `class_exists` guard; the kernel-level tests call the same
   method, so the pipeline the suite proves and the pipeline production runs
   cannot drift apart (the delegated decision — chosen over inlining in
   `index.php`, which would have meant three copies). Rate limiting wires
   inline just above it, added first so the order is headers → CSRF → rate →
   auth → dispatch; each block prints exactly one line (`[rate] …` /
   `[auth] …`), always one, so a deployment can tell "not installed" from
   "broken". **Attribute discovery of `#[AsAuthProvider]` is explicitly
   deferred**: `MiddlewareRegistry` constructs with `new $class()` and no
   arguments, and a construct-with-dependencies mechanism in the kernel is
   not justified by one implementor.
4. **The role bypass moved into the data.** `User::hasPermission()`
   short-circuits on `admin`, but `AuthIdentity::hasPermission()` is a plain
   `in_array` — mapping grants straight across would 403 the very
   administrator the policy admits. The provider expands an `admin` role
   into all five `Permissions::*`, so every denial stays explainable from
   the identity alone. Pinned by `UsersAuthProviderTest` against a user
   whose `role_permissions` rows are empty.
5. **`KernelOnlyBootTest` flipped gate-first**: provider installed → 302
   (+ `Location: /login`), router without provider → 200, neither → 404;
   kernel-only CI keeps asserting 404 because the guard fails there.
6. **The 429 test found a 500.** `RateLimitMiddlewareTest` (real limiter,
   in-memory SQLite: 60 pass, 61st refused, loopback exempt past 65) hit
   `blockedResponse()`'s type-hint of the kernel `RateLimiter` contract —
   while the factory is duck-typed by design — so the first request ever
   refused through Security's structurally-valid limiter would have
   fatalled: **500 instead of 429**. The caller now resolves
   `getRetryAfter()` where the `@var` narrowing holds and passes the int;
   every 429 branch carries `Retry-After` (the bare path sent none).

## Verified (2026-10-15, `/mark` access control, all exit 0)

| Gate | Result |
|---|---|
| `composer test` | 284 tests, 1184 assertions, 1 skip |
| `composer test` (kernel-only worktree) | 284 tests, 224 skipped, 0 failures |
| `make lint` | clean |
| `composer phpstan` (full tree) | level 9, no errors |
| `composer phpstan` (kernel-only worktree) | level 9, no errors — Constraint 5 proved, and the run that caught the factory's closure return type |
| `composer cs-fix-dry` / `composer rector-dry` | 0 of 91 fixable / Rector OK |
| `make lekalo-check` | 23 rules, 0 errors |
| `make extract-check` | 224 identical, 64 recorded divergences |
| `make kernel-size` | 302 089 B / 71 files, delta −36 103 B |
| live `curl` (php -S, port 8177) | the matrix above; probe users deleted afterwards, servers: mine stopped, pre-existing dev server untouched |

Still deferred: CI's `Extract-check` remains not-run — private upstream
(Boot 20). No longer deferred: `#[AsAuthProvider]` attribute discovery (see
§6.3) **shipped in §10**, with `AuthWiring` deleted.


---

