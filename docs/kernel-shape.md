# Kernel shape — the inventory

Measured: 2026-10-15 · Baseline: **338 192 B / 79 files** (10 interface / 67 concrete, Core)

Every `.php` file under `src/Modules/Core`, classified. This is the table the
implementation plan, `make kernel-size` and `tests/Architecture/KernelShapeTest`
all read from — one source, so the three cannot drift into different stories.

Reproduce any row with `make kernel-size` for the totals; the per-file numbers
are `stat -c%s` plus one grep per file, which is why they are written down here
rather than recomputed on every read.

**The `Bucket` column is the approval record `KernelShapeTest` points at.**
A concrete file that is not `contract`, `runtime`, `tooling` or `dead` — or that
is not in the table at all — has no business being in `src/Modules/Core`: the
test fails with the path, the layer and this file's name until the row is
written and `ALLOW_LIST` carries the path.

## Buckets

| Bucket | Means | Count | Bytes |
|---|---|---:|---:|
| `contract` | An interface, a discovery attribute, or a Domain value object — what modules program against. Carries no behaviour the kernel executes. | 20 | 21 878 |
| `runtime` | A concrete class the kernel runs: boot, dispatch, render, cache, middleware. Includes the published surface modules import. | 51 | 269 996 |
| `tooling` | Module management — imported by `bin/cms` and tests, never by a request. Leaves `Modules\Core` in Phase 4. | 3 | 26 167 |
| `dead` | Proven unreferenced: no `new`, no `::`, no import anywhere. Deleted in Phase 3. | 3 | 8 682 |
| **Total** | | **77** | **326 723** |

`src/Modules/Database` adds 2 concrete files (11 469 B) to reach the 79-file
baseline; it ships no interfaces and no buckets — it is all runtime.

## What the table says

- **27 of 77 files have `Importers = 0`** — nothing outside Core names them.
  They are the pool the refactor draws from. All three `dead` files sit inside
  it; none of the `tooling` three do — `bin/cms` and three tests import them,
  which is exactly why they move rather than go.
- **The published surface is 39 edges.** `use Modules\Core\…` in the committed
  catalogue (`modules/`), counted as distinct statements. `Importers` counts
  *files* across catalogue, themes, tests, `bin/`, `public/`, `config` and
  `src/init.php`, and matches any qualified mention rather than only `use`
  lines — which is why `Request` reads 71 rather than 68. It is the wider,
  more pessimistic number on purpose: an FQCN that moves breaks a qualified
  mention just as hard as an import.
- **`Importers` counts `bin/cms`, which has no `.php` extension.** It is a PHP
  program with five kernel `use` statements, and a `--include='*.php'` filter
  drops it silently: that filter is what produced the "30 files" figure the plan
  was first written with. Counting it drops the total to 27 and moves
  `ModuleCatalog`, `ModuleManifest` and `DatabaseMigrator` out of the
  unreferenced pool — they are not unreferenced, they are consumed by the module
  tool.
- **`.phtml` templates are not importers.** They mention kernel classes as
  fully-qualified calls (`ViewRenderer::…`), never as `use` statements — zero
  templates carry one. They are excluded on that basis, not on file extension.
- **`Importers` excludes installed slices** under `src/Modules/<Name>`: they are
  gitignored operator state, so counting them would double every catalogue edge.
  They break all the same when an FQCN moves — that is why Constraint 3 of the
  plan forbids moving published names.
- **`src/composition.php` is not in this table, and that is deliberate.**
  It is kernel-owned (`wire_module_handlers()`, the one place the module
  pairings are declared — `src/http.php` and `bin/cms` both call it) but it
  lives in `src/`, not in `src/Modules/Core`, and it declares **no class**, so
  it has no `Bucket` to be approved into and `KernelShapeTest` cannot see it.
  This inventory measures `Core` + `Database`; a file outside those trees needs
  a row somewhere else or it is in none. Recorded here so the gap is a
  decision on the page rather than an omission somebody re-discovers. If the
  measurement is ever widened to `src/`, this is the first row.
- **`DiscoveryCache` is `runtime`, not `tooling`.** `src/http.php` boots with
  it, unlike the three `tooling` classes only `bin/cms` calls. This settles open
  question 2 of the plan.
