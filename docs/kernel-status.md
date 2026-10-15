# Kernel status — measured, 2026-10-11

This document records what this tree **actually does** when it is booted, not what
the plan intends it to do. Every status code below was read back off this checkout
with `curl` against a freshly started server.

> **Restart the server after editing PHP.** `opcache.revalidate_freq` is 180s in
> this environment and `-d opcache.enable_cli=0` does not switch it off for the
> built-in server: the first version of this document was written from responses
> that were partly stale, and the way that was caught was rendering through the
> layout in a CLI process and comparing the two answers. A check run against
> stale bytecode is a green run of a file that no longer exists.

## What was imported

| | Files | Lines |
|---|---|---|
| `src/Modules/Core` | 65 PHP | 8 194 |
| `src/Modules/Database` | 2 PHP | 372 |
| `src/init.php`, `public/.htaccess` | 2 | — |

Imported verbatim from `../nativa-minimal` — no file in the slice was edited on
import. The import carries the showcase's coupling: **34 non-kernel
`use Modules\<X>\…` statements across 14 files, naming 9 modules**, the number the
ledger in `../nativa-minimal` (`tests/Architecture/KernelCouplingTest.php`)
asserts in both directions.

## Boot 1 — no `vendor/` (a production-shaped tree)

Every surface answers **500**, and the cause is named in the application log:

```
[FATAL] Error: Class "Symfony\Component\Console\Command\Command" not found
        in src/Modules/Core/Interfaces/CLI/Command/MigrateCommand.php:17
  #2 .../Core/Interfaces/HTTP/Router.php(214): class_exists()
  #3 .../Core/Interfaces/HTTP/Router.php(193): scanDirectory()
  #4 .../Core/Interfaces/HTTP/Router.php(40):  discover()
  #5 public/index.php(60): Router->__construct()
```

`Router::scanDirectory()` calls `class_exists()` on **every** PHP class under
`src/Modules` and lets the autoloader trigger. Three of Core's own files —
`Interfaces/CLI/Command/{Migrate,Serve,Seed}Command` — extend a class from
`symfony/console`, a **dev-only** dependency. So a tree with no vendor directory
cannot serve a single request, including the ones that have nothing to do with
the CLI. That is the kernel's zero-dependency claim failing at load time, and it
is why `../nativa-minimal`'s dist works: `bin/build/build-dist.sh` excludes
`*/Interfaces/CLI/Command/` by path, so the scan never sees those files. A loader
guard belongs in that repo's architecture suite; this tree inherits the defect
rather than owning it.

## Boot 2 — `composer install`, BEFORE the first edge was cut

| Surface | Status | First cause | Ledger answer |
|---|---|---|---|
| `/` | 500 | `HomepageAction.php:103` → `Locales\…\LocalesServices` | **M** — the landing page is the app's, not the kernel's |
| `/robots.txt` | 200 (228 B) | — | already correct |
| `/sitemap.xml` | 500 | `ContentServices.php:33` → `Locales\…\LocalesServices` | **C** — default locale through a kernel-owned port |
| `/docs` | 500 | `ThemeResolver.php:61` → `Settings\…\SettingsServices` | **C** — theme name through the same port |
| `/nope` | 404 | — | already correct |
| `/mark` | 404 | — | already correct — and 404, never 500 |

Three of the six surfaces were already at their target value at that point; two
of those for the uninteresting reason that the kernel has nothing to say about
them and one — `/mark` — for the reason the plan gives: a kernel with no `Users`
module has no admin route to protect, so it is *not found* rather than
*forbidden*.

## Boot 3 — the first edge cut: the shell port (2026-10-11)

`ThemeResolver`, `ContentServices` and `FrontendLayout` no longer import
`Settings` or `Locales`. They ask a kernel-owned port instead.

**Added**

