# Mago in `nativa-php-core` — evaluation

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

## Verdict at a glance

| tool | measured on this tree | verdict |
|---|---|---|
| `mago analyze` | 189 errors / 96 warnings / 87 help in ~0,19 s | **not a replacement** — 2 real findings, the rest is config and inference |
| `mago lint` | 570 findings at the default preset, **16** with the curated rule set | **not a gate** — 16 triaged, the 6 worth acting on were fixed 2026-10-22 |
| `mago format` | 124 of 124 files would change (~0,03 s) | **not a replacement** — house rules it cannot express |
| `mago guard` | 0 issues after the `SecurityEventSink` refactor; catches a planted kernel→module import | **gate** — CI job `kernel-dependency`, empty report is the claim |

Reproduce any line with:

```
make mago-spike                     # all four tools, always exit 0
make mago-spike MAGO_ARGS=--strict  # exit non-zero when a tool does
make mago-guard                     # the gate: guard only, --strict
bin/build/mago-spike.sh --only=analyze
```

Reports land in `storage/cache/mago-spike/` (gitignored): `<tool>.txt` and
`summary.tsv`.

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
Result: **16 findings**, all triaged. The two rules that were real and small were
fixed rather than triaged on 2026-10-22, taking the count from 22: `explicit-octal`
(5 implicit `0755`/`0775`/`0002` literals → `0o755`/`0o775`/`0o002`) and
`assert-description` (the one `assert()` in `DatabaseMigrator` now carries the
reason it cannot fire — the `RuntimeException` above it already refuses the same
class).

| finding | count | judgement |
|---|---|---|
| `no-ini-set` in `src/http.php` bootstrap | 5 | intentional |
| `no-empty-catch-clause` (documented best-effort) | 3 | intentional |
| `no-insecure-comparison` on a PHP-tokenizer `$token` | 4 | false positive (name heuristic, not a secret) |
| `no-literal-password` on the test's `TOKEN` constant | 2 | false positive (fixture) |
| `sensitive-parameter` on the CSRF `$token` | 1 | debatable, not a defect |
| `no-unsafe-finally` in a test | 1 | in a test, reviewed |

The security rules were deliberately **not** switched off to make the count look
better: a security rule silenced because this tree happens to trip it is the one
suppression that matters.

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
  written; the remaining 16 findings need a decision each.
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

- `mago.toml`
- `bin/build/mago-spike.sh`
- `tests/Architecture/MagoScopeContractTest.php`
- the `mago-spike` and `mago-guard` targets and the `##@ Mago` block in `Makefile`
- the `kernel-dependency` CI job and its entry in the workflow header
- this document
- `storage/cache/mago-spike/` (gitignored, so `rm -rf` locally)

Nothing else in the tree references Mago: no composer script, no dependency in
`composer.json` or `composer.lock`. The `SecurityEventSink` port is **not** on
that list — it is a code-level improvement that stands on its own, and reverting
Mago does not revert it.