- **Every `dead` row was verified by grep, not inferred**: `ServiceContainer` and
  `EventDispatcher` reference only each other; `AuthProviderRegistry` is named
  only by a docblock in `AsAuthProvider.php` and by prose in
  `docs/kernel-status.md`.

## Movement

The baseline above does not move — a baseline that tracks the tree measures
nothing. The after-state is appended as dated rows instead, and the Inventory
table keeps its baseline rows even where the tree no longer holds the file:
five rows are deleted code and three moved to `src/Modules/Tooling`, which is
exactly the movement the table exists to record.

| Date | Total | Delta vs baseline | Note |
|---|---:|---:|---|
| 2026-10-15 | 338 192 B / 79 files | — | Baseline, before the refactor |
| 2026-10-15 | 301 231 B / 71 files | −36 961 B | After Phases 3-4: 11 793 B deleted outright (3 dead + 2 dead-CLI files) and 26 167 B relocated to `Modules\Tooling` — the slice keeps those 26 KB, so only the `Core` figure drops. Offset by +999 B of comment and docblock edits the same phases made (`Router::mayDeclareRoute()`, `AsAuthProvider`, `RateLimitMiddleware`). Core types: 10 interface / **59 concrete** (was 67). Source: `make kernel-size`, 2026-10-15. |
| 2026-10-16 | 317 447 B / 72 files | −20 745 B | After the auth-provider discovery round (`#[AsAuthProvider]` gets a reader again): `Application/Services/AuthProviderRegistry.php` is **resurrected at its former path** — 9 948 B, bucket `runtime`, importers **5** (`public/index.php`, `tests/Boot/KernelOnlyBootTest.php`, `tests/Discovery/AuthProviderRegistryTest.php`, `modules/Users/tests/AdminAccessControlTest.php`, `modules/Users/Interfaces/HTTP/UsersAuthProvider.php`) — but rebuilt on the CURRENT registry shape, not on its old body: declaration-start `resolveFqcn()` (the deleted copy resolved a type named `says` out of a docblock sentence, Boot 23) and `PreferenceVote::select()` for same-priority contention. **The baseline Inventory row for that path stays exactly as it is** (`dead`, 5366 B): the baseline does not move, and a resurrection is movement, so THIS row is its record — not an edit to the row above. Deleted on the module side instead: `modules/Users/Interfaces/HTTP/AuthWiring.php` (the explicit wiring helper, one `class_exists` and two lines) and the provider's move `Domain` → `Interfaces/HTTP` so the registry's published file-name glob finds it, which also inverts a `Domain → Interfaces\HTTP` import. Core types: 10 interface / **60 concrete** (59 → 60 is the one-line `MAX_APPROVED` raise the shape test makes visible in review). The rest of the delta is the audit rounds' two Core files (`Request.php`, `CsrfMiddleware.php`). Source: `make kernel-size`, 2026-10-16. |
| 2026-10-15 | 302 089 B / 71 files | −36 103 B | After the `/mark` access-control fix (plan fix-mark-access-control): `RateLimitMiddleware.php` 5853 → **7118 B** — `blockedResponse()` lost the contract type-hint that fatalled every structurally-valid limiter with a TypeError on the first refused request (500 instead of 429) and every 429 branch gained `Retry-After`; `Contracts/RateLimiter.php` net unchanged (docblock edit reverted with the `implements` it justified). Importers moved with the new edges, all in the catalogue/composition root: `AuthMiddleware` 0 → **1** (`modules/Users/Interfaces/HTTP/AuthWiring.php`), `RateLimitMiddleware` 0 → **2** (`public/index.php`, `modules/Security/tests/RateLimitMiddlewareTest.php`), `AuthProvider`, `AuthIdentity`, `AsAuthProvider` 0 → **1** each (all `modules/Users/Domain/UsersAuthProvider.php`). Inventory rows stay at their baseline values on purpose (see above); reproduce live numbers with `stat -c%s`. Core types unchanged: 10 interface / 59 concrete. Source: `make kernel-size`, 2026-10-15. |
| 2026-10-23 | 398 193 B / 80 files | +60 001 B | After the boolean-result round (`docs/boolean-vocabulary.md`): `Domain/SubmitResult.php` (3515 B) is added, bucket `contract` — the `ok`/`errors` answer four services used to spell as an array, with "`ok` ⇒ no errors" moved into its constructors, and importers **4** (`CommentsService`, `MediaFolderService`, `MediaFolderDeleteAction`, `PasswordResetService`). The second new file is `Domain/Contracts/Mailer.php` (1895 B, `contract`), which landed with the operator-notification round after the row above was measured — it is recorded here rather than by editing that row. `DocsCatalog` grew 95 B for the new guide entry. `MAX_APPROVED` 64 → 65 together with the one Inventory row. Source: `make kernel-size`, 2026-10-23. |
| 2026-10-23 | 392 690 B / 78 files | +54 498 B | After the per-theme settings contract (plan feature-theme-settings-nav-icon): `Domain/ThemeSetting.php` (3220 B) and `Domain/ThemeSettingValues.php` (5021 B) are added, both bucket `contract` — a declared field, and the storage-prefix/validation rule the admin form, the save path and the renderer share. The port `ShellContextProvider` gained `themeSettings()` (interface, free shape) and `ShellContext::from()` gained an optional `themeSettings` map, so no existing theme fixture moved. `MAX_APPROVED` 62 → 64 together with the two Inventory rows; the raise is this round and nothing else. Source: `make kernel-size`, 2026-10-23. |

