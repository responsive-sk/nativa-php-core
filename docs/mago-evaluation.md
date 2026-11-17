# Mago in `nativa-php-core` > evaluation

**Question:** can this tree use [Mago](https://github.com/carthage-software/mago) —
the Rust PHP toolchain — and for what?

**Answer, short version:** it runs here, on PHP 8.5, and it is fast. It is **not** a
drop-in replacement for `phpstan` level 9 or `.php-cs-fixer.dist.php`: on this tree
the analyzer's 189 errors are 187 parts configuration, operator state and
inference noise to 2 real findings, and the formatter would rewrite every file in
scope while losing rules the project holds on purpose. The one tool that adds
something the repo cannot express today is **`mago guard`** — it states the
kernel's dependency rule declaratively and sees couplings the PHPUnit guard
cannot, because it resolves inline FQCNs.

**Status:** one rule is a gate. `make mago-guard` runs in CI on every pull
request — the kernel→module perimeter below, with **no baseline and no
exception list**. The analyzer, linter and formatter stay informational:
`phpstan`, `php-cs-fixer` and `rector` remain the analysis and style gates.
Plan: `.ai-factory/plans/feature-evaluate-mago-toolchain.md`.

Measured 2026-10-22 with `mago 1.58.2` on PHP 8.5.11, over `mago.toml`'s scope
(123 `.php` files + `bin/cms`, the same nine paths `phpstan.neon` analyses).
The scope grew on 2026-10-27 — the table below is the kernel-scope measurement;
[Scope extension](#scope-extension-2026-10-27) records what changed.

## Verdict at a glance

| tool | measured on this tree | verdict |
|---|---|---|
| `mago analyze` | 189 errors / 96 warnings / 87 help in ~0,19 s | **not a replacement** — 2 real findings, the rest is config and inference |
| `mago lint` | 570 findings at the default preset, **0** with the curated rule set | **gate** — `make mago-lint` fails on any finding; all 22 resolved, no rule silenced |
| `mago format` | 124 of 124 files would change (~0,03 s) | **not a replacement** — house rules it cannot express |
| `mago guard` | 0 issues after the `SecurityEventSink` refactor; catches a planted kernel→module import | **gate** — CI job `kernel-dependency`, empty report is the claim |

Reproduce any line with:

```
make mago-spike                     # all four tools, always exit 0
make mago-spike MAGO_ARGS=--strict  # exit non-zero when a tool does
make mago-guard                     # the gate: guard only, --strict
make mago-modules                   # the catalogue alone, always exit 0
bin/build/mago-spike.sh --only=analyze
```

Reports land in `storage/cache/mago-spike/` (gitignored): `<tool>.txt` and
`summary.tsv`.

### Scope extension, 2026-10-27

`mago.toml`'s `[source] paths` now also name `modules/`: 360 `.php` files of
committed catalogue, the module code `bin/cms module:add` copies into an
install. It is shipped code, and `phpstan.neon` deliberately leaves it out (its
`paths` comment says why), so lint and analyze are the only two tools that read
it. Same binary, same PHP:

| tool | kernel scope (2026-10-22) | + `modules/` (2026-10-27) |
|---|---|---|
| `mago lint` | 0 findings (22 resolved) | **0 findings** — `make mago-lint` stays green over the wider scope |
| `mago analyze` | 367 issues / 184 errors | 1191 issues / 463 errors |
| `mago format` | 124 of 124 files would change | unchanged — `[formatter] excludes` keeps the catalogue out |
| `mago guard` | 0 issues | unchanged — the perimeter constrains the kernel, so `[guard] excludes` keeps the catalogue out |

The analyzer delta is signal, not a configuration artifact: the same classes the
kernel already reports show up in the catalogue — `prepare()` returning
`PDOStatement|false` and called through with `->execute()`
(`invalid-method-access`), and `APP_BASE_PATH` undefined in module tests that do
not bootstrap `src/init.php` (`non-existent-constant`). The first of those was
fixed the same day — 313 findings to 0, see
[the `prepare()` class](#the-prepare-class-313-to-0). The rest stay unfixed for the
reason the catalogue exists: it is re-imported on every module update, so a local
fix becomes a merge conflict. `phpstan` remains the analysis gate; the analyzer
count is a reading, and a bigger reading is the point of widening it.

The linter is the one place the extension had to be qualified: `modules/Themes/`
is a byte-verified mirror of `nativa-themes` (`bin/build/sync-themes.sh --check`
fails on a changed byte) whose 5 `readable-literal` findings can only be fixed
upstream, so `[linter] excludes` names that one directory. Everything else under
`modules/` is lint-covered with no exception.

### The catalogue's own reading

A number for the catalogue alone is a different question from a number for the
tree, so it has its own entry point: `make mago-modules`, i.e.
`bin/build/mago-spike.sh --scope=modules`. It passes `modules` as a positional
path — Mago then uses that *instead of* `[source] paths` — runs lint and analyze
only, writes `storage/cache/mago-spike/modules/`, and exits 0 whatever it finds.

The point is that it cannot become a gate by accident: `--max-issues` is refused
with it (a ceiling is a gate, and the gates over this tree are already
`mago-lint` and `mago-guard`), and `--strict` only warns that it is ignored. It
is also not pushed through the same scope: the run is `lint` + `analyze`, because
`format` is excluded from the catalogue by `mago.toml` and guard's perimeter is a
statement about the kernel, not about what sits on the other side of it.

Measured 2026-10-27: **0 lint findings, 884 analyzer issues (348 errors)**.
The full-scope run above reports the same 884 at `modules/` locations, so this
reading measures the catalogue itself rather than the distance between two
scopes.

CI publishes the reading on every push and pull request: the `module-quality`
job runs `bin/build/mago-modules-report.sh`, which executes the same scope and
renders its `summary.tsv` into the run summary — the analyzer's own breakdown
line included, quoted rather than recomputed. That job carries
`continue-on-error`, and the script exits 0 on every path ("no verified binary"
is reported as such, not as zero findings), so the count is published and
nothing is enforced. A job that could redden a pull request would be the gate
this section says it is not.

### The `prepare()` class, 313 to 0

Anchored at 1384 issues / 802 errors, the catalogue's largest single class was
`invalid-method-access`: 313 findings, every one of them the same defect read
aloud. `PDO::prepare()` and `PDO::query()` are declared as returning
`PDOStatement|false`, so `$stmt = $pdo->prepare(...); $stmt->execute();` is
typed as a method call on `false`. The connections here run with
`PDO::ERRMODE_EXCEPTION`, which makes the `false` branch unreachable at runtime —
but the type says otherwise, and the count said 313.

The fix is a checked `prepare()`/`query()` per module:
`Modules\<Name>\Infrastructure\Persistence\Statement`, with two static methods
that raise `RuntimeException` instead of returning `false`. Thirteen modules had
SQL call sites, so there are thirteen copies — deliberately, because the
catalogue's modules are independent slices that may not import each other, and a
single helper in `Modules\Database` would drag a new file into the kernel slice
that `extract-kernel --check` expects to match upstream.
`tests/Architecture/StatementHelperContractTest.php` holds the thirteen to one
shape: same bytes once the namespace line is normalised, the namespace derived
from the directory, both guards intact, and the module set a ratchet.

Every one of the 313 call sites now goes through it: 177 were `$this->pdo->…`,
43 a local `$pdo`, 12 a `DatabaseConnectionManager::instance()->connection(…)`
receiver, and the rest chained across lines. The helper also made 20 guards
dead — `false === $stmt ? [] : $stmt->fetchAll()`, `if (false === $stmt) {
return []; }` — which were removed in the same pass rather than left as code that
can no longer run.

Measured after: **`invalid-method-access` 313 → 0**, and `possibly-false-argument`
15 → 1 as a side effect. The catalogue reading above moved from 1384 issues /
802 errors to **1057 / 475**, and the whole-tree count from 1691 / 917 to
**1364 / 590**. `make mago-lint` stayed at 0 findings, `make lint` covered the new
files, and the module suite ran against an installed catalogue: 539 tests, 2266
assertions, 0 failures (800 tests / 6210 assertions for the whole suite). The
remaining findings are the classes this pass did not touch —
`mixed-assignment`, `mixed-argument`, `non-existent-constant` in module tests —
and they stay a reading.

### `mixed-assignment`, a partial cut: 291 to 245

`prepare()` was one defect wearing 313 hats. `mixed-assignment` is not: the 291
findings measured before this pass were assignments of `mixed`, and they come
from different places — 98 read `Request::body()` or `query()`, 79 an index into
an untyped array, 20 a `json_decode()`, and the rest a `foreach` over an
`array<string, mixed>` or a `get_object_vars()` of an object.

One of those had a single cause and a single fix. `body()` and `query()` return
`mixed`, so the idiom every module action used —

    is_string($request->body('name')) ? $request->body('name') : ''

— read the field twice, and the second read was still `mixed`: narrowing one call
says nothing about the next. `Request` now carries `queryString()` and
`bodyString()` beside the `header(): ?string` it already had, each reading once
and declaring the default for a value that is not a string; 71 call sites in 21
action classes use them.

Measured after: **`mixed-assignment` 291 → 245**, and `mixed-argument` 197 → 73
as a knock-on — a value typed `string` no longer arrives `mixed` at a typed
parameter. The catalogue reading moved from 1057 issues / 475 errors to
**884 / 348**, and the whole tree from 1364 / 590 to **1191 / 463**.
`make mago-lint` stayed at 0, the suite passed (539 module tests, 804 for the
whole tree), and `make extract-check` reports OK because `Request.php` is now a
recorded divergence in `bin/build/kernel-divergence.txt`.

The remaining 245 are the hard half. An index into `array<string, mixed>` is
`mixed` because the array was never typed — the fix is a shape on the producer
(`@return array{meta?: array{image?: string}}`), not a cast at the consumer, and
a wrong shape there is worse than the warning; `json_decode()` needs the same
decision once per site. That is a design pass through the module boundaries, not
a mechanical one, which is why it is left on the table rather than half-done.

### Which gates see the catalogue, and which see the kernel

Extending `mago.toml` gave the catalogue one gate (`make mago-lint`) and no
more. Every other gate in this tree was written for the kernel, and no single
config states the split, so it is stated once, here.

Static analysis and style:

| gate | kernel (`src/`, `tests/`, `bin/`, `public/`) | `modules/` | what it checks |
|---|---|---|---|
| `composer phpstan` (level 9) | yes | **no** | types, over the kernel slice — `phpstan.neon` says why the catalogue is out |
| `composer cs-fix-dry` | yes | **no** | style; restyling the catalogue makes the next re-import a merge |
| `composer rector-dry` | yes | **no** | language-upgrade rewrites, same scope and same reason |
| `make mago-lint` (ceiling 0) | yes | **yes**, minus `modules/Themes/` | the curated rule set, count gated |
| `make mago-guard` | yes | **no** | kernel→module edges; the catalogue is the other side of the rule |
| `make lint` (`php -l`) | yes | yes | parse errors over every tracked `.php` file outside `vendor/` — and only parse errors |
| `make themes-check` | — | `modules/Themes/` only | byte identity with `nativa-themes`, not quality |
| `make mago-modules` | — | yes, as a reading | informational: 0 findings, 884 analyzer issues, never fails |

Runtime, model and supply chain:

| gate | kernel | `modules/` | what it checks |
|---|---|---|---|
| `composer test` | `tests/` | `modules/*/tests/` | the `modules` suite skips a test whose module is not installed; CI's `modules` job installs the catalogue first, so there it runs |
| `make modules-install` | — | every entry | closure order, refuses a cycle, installs into `src/Modules/` |
| `make mark-smoke` | — | installed catalogue | boots the panel on temp DBs and walks the admin flows over HTTP |
| `make lekalo-check` | the model under `lekalo/` | per-module model | the MODEL, not the PHP: `adapters 0` means nothing compares the two |
| `make security-audit` | tracked files | tracked files | no tracked `.env`, no secret-shaped literal |
| `make dist-check` | build + smoke | **no** | a clean clone builds and boots; the catalogue ships only with `build-dist.sh --with-catalogue` |

One consequence follows, and it is not readable off any single config:

- **The catalogue is not held to the kernel's analysis standard.** `phpstan`
  level 9 stops at the kernel, and `mago analyze` has no ceiling — the
  catalogue's 348 analyzer errors are a reading. `make mago-lint` (zero
  findings, everything under `modules/` except the theme mirror) is the only
  *static-analysis* gate over it; behaviour is covered by the module tests CI
  installs the catalogue to run, and the theme mirror by `themes-check`'s byte
  compare. `make lint` reaches the catalogue too, but `php -l` only answers
  "does this parse".

The split is held by three files that have to move together: `phpstan.neon`
(the kernel paths), `mago.toml` (those paths plus `modules`, and the per-tool
exclusions above), and `tests/Architecture/MagoScopeContractTest.php`, which
fails when the first two disagree.

## Mago and Lekalo are different layers

The obvious question after the verdict is whether this replaces Lekalo. It does
not, and the two are not even reading the same thing: Lekalo reads a YAML MODEL
of the system, Mago reads the PHP SOURCE that implements it. The table is the
short version; the Lekalo side is documented in `docs/modules.md`.

| | Lekalo | Mago |
|---|---|---|
| what it is | a semantic model OF the system, written in `lekalo/` and locked by `lekalo.lock` | a PHP toolchain OVER the source: linter, analyzer, formatter, architectural guard |
| reads | entities, commands, effects, queries, events, policies, endpoints, scenarios | the PHP files in `mago.toml`'s scope (the paths `phpstan.neon` analyses, plus the `modules/` catalogue) |
| answers | "is the model well-formed and self-consistent?" — every `ref` resolves, grammar holds, ids are unique, CLI contract versions match | "what does the code do that a written rule forbids?" — kernel→module edges, 570 lint findings at the default preset, formatting |
| blind spot | the model↔code gap: `lekalo.lock` reports `adapters 0`, no PHP adapter, so a stale or even FALSE description still validates | intent: a name heuristic cannot tell a CSRF fixture from a password — 2 curated `@mago-expect` sites, reviewed |
| the gate here | `make lekalo-check` = lock check + whole model + every module separately; CI job `Lint + PHPStan + Lekalo model` | `make mago-guard` (empty report) and `make mago-lint` (zero findings); CI jobs `kernel-dependency` and `curated-lint` |
| pin | CLI `v0.6.5`, sha256-verified archive | `mago 1.58.2`, sha256-verified archive |
| what closes its gap | PHPUnit contract tests: `ModelPayloadContractTest`, `ModelReferenceContractTest`, `SecurityEventPayloadContractTest` | `KernelCouplingTest` states the same perimeter rule in PHP, and the count gates refuse the next finding |
| in `dist/` | `lekalo/` and `lekalo.lock` are excluded (model of the build, not the build) | `mago.toml` is excluded (how the tree is checked, not how it runs) |

Neither is a check on the other: a model can validate while the code drifts away
from it, and the code can pass every rule while the model describes a system
that does not exist. The contract tests in the last row are what bind the two,
and they are PHPUnit tests rather than either tool's feature.

## How the binary is pinned

`bin/build/mago-spike.sh` downloads `mago-1.58.2-x86_64-unknown-linux-gnu.tar.gz`,
verifies the release archive's `sha256` against a constant in the script, and
caches binary and archive under `storage/cache/mago-spike/`, so the second run is
offline. Same pattern as the `lekalo` step in `.github/workflows/ci.yml`. The
alternative — `composer require --dev carthage-software/mago` — is a thin wrapper
that downloads the binary on first call and needs `GITHUB_TOKEN` on shared CI
runners to avoid the anonymous rate limit; it also adds a dependency to the lock
file the kernel runtime deliberately does not need.

## Analyzer

Same scope, same code, three configurations:

| configuration | errors | issues |
|---|---|---|
| `includes = ["vendor"]` (committed) | **189** | 372 |
| `+ "src/init.php"` | **184** | 367 |
| `+ "src"` (whole tree) | 107 | 285 |

- **Adding `src/init.php` does not fix the biggest bucket, it renames it.**
  `APP_BASE_PATH` is declared with `define()` (`src/init.php:68`); including that
  file makes the constant exist and removes all 53 `non-existent-constant`
  errors, but Mago infers no type from `define()`, so the same 53 sites become
  `mixed-operand` errors. Net: 189 → 184. PHPStan solves this through
  `bootstrapFiles` and constant inference; the 1.58.2 schema has no
  `globals`/`constants` option and `patches` can only refine symbols that
  already exist.
- **Adding `src` is not a configuration this repo may use.** It resolves the 31
  `non-existent-class*` errors only because this workstation has sixteen feature
  modules installed — `phpstan.neon`'s comment explains why that makes a verdict
  depend on the checkout. On a clean clone the errors return.

The 189 errors, by cause:

| cause | count | examples |
|---|---|---|
| `APP_BASE_PATH` (bootstrap) | 53 | every test file |
| optional module not in scope | 54 | `non-existent-method` on `Modules\Outbox\…` in `bin/cms`, `impossible-assignment` on module commands |
| inference / stub limits | 78 | `ReflectionClass::newInstanceArgs()` called invalid (it is not), `assertSame(2, $scans)` read as impossible, `extract()` argument shape |
| known, mirrors `phpstan.neon`'s ignore | 1 | `NavBadgeRegistry::scanFile($file, $found)` |
| by design | 1 | `new $class()` in `MiddlewareRegistry` |
| **real finding** | **2** | `tests/Theme/ThemeClassOwnershipTest.php:553` — `$emitted[$class] = $where` on an uninitialised `$emitted` |

The two real findings are one bug: PHP auto-vivifies `$emitted` into an array on
first assignment, so the test passes and PHPStan level 9 is silent. Mago is the
only tool that names it. That is exactly the "signal PHPStan does not have" this
evaluation was looking for — and it is **two errors out of 189**.

For a gate the remaining 187 would need a baseline file or a wide ignore list,
which is the anti-pattern the plan's decision criteria forbid: a baseline that
grows hides the next finding.

## Linter

The default preset reports **570** findings and is not usable as-is: 241 of them
are `literal-named-argument` (pass literals as named arguments — a call-style
refactor of ~240 sites), 51 are `no-error-control-operator` (the tree uses `@`
on purpose for best-effort filesystem calls, with `@mkdir()`'s return value
checked), 36 are `cyclomatic-complexity` at threshold 15.

`mago.toml` therefore disables 18 rules — each with its measured count and
reason in the file — and keeps correctness, security and readability rules on.
Result: **0 findings**, all 22 resolved on 2026-10-22 — none by silencing a
rule. Sixteen were code fixes:

- `explicit-octal` (5): implicit `0755`/`0775`/`0002` literals now say
  `0o755`/`0o775`/`0o002` — in the kernel, `config/session.php` and the
  `Blog`/`Pages` slices, so the whole tree spells a mode one way;
- `assert-description` (1): the `assert()` in `DatabaseMigrator` carries the
  reason it cannot fire — the `RuntimeException` above it already refuses the
  same class;
- `no-empty-catch-clause` (3): the two in `ModuleCatalogueShapeTest` capture the
  refusal and assert on it instead of swallowing it, and
  `DatabaseConnectionManager`'s WAL pragma logs the failure it used to hide;
- `no-ini-set` (5): `src/http.php`'s three bootstrap values and the two
  `error_log` probes in `AuthProviderRegistryTest` configure PHP before any
  framework exists, so each site carries `@mago-expect lint:no-ini-set` with the
  reason. The rule stays on, and `@mago-expect` turns into an
  `unfulfilled-expect` warning if the site ever goes away;
- `sensitive-parameter` (1): `CsrfMiddleware::verify()`'s `$token` now carries
  `#[\SensitiveParameter]`, so a stack trace from `hash_equals()` cannot print
  it — the finding was a real gap, not a false positive;
- `no-unsafe-finally` (1): `DiscoveryCacheTest` recorded the cleanup failure and
  rethrows it after the `finally`, so a throw there can no longer replace the
  assertion it was cleaning up after.

The last six were false positives of two SECURITY rules, and both rules stay
enabled — that is the part that matters, because a security rule switched off
because this tree trips it is the one suppression worth refusing:

- `no-insecure-comparison` (4): the heuristic reads `$token` as a credential,
  but in `tests/Support/KernelCoupling.php` and
  `tests/Architecture/NameOwnershipContractTest.php` it was an element of a
  `token_get_all()` stream — a token ARRAY or a one-character string like `{`,
  `?`, `|`. The variable is now `$element` with the distinction written next to
  it, which is the accurate name and needs no pragma at all;
- `no-literal-password` (2): `CsrfMiddlewareTest` really does assert on a
  literal CSRF token, because the middleware hashes a fixture against the
  session value — the literal IS the assertion. Both sites carry
  `@mago-expect lint:no-literal-password` and the reason, so the rule stays on
  and an `unfulfilled-expect` warning appears if the fixture ever moves.

### The empty set is what CI gates

`make mago-lint` runs the linter with `--max-issues=0`, and the CI job
`curated-lint` runs exactly that target on the same binary, checksum and cache
key as the guard job, with no `vendor/`. `mago lint` on its own would not be a
usable verdict (it exits 1 while anything remains), and a fixed ceiling would
let the next finding hide under it — zero is the only number this tree can
claim, and a single finding fails the job. A pragma that outlives its code
counts as a finding too, through `unfulfilled-expect`, so the suppressions
cannot rot either.

## Formatter

**124 of 124 files in scope would be rewritten** — the formatter is a different
style, not a faster `php-cs-fixer`. What it cannot express is the deciding part:

| `.php-cs-fixer.dist.php` rule | Mago formatter equivalent |
|---|---|
| `declare_strict_types` (adds the declaration) | **no** — not a formatter concern, absent from the schema |
| `strict_comparison` (`==` → `===`) | **no** — absent |
| `fully_qualified_strict_types.import_symbols => false` | **no** — and this one is load-bearing: `true` would re-add the `Modules\Outbox` imports into `RateLimitMiddleware` and turn `KernelCouplingTest` red |
| `phpdoc_align` (vertical alignment) | **no** — the formatter does not touch docblock contents |
| `blank_line_before_statement` (`return`, `throw`, `try`, `yield`, `break`) | **partly** — only `empty-line-before-return` |
| `declare_equal_normalize` (`declare(strict_types = 1)`) | **yes, configured** — `space-around-assignment-in-declare`, default `false` |
| `concat_space`, `single_quote`, `trailing_comma_in_multiline` | **yes** — `space-around-concatenation-binary-operator`, `single-quote`, `trailing-comma` |

Measured shape of the diff: **28 files differ only** by
`declare(strict_types = 1)` → `declare(strict_types=1)`; the other 96 differ more,
mostly empty constructor bodies collapsed to `) {}`
(`inline-empty-constructor-braces` defaults to true) and argument lists
re-wrapped. A formatter swap is therefore a whole-tree reformat **plus** a
decision to drop rules — a separate plan with its own diff, not a tooling
switch.

## Guard

`mago guard` is the only tool whose model can state the kernel rule the repo
already defends: *a file under the kernel never names a module*. The model is a
namespace whitelist — the moment one rule exists, every namespace without a rule
is a violation (measured: `glob()` in a test read as `Tests\Architecture\glob`,
PHPUnit classes as illegal dependencies) — so `mago.toml` pins guard's scope with
`excludes` and gives each kernel namespace its permits plus `@global`.

Evidence that this is a guard and not a green light:

1. Planted `use Modules\Blog\Interfaces\HTTP\BlogAuthProvider;` in
   `src/Modules/Core/Application/Services/AuthProviderRegistry.php`.
2. `mago guard` → `error[disallowed-use]` naming
   `AuthProviderRegistry.php:7` and the dependency.
3. `composer test -- --filter KernelCouplingTest` → `NEW EDGE
   src/Modules/Core/Application/Services/AuthProviderRegistry.php:7 imports Blog …`.
4. Reverted; both green again.

Both catch the same mutation with the same file and line. The difference is
measurable, not aesthetic:

- **Guard is stricter.** On the untouched tree, before the refactor below, it
  reported **8 errors**, all in `AuthMiddleware.php` and `RateLimitMiddleware.php`
  — the two places where the kernel reached `Modules\Outbox\…` through inline
  FQCNs behind `class_exists()`. `KernelCouplingTest`'s ledger was empty
  precisely because those are not `use` statements; guard resolves them anyway.
  That is the whole argument for having it.
- **Guard does not replace the test.** `KernelCouplingTest` also classifies
  edges (M/C/G) and ratchets unresolved ones; guard has no such ledger. Guard
  states the rule, the test records the decisions.

### As a gate, with no baseline

`make mago-guard` runs `mago guard` with `--strict`, and the `kernel-dependency`
CI job runs that target with no PHP setup and no `composer install` — the
checkout that proves the gate is also the checkout with no `vendor/`. There is no
baseline file: the 8 findings were fixed rather than listed.

**What the 8 became.** A kernel-owned port, `Modules\Core\Domain\Contracts\SecurityEventSink`,
with one method per fact the kernel raises (`authorizationDenied`,
`rateLimitTripped`) and primitives crossing it — the shape `RateLimiter` already
uses. The Outbox module implements it (`OutboxSecurityEventSink`), and that class
is now where the event type and the payload keys live, next to
`SecurityEvents::payloadKeys()`. `src/http.php` — a composition root, the one
place allowed to name modules — constructs the adapter behind `class_exists()`
and passes it to both middlewares; without the module the sink is null and the
record is dropped, never the refusal.

What that changed, measured:

- guard: **8 errors → 0**, with no baseline and no ignore list;
- `KernelCouplingTest`'s ledger is still empty — the kernel adds no `use Modules\…`
  line, it adds a contract the module depends on (the normal direction);
- the payload vocabulary still has one owner: `SecurityEventPayloadContractTest`
  reads `OutboxSecurityEventSink` instead of the two middleware files;
- the kernel no longer turns a 403/429 into a 500 if the recorder fails: both
  middlewares isolate the port call, and `RateLimitMiddlewareTest` hands in a
  throwing sink to prove it.

Gate behaviour after the refactor — planted `use Modules\Blog\…` in
`AuthProviderRegistry.php` → exit 1 with one issue naming file and line;
reverted → exit 0 with `issues=0`, no `baselined=` field, because no baseline
exists to filter anything.

### A baseline that comes back cannot rot

An empty report is the claim today, but the gate also has to handle the day
someone re-introduces a ledger: an entry that no longer matches an issue is a
fixed problem the baseline still declares open, and mago only WARNS about that.
So `bin/build/mago-spike.sh` resolves the baseline path — `baseline = "…"` in
`mago.toml`, else the default `guard-baseline.toml` — and when the file exists
passes `--baseline <path>` plus `--fail-on-out-of-sync-baseline`. The explicit
path is load-bearing: mago does not pick the default file up by itself.

Measured 2026-10-22 on a ledger holding one already-fixed `Modules\Outbox`
reach (the exact shape the baseline used to carry):

| invocation | exit | output |
|---|---|---|
| `guard` with the file in place, no flags | 0 | `No issues found.` |
| `guard --fail-on-out-of-sync-baseline`, no `--baseline` | 0 | warns `no baseline path was specified` |
| `guard --baseline guard-baseline.toml --fail-on-out-of-sync-baseline` | 1 | `contains 1 entry for issues that no longer exist` |

With no baseline the flags are not passed at all — mago warns twice about a
missing path for a flag it cannot apply, and a gate log nobody reads is the
thing this whole section is about.

## Adoption cost

- **Binary:** pin in CI the way `lekalo` is pinned (release tag + `sha256`, no
  token) or accept the Composer wrapper and export `GITHUB_TOKEN` for the first
  download. The spike script already does the first.
- **Analyzer:** a gate needs either constant/`define()` support upstream, a
  bootstrap mechanism, or a reviewed ignore list — today none of the three
  exists in this repo.
- **Linter:** the curated `[linter.rules]` block is the cost, and it is already
  written; the curated set is empty and `make mago-lint` fails on the first
  finding that arrives.
- **Formatter:** the cost is every file in scope plus the rules in the table
  above.
- **Guard:** paid in this change — 8 pre-existing errors removed by a kernel
  port (`SecurityEventSink` + `OutboxSecurityEventSink` + one wiring block), and
  the rule now lives in two places (`mago.toml` and `KernelCouplingTest`). The
  second copy sees strictly more, which is the reason it was worth adding.

## What this spike did not prove

- Whether Mago's analyzer finds things PHPStan never will, beyond the one
  auto-vivification finding. One finding is not a trend.
- Behaviour on a tree that grows: all numbers are from today's 123 files.
- Speed at scale: 0,19 s for `analyze` against PHPStan's ~3 s here is real, but
  this tree is small enough that neither number is a bottleneck.
- Editor/LSP experience, and Mago's own upgrade path (`version = "1"` is pinned
  precisely so a 2.x change cannot arrive silently).
- A true kernel-only comparison: the machine has sixteen feature modules
  installed, so the module-class bucket was classified by cause rather than by
  re-running on a clean clone.

## If this is rejected

Delete, in one commit:

- `mago.toml` — the artifact already excludes it, so nothing a deployment runs
  depends on it
- `bin/build/mago-spike.sh` and `bin/build/mago-modules-report.sh`
- `tests/Architecture/MagoScopeContractTest.php`
- the `mago-spike`, `mago-modules`, `mago-guard` and `mago-lint` targets and
  the `##@ Mago` block in `Makefile`
- the `kernel-dependency`, `curated-lint` and `module-quality` CI jobs and
  their entries in the workflow header
- this document
- `storage/cache/mago-spike/` (gitignored, so `rm -rf` locally)

Nothing else in the tree references Mago: no composer script, no dependency in
`composer.json` or `composer.lock`. The `SecurityEventSink` port is **not** on
that list — it is a code-level improvement that stands on its own, and reverting
Mago does not revert it.
