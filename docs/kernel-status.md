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
| `Themes/ThemeModule.php`, `Themes/kernel/KernelTheme.php` | the kernel's own theme, prefix `k`, no build, no module import |

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
| `Core/Application/Services/AuthProviderRegistry.php` | directory-scan discovery, first provider wins |

**Rewired** — `AuthMiddleware` asks the port (session handling, remember-me
and the permission table stay the module's; uncovered admin paths still cost
access, never leak it); both cache middlewares key on the mapper's default
locale instead of the translation store; the outbox emission in `RateLimit`
is a `class_exists` guard (recorded only when installed, dropped otherwise —
no `use`, so the ledger stays clean); the limiter factory product is
duck-typed and degrades to pass-through when unusable.

`index.php` is deliberately untouched: with no provider installed there is
nothing to wire, and an `if` that can never be true here is untestable here.
The registry IS the mechanism — the composition root wires the middleware
around `AuthProviderRegistry::first()` when a module arrives.

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
(`lekalo/project.yaml` + `modules/core` + `modules/database` + `targets/`, id
`nativa_core` — the IR grammar takes no hyphens — `lekalo.lock` committed,
`validate` + `validate --strict` green on CLI 0.6.5, 23 rules, 0 findings).

Present since 2026-10-11: the module catalogue (`modules/`, task 3.1–3.2) —
`ModuleCatalog`/`ModuleManifest` scan a slice as text, `bin/cms`
`module:list/show/add/remove` (plain PHP, zero dependencies) moves it, and
`modules/Scratch` is the lifecycle fixture whose round trip
(`docs/modules.md`) leaves the tree byte-identical. `KernelOnlyBootTest` as a
real test still waits for the harness: the round trip was proven live over
HTTP, not by PHPUnit.

Absent, deliberately: `KernelOnlyBootTest` as a real test (2.3), and
`bin/build/extract-kernel.sh` + `--check` (2.1). The kernel theme ships no CSS, so
a page it renders is valid and unstyled — plan task 2.2 wants a real trimmed
design, not just a working one.