## Inventory

| Path | Bytes | Layer | Type | Importers | Bucket |
|---|---:|---|---|---:|---|
| `src/Modules/Core/Application/Events/EventDispatcher.php` | 1069 | Application | concrete | 0 | dead |
| `src/Modules/Core/Application/Services/AssetManager.php` | 6398 | Application | concrete | 5 | runtime |
| `src/Modules/Core/Application/Services/AuthProviderRegistry.php` | 5366 | Application | concrete | 0 | dead |
| `src/Modules/Core/Application/Services/CacheInvalidator.php` | 1306 | Application | concrete | 31 | runtime |
| `src/Modules/Core/Application/Services/ContentLocaleMapper.php` | 4360 | Application | concrete | 0 | runtime |
| `src/Modules/Core/Application/Services/DiscoveryCache.php` | 12570 | Application | concrete | 2 | runtime |
| `src/Modules/Core/Application/Services/DisposalRegistry.php` | 2295 | Application | concrete | 3 | runtime |
| `src/Modules/Core/Application/Services/DocsCatalog.php` | 15865 | Application | concrete | 0 | runtime |
| `src/Modules/Core/Application/Services/MarkdownRenderer.php` | 17425 | Application | concrete | 12 | runtime |
| `src/Modules/Core/Application/Services/ModuleCatalog.php` | 13453 | Application | concrete | 1 | tooling |
| `src/Modules/Core/Application/Services/ModuleInstaller.php` | 8494 | Application | concrete | 4 | tooling |
| `src/Modules/Core/Application/Services/ModuleManifest.php` | 4220 | Application | concrete | 1 | tooling |
| `src/Modules/Core/Application/Services/PageCacheService.php` | 10512 | Application | concrete | 2 | runtime |
| `src/Modules/Core/Application/Services/PreferenceVote.php` | 1885 | Application | concrete | 1 | runtime |
| `src/Modules/Core/Application/Services/ServiceContainer.php` | 2247 | Application | concrete | 0 | dead |
| `src/Modules/Core/Application/Services/ShellContextProviderRegistry.php` | 11429 | Application | concrete | 6 | runtime |
| `src/Modules/Core/Application/Services/StructuredDataService.php` | 4178 | Application | concrete | 3 | runtime |
| `src/Modules/Core/Application/Services/View/InlineCss.php` | 4260 | Application | concrete | 6 | runtime |
| `src/Modules/Core/Application/Services/View/InlineJs.php` | 1373 | Application | concrete | 2 | runtime |
| `src/Modules/Core/Application/Services/View/Paths.php` | 1121 | Application | concrete | 0 | runtime |
| `src/Modules/Core/Application/Services/View/ViewRenderer.php` | 2074 | Application | concrete | 16 | runtime |
| `src/Modules/Core/Domain/AdminRoutePolicy.php` | 8285 | Domain | concrete | 0 | contract |
| `src/Modules/Core/Domain/AuthIdentity.php` | 989 | Domain | concrete | 0 | contract |
| `src/Modules/Core/Domain/Contracts/AuthProvider.php` | 3280 | Domain | interface | 0 | contract |
| `src/Modules/Core/Domain/Contracts/NavBadgeContract.php` | 2374 | Domain | interface | 1 | contract |
| `src/Modules/Core/Domain/Contracts/RateLimiter.php` | 1042 | Domain | interface | 0 | contract |
| `src/Modules/Core/Domain/Contracts/ShellContextProvider.php` | 2700 | Domain | interface | 3 | contract |
| `src/Modules/Core/Domain/Permissions.php` | 2724 | Domain | concrete | 0 | contract |
| `src/Modules/Core/Domain/ShellNavItem.php` | 1048 | Domain | concrete | 9 | contract |
| `src/Modules/Core/Domain/SubmitResult.php` | 3515 | Domain | concrete | 4 | contract |
| `src/Modules/Core/Domain/ThemePreference.php` | 1536 | Domain | concrete | 2 | contract |
| `src/Modules/Core/Domain/ThemeSetting.php` | 3220 | Domain | concrete | 4 | contract |
| `src/Modules/Core/Domain/ThemeSettingValues.php` | 5021 | Domain | concrete | 5 | contract |
| `src/Modules/Core/Infrastructure/Attributes/AsAuthProvider.php` | 766 | Infrastructure | concrete | 0 | contract |
| `src/Modules/Core/Infrastructure/Attributes/AsMarkdownBlock.php` | 715 | Infrastructure | concrete | 0 | contract |
| `src/Modules/Core/Infrastructure/Attributes/AsMiddleware.php` | 602 | Infrastructure | concrete | 0 | contract |
| `src/Modules/Core/Infrastructure/Attributes/AsMigrator.php` | 567 | Infrastructure | concrete | 10 | contract |
| `src/Modules/Core/Infrastructure/Attributes/AsNavBadge.php` | 864 | Infrastructure | concrete | 1 | contract |
| `src/Modules/Core/Infrastructure/Attributes/AsShellContextProvider.php` | 808 | Infrastructure | concrete | 3 | contract |
| `src/Modules/Core/Infrastructure/Bootstrap/Bootstrapper.php` | 3833 | Infrastructure | concrete | 1 | runtime |
| `src/Modules/Core/Infrastructure/Env.php` | 4560 | Infrastructure | concrete | 8 | runtime |
| `src/Modules/Core/Infrastructure/Http/JsonResponse.php` | 625 | Infrastructure | concrete | 0 | runtime |
| `src/Modules/Core/Infrastructure/Http/Request.php` | 10965 | Infrastructure | concrete | 71 | runtime |
| `src/Modules/Core/Infrastructure/Http/Response.php` | 2277 | Infrastructure | concrete | 60 | runtime |
| `src/Modules/Core/Infrastructure/Paths/AppPaths.php` | 3926 | Infrastructure | concrete | 23 | runtime |
| `src/Modules/Core/Infrastructure/Registry/NavBadgeRegistry.php` | 5490 | Infrastructure | concrete | 1 | runtime |
| `src/Modules/Core/Infrastructure/Session/FileSessionHandler.php` | 2606 | Infrastructure | concrete | 0 | runtime |
| `src/Modules/Core/Infrastructure/Storage/LocalStorageProvider.php` | 8309 | Infrastructure | concrete | 7 | runtime |
| `src/Modules/Core/Interfaces/CLI/CliOutput.php` | 577 | Interfaces | interface | 17 | contract |
| `src/Modules/Core/Interfaces/CLI/Command/MigrateCommand.php` | 921 | Interfaces | concrete | 0 | runtime |
| `src/Modules/Core/Interfaces/CLI/Command/ServeCommand.php` | 2190 | Interfaces | concrete | 0 | runtime |
| `src/Modules/Core/Interfaces/CLI/MigratorInterface.php` | 779 | Interfaces | interface | 10 | contract |
| `src/Modules/Core/Interfaces/CLI/Support/DatabaseMigrator.php` | 4870 | Interfaces | concrete | 1 | runtime |
| `src/Modules/Core/Interfaces/HTTP/Actions/DocsIndexAction.php` | 5041 | Interfaces | concrete | 0 | runtime |
| `src/Modules/Core/Interfaces/HTTP/Actions/DocsPageAction.php` | 6026 | Interfaces | concrete | 0 | runtime |
| `src/Modules/Core/Interfaces/HTTP/Actions/HomepageAction.php` | 2195 | Interfaces | concrete | 1 | runtime |
| `src/Modules/Core/Interfaces/HTTP/Actions/LlmsTxtAction.php` | 2830 | Interfaces | concrete | 0 | runtime |
| `src/Modules/Core/Interfaces/HTTP/Actions/RobotsAction.php` | 1902 | Interfaces | concrete | 0 | runtime |
| `src/Modules/Core/Interfaces/HTTP/Actions/SitemapAction.php` | 2665 | Interfaces | concrete | 0 | runtime |
| `src/Modules/Core/Interfaces/HTTP/Attributes/Route.php` | 711 | Interfaces | concrete | 55 | contract |
| `src/Modules/Core/Interfaces/HTTP/ContentServices.php` | 1551 | Interfaces | concrete | 28 | runtime |
| `src/Modules/Core/Interfaces/HTTP/ErrorPageRenderer.php` | 3948 | Interfaces | concrete | 2 | runtime |
| `src/Modules/Core/Interfaces/HTTP/FrontendLayout.php` | 29154 | Interfaces | concrete | 19 | runtime |
| `src/Modules/Core/Interfaces/HTTP/Kernel.php` | 7339 | Interfaces | concrete | 4 | runtime |
| `src/Modules/Core/Interfaces/HTTP/Markdown/MarkdownBlockContext.php` | 1309 | Interfaces | concrete | 2 | runtime |
| `src/Modules/Core/Interfaces/HTTP/Markdown/MarkdownBlockInterface.php` | 1247 | Interfaces | interface | 0 | contract |
| `src/Modules/Core/Interfaces/HTTP/Markdown/MarkdownBlockRegistry.php` | 5779 | Interfaces | concrete | 1 | runtime |
| `src/Modules/Core/Interfaces/HTTP/Middleware/AuthMiddleware.php` | 4980 | Interfaces | concrete | 0 | runtime |
| `src/Modules/Core/Interfaces/HTTP/Middleware/CsrfMiddleware.php` | 3024 | Interfaces | concrete | 8 | runtime |
| `src/Modules/Core/Interfaces/HTTP/Middleware/MiddlewareInterface.php` | 373 | Interfaces | interface | 1 | contract |
| `src/Modules/Core/Interfaces/HTTP/Middleware/MiddlewareRegistry.php` | 6847 | Interfaces | concrete | 3 | runtime |
| `src/Modules/Core/Interfaces/HTTP/Middleware/PageCacheMiddleware.php` | 1624 | Interfaces | concrete | 1 | runtime |
| `src/Modules/Core/Interfaces/HTTP/Middleware/RateLimitMiddleware.php` | 5853 | Interfaces | concrete | 0 | runtime |
| `src/Modules/Core/Interfaces/HTTP/Middleware/SecurityHeadersMiddleware.php` | 4343 | Interfaces | concrete | 0 | runtime |
| `src/Modules/Core/Interfaces/HTTP/PageContext.php` | 10998 | Interfaces | concrete | 12 | runtime |
| `src/Modules/Core/Interfaces/HTTP/Router.php` | 13405 | Interfaces | concrete | 5 | runtime |
| `src/Modules/Core/Interfaces/HTTP/ShellContext.php` | 2216 | Interfaces | concrete | 7 | runtime |
| `src/Modules/Core/Interfaces/HTTP/ThemeKernel.php` | 1844 | Interfaces | concrete | 9 | runtime |
| `src/Modules/Core/Interfaces/HTTP/ThemeRegistry.php` | 7821 | Interfaces | concrete | 9 | runtime |
| `src/Modules/Core/Interfaces/HTTP/ThemeResolver.php` | 2991 | Interfaces | concrete | 13 | runtime |
| `src/Modules/Core/Kernel/Contracts/ErrorRendererInterface.php` | 357 | Kernel | interface | 0 | contract |
| `src/Modules/Core/Kernel/Contracts/RouterInterface.php` | 543 | Kernel | interface | 0 | contract |
