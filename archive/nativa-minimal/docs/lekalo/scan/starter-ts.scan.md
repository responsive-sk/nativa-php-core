# Starter TS bundle — Lekalo scan evidence (#44)

> Evidence-only record. Nothing here is executed in CI and nothing from the
> scan cache is committed (gitignored under `storage/cache/lekalo/`).

## Toolchain

| Component | Identity |
|---|---|
| CLI | `lekalo 0.3.2` |
| Adapter | `lekalo-target-node-typescript` v0.3.2, entry digest `sha256:bf30659659570a45764a98f2152e0da06cf6d80e325cef4faf876b270497d55d` |
| Compiler | vendored `typescript@5.9.3` |
| Source tag | `v0.3.2` (`adapters/node-typescript/` from the upstream clone) |

## Method (reproducible)

The scanner requires **lowercase root segments** (lexical root validation in
the kernel refuses uppercase root segments — at scan time the bundle lived in
`Templates/…`, since the 2026-09-22 rename to `Frontend/…` the root is
lowercase and the mirror step below is only needed for historical reproduction)
and one Program per launch, so the scan runs
over a **disposable lowercase mirror** of the bundle:

1. `git clone --depth 1 --branch v0.3.2 https://github.com/ichinya/lekalo
   storage/cache/lekalo/lekalo-src`
2. Mirror: `cp -r Frontend/packages/. storage/cache/lekalo/nativa-ts-mirror/`,
   drop `node_modules`, strip CSS side-effect imports (`import './x.css'`)
   from the mirrored `.ts` files (unresolvable by the vendored compiler →
   honest uncertainty, zero symbol information lost).
3. One launch profile per Vite entry — the four entries share module-level
   helpers by design, so a single whole-tree Program reports TS2451 redeclare
   noise; per-entry Programs (single-file `files:` tsconfig seed, `lib:
   ["ES2015","DOM","DOM.Iterable"]`, `strict:false`) mirror the real Vite
   build, which also bundles each entry independently:
   ```json
   {"id":"standalone","mode":"observed","target":"node-typescript",
    "readRoots":[{"path":"tsconfig.json","kind":"file"},
                 {"path":"src/<entry>.ts","kind":"file"},
                 …helper files for main…],
    "exclusions":[],
    "provenance":{"origin":"declared","revision":"nativa-starter-ts-<entry>",
                  "disposition":"unconfirmed"}}
   ```
4. Adapter-direct one-shot exchange (the CLI `lekalo scan` merge path is
   intentionally **not** used — see "Bindings" below):
   ```sh
   node …/adapter.mjs --lekalo-project-profile-json "$(cat <profile>)" <<REQ
   {"protocol":"lekalo.target/v1","protocol_version":"0.3.2","operation":"scan",
    "request_id":"req-<64hex>","project_root":".","target":"node-typescript",
    "profile":"standalone"}
   REQ
   ```

## Results (2026-09-21)

| Entry | Outcome | Entries (proposed semantic ids) |
|---|---|---|
| `src/magazine.ts` | `ok` | 3 — `project.onDomReady`, `project.initBackToTop`, `project.initMobileMenu` (q=medium) |
| `src/minimal.ts` | `ok` | 2 — `project.initBackToTop`, `project.onDomReady` (q=medium) |
| `src/moonshine.ts` | `ok` | 0 — no top-level exports (script-style bundle, correct) |
| `src/main.ts` | `partial` (honest refusal) | 0 — residual `any-surface` on `containerQueryManager` (the exported `ContainerQueryManager` instance's type contains the private `WeakMap` field under inferred types; not resolvable without fabricating a type) |

Symbol families indexed across entries: DOM-ready orchestrators
(`onDomReady`), scroll handlers (`initBackToTop`), menu builders
(`initMobileMenu`, magazine), the container-query fallback manager
(`containerQueryManager`, main — evidence partial as above). Structural
signature digests and typed reference rows were present on every emitted
entry (`evidence.signature` non-null).

## Bindings — deliberately none

The scanner proposes semantic ids scoped by the **package name**
(`@nativa/starter` → scope `nativa_starter` → `nativa_starter.<symbol>`).
The canonical model has no such module (declared modules: `users`,
`comments`, `blog`, `themes`, `design`) and the change's forbidden surface
excludes `lekalo/modules/**`, so:

- `bindings propose/confirm` were **not run** — a merged scan would refuse
  with `observed.unknown-module` (LEK-OBS-003), and minting a `frontend`
  module for proposals with no semantic counterparts is out of scope.
- The observed index stays empty: `bindings list/audit` report
  `observed.index-missing` (LEK-OBS-004) — the expected, honest state for a
  project that never merged scan inventory.
- This record is the evidence home; if the model ever grows a frontend
  module, re-run the per-entry scans through the CLI merge path and confirm
  bindings selectively.

## Cadence

Manual, on TS surface changes (new entry, new exported helper family):
re-clone the adapter at the pinned tag, rebuild the mirror, re-run the
per-entry profiles. The mirror is disposable (`storage/cache/lekalo/` is
gitignored); the only committed artifact is this record.

## Gates after the scan (unchanged, verified)

- `lekalo lock --check` → exit 0; `lekalo update --dry-run` → `+0 ~0 -0`
- `lekalo validate` → exit 0 (model untouched)
- `lekalo bindings audit` → `observed.index-missing` (documented above)
