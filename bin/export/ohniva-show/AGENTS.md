# AGENTS.md

This tree is **generated** by
`bin/build/export-core.sh --profile bin/export/ohniva-show` in
[`responsive-sk/nativa-php-core`](https://github.com/responsive-sk/nativa-php-core).
`.nativa-ohniva-show-export.json` names the commit it came from.

Do not edit a file here. A change belongs in the source repository, where the
gates live; the next publish replaces this tree. An edit made here is lost and,
until it is lost, it is a fork nobody measures.

## Working here

- `make gates` prints every pin and gate. The registry is `toolchain.json`, and
  the workflow in `.github/workflows/ci.yml` is its CI side.
- The kernel runtime has no Composer packages. `composer install` is dev
  tooling; the tree boots without `vendor/`.
- `src/Modules/` and `modules/` are both tracked on purpose: the slices make a
  clone boot, the catalogue keeps `module:list`, `module:remove` and the panel's
  Modules page working. `php bin/cms module:show` must report identical.
- A kernel file may not import a module. `make mago-guard` enforces it.
- `storage/` is runtime state. The content here is an empty seed; the live site
  owns its own `storage/`.
- `public/dist/moonshine` is the committed admin bundle. The public theme
  bundle is built by `make assets` and gitignored.

## Commit messages

`type(scope): subject`, with the type from `feat`, `fix`, `docs`, `refactor`,
`test`, `chore`, `ci`, `build`, `style`, `perf`, and no agent footer.
