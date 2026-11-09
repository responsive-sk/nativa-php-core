# Boot 52: the page cache leaves the kernel

The page cache was a kernel middleware with a module-shaped job: it stores
HTML for anonymous visitors, invalidates on content writes and is optional at
deployment time. It now lives in `modules/PageCache`. The kernel keeps one
read port for the rate-limit fallback and no cache policy of its own.

## Before

- `src/Modules/Core`: **324 809 B / 63 files** (8 interface / 53 concrete).
- `KernelShapeTest::MAX_APPROVED`: **52**.
- Source suite: **1099 tests / 7272 assertions / 1 skipped**.

## What moved

| Surface | Bytes | New owner |
|---|---:|---|
| `PageCacheService` | 10 512 | `modules/PageCache` |
| `CacheInvalidator` | 1 306 | `modules/PageCache` |
| `PageCacheMiddleware` | 1 624 | `modules/PageCache` |

The kernel gained `Modules\Core\Domain\Contracts\PageCacheReader`, with only
the two methods `RateLimitMiddleware` needs: `get()` and
`cacheKeyForRequest()`. The module's service implements it, so the kernel
dependency points at the kernel and a tree without PageCache passes null.

`CacheInvalidator` is now a module service. Every content module that writes
(Blog, Cardboard, Comments, Forms, Locales, Media, Outbox, Pages, Questions,
Queue) imports it, and its install closure now names PageCache. There is no
cycle: PageCache imports only Core.

## Wiring and Tooling

`src/http.php` builds the service and middleware through reflection, guarded by
`class_exists`, when `PAGE_CACHE` is enabled. The service is built first so the
rate limiter receives the reader; the middleware is added after the limiter so
the limit is counted before a cache hit. A kernel-only tree logs the absent
module and answers normally without a cache.

Tooling ships in the kernel-only export, so `ModuleInstaller::flushPageCache()`
does not import the module. It keeps the base-relative path
`storage/cache/pages` and the same `*.json` glob; `PageCacheDirectoryTest` pins
that path against the module's `DEFAULT_DIR`.

The three cache tests moved to `modules/PageCache/tests` and skip when the
module is absent, so a fresh kernel-only checkout starts clean.

## After

- `src/Modules/Core`: **298 995 B / 61 files** (9 interface / 50 concrete).
- `KernelShapeTest::MAX_APPROVED`: **52 -> 49**.
- Source suite: **1099 tests / 7272 assertions / 1 skipped**.
- Fresh kernel-only checkout: **1059 tests / 3721 assertions / 867 skipped**,
  zero errors; `/mark` answers **404**.
- `make extract-check`: **199 identical / 89 recorded divergences**; the three
  moved paths are `DROPPED` with `modules/PageCache` named, and the new
  `PageCacheReader` port is `OWNED`.
- `make lekalo-check`: **35 modules**.
- `make lint`, `composer phpstan`, `make analyze`, `make mago-lint` and
  `make mago-guard` are green.
- Export from `f44969f`: **183 files / 169 tests / 439 assertions / 52 skipped**;
  `make core-export-check` is green.
- With `PAGE_CACHE=1`, a guest request to `/` is served from the module cache
  with `X-Cache: HIT`; `/mark` still redirects (302).

## Still open

- `ThemeResolver`, `ThemeKernel` and `ThemeRegistry` remain on the render path.
- `StructuredDataService`, `ContentServices` and `ShellContextProviderRegistry`
  remain kernel-owned because the kernel's own layout and homepage read them.
