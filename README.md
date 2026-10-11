# Nativa PHP Core

The **kernel-only** tree. Two modules travel whole — `Modules\Core` and
`Modules\Database` — plus one theme. Everything else is a module: copied in from
`modules/` with `bin/cms module:add`, deleted with `bin/cms module:remove`, and
provably gone afterwards.

This tree exists to test that claim. The showcase (`../nativa-minimal`) is a full
site; a site cannot demonstrate that something is removable, because the thing it
would demonstrate on is always installed.

## Where this came from

The kernel slice was imported verbatim from `../nativa-minimal` on 2026-10-11:
`src/Modules/Core` (65 PHP files, 8 194 lines) and `src/Modules/Database` (2 files,
372 lines). Nothing in the slice was edited on import — an import that starts
"fixing things" is a fork, and a fork cannot tell you what the original did.

That import carries the showcase's coupling with it: **34 `use Modules\<X>\…`
statements across 14 files, naming 9 modules** (Blog, Cardboard, Locales, Media,
Outbox, Pages, Security, Settings, Users). The ledger — every edge, with the
answer it gets (`M`ove / `C`ontract / `G`uard) — is `../nativa-minimal`'s
`tests/Architecture/KernelCouplingTest.php` + `tests/Support/KernelCoupling.php`,
and the measured state of THIS tree is `docs/kernel-status.md`.

## The boundary rule

> A kernel file may not import a module. A module may import the kernel.

A surface that needs a module is a surface that belongs to a module. The kernel
owns: routing, the request/response pair, the middleware pipeline and its
discovery, the migrator runner, the SQLite connection manager, the asset
resolver, markdown rendering, the error pages and the SEO text surfaces
(`/robots.txt`, `/sitemap.xml`) — degraded to what they can answer with nothing
installed.

## What it does today

`docs/kernel-status.md` records the measured state, with the status codes read
back off this tree rather than paraphrased from a plan. Measured 2026-10-11 on a
freshly started server: `/robots.txt`, `/sitemap.xml` and `/docs` answer **200**,
`/nope` and `/mark` answer **404** (not 500 — there is no admin route to protect
with no `Users` module), and `/` is the single remaining 500, because
`HomepageAction` still reaches for the module that owns the copy it renders.

The shell port (`ShellContextProvider`, discovered by `#[AsShellContextProvider]`)
is the first of the 34 edges cut: the site name, the active theme, the default
locale, the language list and the navigation menu now come from a kernel-owned
port a module implements — and **absence is a first-class answer**, so this tree
renders with no provider installed at all.

## Run it

`composer install` first: measured, a tree with no `vendor/` cannot serve a single
request, because `Router::scanDirectory()` lets the autoloader touch Core's own
`Interfaces/CLI/Command/*` classes and three of them extend `symfony/console` — a
dev-only dependency. See "Boot 1" in `docs/kernel-status.md`; the guard that would
close it lives in `../nativa-minimal`'s architecture suite.

```sh
composer install          # dev tooling only — the runtime has zero Composer packages
php -S 127.0.0.1:8000 -t public
```
