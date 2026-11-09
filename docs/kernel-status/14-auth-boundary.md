# Boot 51: the auth surface leaves the kernel

The admin gate was the last shared surface a kernel path still named. The
round moved the port, the identity value object, the provider registry and the
middleware into a new `modules/Auth` catalogue slice. The kernel kept only the
fallback that makes an absent gate a 404, not an unguarded 200.

## Before

- `src/Modules/Core`: **346 540 B / 68 files** (9 interface / 57 concrete).
- `KernelShapeTest::MAX_APPROVED`: **56**.
- Source suite: **1099 tests / 7272 assertions / 1 skipped**.
- Export: **192 files / 1 216 190 B**; export check **191 tests / 525
  assertions / 52 skipped**.
- Catalogue: **33 modules**.

## What moved

| Surface | Bytes | New owner |
|---|---:|---|
| `AuthProviderRegistry` | 9 986 | `modules/Auth` |
| `AuthMiddleware` | 6 442 | `modules/Auth` |
| `AuthProvider` (interface) | 3 280 | `modules/Auth` |
| `AsAuthProvider` | 1 051 | `modules/Auth` |
| `AuthIdentity` | 989 | `modules/Auth` |

`AdminUnavailableMiddleware` and `AdminRoutePolicy` stayed: the prefix and the
safe answer for a tree without a provider are kernel rules. The moved
middleware still reads `AdminRoutePolicy`, which is a module importing the
kernel and is allowed.

The two tests that own these classes moved with them:
`AuthProviderRegistryTest` and `AuthMiddlewareDenialTest` now live in
`modules/Auth/tests`, each with a skip guard for a kernel-only checkout.

## The wiring, without a kernel import

`src/http.php` no longer imports an Auth class. It keeps two class names as
strings, asks the registry for `first()` only when the class exists, and builds
the middleware through `ReflectionClass` with the provider and the kernel's
`SecurityEventSink`. If the module is absent, the registry is absent, the
provider is absent, or the middleware cannot be built, the kernel adds
`AdminUnavailableMiddleware`. The failure mode is 404, never an unguarded 200.

This is the same shape the Outbox sink and the Security limiter already use:
the composition root may name a module in a string, never in a `use` or a
`::class` that the export's PHPStan would resolve.

`UsersAuthProvider` now imports `Modules\Auth` and is unchanged otherwise.
`module:add Users` now names `Auth` among its missing dependencies until it is
installed; the catalogue installer orders the closure so Auth comes first.
`module:remove Auth` refuses while `Users` imports it, naming the holder.

## After

- `src/Modules/Core`: **324 792 B / 63 files** (8 interface / 53 concrete).
- `KernelShapeTest::MAX_APPROVED`: **56 -> 52**.
- Source suite: **1099 tests / 7271 assertions / 1 skipped**; the same tests
  run, now from the module that owns their subject.
- `make extract-check`: **199 identical / 89 recorded divergences**; the five
  moved paths are `DROPPED` with `modules/Auth` named as the owner.
- `make lekalo-check`: **34 modules** validated.
- `make lint`, `composer phpstan`, `make analyze`, `make mago-lint` and
  `make mago-guard` are green.
- Source CI: **16/16 jobs**, run `42630529705` on `7c61069`.
- Fresh kernel-only checkout: **1059 tests / 3810 assertions / 838 skipped**,
  zero errors; `/mark` answers **404**.

Export measured from `7c61069`: **187 files / 191 tests / 502 assertions /
52 skipped**. The five runtime files leave with the Auth module; the two moved
test files were already excluded from the export by `phpunit.exclude`, so the
test count is unchanged. A kernel-only checkout still answers `/mark` with
404; the admin smoke gate (all modules installed) still reaches the login
redirect.

The published export is `responsive-sk/nativa-core@fc6c38f`, generated from
source `691b364`; its CI is **9/9 jobs**, run `42631413239`.

## Still open

- `ThemeResolver`, `ThemeKernel` and `ThemeRegistry` remain on the render path.
- The page cache remains in the dispatch path and is used by Tooling.
- `StructuredDataService` still has the kernel homepage as a caller.
- `AdminRoutePolicy` stays in the kernel even though the auth module reads it:
  the admin prefix is a kernel routing rule, and the fallback must exist
  without the module.