| File | What it is |
|---|---|
| `Core/Domain/Contracts/ShellContextProvider.php` | the port: site name, theme, default locale, languages, `<html lang>`, navigation |
| `Core/Domain/ShellNavItem.php` | kernel-owned nav item (the shell cannot carry a module's DTO) |
| `Core/Infrastructure/Attributes/AsShellContextProvider.php` | the discovery attribute |
| `Core/Application/Services/ShellContextProviderRegistry.php` | directory-scan discovery + the documented merge rule |
| `Themes/ThemeModule.php`, `Themes/kernel/KernelFallbackTheme.php` | the kernel's built-in default theme (prefix `k`, plain HTML, no CSS), used when no theme module answers |
| `Modules/KernelTheme/Theme/KernelTheme.php` | the kernel's visual theme, prefix `k`, no build, no module import; a module since the theme move |

**Rewired** — `ThemeResolver` (theme + whitelist), `ContentServices` (default
locale closure), `FrontendLayout` (site name, nav, languages, htmlLang),
`ShellContext` (nav type), `ThemeKernel` (kernel theme only, and an unknown name
falls back to it rather than to a class the tree does not have), `SitemapAction`
(each module-owned entry family is skipped when its module is absent).

The ledger moved with it: **34 → 27 edges (14 → 10 files)**, and `Settings` is gone
from the kernel's imports entirely — the module list is now Blog, Cardboard,
Locales, Media, Outbox, Pages, Security, Users.

| Surface | Boot 2 | **Boot 3** | Note |
|---|---|---|---|
| `/` | 500 | 500 | `HomepageAction.php:103` → `LocalesServices` — the **M** edge, not yet moved |
| `/robots.txt` | 200 | **200** (228 B) | unchanged |
| `/sitemap.xml` | 500 | **200** (396 B) | well-formed XML, 2 URLs — and it no longer advertises `/blog`, because a kernel-only tree serves no `/blog` and a sitemap that advertises a 404 teaches a crawler to find one |
| `/docs` | 500 | **200** (3346 B) | rendered by the kernel theme, `<html lang="en">`, `noindex`, and the catalogue's own "No guides are published in this deployment." |
| `/nope` | 404 | **404** (3765 B) | now a full document in the kernel theme |
| `/mark` | 404 | **404** (3765 B) | unchanged — still not found rather than forbidden |

No PHP warning, notice, deprecation or undefined-property error appears in the
application log across all six.

### Both halves of the port were exercised

A port whose every method returns null is a port whose read path never runs, and
a green boot in a tree with no provider proves only the absence half. So a
throwaway provider was installed, measured, and deleted:

| | with a provider | with none |
|---|---|---|
| brand | `Scratch Site` | `Nativa PHP Core` (the layout's own default) |
| header nav | 2 items — the active one carries `aria-current="page"`, the external one carries `rel="noopener noreferrer"` | 0 items, and no empty `<nav>` element |
| locale switcher | `en` / `sk` links | nothing (fewer than two languages) |
| status | 200 | 200 |

### Known gaps this step did NOT close

1. **`/` is still 500, and the sitemap still lists `/`.** The kernel lists the URL
   shapes it knows (home + the locale roots); whether anything serves `/` is
   decided by the `M` move of `HomepageAction`, which has not happened yet. Until
   it does, the sitemap advertises a URL that answers 500 — the next step has to
   settle what serves home rather than leave two surfaces disagreeing.
2. **The default locale still resolves through `ContentLocaleMapper`'s own
   `LANGUAGES` map** (`sk_SK`/`en_US`). The *default* is now the provider's answer,
   but the *set* of known languages is still a literal in the kernel, which is why
   `/sitemap.xml` emits `/en/` in a tree with no locale module. The port has
   `languages()`; nothing in `ContentLocaleMapper` reads it yet.
3. **The middleware are not wired here at all** (`LocaleMiddleware`,
   `RateLimitMiddleware`, `PageCacheMiddleware`, `AuthMiddleware`), so the statuses
   above are kernel + routing, not the full production pipeline.
   `RateLimitMiddleware:89`, `PageCacheMiddleware:36` and `HomepageAction` still
   import `LocalesServices` directly, and `HomepageAction:190` calls
   `LocalesServices::t()` for its copy.
4. **The admin/SEO meta strings** in `HomepageAction` and `ErrorPageRenderer` still
   name the showcase (`Nativa Starter — Home`).

## Boot 4 — the home surface stays in the kernel (2026-10-11)

`HomepageAction` no longer imports `Blog` or `Locales`. The showcase home
(hero + feature sections + latest articles, copy through the translation
system) is the app's, not the kernel's — its ledger answer is **M** and it
moves to the slice that owns its copy (`Modules\Site`, task 1.1). What stays
here is the smallest surface the kernel answers honestly with nothing
installed: the site name through the `ShellContextProvider` port, rendered by
the kernel theme from `ShellContext` + `PageContext` alone.

The ledger moved with it: **27 → 24 edges (10 → 9 files)**. `HomepageAction`
is the first `M` row gone; the remaining `M` rows are `SitemapAction`
(Blog ×3, Pages), `ServeUploadedFileAction` (Media), `SeedCommand`
(Security + Users ×3), `MetricContract` + `CardboardRegistry` (Cardboard ×2)
and `PageContext` (Blog ×2, answered `C`).

| Surface | Boot 3 | **Boot 4** | Note |
|---|---|---|---|
| `/` | 500 | **200** (3694 B) | kernel theme, `<html lang="en">`, brand + H1 `Nativa PHP Core`, no `<nav>` (0 items, none rendered), canonical `/` |
| `/robots.txt` | 200 | **200** (229 B) | unchanged |
| `/sitemap.xml` | 200 | **200** (398 B) | unchanged — and it no longer advertises a 500: the `/` it lists now answers 200 |
| `/docs` | 200 | **200** (3346 B) | unchanged |
| `/nope` | 404 | **404** (3765 B) | unchanged |
| `/mark` | 404 | **404** (3765 B) | unchanged — still not found rather than forbidden |

No new `[KERNEL] Error` across the six requests (log count 35 before = 35
after); the only entries are the pre-existing `LocalesServices not found`
lines from before the cut.

### What this step did NOT close

1. **`/en/` is still advertised but unserved** (gap #2 survives): the sitemap
   lists `/` + `/en/` from `ContentLocaleMapper::LANGUAGES`, but the tree has
   no `/en/` route — only `/` answers 200. The port has `languages()`; nothing
   in the mapper reads it yet.
2. **The middleware `G` edges are untouched** (`AuthMiddleware` 6,
   `RateLimitMiddleware` 4, `PageCacheMiddleware` 1) — task 1.3.
3. **`ErrorPageRenderer` still names the showcase** (`Nativa Starter`); the
home title is kernel-named since this step, the error pages are not — gap #4
narrowed, not closed.

## Boot 5 — the middleware lose their module imports (2026-10-11)

`AuthMiddleware` (6 edges), `RateLimitMiddleware` (4) and
`PageCacheMiddleware` (1) no longer import `Users`, `Outbox`, `Security` or
`Locales`. The ledger moved with it: **24 → 13 edges (9 → 8 files)** — every
`G` row is gone, and what remains is all `M` (the surfaces that move out) and
`C` (the `PageContext` payload trim).

**Added** — the auth port, mirroring the shell port's shape:

| File | What it is |
|---|---|
| `Core/Domain/Contracts/AuthProvider.php` | the port: identity, protected paths, required permission, guest-only paths, event sink |
| `Core/Domain/AuthIdentity.php` | kernel-owned identity (id, active, permissions) — the User entity never crosses |
| `Core/Infrastructure/Attributes/AsAuthProvider.php` | the discovery attribute |

**Rewired** — `AuthMiddleware` asks the port (session handling, remember-me
and the permission table stay the module's; uncovered admin paths still cost
access, never leak it); both cache middlewares key on the mapper's default
locale instead of the translation store; the outbox emission in `RateLimit`
is a `class_exists` guard (recorded only when installed, dropped otherwise —
no `use`, so the ledger stays clean); the limiter factory product is
duck-typed and degrades to pass-through when unusable.

`index.php` is deliberately untouched: with no provider installed there is
nothing to wire, and an `if` that can never be true here is untestable here.
The composition root IS the mechanism — it hands `AuthMiddleware` whatever
`AuthProvider` the installed module supplies, constructor-injected, with no
kernel registry in between to fall out of step with what is installed.

(**Superseded by §6:** this tree has since gained a provider module —
`UsersAuthProvider` — so the composition root now does carry the `if`, behind
`class_exists`, and §6 records the behaviour measured with and without it.
**Superseded again by §10:** the `if` no longer names the module — the provider
is discovered by `#[AsAuthProvider]`, so the composition root asks a Core
registry instead of a module class.)

| Surface | Boot 4 | **Boot 5** | Note |
|---|---|---|---|
| `/` | 200 | **200** (22936 B) | still Mono (installed); ~400 B lighter — the white-page fix strips comments from the inlined CSS |
| `/robots.txt` | 200 | **200** (229 B) | unchanged |
| `/sitemap.xml` | 200 | **200** (398 B) | unchanged |
| `/docs` | 200 | **200** (22535 B) | same comment-strip delta, Mono chrome |
| `/nope` | 404 | **404** (3765 B) | unchanged |
| `/mark` | 404 | **404** (3765 B) | still not found rather than forbidden — no provider, no middleware, no route |

No new `[KERNEL] Error` (log count 35 = 35). The three middleware are not in
the pipeline here (no `#[AsMiddleware]`, never were), so the statuses prove
the rewrite broke nothing reachable; the guards themselves were proven by a
throwaway CLI harness (stub provider, stub limiter, temp-dir cache — 14
checks: anon→login, inactive→403, no-policy→403+event, missing-perm→403+event,
allowed→next, guest-only→mark, public→next, allow→next, trip→bare-429 with no
outbox and no fatal, trip→429+X-Cache-HIT, junk-limiter→next, loopback
bypass, GET cached, POST skipped). ALL GREEN. The harness lives in `/tmp`,
not in the tree: without the PHPUnit harness (task 2.1) a committed test
could not run anywhere.

## Boot 6 — the sitemap lists what the kernel serves (2026-10-11)

`SitemapAction` no longer imports `Blog` (×3) or `Pages`. The article
enumeration was Blog's half, the page enumeration Pages', the author profiles
Blog's URL shape, the blog index and feeds the serving module's — four `M`
ledger rows, deleted with the code they classified. The kernel lists home +
locale roots and nothing else: a sitemap that advertises a 404 teaches a
crawler to find one. When those modules arrive they extend the sitemap
through their own contribution (a port, like every other kernel surface).

The ledger: **13 → 9 edges (8 → 7 files)**. Remaining: `ServeUploadedFile`
(Media), `SeedCommand` (Security + Users ×3), `PageContext` (Blog ×2, `C`),
`MetricContract` + `CardboardRegistry` (Cardboard ×2).

| Surface | Boot 5 | **Boot 6** | Note |
|---|---|---|---|
| `/sitemap.xml` | 200 (398 B) | **200** (398 B) | byte-identical body (modulo host port) — the guards already skipped everything the trim deleted |
| `/` | 200 | **200** (22936 B) | unchanged |
| `/robots.txt` | 200 | **200** (229 B) | unchanged — the Allow-list is permission, not advertisement; no fetch follows it |
| `/docs` | 200 | **200** (22535 B) | unchanged |
| `/nope` | 404 | **404** (3765 B) | unchanged |
| `/mark` | 404 | **404** (3765 B) | unchanged |

No new `[KERNEL] Error` (35 = 35). Gap #2 survives the trim: the sitemap
still lists `/en/` from `ContentLocaleMapper::LANGUAGES` while no `/en/`
route exists — the port has `languages()`, nothing in the mapper reads it
yet.

## Boot 7 — serving uploads leaves the kernel (2026-10-11)

`ServeUploadedFileAction` is deleted. The upload allowlist is Media's policy
and serving uploads is Media's route — the `M` row said so, and with no Media
module installed the file was a guarded 404 looking for a database it will
never find. A kernel with no uploads module serves no uploads:
`/storage/uploads/*` answers the generic page 404 (modulo nonces, the only
content delta is one sentence: "the requested file" → "the requested
page"). The content lives in git history and in `../nativa-minimal`, where
the Media slice will carry it.

The ledger: **9 → 8 edges (7 → 6 files)**. Remaining: `SeedCommand`
(Security + Users ×3), `PageContext` (Blog ×2, `C`), `MetricContract` +
`CardboardRegistry` (Cardboard ×2).

| Surface | Boot 6 | **Boot 7** | Note |
|---|---|---|---|
| `/storage/uploads/abc123.webp` | 404 (3765 B) | **404** (3765 B) | same status and size, generic renderer |
| `/` | 200 | **200** (22936 B) | unchanged |
| `/robots.txt` | 200 | **200** (229 B) | unchanged |
| `/sitemap.xml` | 200 | **200** (398 B) | unchanged |
| `/docs` | 200 | **200** (22535 B) | unchanged |
| `/nope`, `/mark` | 404 | **404** | unchanged |

No new `[KERNEL] Error` (35 = 35).

## Boot 8 — the seed command leaves the kernel (2026-10-11)

`SeedCommand` is deleted. Seeding writes hashed credentials, roles and
permissions — a kernel with no auth has nothing to seed, and the permission
vocabulary belongs to the module that enforces it. Four `M` rows
(Security ×1, Users ×3) gone with the file; the content lives in git history
and in `../nativa-minimal`, where the Users+Security slices will carry it.
The kernel's own `bin/cms` never had a seed command (only `module:*`), so
nothing references the deletion.

The ledger: **8 → 4 edges (6 → 3 files)**. Remaining: `PageContext`
(Blog ×2, `C`), `MetricContract` + `CardboardRegistry` (Cardboard ×2).

All six surfaces unchanged (`/` 200 Mono 22936 B through `/mark` 404), log
35 = 35 — a CLI deletion with no route to remove, verified anyway.

### Mono stays installed here

This checkout keeps `Mono` installed as its default presentation (operator
state, gitignored — `src/Modules/Mono/` never enters history). The kernel's
own defaults stay `kernel` deliberately: a kernel defaulting to a module
theme it may not have is the same bug class this tree exists to kill
(a page rendering another theme's answer). `ThemeKernel` falls back, the
provider votes, the merge rule keeps every other kernel default.

## Boot 9 — zero edges (2026-10-11)

The last four rows are gone, and the count the tree exists to move reads
**0**: no kernel file imports a module anymore (`Database` excluded — it is
the kernel's second half, contributing zero by definition).

- `MetricContract`, `MetricRegistry`, `AsMetricProvider`, `CardboardRegistry`
  deleted as one subsystem: the metric registry has no reader in a kernel
  with no admin, and deleting two of the four would have left the other two
  referencing the missing. Content in git history and `../nativa-minimal`.
- `PageContext` trimmed (`C`): the `?SearchResult` prop and factory param are
dropped (no theme in any tree reads `$context->result` — only `query` +
`results`), article lists re-documented as plain `list<object>` (slug, title)
read via `get_object_vars`, the shape the themes actually consume.
- `NavBadgeRegistry`'s prose no longer names the deleted `MetricRegistry`.

The ledger: **4 → 0 edges (3 → 0 files)**. The boundary rule from the README
now holds by measurement, not by intention.

### The first real catalogue module

`modules/Cardboard/` — 22 files, copied verbatim from `../nativa-minimal`
(`diff -r` identical; an import that starts fixing things is a fork).
`module:show` reads it on scan strength alone (6 `/mark` routes, admin
scripts, no `module.json`): closure `Audit, Blog, Locales, Media, Outbox,
Pages, Security, Settings, Users`. `module:add` refuses by name (exit 1,
nothing copied) — the guard proving itself against a real module, not a
fixture.

All six surfaces unchanged (`/` 200 Mono through `/mark` 404), log 35 = 35.

## Boot 10 — the harness (2026-10-11)

`composer test` is green: **7 tests, 13 assertions, ~1 s, in-process** —
the six surfaces no longer need six curls through `:8000` and the lifecycle
no longer needs eyeballing.

- `ModuleInstaller` (`Core/Application/Services`): the add/remove half of
  `bin/cms` moved verbatim into a service that throws the refusal with the
exact CLI string. The script is a thin printer now (same strings, same exit
codes — `add → 0, re-add → 1, remove → 0, re-remove → 1`, verified after the
move). One wording, two front-ends.
- `phpunit.xml` + `tests/bootstrap.php`: the harness boots the REAL
  `src/init.php`, not a fixture — a fixture init that drifts is the
  staleness this harness exists to catch.
- `KernelOnlyBootTest` (task 2.3): the six surfaces dispatched through the
  `Kernel`, never by calling an action. Status codes only — with Mono
  installed `/` wears Mono, kernel-only the kernel theme; both are 200, and
  which theme renders is `ThemeKernel`'s business, not the boot contract's.
- `ModuleLifecycleTest` (task 3.3): hash → add Scratch → `/scratch` 200s →
  second add refuses → remove → `/scratch` 404s → hash equals the pre-add
  value; plus the negative (a throwaway `TempHolder` importing Scratch →
  removal refused with the holder named, fixture deleted in cleanup).

Two traps the harness tripped over while being built, both load-bearing:

1. **The 404 leg runs in a subprocess.** PHP's class table persists per
   process: after `remove()`, the in-process `Router` still resolves the
   deleted `ScratchAction` (already declared) and answers 200 — the same
   staleness class as the opcache incident, one layer down. A fresh `php -r`
   is the only honest 404.
2. **The tree hash streams.** The first version held every file's content
   in one array and OOM'd at 128 M on `vendor/` — `hash_update` per file,
   O(1) memory. (And a probe footnote: `config/session.php` fatals when any
   output precedes `init.php` — session ini settings cannot move after
   headers. The harness is safe because the bootstrap emits nothing, but a
   `php -r 'echo …; require init'` dies with 255 and the reason lives only
   in `storage/logs/php-error.log`.)

All six surfaces unchanged, log 35 = 35. Task 2.1's `extract-kernel.sh`
still does not exist — but when it does, there is now a `tests/` worth
adopting instead of bringing one.

## Boot 11 — the extraction guard (2026-10-11)

Task 2.1 closes the gap its own text names: the baseline commit carried the
tree without `tests/`/`phpunit.xml`/`phpstan.neon`/`Makefile`, and `composer
test` in a fresh clone failed until the harness landed (Boot 10) — now the
remaining two files land too, plus the script the task actually asks for.

- `bin/build/extract-kernel.sh` (`--to`, `--check`, `--minimal-root`):
  materializes the upstream slice or diffs it against this tree. The
  semantics are adapted to the section-1 ruling (the work happens HERE —
  one-way import, not re-derivation): the script cannot regenerate our tree
  from minimal, so `--check` guards the complement instead. Every difference
  must be recorded in `bin/build/kernel-divergence.txt` (`DIVERGED` 20 /
  `OWNED` 16 / `DROPPED` 6); anything unrecorded fails, in both directions.
- `--check` is green: **65 identical, 30 recorded, exit 0** — and the
  spec's mutation proof holds: a probe comment in an unlisted file flips it
  to exit 1 with a unified diff naming the file; reverting flips it back.
- `phpstan.neon` (level 9, kernel paths) + `Makefile` (kernel target subset
  incl. `extract-check`): `composer phpstan` is green, `composer test` stays
  7/7. Getting there fixed 8 real findings, no suppressions: a kernel-owned
  `RateLimiter` contract narrows the middleware's guarded factory product
  (the `isLimiter()` pass-through contract is untouched — only the type
  after the guard changed), `readModuleJson` rejects non-string keys,
  `bin/cms` handles undefined `$argv`, the lifecycle cleanup is explicit
  per-step blocks, and two Mono annotations were fixed in the catalog and
  synced to the installed copy.

Two things the guard surfaced, recorded rather than fixed (both owned
elsewhere): upstream's `Router` gained the task-0.3 loader guard after our
import — the Boot-1 defect is still inherited here — and upstream deleted
`ThemeRegistry`, which we keep. Both lines sit in the divergence record with
the reason attached, so adopting either is a one-line deletion, not an
investigation.

Still open, deliberately: `cs-fix-dry`/`rector-dry` need configs this tree
does not have (minimal's were not in 2.1's emitted list; importing them +
passing them is its own task), and `composer analyze` stays red until then.
`phpstan` + `test` + `extract-check` + `lint` are the green gates.

Surfaces over HTTP (fresh `php -S` — the dev server was down when this
boot was written): all six statuses match (`/` 200, robots/sitemap/docs
200, `/nope` + `/mark` 404; sizes match except `/` at 22934 B vs historical
22936 B). The 2 B are unexplained but bounded: two fresh renders differ in
exactly three 32-byte runs — the three CSP nonces — and nothing else, and
nothing in the `/` render path changed (both Mono edits are annotation- /
signature-only, the middleware is not in the pipeline here). `php-error.log`
grew during this boot, entirely from harness construction probes (the
session-after-output fatal and the first-version OOM, all from `php -r` /
PHPUnit processes — none from a served request).

## Boot 12 — the kernel theme gets a design (2026-10-11)

Task 2.2: a trimmed design that renders from `ShellContext` alone, plain
CSS, no build, registered as the default. The registration was already true
(`PUBLIC_THEME` is `kernel`, unknown names fall back to it); what was
missing was the design — pages were valid and unstyled.

- `src/Modules/KernelTheme/assets/critical/kernel.css` (moved from `src/Themes/kernel` in the theme move): one hand-written file
  (~60 rules, system stack, light), inlined through `InlineCss` like every
other theme's critical CSS. No `@charset`/`@import`/`@layer`, no build step.
  `KernelTheme::criticalCss()` returns it instead of `[]`.
- `ThemeModule::errorContent()` — the error block belongs to the active
  theme. `ErrorPageRenderer` resolves the theme from the request path
  (`ThemeResolver::resolvePath()`, extracted from `resolve()` so both
  answer identically) and can no longer fail the error page: resolution is
  wrapped, anything trips → kernel theme. The `st-hero` classes and the
  `... Nativa Starter` title are gone from the renderer, and the guide-404
  in `DocsPageAction` delegates to it instead of carrying its own `st-`
  markup with inline styles. `Kernel::renderError` threads the path from
  all four call sites.
- Implemented in all three `ThemeModule`s: kernel (`k-error`), Mono
  (`mo-hero`), Erik (`er-section`). Mono's fix went into the catalog and
  was synced to the installed copy — and the gap bit immediately: PHPStan
  failed on the stale installed `MonoTheme` (abstract-method fatal), the
  same catalog/installed skew `module:add` refuses to create. A note for
  the lifecycle docs, not a code change.
- `tests/Theme/KernelThemeTest`: the file ships, survives the loader with
  the theme's vocabulary in it, the kernel layout wears `k-`, the error
  page is `k-error` with escaped inputs and no showcase strings.

Measured over HTTP (fresh `php -S`, Mono installed): `/` 200 Mono
unchanged, `/nope` 404 now Mono-chrome (was kernel 3765 B), `/mark` 404
kernel-chrome (admin theme `moonshine` is not installed — the fallback rule
working as documented), guide-404 Mono-chrome. Bodies verified by grep:
`mo-hero` without `st-hero`/`Nativa Starter`, `k-error` without `mo-`.
Gates: `composer test` 11/29, `phpstan` clean, `--check` green (61
identical, 34 recorded — the four rewritten slice files added to the
record).

Deliberately not this task: `starter` as an addable module (no starter
slice exists in this tree; packaging it is minimal-side work) and the
`cs-fixer`/`rector` configs from Boot 11.

## Boot 13 — the last showcase strings (2026-10-11)

Gap #4 closes: a grep for `Nativa Starter|nativa_starter` across the tree
now hits only history (this log), the harness assertion that guards it,
and Cardboard's fixed fallback. What moved:

- `config/session.php`: the session default is `nativa_core` — matching
  `.env.example` at last (code said `nativa_starter` while the example said
  `nativa_core`). No live sessions to invalidate: no auth module installed.
- `AssetManager`: unknown bundles fall back to `kernel`, the theme this
  tree has, not `starter`. Behavior-identical (no manifest either way —
  every in-tree caller passes the bundle explicitly), name-honest.
- `LlmsTxtAction`: the served `/llms.txt` was full showcase copy *and*
  advertised blog/feed/author routes that answer 404 here. Rewritten to
  describe the kernel and link only what it serves (`/`, `/docs`,
  `/sitemap.xml`, `/robots.txt`) — same rule as the sitemap.
- `ThemeModule` docblock + `FrontendLayout` comments: wired themes are
  kernel/mono/erik now, critical CSS is "small" without naming a bundle.
- Cardboard's dashboard fallback: `Nativa Starter` → `Mark` (its own
  brand, per its layout titles). Catalog edit, uninstalled module, zero
  runtime effect here.

Kept deliberately: `ThemeKernel`'s docblock naming `StarterTheme` (it
documents the fallback that must NOT happen) and "starter kit" as a
generic term in `MarkdownRenderer`.

Gates: `phpstan` clean, `composer test` 11/29, `--check` green (58
identical, 37 recorded). HTTP: all seven surfaces answer (`/llms.txt` 200,
792 B, kernel copy with absolute links); `/` back at 22934 B.

## Boot 14 — the admin stack installs (2026-10-11)

The catalogue had one installable module and one that refused with nine
missing dependencies. It now has **sixteen**, and Cardboard — the real
admin app — installs and serves `/mark`.

### What the guard found

`module:add` refused Cardboard because twelve module slices each import the
other's classes. That was not a tooling bug: running `module:show` over the
graph surfaced **five real dependency cycles**, each fixed at its cause:

1. **Cardboard ↔ everything (62 import sites).** The admin overlay was two
   things in one module: a *presentation kit* (layout, card/trend/chart
   models, locale keys, bulk-action shell, the metric registry) and the
   *admin app* (dashboard, users, roles, settings actions). The kit half
   moved to `modules/CardboardKit` — a leaf with zero module dependencies —
   and eleven modules now depend on the kit instead of on the admin app.
   The kit's one back-reference (the layout asked `CardboardServices` for
   nav/user/settings) became the **`AdminContextProvider` port** (attribute
   + registry, same discovery shape as the shell port), implemented in
   Cardboard. The kit names the port; nothing points kit → Cardboard.
2. **Users ↔ Outbox.** Users' login/logout actions imported Outbox's event
   services; Outbox imported Users' `AdminRoutePolicy`. Both were
   observability, not function: each call site is now a `class_exists`
   guard (no `use`), so login works without the outbox and audit still
   records with it.
3. **Users → Locales → Audit → Users.** Same treatment on the Locales→Audit
   edge (the only one that needed a guard — the other two are load-bearing).
4. **Pages ↔ Questions** and **Blog ↔ Questions/Comments.** The embeds
   (question box, comment section, questions sidebar) are optional content:
   guarded, and absent module means the block is omitted, not fatal.
5. **Cardboard → Blog/Users/Pages services.** `CardboardServices::blogCrud()`
   and `::users()` were convenience re-exports; the two callers now build
   the service they need directly.

Two side findings, fixed: the metric subsystem Boot 9 deleted from the
kernel actually belonged to the dashboard (`MetricContract`, registry,
attribute, `CardboardRegistry`) and was restored **into the kit**, where its
readers are; and `PageContext`'s Boot-9 parameter removal left
`BlogIndexAction` passing a now-nonexistent argument positionally, so
`forBlogSearch` received the result list where the dropped `SearchResult`
had been — a latent argument-shift bug that only became reachable once Blog
was installable. Boot 9's claim was true for every theme *in this tree*; the
upstream showcase themes do read `$context->result`, so that property is
still absent here and the call site is the fix.

### The wall came down honestly

`Security Settings CardboardKit Outbox Locales Users Audit Media Blog
Comments Pages Questions Cardboard` — thirteen slices, installed in the
order the acyclic graph allows, each copied verbatim from the catalogue.

Measured over HTTP (`php -S`, all thirteen installed): `/` 200 (Mono, with
Settings' theme setting pinned to `kernel` so the public whitelist keeps
answering), `/mark` **200** (35391 B, `cba-` chrome, no fatal), `/blog` 200,
`/blog/feed.xml` 200, `/login` 200, `/docs` 200, robots/sitemap/llms 200,
`/nope` 404. No new `[KERNEL] Error` across the run.

### Gates

- `phpstan.neon` scope corrected: it analysed `src/` including installed
  slices, so a gate result depended on what the operator had installed.
  Now the kernel scope only (Core/Database/Themes/public/bin/tests) and
  green.
- `KernelOnlyBootTest` is install-aware: `/mark` expects 404 kernel-only
  and 200 with Cardboard installed, and a separate test asserts the
  kernel rule that holds either way — an unrouted admin path is NOT FOUND,
  never Forbidden. Verified in both directions (12/31 installed, 12/31
  kernel-only with the skip correctly not firing).
- `extract-kernel --check` green, `composer test` 12/32.

### Still open

Upstream showcase themes (starter, moonshine, magazine, …) read
`$context->result`; neither our themes nor the CATALOG's do. Restoring that
property is a kernel decision with a Blog-typed argument — deferred, and the
only caller is fixed. The nine newly catalogued modules arrived verbatim and
are **unverified beyond install + route**: their own acceptance suites (if
any) were not run — this boot proves the graph installs and serves, not that
the admin CRUD flows are correct.

## Boot 15 — the admin gets its stylesheet (2026-10-11)

The report was "`/mark` works but has no pnpm assets". Measured, it was
worse and more specific than that:

| | before | after |
|---|---|---|
| `rel="stylesheet"` links on `/mark` | 0 | 2 (mark, mark-switch) |
| classes used vs defined | 67 used / **0 defined** | 67 / 64 |
| inline shell JS | 21 344 B (works) | unchanged |

The admin chrome's CSS comes from the Vite manifest
(`AssetManager('moonshine')` → `public/dist/moonshine/manifest.json`), and
this tree had no `public/dist` at all — no `Frontend/` workspace either, so
there was nothing here to build. `markCssLinks()` degraded to an empty
string exactly as designed; the page was simply unstyled.

**Fixed by importing the build**, not by hand-writing admin CSS:
`make assets` copies `public/dist` from the showcase workspace (which is
where a build can actually happen), and the result is **gitignored** —
`/public/dist/` is a build artifact, precisely as in `nativa-minimal`. The
**fonts are tracked** instead (`public/fonts/mark/*.woff2`, 5 files, 140 K):
the admin stylesheet `@font-face`s them, so they are source, not output,
and a checkout that has them renders in the designed face rather than a
fallback. All five answer 200.

**A real coupling bug came out of it.** The kit's templates loaded their
shell scripts through Cardboard's path
(`Modules/Cardboard/Interfaces/HTTP/assets/scripts/…`), so a tree with Blog
installed but **not** Cardboard — a configuration Blog's own closure
allows — rendered admin pages with silently dead JS. Six scripts
(`admin-shell`, `admin-shell-commands`, `admin-theme-init`, `bulk-actions`,
`compact-shell`, `compact-shell-commands`) moved into the kit that loads
them; Cardboard keeps the three its own templates use (`avatar-b64`,
`settings-table`, `settings-tabs`). Verified with Cardboard *removed*:
`/mark/articles` 200 (33 628 B) with the shell JS intact (304 + 21 344 B)
and both stylesheets — the exact case that used to lose its scripts — plus
`/mark` correctly 404 and a graceful `Guest` header (no provider).

Three classes remain undefined and are recorded rather than papered over:
`m-mono` (used as a class in the dashboard's system table; upstream defines
only the `--m-mono` custom property), `m-btn--active` (period selector) and
`m-trend--flat` (a docblock example). All three are cosmetic — the base
rules style them — and all three are upstream's, so fixing them here would
be a fork of a module we import verbatim.

Gates after the change: `phpstan` clean, `composer test` 12/32, `--check`
green, `/` 200 (22 934 B, unchanged), `/mark` 200 (35 545 B), no new
`[KERNEL] Error` across the run.

# Boot 16 — the admin CRUD flow, and the marker that ate its tables

Verifying the admin flow end to end (the last item Boot 15 left open) found
the write path broken — and not by the admin code.

**What was measured.** `POST /mark/categories/create` with a valid CSRF
token and `category=Verify-CRUD-…` → **500**, while the category *did*
persist (Blog's taxonomy registry is
`storage/data/blog-taxonomy.json`, not SQLite). The log named it:

```
[KERNEL] PDOException: no such table: audit_log
  (Audit/Infrastructure/Persistence/Repositories/AuditLogRepository.php:29)
```

Every admin write records an audit event, so the write was landing and then
dying on its own trail. `audit.db` was empty, and so were `blog`, `comments`,
`outbox`, `core` and `media` — the tree answered 200 everywhere it did not
write, which is why the flow had looked fine.

**Root cause: a stale migration marker.** Migrations auto-run on the first
request and then leave `storage/cache/.migrations-applied`, whose content was
— measured — a single word: `config`. It was written on 2026-10-11 21:48,
when Settings was the only installed module. Boot 14's `module:add` then
installed thirteen more slices **without touching that file**, so the
Bootstrapper kept skipping the run and none of their tables were ever
created. Deleting the marker and hitting `/` re-ran all nine migrators
(`audit, blog, comments, config, locales, media, outbox, questions, users`)
and created `audit_log`, `article_views`, `outbox_messages`; the same POST
then answered **302**, the category persisted, and `audit_log` held its
`categories.create` row.

**The full CRUD cycle, measured through HTTP** (Browser: `curl` with a
session cookie and the form's CSRF token): `create` 302 → present in
`blog-taxonomy.json`; `rename` 302 and `delete` 302, both audit-logged;
a `POST` with no token → **403** (the CSRF guard fires); `login` 302 →
`/mark`; no `[KERNEL] Error` anywhere in the run.

**One caveat, upstream — not fixed here.** `rename` and `delete` rewrite
**article front matter** (`replaceCategoryInAllArticles` /
`removeCategoryFromAllArticles`); they never touch the registry. This tree
has no `storage/blog/` at all, so *every* listed category is registry-only
and both buttons are no-ops — they answer 302, write an audit row reading
`(0 articles)`, and change nothing. `create` is the only button that
persists here. The actions are `Modules\Blog`, imported verbatim, so this is
recorded rather than forked (the same call as the three undefined CSS
classes in Boot 15).

**Gates after the change:** `phpstan` clean (82/82 files), `composer test`
13 tests / 30 assertions / 1 skipped, `extract-kernel.sh --check` OK (57
identical, 38 recorded divergences — the new Bootstrapper rewrite is the
38th).

**Fixed at the cause**, not by pre-creating tables: the marker records "the
migrators of *this* module set have run", so `ModuleInstaller::add()` and
`remove()` now call `Bootstrapper::invalidateMigrations()`. The next request
re-runs the (idempotent) migrators against the new set. A regression test in
`ModuleLifecycleTest` writes the marker, installs the fixture slice, and
asserts it is gone.

**No `migrate` command exists in this tree** — `bin/cms` ships `module:*`
only, so until now the marker was a trap with no way out. The invalidation
makes that a non-issue for the CLI path; `rm storage/cache/.migrations-applied`
is the remaining manual lever, and it is now written down in the
Bootstrapper's own docblock rather than left to be rediscovered.

**Also confirmed on the way:** the login POST records its
`security.auth.attempt` into the *Outbox*, so the same stale marker was
logging `no such table: outbox_messages` on every sign-in — silent in the
response, loud in the log. With the table now in place that line changed to
`[OUTBOX] Failed to process security.auth.attempt (attempt 0/5): No handler
registered for event type: security.auth.attempt` — a second, separate gap
(the Outbox has no handler for the event the login emits) that the missing
table had been hiding. Recorded, not fixed here: the login itself answers
302 → `/mark` and holds the session regardless.

# Boot 17 — the pnpm workspace comes home

Boot 15 answered "`/mark` works but has no pnpm assets" by importing a
**build** and keeping the tree Node-free. That left the honest objection
standing: the imported bundle was production output, and nothing here could
reproduce it. This boot imports the workspace instead.

**What came in:** `Frontend/packages` — 193 files, 1.5 MB, copied verbatim
from the showcase: the pnpm workspace root (`package.json`,
`pnpm-workspace.yaml`, `pnpm-lock.yaml`, `tsconfig.base.json`,
`tsconfig.build.json`), the shared `build/vite-theme-factory.ts` +
`build/strip-at-layer.ts`, and one package per theme (`starter`, `erik`,
`moonshine`, `magazine`, `minimal`, `basic`, `jednotka`). `node_modules`
(59 MB of third-party files in the checkout it came from) is **not** part of
the import and is gitignored, alongside the built `public/dist`.

The factory needs no edit: it derives the repo root as
`<root>/Frontend/packages/../..`, which is `<root>` in this tree exactly as
it is upstream, so `outDir` lands on this `public/dist/<theme>/`.

**Proven end to end, in this tree:**

| | result |
|---|---|
| `pnpm install --frozen-lockfile` | 18 packages, all reused from the local store (offline) |
| `pnpm build` | 7 theme bundles into `public/dist/<theme>/` |
| `diff -rq public/dist ../nativa-minimal/public/dist` | **silent** — byte-identical build |
| hashed names vs the Boot 15 import | identical (`mark-BlzW9kQ5.css`, `moonshine-DtY8mHQK.js`) |
| `/mark` stylesheets, shell JS, 5 fonts | 200, unchanged |

The build logs "…/fonts/mark/Gilroy-*.woff2 — didn't resolve at build time, it
will remain unchanged to be resolved at runtime" for the five Gilroy faces —
correct and expected: those URLs are absolute (`/fonts/mark/…`),
the fonts are tracked in `public/fonts/`, and Boot 15 already verified all
five answer 200.

**`make assets` now builds here** (`cd Frontend/packages && pnpm install
--frozen-lockfile && pnpm build`); the old copy-from-the-showcase target
survives as `make assets-import` for a machine without pnpm. The
`extract-kernel.sh` slice grew `Frontend/packages`, so the import is guarded
rather than merely present: **250 identical / 38 recorded divergences** (was
57/38). A new `SLICE_SKIP` list prunes `Frontend/packages/node_modules` from
both the stage and the walk — without it the check compared a dependency
store and reported every file as "exists only here".

# Boot 17b — `bin/cms migrate`

Boot 16 gave the tree a way to *recover* from a stale migration marker and no
way to *force* one. `bin/cms` was four `module:*` commands by design, and the
Bootstrapper's docblock pointed at a `migrate` command that this tree does
not have. It has one now — a fifth argv-parsed command, zero dependencies
like the rest, built directly on `DatabaseMigrator` and a three-line
`CliOutput`:

```
php bin/cms migrate
  → Migrating audit.db … ✓ Migrated: audit, blog, comments, config, locales,
    media, outbox, questions, users
```

Verified against the Boot 16 failure state: `audit_log` dropped by hand,
`migrate` recreated it, exit 0; an unknown command still exits 1.

# Boot 18 — the two silent gaps the admin flow exposed

Both were found by reading the log after the Boot 16 fix, and both share a
shape: an operation answered 302, and nothing happened.

## `security.auth.attempt` had no handler

```
[OUTBOX] Failed to process security.auth.attempt (attempt 0/5):
No handler registered for event type: security.auth.attempt
```

Sign-in records the attempt into the Outbox; the Outbox's end-of-request
drain (`OutboxDrain::drain()`) registers only the handler the Outbox module
can see — the article-publish cache flush. The security handler lives in the
**Audit** module, because its output is an audit row, and Outbox reaching into
Audit is the module dependency upstream's `ModuleIsolationTest` refuses — a
guard that does **not** exist in this tree (`find` over the whole tree: zero
hits outside `../nativa-minimal`; the kernel half now has one, see "Kernel
contracts" at the end of this file). Upstream wires
the pair in its composition root; this tree's `public/index.php` is the
kernel's half and has "nothing to wire", so the pair was never declared and
every security event landed in `outbox_messages` as `failed`.

Fixed where upstream fixes it — the composition root — under `class_exists`
guards on all three classes, so a kernel-only tree still boots with nothing
to wire (the same trick `LoginAction` uses for its Outbox call, and the reason
no `use` statement appears).

**Measured:** a sign-in now delivers 1 message, writes the audit row
(`security.auth.attempt`, `audit_log` rowid 2 — the table's first security
record) and logs **zero** `No handler registered` lines. The 10 messages that failed before the fix stay `failed`:
`findPending()` selects `pending`, so they are history, not a queue — they are
left as the record of the outage rather than silently replayed.

## Rename and delete never touched the registry

`/mark/categories` lists the **registry** (`storage/data/blog-taxonomy.json`,
where a pre-created category lives) merged with article front matter. Its
Rename and Delete actions only rewrote **front matter**
(`replaceCategoryInAllArticles` / `removeCategoryFromAllArticles`) — so on any
category that lives in the registry alone, both buttons answered 302, wrote
an audit row, and changed nothing. This tree has no `storage/blog/` at all, so
*every* category was in that case.

`TaxonomyService` gained `renameCategory()` / `deleteCategory()` (registry
edit, case-insensitive, first-occurrence-wins dedupe — the same rule readers
apply), and the action now does both halves: the registry and the articles.
The audit detail says which: `(0 articles, registry: renamed)`.

**Measured** through HTTP, registry-only: create → `[\"Reg…\"]`; rename →
`[\"Reg…-renamed\"]` and the list shows the new name; delete → `[]` and the
list no longer shows it.

**This is the catalogue's first fork of an upstream module.** `modules/Blog`
now differs from `nativa-minimal/src/Modules/Blog` in two files, and the
installed copy under `src/Modules/Blog` was synced to match (the slice is
operator state and is gitignored — the catalogue is the source). No
`kernel-divergence.txt` entry applies: the record guards the kernel slice
(`Core`, `Database`, `config`, `Frontend/packages`), not module catalogues.

**Verification gotcha worth knowing:** `php -S` runs under the CLI SAPI, where
`opcache.enable_cli=On` and `opcache.revalidate_freq=180` are both set — the
long-running dev server kept serving the pre-edit action, so the first run
read as "the fix did nothing". The files were correct the whole time; the
server needed a restart. Same class of trap as the Boot 1 opcache incident,
same lesson: restart before believing a null result.

# Boot 19 — CI, and the zero-dependency claim it falsified

`.github/workflows/ci.yml` — six jobs, each mirroring what the showcase's CI
does for its own tree, on the shape a fresh clone actually has (kernel-only:
`src/Modules/*` is gitignored operator state).

| job | what it asserts |
|---|---|
| Lint + PHPStan | `make lint` over every shipped `.php`, then `composer phpstan` |
| Code style | `composer cs-fix-dry` + `composer rector-dry` |
| PHPUnit | `composer test` in the kernel-only shape |
| Assets (pnpm) | the committed workspace builds; the admin manifest keeps its entry keys; no `@layer` survived (Chrome 70 drops the block) |
| Boot (no vendor) | no `vendor/`; `bin/cms migrate` + `module:list`; six surfaces over HTTP; a module installed → 200 → removed → 404; an empty error log |
| Extract-check | **not run** — the guard diffs against upstream (`evan70/nativa-minimal`), which is PRIVATE and unreachable with this run's `GITHUB_TOKEN`; the job states the measurement and how to enable it (Boot 20) |

## What the no-vendor job caught

**The kernel's headline claim was false.** A vendor-less checkout answered
**500 to every request** — `/` included, kernel-only included:

```
[FATAL] Error: Class "Symfony\Component\Console\Command\Command" not found
  in src/Modules/Core/Interfaces/CLI/Command/ServeCommand.php:24
  #2 Router.php(214): class_exists()
  #4 Router.php(40): Router->discover()
```

`Router` discovery read every file under the action dirs and
`class_exists()`-ed it, which makes each class's **parent** a load-time
dependency of *routing* — and at Boot 19 the kernel core itself shipped
three such classes (`MigrateCommand`, `ServeCommand`, the rest of
`Interfaces/CLI/Command/`), all extending `symfony/console`, a **dev-only**
dependency. The divergence record named it as the kernel's one inherited
defect (`Router.php # upstream task 0.3 loader guard not adopted here; Boot 1
 defect still inherited`). Those three are since deleted as dead code — the
guard is not, because module-level commands still extend symfony/console and
`$actionDirs` is the whole of `src/Modules`, route-less files included.

**Fixed by porting upstream's guard**: `Router::mayDeclareRoute()` reads the
file as TEXT and skips anything without a `#[Route(` token before any class is
loaded — permissive in the miss direction, because a file that cannot declare
a route cannot be a route. Our `Router` now differs from upstream in two
comment blocks and nothing else.

**Measured after the fix**, in copies of this tree with no `vendor/`:

| tree | before | after |
|---|---|---|
| kernel-only: `/`, `/sitemap.xml`, `/robots.txt`, `/docs` | 500 | 200 |
| kernel-only: `/nope`, `/mark` | 500 | 404 (never 403) |
| kernel-only: `module:add Scratch` → `/scratch` → `module:remove` | 500 | 200 → 404 |
| **14 modules installed**, no vendor: `/mark`, `/mark/categories` | 500 | 200 |
| `PHP Fatal error` / `[KERNEL] Error` lines in `storage/logs/php-error.log` | not counted before | 0 |

## What the kernel-only run of the suite found

The workstation and CI run the same tests over **different trees**: here
`src/Modules/` holds fourteen installed modules, there it holds `Core` +
`Database`. Three things only the second shape shows — each reproduced in a
copy of this tree with no `vendor/` and no installed slices:

1. **`module:add` failed intermittently** — `Cannot copy
   .../TempHolder/module.json`. `ModuleInstaller::copyTree()` never created
   its destination root, and the iterator yields a slice's contents in
   **readdir order**: a slice whose root holds a file before any directory
   handed that file over while the target did not exist yet. The lifecycle
   fixture is that shape (`module.json` beside `src/`), which is why the same
   test passed on one filesystem and failed on another. Fixed at the contract
   (`copyTree` creates `$to` first); no shipped catalogue entry triggers it
   today — all sixteen hold directories only — so this was a trap waiting for
   the first module that ships a README or a manifest.
2. **`phpstan` failed on the registry test** — five `class.notFound` errors
   for `Modules\Blog\Application\BlogServices`, in exactly the tree the test
   skips in. Solely a *static* problem: the runtime reaches an optional module
   through a class-name string (`LoginAction`'s idiom), but an analyser cannot
   be told "this file is not analysed when the class is absent". The test moved
   **to the module's own directory** (`modules/Blog/tests/`, a second PHPUnit
   suite) — `tests/` is analysed and `modules/` is not, so a test that names an
   optional module's class cannot break the analyser of a tree without that
   module. That is also where such a test belongs: beside the code it covers,
   in the committed catalogue.
3. **`composer test` reported 1 error, not a skip** — the copyTree failure
   above, surfacing through `ModuleLifecycleTest`'s round trip.

After the three: the same copy runs **18 tests / 32 assertions / 5 skipped**
(the Blog registry tests skip without Blog) and `phpstan` reports **no
errors** — against 18 / 45 / 1 and clean on the workstation. `cs-fix-dry`,
`rector-dry` and `make lint` are green in both.

The assets job needed its own correction, and the assertion caught it: the
Vite manifest is keyed by RESOLVED SOURCE PATH (`src/mark/index.css`), not by
the entry name, so the `has("mark")` assertion this workflow shipped with was
false on the first run — a red job for the wrong reason. It now matches the
entries' `name` field (with a negative control proving the query can fail) and
also asserts that the file the `mark` entry names exists.

## Two gates that could not fail

`make lint` ended in `grep -v 'No syntax errors' || true`: it printed every
parse error and exited **0**. It now sends `php -l`'s chatter to `/dev/null`,
lets parse errors reach stderr, and lets `xargs`'s status through — verified by
planting `<?php function broken( {` in `src/`: exit **2** before the fix read
`0`.

`composer cs-fix-dry` and `composer rector-dry` had **no config files** in the
slice, which is worse than a weak gate: the cs-fixer dry run *created* a
config and appended two lines to `.gitignore` — a check that mutates the tree
it checks — and Rector printed `No rector.php config found` and exited 0.

Both configs now exist, taken from the showcase (measured green there: 0 of
385 files) and scoped to the code this repo owns and may restyle:
`src/Modules/{Core,Database}`, `src/Themes`, `bin`, `public`, `tests` — the
same scope `phpstan.neon` analyses. Excluded, deliberately: `src/Modules/*`
elsewhere (installed slices — operator state, so the verdict would depend on
what is installed) and `modules/` (the verbatim catalogue — restyling it turns
the next re-import into a hand-merge).

Rector is **pinned to 2.6.1**, the version the showcase resolves. 2.7.0 adds
`StringClassNameToClassConstantRector`, which rewrote four files that are
byte-identical to the upstream slice — a dev-tool bump that would have bought
four recorded divergences for no behavioural gain. Bump it in a commit that
also restyles the slice and records what changed. The eight files the
gate *did* flag are all mine or the tree's (every one an `OWNED` or already
`DIVERGED` path), and they are fixed; `extract-kernel --check` stayed at
**250 identical / 38 recorded divergences** through all of it.

## Bootstrapper

```
[bootstrapper] No migrators found (add #[AsMigrator] classes under
               src/Modules/*/Infrastructure/Persistence/Migrations/).
```

Correct and expected: `Modules\Database` is the connection manager and the
migrator runner, not a table owner, and the kernel ships no migrations of its
own. A tree that created tables here would be creating a module's schema.

## Not yet in this tree

Present and verified: the kernel slice, the shell port, the kernel theme,
`src/init.php`, the composition root, `config/session.php`, the storage skeleton,
`composer.json` (zero production dependencies, dev-only tooling), the
`.env.example` for the keys the kernel reads, and the Lekalo skeleton
(`lekalo/project.yaml` + `targets/` + one `modules/<name>/module.yaml` per
`src/Modules` directory — 21 modules: `core`, `database`, `kernel_theme` and the
feature modules — id `nativa_core` — the IR grammar takes no hyphens — `lekalo.lock`
committed, `validate` + `validate --strict` green on CLI 0.6.5, 23 rules, 0 findings).

Present since 2026-10-11: the module catalogue (`modules/`, task 3.1–3.2) —
`ModuleCatalog`/`ModuleManifest` scan a slice as text, `bin/cms`
`module:list/show/add/remove` (plain PHP, zero dependencies) moves it, and
`modules/Scratch` is the lifecycle fixture whose round trip
(`docs/modules.md`) leaves the tree byte-identical — proven by
`ModuleLifecycleTest` since Boot 10, with `KernelOnlyBootTest` beside it
(`composer test`, 7 tests, 13 assertions).

Absent, deliberately: a real trimmed design for the kernel theme. It ships no
CSS, so a page it renders is valid and unstyled — plan task 2.2 wants a kernel
theme that was *designed*, not just one that works. (`bin/build/extract-kernel.sh`
+ `--check`, listed here as absent while it did not exist, arrived in Boot 11 and
runs as `make extract-check`; a CI run would need a credential for the private
upstream — see Boot 20, below.)

# Boot 20 — the extract-check linkage that was added and removed, and the four files upstream had not committed

The `extract-check` job printed `NOT RUN` and exited 0 because the guard diffs
this tree against `../nativa-minimal` and a runner has no checkout of it. The
attempt to make it real ended where it started — with `NOT RUN` — and both
halves are worth keeping, because the reason for the failure was measured late
and would have been cheap to guess wrong (it *was* guessed wrong first).

## The attempt

**Layout, first.** `actions/checkout` cannot produce a literal sibling — it
throws on any `path` outside the workspace (its source, `src/input-helper.ts`,
the `path` input: `Repository path '<resolved>' is not under
'<GITHUB_WORKSPACE>'`, compared as `(repositoryPath + sep).startsWith(
workspace + sep)` on absolute paths). So upstream went **inside** the
workspace (`.upstream/nativa-minimal`) and the guard was pointed at it with
`--minimal-root`, the override the script documents for CI. The nested tree is
inert for the diff: the guard walks only `SLICE_DIRS` + `SLICE_FILES`.

**It failed inside the action, and not because of the layout:**

```
Syncing repository: evan70/nativa-minimal
Determining the default branch
Error: Not Found - https://docs.github.com/rest/repos/repos#get-a-repository
```

**`evan70/nativa-minimal` is PRIVATE.** The run's `GITHUB_TOKEN` is scoped to
*this* repository, so `GET /repos/evan70/nativa-minimal` answers **404** — for a
private repository, even to a token that exists.

## How this got mis-diagnosed first (the useful part)

The measurement the attempt rested on was this one:

```
$ git ls-remote https://github.com/evan70/nativa-minimal HEAD
cc79d4ee994dda7ba2f563c8c224a34559cf032d	HEAD
```

Read as "a public read — the repository is reachable without credentials". It
is not a public read: **this machine's git config carries
`credential.helper = store`**, so the call was authenticated by a stored token
and said nothing about visibility. With the helpers out of the picture:

```
$ GIT_CONFIG_GLOBAL=/dev/null GIT_ASKPASS=/bin/true \
    git ls-remote https://github.com/evan70/nativa-minimal HEAD
remote: Repository not found.
fatal: Authentication failed for 'https://github.com/evan70/nativa-minimal/'
```

and the API agrees: `GET /repos/evan70/nativa-minimal` → **404** anonymously,
**200** with the owner's token, which reports `"private": true`.

The lesson generalises past this job: *a local check that passes is evidence
about the local environment until you know what else it used.* "Anonymous" in
a shell with a credential helper is a claim about the helper. The CI job was
that check, run in the one environment that does not have the operator's
credentials.

## Resolution: the linkage is deleted

Searching for a credential the runner has (a PAT secret) is a real option, and
it is written down in the job rather than done: a fine-grained PAT with
`contents: read` on the private upstream, stored as `UPSTREAM_TOKEN`, the
checkout with that token, the guard with `--minimal-root`. Until someone
decides to hold that secret, the job is `NOT RUN` again — now stating the
measured reason instead of "a runner has no checkout of it", which was true but
not the whole truth.

Where the gate *can* run it is unchanged and green: `make extract-check` on a
workstation, against the sibling checkout.

## What the attempt found anyway

Run against a **clone** of upstream — committed state, as opposed to the
sibling's working tree — the guard reported **4 unrecorded differences**:

```
DRIFT: Frontend/packages/jednotka/src/main.ts differs from upstream (not DIVERGED)
DRIFT: Frontend/packages/jednotka/src/jednotka/content.css …
DRIFT: Frontend/packages/jednotka/src/jednotka/toc.css …
DRIFT: Frontend/packages/jednotka/src/jednotka/tokens.css …
```

No one hand-edited them. `cmp` settles it in both directions:

| comparison | result |
|---|---|
| our file vs `../nativa-minimal/<file>` (working tree) | **identical** |
| our file vs `git -C ../nativa-minimal show HEAD:<file>` | differs |
| upstream working tree vs its own `HEAD` | differs (the four are `M` there) |

The import that first carried `Frontend/packages` read a checkout whose
jednotka edits were **not committed yet**. What is committed here is upstream's
next commit. The workstation's `make extract-check` could never see this — it
compares against the sibling's *working tree*, where the bytes match. A gate
that only ever runs where somebody's uncommitted work lives is a gate that
cannot report this.

**Resolution: recorded, with an expiry date.** The four paths are `DIVERGED`
in `bin/build/kernel-divergence.txt` under a section that states the expiry:
delete it once upstream commits them, because after that the two sides are
identical and a `DIVERGED` line would only hide later drift in four theme
files. The section also carries the reproduction — `git clone --depth 1` of
upstream, then `--check --minimal-root` at the clone — because that comparison
is the only one that shows them. Counts with the section in place: **246
identical / 42 recorded** (was 250/38).

## Verified

| check | result |
|---|---|
| guard against a fresh clone of upstream, four entries recorded | `OK (246 identical, 42 recorded divergences)`, exit **0** |
| the same run with those four lines removed | exit **1**, `4 unrecorded difference(s)` — the entries are load-bearing, and the finding reproduces without CI |
| `make extract-check` (sibling working tree) | `OK (246 identical, 42 recorded)`, exit **0** |
| the job's `NOT RUN` step, extracted from the workflow and run | exit **0**, prints the private-repo reason and the way to enable it |
| the job's steps | `Checkout code` (this repo) + that step; no second repository, no `secrets.` reference anywhere in the workflow |
| anonymised upstream access | `Repository not found` / API 404 without a token; 200 with the owner's, `"private": true` |

The planted-edit negative control from the previous version of this section no
longer applies to CI (there is no guard step there any more); it still holds for
the clone-based run above.

# Boot 21 — an article written by the module, and a dist you can deploy

Two deliverables, one artifact each: content created through the Blog module's
own write path, and a deployable tree produced by a packaging script.

## The article

`storage/blog/nativa-php-core.md` — a Slovak article about this kernel,
discovered at `/blog` and served at `/blog/nativa-php-core` (200; ten `##`
sections; the feed carries it).

Three decisions worth recording, because each one could have been wrong quietly:

- **It was written by `BlogCrudService::save()`**, not by hand-editing a file.
  The module owns the front-matter serialization, and a hand-written header
  would be a second implementation of it that only *looks* the same until a key
  changes.
- **The category was registered first**, through `BlogServices::taxonomy()`
  (`storage/data/blog-taxonomy.json` now holds `Architektúra`), which is the
  order the `/mark/categories` surface assumes.
- **`author` is the display name and `author_email` is the login.** The admin
  create action writes the *login* into `author` (`ArticlesCreateAction::buildMeta()`),
  and the article page passes `author` straight into the JSON-LD — so an
  article created by that form publishes its author's e-mail address in
  structured data. Measured in the showcase's own content: 210 articles carry
  `author: "Nativa Dev"` against 4 carrying an address. The article follows the
  content convention, and the first draft here demonstrated the failure mode
  before it was fixed (`"author":{"@type":"Person","name":"nativa.dev@example.test"}`).

That last one is a real defect in the admin form, not a content problem. It is
recorded here rather than fixed: the fix belongs to the action (stamp the
resolved user's name next to the address it already stamps), and it is an
upstream file — changing it here would add a divergence entry for a bug that
should be fixed once, where it lives.

## The dist

`bin/build/build-dist.sh` — ported from upstream's script of the same name,
which produces exactly this kind of artifact (all real file copies, no symlinks,
so a plain FTP upload works), with three differences that are the kernel's:

1. **`bin/cms` stays.** Upstream deletes its console from the dist because that
   console extends `symfony/console`, a dev dependency. Here the console *is*
   the production tool (`module:add/remove`, `migrate`) and it is plain PHP by
   design — a dist that dropped it could not manage its own modules.
2. **Assets are not rebuilt inside the script.** The pnpm workspace is committed
   and `make assets` is the one build path, so the script delegates instead of
   keeping a second copy of the pnpm invocation. `--skip-assets` uses the
   existing `public/dist` and *fails* when there is none, rather than shipping a
   dist with no stylesheet.
3. **Databases are migrated, never seeded or copied.** The kernel has no
   seeders, and the developer's `storage/data/` is local state. `php bin/cms
   migrate` runs inside the artifact: nine databases, real schemas, fresh
   `config.db` defaults, 168 locale rows — and it doubles as the proof that the
   artifact boots with no `vendor/` at all.

What it ships: `src/` (the installed slices travel with the tree), `config/`,
`public/` (including the built `dist/`), `bin/cms`, `modules/` (the catalogue,
so the deployed tree can still manage modules), `docs/`, `README.md`,
`.env.example`, a fresh storage skeleton, content from `storage/{blog,pages,blog-images}`,
and `.build-info`. What it excludes, with a reason per line in the script:
history, CI, `tests/`, `Frontend/` sources, `vendor/`, `bin/build/`, the dev
tool configs, `.env*`, runtime storage state, the Lekalo model and the repo's
own ignore rules.

## Measured, end to end

```
bin/build/build-dist.sh          # 3.0 s including the asset build
  → dist/  5.3 MB, 630 files, vendor/: absent
  ok  /  200   /sitemap.xml  200   /robots.txt  200   /docs  200   /nope  404
  --  /mark  200   (the admin IS installed in this tree; not asserted)
  error log is clean
```

Then, against the artifact itself rather than the source tree:

| check | result |
|---|---|
| `GET /blog/nativa-php-core` from `dist/public` | 200, H1 + 10 sections |
| `php dist/bin/cms module:add Scratch` → `GET /scratch` | 200 |
| `php dist/bin/cms module:remove Scratch` → `GET /scratch` | 404 |
| `dist/vendor` | does not exist — and the `/mark` 200 above is the admin module serving with no Composer packages at all |

The last row is the interesting one: the admin theme's stylesheet comes from
`public/dist/moonshine/manifest.json`, so that page is the artifact's own proof
that `make assets` output, the module catalogue, the migrator runner and the
zero-dependency runtime all work in the same tree.

## Two `Modules` trees — and why the artifact ships one of them

The first version of this script shipped both, and the first person to open
`dist/` asked the obvious question: *"modules twice?"*. They are not copies of
each other — but the artifact does not need both, and the default now ships
one:

| path in the repo | what it is | size | shipped by default |
|---|---|---|---|
| `modules/<Name>/` | the **catalogue**: slices waiting to be installed | 1.8 MB, 16 | **no** (`--with-catalogue`) |
| `src/Modules/<Name>/` | the **installed copies**: what actually runs | 2.3 MB, 14 | yes |

Measured overlap, in the build that does ship both: **12 of the 14 installed slices are byte-identical to their
catalogue counterpart** (815 KiB of payload exists twice). Two differ, each for
a reason that is state, not a bug:

- `Blog` — the catalogue gained `tests/` this session; the installed copy
  predates it. (`diff -rq`: *Only in modules/Blog: tests*.)
- `Cardboard` — the installed copy still carries six JS files under
  `Interfaces/HTTP/assets/scripts/` that the catalogue deliberately dropped in
  `bbac4ee` ("kit assets un-coupled"), and **nothing references them**: a grep
  for `admin-shell.js`, `admin-theme-init.js`, `bulk-actions.js`,
  `compact-shell.js` across the module's PHP and templates finds no call site.
  They are stale leftovers in the working tree, not a missing file in the
  catalogue — a fresh `module:add Cardboard` produces the leaner, intended
  slice.

**Default: `modules/` is not shipped.** A deployment runs what is installed;
installing happens in the repo, which is where the catalogue belongs (it is
source, not runtime). Measured: **3.6 MB / 384 files**, and `dist/` carries the
word Modules once — `src/Modules/`. The capability is gone *cleanly* rather than
half-broken: `module:list` reports `Catalogued (0): (empty catalog)`,
`module:add Scratch` exits **1** with `cms: Module 'Scratch' is not in the
catalog (modules/Scratch/).`, and `module:remove` — which only deletes — still
works (verified by removing `Mono` from the artifact; two assertions, not one:
the command exits 0 and the directory is gone).

**`--with-catalogue` is the opt-in**, for a deployment that must install
modules on the target: **5.3 MB / 630 files**, and it reports the duplication it
brings instead of leaving it to be discovered inside the artifact:

```
==> Catalogue in the artifact (module:add works here)
    note: Blog — installed copy DIFFERS from the catalogue
    note: Cardboard — installed copy DIFFERS from the catalogue
    12 slice(s) byte-identical to the installed copy (a second copy of what runs)
    2 drifted, 2 not installed
```

# Boot 22 — testing the `erik` theme, and the asset defect it found

The question was "can we test the erik theme too?" — and testing it is what
found the bug, because the theme **rendered**: `er-` classes, correct markup,
200 everywhere. What it did not do was load its own stylesheet.

## What the theme is

`erik` is not a kernel theme. It arrives with the `Erik` module (3 files:
`Theme/ErikTheme.php`, `assets/critical/erik.css`,
`Interfaces/HTTP/ErikShellContextProvider.php`), and installing that module is
what makes it active:

```
php bin/cms module:add Erik          # 3 file(s) copied
ThemeRegistry::all()  → erik, mono
ThemeKernel::for('erik') → Modules\Erik\Theme\ErikTheme   (bundle=erik, prefix=er)
```

Note what that says about this tree: `settings.theme` is still `kernel`, and
`ThemeResolver::resolvePath('/')` answers `erik` anyway — the Erik **and** Mono
modules both ship a provider that hardcodes its theme
(`#[AsShellContextProvider(priority: 0)]`, "a real deployment answers `theme()`
from its settings module instead" — the Erik provider's own comment). Providers
win over the setting, first non-null in (priority, discovery) order, so with
both installed `erik` is active; removing Erik makes **`mono`** active, not the
kernel theme. The kernel theme is what you get when neither is installed.

## The defect: the built stylesheet was never used

`FrontendLayout` inlines the theme's CSS rather than linking it (deliberate —
it removes a render-blocking request). It asks `AssetManager` for the active
theme's name (`$themeModule->name()`, which is also what names the body class
`<body class="erik-shell">`), and `AssetManager::knownBundles()` was:

```php
[ ThemeResolver::ADMIN_THEME, ...ThemeResolver::PUBLIC_WHITELIST, 'minimal' ]
```

— a **static** list: `moonshine`, `kernel`, `minimal`. `erik` is not in it, so
the bundle resolved to `kernel`, the manifest under
`public/dist/kernel/manifest.json` does not exist, and the layout fell back to
the theme's critical file. Measured on `/`:

| | inlined CSS | `.er-` rules |
|---|---|---|
| before | **706 chars** (the 1119-byte critical file, minified) | 12 |
| after | **35 261 chars** — byte-equal to `public/dist/erik/assets/erik-A5GsJi84.css` | 412 |

A page styled with 2 % of its stylesheet and nothing in the log to say so. It is
the same shape as the defect `ThemeKernel`'s docblock already warns about (a
theme served another theme's stylesheet is "indistinguishable from a page that
works"), one layer down: this time the fallback was *correct* for the kernel's
own theme and silently starved a discovered one.

## The fix

The bundles this tree has are discovered the way its themes are.
`ThemeRegistry::bundles()` asks each discovered theme for `bundle()` — asked,
not derived from the theme's name, because the contract has both for a reason —
skips a theme that cannot be constructed (the same rule `all()` already
follows), and memoizes, since `AssetManager` reads the list from its
constructor (200 calls: 1.75 ms). `AssetManager::knownBundles()` adds it to the
static three. `ThemeRegistry::reset()` clears the memo alongside `$themes`.

Verified after the fix: `/`, `/blog`, `/blog/nativa-php-core`, `/docs` all
inline the 35 261-char build (412 `.er-` rules) and the `erik` JS bundle is
referenced; `/nope` still 404s; the error log stays empty. Removing Erik returns
the tree to `mono` with 19 058 chars of its own critical CSS and no `.er-`
rules — the discovery is not sticky. `AssetManager('kernel')` still reports
`available() = no` (there is no `public/dist/kernel/`), so the kernel-only tree
keeps the critical-CSS fallback it had.

## The tests

Two files, split the way this tree splits tests. `tests/` is inside PHPStan's
scope and `modules/` is not, so the half that names `ErikTheme` lives beside
the module and the half that names only kernel classes lives in the harness.

`modules/Erik/tests/ErikThemeTest.php` (the fixture): the theme is discovered,
`name()` and `bundle()` both answer `erik`, the bundle is in
`AssetManager::knownBundles()`, the rendered `<style>` block is byte-equal to
`public/dist/erik/assets/erik-A5GsJi84.css` **and** not the critical subset
(which is named explicitly, so the assertion cannot pass by the fallback
growing), the `.er-` vocabulary is present in quantity, the document wears
`erik-shell` + `er-header`/`er-main`/`er-footer` and no sibling's `k-`/`mo-`
class, and the live homepage — dispatched through `Kernel` — carries the
active theme's build.

`tests/Theme/ThemeBundleDiscoveryTest.php` (the door, closed for the next
theme): every theme `ThemeRegistry` discovers must declare a bundle
`AssetManager` knows, and every discovered theme whose build sits under
`public/dist/` must render a document containing that build's bytes. It walks
the same discovery `ThemeResolver` uses — and holds the same "no module class
names" line as every other file under `tests/` — so it runs and passes in the
kernel-only tree too, where the discovered set is simply empty.

Two details in those files are load-bearing, and both were measured:

- **The skip guard is the manifest on disk, never `AssetManager::isAvailable()`.**
  The first draft guarded the byte-parity assertions on `isAvailable()` — the
  exact call the defect breaks — so with the fix removed the test *skipped*
  instead of failing. Guarding on the path makes it fail, which is the whole
  job.
- **The live-surface test asserts against the resolver's answer, not against
  `erik`.** Installing another provider re-points the test instead of breaking
  it, while the defect still fails it.

Five tests now fail with the fix removed and pass with it in place — checked by
reverting only the `...ThemeRegistry::bundles()` line:

| | without the fix | with the fix |
|---|---|---|
| `ErikThemeTest::themeIsDiscoveredAndItsBundleIsKnownToAssetManager` | fails | passes |
| `ErikThemeTest::layoutInlinesTheBuiltStylesheetAndNotTheCriticalSubset` | fails | passes |
| `ErikThemeTest::liveSurfacesInlineTheActiveThemesOwnBuild` | fails | passes |
| `ThemeBundleDiscoveryTest::everyDiscoveredThemesBundleIsAKnownAssetBundle` | fails (`erik`, `mono`) | passes |
| `ThemeBundleDiscoveryTest::everyDiscoveredThemeWithABuildRendersItsOwnStylesheet` | fails | passes |

The whole suite: 24 tests, 76 assertions, 1 skip (the pre-existing Cardboard
one) — plus `phpstan`, `rector --dry-run` and `php-cs-fixer --dry-run` clean.

One gap the second test now covers but the kernel does not yet close: nothing
today has `name() !== bundle()`, while `FrontendLayout` hands `name()` to
`AssetManager` where the contract offers `bundle()`. A theme that renames its
bundle would be served under its name — the same wrong-stylesheet shape, one
step further in. The guard fails on it (`"does not inline bundle X"`) instead
of staying silent, which is why the assertion was written against the
declared bundle rather than against the theme name.

## State of the tree afterwards

Erik was installed to run this test and **uninstalled again** afterwards —
`bin/cms module:remove Erik`, which is a change to `src/Modules/` and therefore
gitignored. The live theme is `mono` once more: `/`, `/blog`, `/docs` all serve
its own 19 056-char critical CSS (211 `.mo-` rules, zero `.er-`), body
`mono-shell`, and `/nope` still answers 404.

That is why the erik suite SKIPS in this working copy — `ErikThemeTest` and the
build half of `ThemeBundleDiscoveryTest` guard on `src/Modules/Erik` being
installed, exactly as `TaxonomyRegistryTest` guards on Blog. Suite in this
state: 24 tests, 49 assertions, 6 skips. `bin/cms module:add Erik` puts it back
and all six run.

# Boot 23 — the menu nobody asked for (2026-10-12)

The Mono work ended with a styled page and a navbar carrying a brand and
nothing else. The vendored CSS was fine; the document simply had no
`mo-navbar__menu` in it. Asked to "add the nav menu", the honest first step was
to find out who was supposed to answer it — and the answer was nobody.

## What was missing

Boot 3 moved navigation behind the shell port. Upstream, `FrontendLayout` read
it straight from `SettingsServices::menu()->menu('header'|'footer', $path)`;
here that call became `ShellContextProviderRegistry::navigation()`, whose merge
rule is **first provider that returns a non-empty list for that slot** — and no
installed module implemented `navigation()` at all. Only the two theme fixtures
did, and both answer `[]` on purpose. The rule is also the reason the failure
was invisible: an empty answer *is* the "no menu" answer, so an unasked question
renders exactly like a site with no menu configured.

Measured: `header: 0 items`, `footer: 0 items` on every path, and
`mo-navbar__menu` absent from `/`, `/blog`, `/docs` and the article.

## The fix

`Settings` owns the `menu_items` table, its `MenuService` already builds the
nested tree, filters `is_active` and marks the current path active — it was the
owner without a voice. So it got one:

`modules/Settings/Interfaces/HTTP/SettingsShellContextProvider.php` (+ the
installed copy under `src/Modules/Settings/`), `#[AsShellContextProvider]`,
answering `navigation()` only. Site name, theme and locale are answered by other
providers; a second answer would be a second source. `MenuItemDTO` → `ShellNavItem`
is a straight map (label, href, active, external, children one level down).

The rows come from the same seed upstream has: header `Home /`, `Articles /blog`,
`Admin /mark`; footer `Privacy /privacy`, `Cookies /cookies`. `Admin` in a public
navbar is upstream's own seed, kept as parity — hiding it is an operator choice
in `/mark/settings`, not a kernel decision. **(Boot 24 revised this: for a reader
who is not signed in the item is a 302 to `/login`, so the provider now withholds
admin hrefs from anonymous visitors and keeps them for editors.)**

Cost, because the nav is now read on every public render: **0.24 ms per page**
for both slots (1000 iterations, SQLite, `SettingsServices` memoizes the
service), against 0.63 ms for a full mono render.

## The trap it sprung

The provider was written, copied, and **not discovered**: the registry still
listed one provider, and the menu stayed empty. The cause was the discovery
mechanism itself. `ShellContextProviderRegistry::load()` resolves the FQCN with
the FIRST `class <name>` match in the file — a text-first rule, the same shape
as `Router::mayDeclareRoute` — and the docblock's phrase "This class says the one
thing…" made it resolve a type named `says`. The class did not exist, so the file
was skipped, silently, with the menu still gone.

Rewording is the whole fix here (the note is now phrased without the keyword),
and it is what surfaced the fragility: **prose can shadow discovery in any of
this tree's text-first registries**, and the failure is invisible. The robust
change — anchor the pattern to a declaration line rather than the first mention —
touches `ShellContextProviderRegistry`, `ThemeRegistry` and `MiddlewareRegistry`,
and is deliberately NOT in this commit; it is recorded here as a known trap.
**(Boot 24 closed it — and found a fourth copy of the resolver in
`MarkdownBlockRegistry`.)**

## Mono's navbar on a phone

Mono's vendored CSS hides `.mo-navbar__menu` below 768px and opens it by adding
`mo-navbar--menu-open` from the live site's JavaScript — which this tree does not
ship (mono has no build; `criticalCss()` is the whole theme). So the menu the
port had just filled would have been unreachable on a narrow screen. One media
query in `mono.css` shows it inline and scrollable under 768px instead:no JS, no markup change, the same always-visible shape the kernel theme's `k-nav`
already has. Desktop is untouched. **(Boot 25 replaced the always-visible strip
with the theme's own meatball — a checkbox plus a label, still no JS.)**

## Verified

- registry discovers 2 providers; `header: [*Home → /] [Articles → /blog] [Admin → /mark]`, `footer: [Privacy] [Cookies]`; `/` marks Home active, `/blog` and the article mark Articles;
- live on `:8000`: `/`, `/blog`, `/blog/nativa-php-core`, `/docs` all 200, each with `mo-navbar__menu` and the active link correct;
- the same rows render in the kernel theme (`k-nav`) and its footer — the port feeds every theme, not just the one that prompted this;
- chromium at **390px and 1280px**: menu `display:flex`, three links, inside the viewport, nothing clipped;
- `phpunit` 28/28 (6 skips), `phpstan`, `rector --dry-run`, `php-cs-fixer --dry-run` clean; `extract-kernel --check` OK (246 identical, 42 recorded divergences — the new file needed no entry).
- 4 of those tests are new (`modules/Settings/tests/SettingsShellContextProviderTest.php`): the header and footer answers, the active item under a nested path, and the abstentions. They build their own temp `config.db` with the Settings migrator — not `DatabaseMigrator`, which would run every module's migrator and touch the working tree's real databases — and **3 of the 4 fail when `navigation()` is reverted to `[]`**, so the silent-empty answer that caused this cannot come back unnoticed.

# Boot 24 — the admin link a visitor may not see, and the prose that ate discovery (2026-10-12)

Two asks: hide the admin link from anonymous readers while editors keep it, and
close the trap Boot 23 recorded and left open. The first one turned out to have a
second half nobody had asked for.

## The admin link

Boot 23 left `Admin` (→ `/mark`) in the header for everyone, on the grounds that
hiding it was an operator's choice in `/mark/settings` rather than a kernel
decision. That reading missed a direction: for a reader who is not signed in the
item is a 302 to `/login` — a dead end dressed as navigation, in every theme, on
every public page. So the provider that answers the slot now answers it per
visitor: an item whose href is `/mark` or under it is dropped unless the visitor
is signed in, children included by the same rule however deep the menu nests (a
nested `Moderation → /mark/comments` goes with it while its parent stays).

"Signed in" is read the way the kernel already reads it — `$_SESSION['auth']['user_id']`,
behind the guard that keeps a public page from handing a session to a reader who
never logged in (`FrontendLayout::sessionHasAuth()`). The auth PORT is not asked
because its `identity()` takes a `Request` and the shell port carries none:
asking would mean inventing a second answer to a question the kernel already
answers for itself.

**Measured on the live server**, with a real session in `storage/sessions` and its
cookie:

| request | navbar |
|---|---|
| guest `GET /blog` | `['Home', 'Articles']` |
| editor `GET /blog/nativa-php-core` | `['Home', 'Articles', 'Admin']` |
| guest `GET /blog/nativa-php-core` | `['Home', 'Articles']` |

## The leak the nav half exposed

Verifying that table found the other half: a page cache that does not know WHO a
page was rendered for. `PageCacheService` keys an entry by method + path + query +
locale and `PageCacheMiddleware` writes one on every cacheable response — while
the shell chrome advertises `/mark` to exactly one class of visitor. An editor's
`/` stored under that shared key is an anonymous reader being handed a link to the
admin surface, and it is not served back to the session that produced it:
`RateLimitMiddleware` serves the cache to **whoever trips the limit**.

`isCacheable()` now refuses a response rendered for a signed-in visitor
(`renderedForASignedInVisitor()` — same session read, same guard). The anonymous
render of the same URL is still cached: a policy that quietly stopped caching the
site would silence the leak and this test with it.

**This tree never populated that cache**: `PageCacheMiddleware` needs a
`PageCacheService`, so `#[AsMiddleware]` discovery skips it (zero-argument classes
only) and the kernel-only root wires nothing by hand — `storage/cache/pages` held
0 files after every request above. Upstream is the other case:
`../nativa-minimal/public/index.php:127` constructs it. The rule is latent here
and live there, which is the shape a kernel-owned policy has to be written for.
`PageCacheService.php` is now a recorded DIVERGED file.

## The prose trap, closed

Boot 23 recorded the discovery defect and deliberately did not fix it. Four
registries resolve an FQCN from file text the same way —
`ShellContextProviderRegistry`, `ThemeRegistry`, `MiddlewareRegistry` and
`MarkdownBlockRegistry` — and all four took the FIRST `class <name>` mention in
the file, so a docblock sentence ("This class says the one thing…") resolved a
type named `says`; the class did not exist, the file was skipped, and the
provider, theme or middleware disappeared with no error and no log line.

A declaration now has to START something: the pattern is anchored to a line start
(or a statement, after `;`) with the modifiers PHP allows in front
(`final|abstract|readonly`). The `;` half is not decoration — a compact but legal
`namespace Demo; class Thing {}` has to keep resolving, and too strict a heuristic
trades one silent skip for another, which is the failure mode this whole exercise
is about.

`tests/Discovery/TextFirstFqcnTest.php` pins all four copies at once (12 cases:
both prose-shadowing halves, one real declaration shape per modifier, and a file
with no declaration answering null rather than guessing). Against `HEAD`'s
resolver **3 of the 12 fail** — the docblock's own phrase "a class of things"
resolving as the type name `Demo\of`.

## Verified

- live `:8000`, fresh server — opcache's 180s revalidate means a running server answers from the front of the last three minutes, so every response above was read after a restart;
- guest/editor/guest navbar exactly as tabulated, `200` on all three, nothing in the server log;
- `storage/cache/pages`: 0 files after a guest render and 0 after an editor render — the middleware is not wired here, which is why the rule is recorded rather than demonstrated in this tree;
- `phpunit` 51 tests (6 skipped), `phpstan`, `rector --dry-run`, `php -l` and `php-cs-fixer --dry-run` (84 files) clean;
- the negative checks, so the two new rules cannot be dropped unnoticed: with the cache rule removed **2 of the 8** `tests/Cache/PageCacheServiceTest.php` cases fail (the editor's page lands in the cache), and with `HEAD`'s resolver **3 of the 12** discovery cases fail;
- `extract-kernel --check` OK (243 identical, 45 recorded divergences) — the three kernel files this boot rewrote (`PageCacheService`, `MiddlewareRegistry`, `MarkdownBlockRegistry`) are recorded as DIVERGED with their reasons. Upstream still carries both defects; the record is what says so.

# Boot 25 — mono gets the meatball, and two seeded links that were 404s (2026-10-12)

Reported as "the mono nav has no menu on mobile; cardboard has one". Measured
first, and the strip Boot 23 added **was** there in this checkout: at 390px and
320px on `/`, `/blog`, the article and `/docs`, `.mo-navbar__menu` computed
`display:flex` with its links visible, unoccluded (`elementFromPoint` returned
the link itself), for guests and for a signed-in editor — and the rule that does
it is in the served bytes. So the report was really about *pattern*, not
presence: cardboard's nav is a button that opens a drawer, and mono's was a
permanently visible swipeable strip.

## What mono does now

The theme's own meatball — the vendored snapshot already ships
`.mo-navbar__toggle`, `--meatball`, `--close` and `mo-icon-btn`, and expects the
live site's JS to add `mo-navbar--menu-open`; this tree ships no JS, so the open
state is a **form control** and the styling keys off `:checked`, in the idiom the
shared layout uses for the starter nav and Erik repeats in `er-nav__toggle-input`:

- `MonoTheme::menuToggleInput()` renders a visually hidden
  `input[type=checkbox]#mo-nav-toggle`, named by `aria-label` (both are dropped
  when the header has no items — a toggle that opens nothing is worse than none);
- `MonoTheme::menuToggleLabel()` renders the meatball + close icons as a `label`
  in the actions cluster;
- `mono.css` keys the open state on `.mo-navbar__toggle-input:checked ~ …`.

The mechanism rests on **DOM order**: one `~` selector has to reach both the menu
and the label from the input, which only works while the input is first and the
label comes after the menu. `modules/Mono/tests/MonoThemeTest.php` pins that
order, the checkbox contract and the `:checked` CSS, because nothing in a
stylesheet enforces it.

The open state is deliberately scoped to `@media (max-width:767px)`: without the
query, a menu left checked in portrait would follow the reader into landscape as
an absolute overlay — and the control that could close it, the meatball, is
hidden from 768px up.

## Measured (chromium, fresh server)

| width | closed | after tapping the meatball | desktop |
|---|---|---|---|
| 390px | menu `none`, toggle `flex`, meatball `block`, close `none` | `checked`, menu `flex`, 2 links visible, meatball `none`, close `block` | 1280px: menu `flex`, toggle `none` |
| 320px | same | same (signed in: Home, Articles, Admin — all inside the opened panel, none clipped) | — |

Also: `Tab` reaches the checkbox (first focusable in the navbar), `Space` opens
the menu, and following a link leaves the state behind (`checked:false`, menu
`none` on the next page — it is markup state, not persisted). The focus ring
rule matches and changes the computed style (deleting it flips `outline-style`
from `solid` to `none`); the headless build reports `outline-width: 0px` even for
an inline literal, so the ring was verified by matched-rule, not by pixels.

## Two seeded menu links that were dead ends

While sweeping the nav for this, every path in it was requested: the seed's
footer rows `Privacy -> /privacy` and `Cookies -> /cookies` **both 404** in this
tree — there is no route for either (the kernel serves `/`, `/blog`, `/docs`,
`/mark`, `/storage/*`, the feeds and the auth pages). A fresh install therefore
shipped a footer with two dead links.

The seed now carries one row the kernel can serve — `Docs -> /docs` — and the
rule that produced it is pinned by
`SettingsShellContextProviderTest::theSeedAdvertisesNoRouteTheKernelDoesNotServe()`:
*the seed may only advertise routes the tree it ships to serves.* A deployment
with its own legal pages adds them in `/mark` → Navigation; the seed is a
starting point, not a policy. Existing databases keep their rows — the migrator
seeds idempotently and never rewrites what an operator may have edited.

## Verified

- live `:8000` (restarted, so no opcache staleness): toggle closed → open → closed at 390px and 320px, guest and editor, on the homepage;
- keyboard: `Tab` → checkbox focused, `Space` → menu opens;
- 1280px unchanged: menu visible, toggle `display:none`;
- `phpunit` 56 tests (6 skipped), `phpstan`, `rector --dry-run`, `php-cs-fixer --dry-run` and `php -l` clean; `extract-kernel --check` unchanged (the mono theme and the Settings migrator are module-owned, not part of the kernel slice).

# Boot 20 — CardboardKit merges into Cardboard (single admin module)

Boot 14 split the admin overlay into a leaf kit + admin app to break the
`Cardboard ↔ everything` cycle. The two namespaces (`CardboardKit` vs
`Cardboard`, including `CardboardRegistry` living in the Kit and `Sparkline`
living in the app) read as duplication. Decision: **only `Cardboard` stays**.

What moved (catalogue `modules/`, synced verbatim into `src/Modules/`):

- Kit files → same relative paths under `Cardboard/` (`Domain/Model`,
  `Domain/Contracts/Chart+Metric`, `Application/Services/AdminLocale,
  IconRegistry, BulkActions`, `Interfaces/HTTP/AdminLayout+CompactLayout,
  templates, assets/scripts`, `Infrastructure/Attributes/AsMetricProvider,
  Registry/*`). `Sparkline/Bar/Donut` finally sit side by side.
- Deleted as a port: `AdminContextProvider`, `AsAdminContextProvider`,
  `AdminContextRegistry`, `CardboardAdminContext`. The layout calls its own
  `CardboardServices` directly (try/catch → `Guest`/sidebar/`[]` fallback).
- `modules/CardboardKit/` deleted.

Cycle discipline (the catalog derives closures from `^use Modules\` lines):

- Cardboard keeps `use` only for self, `Core`, `Database`, `Security`,
  `Settings`, `Outbox` — none of which import Cardboard back.
- `Audit/Blog/Media/Pages/Users` references inside Cardboard are
  fully-qualified inline (same runtime behaviour with the full set
  installed, invisible to the closure scanner). `AdminNavService` and
  `BulkEntityGate` carry local permission-string tables for the same reason.
- Measured closures: Cardboard → `Outbox, Security, Settings`;
  Blog/Pages/Users/Audit/Media → Cardboard. Acyclic; `module:show` green.

## Verified

- `composer phpstan` clean, `composer test` 56/147 (6 skipped, same skips),
  `php -l` on all touched files clean;
- live `php -S` (fresh, restarted): `/mark` 200, `/mark/users` 200, sidebar
  chrome + `mark` CSS + `Guest` fallback, 0× `CardboardKit` in the HTML,
  0× `[KERNEL] Error`;
- `module:show Cardboard` routes (6× `/mark/*`) + assets (9× scripts) intact.

# Kernel contracts — the shape that can shrink but not silently grow (2026-10-15)

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

## 1 — Measure before touching anything

`bin/build/kernel-size.sh` + `make kernel-size` print the per-layer bytes, the
file counts and the interface/concrete split on demand, with `baseline:
338192 B / 79 files` as a footer so any run diffs against the plan in one line.
`docs/kernel-shape.md` carries one row per Core file — bytes, layer, type,
importers, bucket (`contract` / `runtime` / `tooling` / `dead`) — and the bucket
column is what the shape guard reads as the approval record.

## 2 — The guard that did not exist here

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

## 3 — What the kernel never calls

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

## 4 — The tooling leaves `Modules\Core`

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

## 5 — Locking the shape

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

## 6 — The gate nobody wired (2026-10-15, `fix/mark-access-control`)

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

# 7 — The reset token in the log (2026-10-15, security-audit finding #1)

The audit of the public routes and the CSRF flow left one medium finding that
mattered more than its label: `PasswordResetService::requestReset()` appended
the plaintext reset token — beside the address it belongs to — to
`storage/logs/app.log` on every request, **in every environment**, and the
success page named the file for the visitor. The comment above it said "dev
mailer"; the code carried no gate at all, and `.env.example` ships `APP_ENV`
that nothing in this flow read. Reading the log was enough to take over any
account that had asked for a reset.

Reproduced first, through the real route with `APP_ENV=production` (php -S,
port 8177 — the probe script lives in `/tmp`, not in the tree):

```
POST /forgot-password (APP_ENV=production) => HTTP 200
--- response alert ---
If an account exists for that email, a reset link has been logged by the dev mailer (storage/logs/app.log).
--- new app.log lines ---
[2026-10-15 23:30:10] [mailer:dev] Password reset for admin@example.test → token: <64 hex> (valid 1 h)
exit 1
```

## The fix: delivery moves behind a port

`PasswordResetMailer` (`Domain/Contracts`) owns delivery. Two implementations:

- `DevLogPasswordResetMailer` — the development transport: the same log line as
  before, with the log file **injected** rather than resolved inside, so the
  suite can assert what lands in it on a file the test owns;
- `NullPasswordResetMailer` — what production gets: nothing is delivered and
  nothing is written, while the answer stays exactly as neutral as before (no
  enumeration oracle either way). The drop is noted at `LOG_LEVEL=debug`.

`PasswordResetMailerFactory` is the ONE place that picks between them, from a
**whitelist** of environment names (`development`, `local`, `testing`, `test`,
`dev`) rather than "everything that is not production": `staging`, a typo, or
an environment nobody classified must not be the one that keeps a credential on
disk. `requestReset()` returns `void` now — the raw token leaves the service
only through the port, so no caller can decide to record it. `AuthServices::resets()`
composes the two, which is where the environment fact is read exactly once.

Two consequences worth naming:

- `APP_ENV` is now read by two gates (HSTS, this one), both treating unset as
  development — that default is what makes a fresh checkout work without a
  `.env`. `.env.example` now says what each value means for reset links, and
  which class to implement to deliver them in production (the kernel ships no
  mail transport).
- The success page names `storage/logs/app.log` only where the link really is,
  from the same `writesToLog()` the transport choice comes from. A production
  visitor is no longer pointed at — or told about — a log file.

**The trap this round walked into:** composer resolves `Modules\` to
`src/Modules/`, so the INSTALLED slice is what runs and what the tests load;
`modules/Users/…` on its own is invisible until re-installed. Verified with
`ReflectionClass::getFileName()`. The sync used the documented path —
`bin/cms module:remove Users --force` (refused otherwise: fourteen files across
Audit/Blog/Comments/Questions import Users) then `bin/cms module:add Users` —
and `diff -rq modules/Users src/Modules/Users` is now empty.

## Verified (2026-10-15, plaintext reset token, all exit 0)

| Gate | Result |
|---|---|
| probe, before the fix | exit 1 — `[mailer:dev] … token: <64 hex>` line in `storage/logs/app.log`, page naming it |
| probe, after the fix | exit 0 — HTTP 200, "…a reset link has been sent.", `(no new lines)` |
| `PasswordResetMailerTest` (new) | 5 tests green; mutation-checked — forcing `create()` to always return the dev transport fails the production case with the leaked token line in the diff |
| `composer test --filter PasswordReset` | 10 tests, 39 assertions, 0 failures |
| `composer test` (full suite) | 289 tests, 1215 assertions, 1 skip |
| `php -l` on the 9 changed files | clean |
| `composer phpstan` (gate scope) | level 9, no errors |
| phpstan level 9 on `src/Modules/Users` (informational — the gate excludes installed slices) | 2 errors, both pre-existing in `UsersAuthProviderTest` (committed on `feature/authprovider-discovery`, untouched here) |
| `php-cs-fixer --dry-run` on the changed files | 0 fixable (5 other `modules/Users` files were already unfixable-clean outside the config's paths) |
| `make lekalo-check` | 23 rules, 0 errors, 0 warnings — the `users.password_reset_flow` summary no longer claims the token is logged unconditionally |
| `make extract-check` | 224 identical, 64 recorded divergences (`.env.example` is in `SLICE_SKIP`, so its note is not a divergence) |

Cleanup after the probes: the token row the reproduction created for
`admin@example.test` was deleted and `storage/logs/app.log` (created by the
reproduction, carrying that live token) was removed — the tree is back to
`php-error.log` only. Servers stopped; the pre-existing dev server was never
running during this round.

---

# 8 — The comment form's bot defense (2026-10-15, security-audit finding #2)

**Finding #2 as first written was wrong in its first half, and the correction
is the useful part.** It claimed `POST /blog/{slug}/comments` carried "only the
global 60/min limiter". It does not: `CommentsService` rate-limits submissions
per **IP+slug** at 10 per 60 s (loopback exempt so local dev is not throttled),
applied BEFORE validation so a malformed payload cannot bypass it. What the
route really lacked, next to the tree's other public form (`QuestionSubmitAction`
— 5 per 300 s per IP plus a `question_website` honeypot), was the **honeypot**.

Measured before the change, through the real action on temporary databases
(`DB_COMMENTS`/`DB_USERS`/`DB_OUTBOX`, so the working tree's `comments.db` was
never touched):

```
POST /blog/hello-world/comments (every field filled, incl. comment_website) => HTTP 302 /blog/hello-world#comments
comments stored: 1
  row: hello-world | Bot Probe | pending
exit 1
```

A submission that filled every field it could find — the naive-crawler shape a
honeypot exists for — was accepted and left a `pending` row in the moderation
queue, indistinguishable from a reader's comment.

## What changed

- **`CommentSection::form()`** renders the field in the markup shape the
  questions block already uses (`<prefix>-comments__hp`, `hidden`,
  `autocomplete="off"`, `tabindex="-1"`, label "Website"): a honeypot nobody
  renders catches nobody, so it lives in the form the crawler is handed.
- **`CommentSubmitAction`** discards a filled `comment_website` BEFORE any
  lookup or write, and answers with the very redirect a real submission gets —
  the bot cannot tell which field gave it away and nothing reaches moderation.
  The malformed-payload case is answered the same way: "silently" is the
  contract, not a detail.
- The action's docblock now names both defenses and says which one is the
  boundary: the honeypot is a cheap filter for naive crawlers, the per-IP+slug
  limiter is what bounds the queue.

Deliberately **not** added: a second, per-IP-only limiter. The route already
sits behind the global 60/min per-IP middleware (`RateLimitMiddleware`, wired
whenever Security is installed — and Comments hard-depends on Security), so a
third number would be a redundant knob rather than a defense; the per-article
keying of the existing limiter is exactly what the global cap already covers.

## Verified (2026-10-15, comment honeypot, all exit 0)

| Gate | Result |
|---|---|
| probe through the action (temp DBs), before | exit 1 — `pending` row stored for a honeypot-filling bot |
| probe through the action (temp DBs), after | exit 0 — HTTP 302, `comments stored: 0` |
| HTTP probe (`php -S`, port 8178, `APP_ENV=production`, real `comments.db`) | form renders `name="comment_website"`; clean submission → 302 `…/{slug}#comments` and +1 row; honeypot submission → **identical 302**, +0 rows; the probe's row deleted afterwards |
| `CommentHoneypotTest` (new) | 4 tests, 16 assertions (the form field is asserted for BOTH theme vocabularies, `st` and `er`); mutation-checked on the final files — neutering the check fails both discard tests (`1 is identical to 0`, and 404 instead of 302 for the malformed payload) while the control stays green |
| `composer test` | 293 tests, 1231 assertions, 1 skip |
| `php -l` on the 3 changed files | clean |
| `php-cs-fixer --dry-run` on `modules/Comments` | my 3 files clean (`CommentsMetric.php` was already unfixable-clean outside the config's paths) |
| `composer phpstan` (gate scope) | level 9, no errors |
| `make extract-check` | 224 identical, 64 recorded divergences |
| `make lekalo-check` | 23 rules, 0 errors, 0 warnings |

The installed slice was re-copied after the edit (`bin/cms module:remove Comments
--force` + `module:add Comments`) — same trap as §7: composer resolves `Modules\`
against `src/Modules/`, so a catalogue edit alone is invisible to the suite.

---

# 9 — Password policy, submit throttles, the CSRF origin check (2026-10-16, security-audit findings #3–#8)

This round continued the audit §7/§8 started. The first thing it did was
re-verify every remaining premise against the code, and that mattered: §8 had
already caught finding #2's first half being wrong (it claimed the comments
route had no limiter of its own), so no finding was fixed on its own wording.

| # | Finding as first reported | Re-verified fact | Evidence | Verdict |
|---|---|---|---|---|
| 3 | password minimum is 8, baseline is 12 | TRUE — and it lived in **three** places, not one | `AuthService::register()` (`required|min:8|confirmed`), `PasswordResetService::reset()` (same), `Cardboard\…\UsersAction::validate()` (`required|min:8` / `min:8`); the message `'Password must be at least 8 characters.'` typed at all three; `lekalo/modules/users/policies.yaml` said 8 | confirmed, medium |
| 4 | open registration has no throttle of its own | TRUE — `register()` never touched the injected `RateLimiter`; only the global 60/min/IP middleware bounded it | `AuthService` (no limiter call on the register path; `isLoginAllowed()` is the only use) | confirmed, low-medium |
| 6 | `/forgot-password` has no per-email throttle | TRUE — no limiter anywhere on the path; each request DELETEs + INSERTs one token row, and a *distinct* address per request grows the table without bound | `PasswordResetRequestAction::submit()` → `PasswordResetService::requestReset()` | confirmed, low-medium |
| 7 | CSRF is token-only, no Origin/Referer cross-check | TRUE — no `origin`/`referer` read anywhere in the tree | `CsrfMiddleware` (global on POST/PUT/DELETE/PATCH, `hash_equals`, 403 on mismatch) | confirmed, low (defense-in-depth) |
| 8 | `audit.sh` dies under `set -e`; secrets regex false-positives | TRUE, with a root cause: `((ISSUES++))` returns exit **1** when the counter is 0, so the first branch entered killed the script — exit 1 after one section, no Summary, empty stderr | `.agents/skills/aif-security-checklist/scripts/audit.sh`, measured run | confirmed, tooling |

Sweep of the same surface (nothing else found): the public POST surface is
`/register`, `/login`, `/logout`, `/forgot-password`, `/reset-password`,
`/blog/{slug}/comments`, `/blog/{slug}/inline` (guarded by `ArticleWriteGuard`)
and `/questions/{type}/{slug}`; every other POST route sits behind the auth gate.
Login ✓ (5/min per IP+email), questions ✓ (5/300 s per IP), comments ✓ (10/60 s
per IP+slug) + honeypot ✓ (§8), registration and reset requests ✗ → fixed here.
`/reset-password` needs none: a 256-bit token is not brute-forcible and the
global middleware bounds the request rate (documented, not silently skipped).

## The password minimum: 8 → 12, and one owner for it

`Modules\Security\Domain\PasswordPolicy` is now the only place the number
appears (`MIN_LENGTH = 12`, the OWASP ASVS 2.1.1 baseline), with `minRule()` and
`tooShortMessage()` derived from it. The three call sites compose
`'required|' . PasswordPolicy::minRule() . '|confirmed'` and the message, so an
admin form can no longer accept what the public register refuses — which is what
"four copies of one policy" meant in practice.

**The owner is `Security`, not `Users`, and the ledger is why.**
`Cardboard`'s closure is `Outbox, Security, Settings, Validation` — `Users` is
NOT in it, even though `Cardboard` reaches `Users` classes through inline FQCNs
and `CardboardServices::need()`. Both consumers already depend on `Security`, so
the constant costs no new edge; anywhere else would have added one (visible or
invisible). The coupling ledger stays empty.

Length is the ONLY rule owned there, on purpose: NIST SP 800-63B and this tree's
dislike of decorative controls agree that a length floor is what a policy can
honestly demand, while character-class rules buy lockouts and a support burden.

## The two unthrottled public writes

`AuthService::register()` gained `$ip` and a per-address limit (5 accounts per
hour), `PasswordResetService::requestReset()` gained `$ip` and now returns
`array{ok, retryAfter}` (`CommentsService::submit()`'s shape). Both answer
**429 + `Retry-After`** through one new `ThrottledResponse`, deliberately with no
`Location` header — a refusal that also redirects has its body discarded by
`Response::send()`, so the client never reads why (`QuestionSubmitAction::refusal()`
is the sibling precedent).

Three properties worth naming, because each is a decision and not an accident:

- **The registration limit counts ACCOUNTS CREATED, not attempts.** It is asked
  after validation, immediately before the insert, so an honest reader who
  mistypes a password five times is not locked out of the form; mass account
  creation is bounded either way. Raw request rate stays the global middleware's
  business.
- **The reset-request limit is per caller *and* per target** (5/900 s per
  address, 3/3600 s per address-like key). An endpoint that mints a token for
  any address it is handed is otherwise a free amplifier for mail — or, in a
  tree with no transport, for log lines. It is asked BEFORE the token row is
  rewritten, so a refused request cannot invalidate the link the reader already
  holds.
- **The refusal says nothing about who exists.** Both keys are the caller's own
  inputs — the address they connect from, the address they typed — never whether
  an account exists, so a 429 answers identically for a registered and an
  unregistered email. The non-enumerability of the flow survives the throttle.

`Modules\Security\Domain\LocalClient` is the one owner of "the local developer
is not a spammer" (`isLoopback()`), and `CommentsService`'s private list folded
into it — three module limiters would otherwise have spelled the same three
addresses. `RateLimitMiddleware` keeps its own const, because Core may not name a
module; that constraint is worth more than one shared constant.

## The CSRF origin check (#7)

`Request::isSameOrigin(string $url)` compares a URL's HOST against the request's
`Host`, guarded exactly as `absoluteUrl()` guards it (with `TRUSTED_HOSTS` set,
an unrecognised Host resolves to the first allowed name, so a forged Host cannot
make a cross-site request describe itself as same-site). `CsrfMiddleware` calls
it with `Origin`, falling back to `Referer`.

The order inside `handle()` is token **first**, then origin — so a request
without a token is refused with the same 403 and the same message as before the
check existed, and no existing probe or integration changes meaning. Neither
header present → token-only: `curl`, a server-to-server call, a proxy that strips
both. `Origin: null` (a sandboxed document) and a malformed value name no host,
so they fail closed.

## Found by the sweep, deliberately not changed

`Request::getClientIp()` walks `X-Forwarded-For` from the RIGHTMOST hop and
returns the first address that is not a trusted proxy — the correct shape, a
spoofed leftmost entry is ignored. The one deployment where the loopback
exemption's reasoning lapses is worth stating: a reverse proxy **on the same
host** that is not listed in `TRUSTED_PROXIES` and sets no `X-Forwarded-For`
makes every request arrive from `127.0.0.1`, and the exemption then applies
production-wide — to the global middleware and every module limiter alike. The
answer is configuration, not code, so `.env.example` now says it next to
`TRUSTED_PROXIES` (an INFO item, no behaviour change).

## The audit script, and the vendored-copy drift

`.agents/skills/aif-security-checklist/scripts/audit.sh` (4 increments →
`ISSUES=$((ISSUES + 1))`, and the secrets pattern requires a quoted literal of
8+ characters) now reaches its own Summary: exit 0, six sections, empty stderr —
measured before/after on the same tree. The overshoot in the first attempt is
worth recording: `-i` was tried to catch camelCase `$apiKey`, and it re-flagged
`INVALID_TOKEN = 'passwords.token'`-style constants in `vendor/` (three in this
tree), so the camelCase spelling was added to the alternation instead. Probe on
a synthetic file: the old pattern reported 4 lines (3 of them code), the new one
reports exactly the 2 real literals.

`.agents/` is vendored, hash-managed tooling (gitignored, recorded in
`.ai-factory.json`). The fix is **local and the recorded hash is NOT rewritten**
— rewriting it would falsify the "pristine installed copy" record — so a future
`$aif` may flag or re-install the file. That consequence is the price of fixing
it here rather than upstream.

## Verified (2026-10-16, audit findings #3–#8, all exit 0)

| Gate | Result |
|---|---|
| `composer test` (full suite) | 310 tests, 1296 assertions, 1 skip (310 vs 293 at §8: the new policy/throttle/CSRF/USERS cases) |
| `composer phpstan` | level 9, no errors |
| `composer cs-fix-dry` | 0 of 92 files fixable (the gate's paths: `src/Modules/Core`, `src/Modules/Database`, `src/Themes`, `bin`, `public`, `tests`) |
| php-cs-fixer on the touched module files (informational — modules are outside the config's paths) | my 6 files clean; 7 pre-existing `modules/Users`+`modules/Comments` files still unfixable-clean |
| `php -l` on every changed/new file | clean |
| `make lint` | exit 0 |
| `make lekalo-check` | 23 rules, 0 errors, 0 warnings; `lock --check` unchanged (`sha256:e77647f7…`) — the lock does not cover descriptions/`applies_to`, so the policy edit moved no hash |
| `make extract-check` | OK — 222 identical, **66** recorded divergences (two new `DIVERGED` lines in `bin/build/kernel-divergence.txt` for `Request.php` and `CsrfMiddleware.php`) |
| live HTTP, `php -S 127.0.0.1:8199` (fresh, `APP_ENV=production`, real session + real token from `GET /register`) | cross-site `Origin` + **valid** token → 403 `Cross-site request rejected`; same-origin `Origin` + token → 200 (and the 12-character policy message); no token + cross-site → 403 `CSRF token validation failed` (**token decided first**); cross-site `Referer` with no `Origin` → 403; **neither header + token → 200**; `Origin: null` → 403; `storage/data/users.db` unchanged (no account created by any probe) |
| live throttle probe through the REAL actions, synthetic non-loopback `REMOTE_ADDR`, temp DBs | register: 5 → 302, 6th → **429, `Retry-After: 3599`**, exactly 5 rows stored; reset per target from four different callers: 3 → 200, 4th → **429, `Retry-After: 3600`**; reset per caller: 5 → 200, 6th → **429, `Retry-After: 900`**; loopback: 7 of 7 registrations accepted |
| mutation checks (5, each reverted) | hardcoding `min:8` in `register()` → `AuthServiceTest:140` fails; `LocalClient::isLoopback()` → `false` → 2 failures (`AuthServiceTest`, `PasswordResetServiceTest:193`); registration limiter → `true` → `AuthServiceTest:166` fails; `FORGOT_EMAIL_LIMIT = 30` → `PasswordResetServiceTest:179` fails; disabling the CSRF cross-check → 5 `CsrfMiddlewareTest` failures (the cross-site/`null` cases), with the same-origin, `Referer`, absent-header and token cases still green |
| module install trap (§7/§8) | `bin/cms module:remove <M> --force` + `module:add <M>` for Users, Security, Cardboard, Comments; `diff -rq modules/<M> src/Modules/<M>` empty for all four |
| probe cleanup | the `php -S` probe server stopped; both probe scripts live in `/tmp`, no probe rows in `storage/data/*.db`, `storage/logs/app.log` untouched (`APP_ENV=production` selects the null transport) |

---

# 10 — The auth provider nobody had to list (2026-10-16, the deferred half of §6)

§6 wired the `/mark` gate explicitly and deferred `#[AsAuthProvider]` discovery
for a stated reason: the registry would have to construct a middleware with
dependencies. That reason is §6's, not this round's — a PROVIDER is a port
implementation with a zero-argument constructor (`UsersAuthProvider` was built
that way on purpose), so a registry can instantiate it while `AuthMiddleware`,
which takes the provider, stays explicitly wired. This round does exactly that.

Two facts decided the shape before any code was written, both measured:

- the attribute had **0 readers** since `93b09e3` deleted `AuthProviderRegistry`
  as dead code, and
- the two-line wiring existed in **three copies** (`public/index.php`,
  `KernelOnlyBootTest`, `AdminAccessControlTest`), which is the drift the
  single shared address was invented to prevent and which no test could catch,
  since all three were the same code.

## What shipped

| Piece | Before | After |
|---|---|---|
| `src/Modules/Core/Application/Services/AuthProviderRegistry.php` | deleted (`git show 93b09e3^`) | **resurrected at its former path**, rebuilt on the CURRENT registry shape |
| `modules/Users/Domain/UsersAuthProvider.php` | `Domain`, import of `Interfaces\HTTP\AuthServices` | moved to **`Interfaces/HTTP/`** (the registry's published file-name contract), import inverted |
| `modules/Users/Interfaces/HTTP/AuthWiring.php` | the one explicit wiring address | **deleted** |
| `public/index.php` | `class_exists(AuthWiring::class)` + `AuthWiring::wire()` | `AuthProviderRegistry::first()` — **no module class named for auth** |
| the two test mirrors | `class_exists` + `wire()` (a copy each) | the same two Core-only lines as the root |

**The registry is the concept, not the old code.** Its `resolveFqcn()` is the
declaration-start version Boot 23 hardened — the deleted copy took the FIRST
mention of `class` anywhere in the file, which resolves a type named `says` out
of a docblock sentence and silently skips the file — and same-priority
contention votes through `PreferenceVote::select()`, the rule every other
registry already shares. `tests/Discovery/TextFirstFqcnTest` now pins this fifth
copy: a shape shared by five files is a shape that will be fixed in four of them.

Three decisions, each a rule rather than an accident:

- **One provider, not a merged answer.** Unlike the shell port (where two modules
  legitimately answer different halves), there is no half of "who is this
  request" a second module could own: two answers is not depth, it is ambiguity.
  Lowest priority wins; a tie keeps the first and LOGS the rest.
- **Zero-argument constructors are enforced, not documented.** Discovery
  instantiates at scan time, so a provider that took a dependency would capture
  one from the wrong moment — the harness swaps `DB_*` between cases and the
  captured connection would outlive its database. `getNumberOfRequiredParameters()`
  refuses it; the module's provider resolves services lazily instead.
- **A provider that throws is NOT caught** (same rule as the shell registry): an
  auth answer that disappears quietly is a gate nobody can trust.

## The governance question the plan left open

The plan asked whether the resurrected file's Inventory row should be corrected
(`dead` → `runtime`) or whether a Movement entry alone suffices, defaulting to
"correct the row AND add the entry". **The document's own rule wins:**
`docs/kernel-shape.md` states that the baseline does not move and that rows stay
at their baseline values even where the tree no longer holds the file. A
resurrection is movement, so the row keeps its baseline fact (`dead`, 5 366 B)
and the dated Movement row records the file being alive again — with its new
size, bucket and importers. Correcting the row would have quietly rewritten the
baseline the whole table is measured against.

## Two traps this round walked into (both recorded, neither silent)

1. **A glob inside a docblock closed the comment.** Writing the registry's
   contract as prose — `` `*/Interfaces/HTTP/*AuthProvider.php` `` — put a
   literal `*` `slash` pair inside the comment, which ends it early: every
   following line became code, and the moved provider died with
   `syntax error, unexpected token "#["`. `ShellContextProviderRegistry` already
   warns about exactly this in its own docblock. The suite caught it in 13
   errors; the fix is a wording, not an escape.
2. **A symlinked `vendor` broke the kernel-only worktree, not the change.** The
   first kernel-only run failed `ModuleLifecycleTest` with
   `file_get_contents(): … Is a directory`: that test hashes the whole tree and
   `RecursiveDirectoryIterator` does not follow symlinks, so the fixture
   `vendor` link was read as a file. Proved by a CONTROL worktree at `HEAD`
   without this round's changes — it failed identically. `cp -a vendor` instead
   of `ln -s vendor` turned the real run green.

## Verified (2026-10-16, all exit 0 unless stated)

| Gate | Result |
|---|---|
| `composer test` (full suite) | **315 tests, 1323 assertions**, 1 skip (289 → 315: the registry's 5 discovery contracts + the mirrors) |
| `composer phpstan` | level 9, no errors |
| `composer cs-fix-dry` | 0 of 94 files fixable |
| `composer rector-dry` / `make lint` | Rector OK / lint clean |
| `make lekalo-check` | 23 rules, 0 errors, 0 warnings; `lock --check` unchanged (`sha256:e77647f7…`) |
| `make extract-check` | OK — 222 identical, 66 recorded divergences, **plus the new `OWNED` row** for the registry (found by running the check, not by guessing the entry shape: it reported `exists only here (not OWNED)` first) |
| `make kernel-size` | 317 447 B / 72 files, delta −20 745 B; Core types 10 interface / **60 concrete** — the one-line `MAX_APPROVED` 59 → 60 raise the shape test exists to make visible |
| kernel-only worktree (this round's files, `cp -a vendor`) | `composer phpstan` clean; `composer test` 315 tests, 240 skipped, 0 failures — the mirrors now name only Core classes, and the registry answers `null` with no module installed |
| live `curl`, `php -S 127.0.0.1:8201` (real session + real `_token` from `GET /login`, two seeded probe users) | anon `/mark` → **302 `/login`, 0 B**; anon `/mark/users` → **302 `/login`, 0 B**; `/` → 200; admin login → 302 `/mark`, then `/mark` → **200 (34 646 B)**, `/mark/users` → **200 (37 663 B)**, `/login` → **302 `/mark`** (guest-only); identity WITHOUT the permission `/mark` → **403 (53 B)**, `/mark/users` → **403 (54 B)** — identical to the explicit wiring §6 measured |
| `[auth]` log line, live | `[auth] wired Modules\Users\Interfaces\HTTP\UsersAuthProvider` — the discovered class is named, from its new location |
| mutation checks (3, each reverted byte-identical) | disable the zero-arg refusal → the refusal test errors; disable the contention logging → the tie test fails with the missing line in the diff; remove BOTH the text gate and the attribute check → the unmarked-file test errors. (Removing either gate ALONE changes nothing — two independent gates guard the label, which is worth knowing when reading that test.) |
| module install trap (§7/§8/§9) | `module:remove Users --force` + `module:add Users`; `diff -rq modules/Users src/Modules/Users` empty; the cascade into Comments/Questions left both identical (11/11 and 10/10 files) |
| probe cleanup | probe users deleted (`users` back to 3, 0 `probe-%` rows, their `sessions`/`user_roles`/`login_attempts` rows with them); the probe server stopped; both probe scripts lived in `/tmp`; `git worktree list` back to the single main tree |
