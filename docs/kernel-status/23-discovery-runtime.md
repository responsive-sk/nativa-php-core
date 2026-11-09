# Boot 60: discovery runtime leaves the HTTP kernel

`DiscoveryCache` caches the route and middleware scan, and `PreferenceVote` is
the contention rule every provider registry shares. Both lived under
`Modules\Core\Application\Services`, although neither answers a request: one
loads a scan result at boot, the other decides which of two providers at the
same priority wins and which is logged as ignored.

They moved to the new kernel-owned `Discovery` namespace, the same shape the
`Shell`, `Themes` and `View` splits used. No module was imported by either
class, and no module API changed: only the namespace and the owning directory
move.

## Before

- `src/Modules/Core`: **170 284 B / 40 files** (8 interface / 30 concrete).
- `KernelShapeTest::MAX_APPROVED`: **30**.
- Source suite: **1101 tests / 7251 assertions / 1 skipped**.

## What moved

| Surface | Bytes | Old path | New path |
|---|---:|---|---|
| `DiscoveryCache` | 12 567 | `Core/Application/Services` | `src/Discovery` |
| `PreferenceVote` | 1 885 | `Core/Application/Services` | `src/Discovery` |

The moved files are 24 B smaller each because `namespace Discovery;` is shorter
than `namespace Modules\Core\Application\Services;`. Core loses the old sizes;
the new root carries 14 404 B.

## Wiring

`composer.json`, `src/init.php`, `phpstan.neon`, `.php-cs-fixer.dist.php`,
`mago.toml` and `NameOwnershipContractTest::SCOPE` gained the `Discovery` root.
The Mago perimeter has a rule for it: `Discovery` may read `Modules\Core` and
global names, never a module. `Shell` and `Modules\Tooling` may read
`Discovery`, because the shell registry and the module installer are its
callers.

`ModuleInstaller`, `bin/cms`, `src/http.php`, `ShellContextProviderRegistry`,
`DiscoveryCacheTest` and `PreferenceVoteTest` import the new namespace. The
catalogue's `modules/Auth/Application/Services/AuthProviderRegistry.php` does
too, and the installed Auth slice was reinstalled with
`module:remove Auth --force && module:add Auth` so the operator copy matches.

`KernelShapeTest` lost both allow-list rows and lowered `MAX_APPROVED` from 30
to 28. `bin/build/kernel-divergence.txt` records the old paths as `DROPPED` and
adds `OWNED src/Discovery`.

## After

- `src/Modules/Core`: **155 832 B / 38 files** (8 interface / 28 concrete).
- `KernelShapeTest::MAX_APPROVED`: **28**.
- Source suite: **1101 tests / 7250 assertions / 1 skipped**. The count is one
  assertion lower than the previous boot; no test was removed or skipped.
- `make lint`, `composer phpstan`, `make analyze`, `make mago-lint` and
  `make mago-guard` are green.
- `make extract-check`: **186 identical / 102 recorded divergences**.
- `make core-export-check`: **181 files / 167 tests / 398 assertions / 52
  skipped**.
- `make dist-check`: clean clone -> **201 files**, no `vendor/`, smoke clean.
- Source CI: **16/16 jobs**, run `42689868658` on `2b6e22a`. The kernel-only
  boot job and the module-installed PHPUnit job both passed.
- Published export: `responsive-sk/nativa-core@84bee8b`, generated from source
  `2b6e22a`; public CI is **9/9 jobs**, run `42690300210`.
- Public clone with modules installed: `/`, `/sitemap.xml` and `/robots.txt`
  answer 200; `/llms.txt` and `/mark` answer 404; robots has no
  `Allow: /llms.txt` line.
- Fresh public clone with no `vendor/` and only `KernelTheme` installed: `/`,
  `/sitemap.xml` and `/robots.txt` answer 200; `/llms.txt` and `/mark` answer
  404; robots omits the LLM allow line.

## Still open

- `WebsiteStructuredData` is still the only Core service whose consumers are a
  module (`Pages`) and the kernel home. Extracting it cleanly needs a port for
  the home's JSON-LD, not another directory move.
- `SubmitResult` is used only by Comments, Media and Users; it is a candidate
  for a shared module, but that creates a module dependency for a value object
  and needs its own decision.
- `FileSessionHandler` still has no importer in this tree. It is runtime code
  waiting for a decision: wire it or delete it, not move it.
