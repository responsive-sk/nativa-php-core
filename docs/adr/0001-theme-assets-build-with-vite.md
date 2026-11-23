# ADR 0001 > Theme assets are built by Vite

- Status: accepted, 2026-11-24
- Supersedes: none
- Gate: `tests/Architecture/BundlerDecisionContractTest.php`

## Context

The kernel is PHP with no Composer packages, and its frontend is a pnpm
workspace at `Frontend/packages`, one package per theme. Each package builds
into `public/dist/<theme>/`, and PHP reads the result through one file:
`manifest.json`. `Themes\AssetManager` probes the entry key and the source
variants of it inside that manifest, so an entry key is a PHP-visible name, not
an implementation detail.

The build carries five constraints that are not negotiable, because the pages
they produce are measured and pinned:

- `target: 'es2015'`, for a Chrome 70 and Android 9 baseline.
- One self-contained file per entry. A shared chunk would emit `import`
  statements, and the deferred classic `<script defer>` cannot execute them.
- No `@layer` in the output, because Chrome 70 drops the whole block.
- The manifest is written beside the bundle it describes.
- The build is reproducible from the catalogue under `modules/Themes/`, not
  from the installed copy under `src/Modules/`, which is gitignored.

Today that shape lives in one place,
`Frontend/packages/build/vite-theme-factory.ts`, and every theme's
`vite.config.ts` is eight lines that call it. What is missing is the decision
above it: why this bundler, and what a change may not introduce in its place.
Without that, the answer to "can we try webpack for this one package" is a
reviewer's memory rather than a recorded fact.

## Decision

**Vite is the only bundler in this tree.** One version family, one config
shape, one manifest contract, one place where the target and the output rules
live.

- A theme package builds through
  `createThemeConfig({ theme, inputs })` from
  `Frontend/packages/build/vite-theme-factory.ts`. A package may not carry its
  own copy of the target, the manifest setting or the output directory.
- A new theme adds a row to `THEME_PORTS` and an inputs record. That is the
  whole build-side cost of a theme.
- An extra entry, including a per-surface entry such as `boson-article`, is an
  ordinary key in `inputs`. The key names the source file and becomes the name
  PHP probes; nothing about the build changes because an entry exists for one
  page.
- A bundler change is a new ADR that supersedes this one, with the measurement
  that motivated it. It is never a `package.json` edit inside a theme task.

## Rejected alternatives

| Tool | Why not |
|---|---|
| Webpack | a second config language, a loader stack per file type, and the same output we already have. Nothing in the measured payloads asks for it |
| Turbopack | a Next.js dev server, not a library build. Adopting it moves a PHP tree's assets inside a JavaScript framework it does not use |
| Rspack | webpack-compatible config without webpack's maturity, for a config we would have to write first |
| Parcel, Snowpack | unmaintained or retired; a new build would start on an EOL tool |
| Rollup directly | Vite already wraps Rollup (Rolldown in the pinned major), and a direct config would fork the manifest and the output rules |
| esbuild directly | Vite already runs it for transforms. A direct call puts the target, the `@layer` strip and the naming in a second place |
| One bundler per package | the manifest contract and the baseline would drift per theme, which is the defect `ThemeBundleDiscoveryTest` exists to catch |

## Consequences

- The gate reads this file's machine block and asserts two directions: no
  package declares a rejected bundler, and every theme config reaches the one
  factory. Deleting a rule here fails the gate, so the decision cannot rot
  into prose.
- A bundler shipped by a dependency is not policed. The gate checks declared
  dependencies in `Frontend/**/package.json`, not the lockfile, because a
  transitive tool is not a choice.
- Changing the accepted bundler means editing the block below and writing the
  superseding ADR in the same change. The two are one fact.

```json
{
  "accepted_bundlers": ["vite"],
  "rejected_bundlers": [
    "webpack",
    "turbopack",
    "rspack",
    "parcel",
    "snowpack",
    "rollup",
    "esbuild"
  ]
}
```
