# Modules — adding and removing

A module is a slice of the application that can be installed and, provably,
removed again. The catalogue is `modules/<Name>/`; installation copies the
slice to `src/Modules/<Name>/`, removal deletes it. Everything the tool knows
about a module it read from the module's own files — never from a list the
kernel would have to keep.

## The tool

`bin/cms` is plain PHP with zero dependencies (the kernel runtime has no
Composer packages, so it cannot be a symfony/console application). Exit 0 on
success, 1 on refusal or error; refusals go to STDERR and copy nothing.

| Command | What it does |
|---|---|
| `php bin/cms module:list` | installed + catalogued modules |
| `php bin/cms module:show <Name>` | the manifest — what WOULD be installed, before anything is copied |
| `php bin/cms module:add <Name>` | copy the slice in; flush the page cache |
| `php bin/cms module:remove <Name> [--force]` | delete the slice; flush the page cache; report (never delete) the data |

## What the scan sees

Routes from `#[Route]`, migrators from `#[AsMigrator]`, middleware from
`#[AsMiddleware]`, nav badges from `#[AsNavBadge]`, markdown blocks from
`#[AsMarkdownBlock]`, shell providers from `#[AsShellContextProvider]`, themes
from `implements ThemeModule`, assets from `assets/` directories, the SQLite
file from the module name (`Scratch` → `storage/data/scratch.db`), and the
dependency closure from `use Modules\<X>\…` across the module's own files
(never self, `Core` or `Database` — those are the tree, not something in it).

The scan reads files as TEXT and never loads a class it describes, so a module
whose dependencies are absent is still describable. A scanner that fatals on a
missing class cannot report the very closure that is missing.

## The closure rule

`module:add` refuses when any closure member is not installed, names the
missing modules, and copies nothing. `module:remove` refuses while any
remaining installed module imports the name, names the importing file, and
leaves the slice untouched. `--force` is the only way past a refusal.

`Core` and `Database` can never be removed — they are the kernel itself.

## module.json (optional)

Only for what a scan cannot see: an npm package, a PHP package, a runtime
dependency on another module.

```json
{
  "depends": ["Locales"]
}
```

A module written before this capability existed installs unchanged, on the
strength of the scan alone — `modules/Scratch/` ships no `module.json` on
purpose, so the suite keeps proving that path.

## Removal never deletes data

The code is not the data. `module:remove` deletes the slice and reports what
it leaves behind: the module's `storage/data/<db>.db` (with `-wal`/`-shm`/
`-journal` when present), its uploaded assets and its frontend package entry.
The operator may hold the only copy; a removal that silently deletes it is not
a removal, it is loss.

## The round trip (measured 2026-10-11)

`modules/Scratch` — one route (`GET /scratch`), zero non-kernel imports — is
the lifecycle fixture:

- hash of the tree (excluding `.git`, volatile `storage/` and `.lekalo/`):
  `812636c7…` before and `812636c7…` after — byte-identical;
- `module:add Scratch` → `/scratch` answers **200** (`<title>Scratch</title>`);
  a second `module:add` is refused (`already installed`, exit 1);
- `module:add Provisional` (a throwaway slice importing `Modules\Nope`)
  is refused by name (`missing dependencies: Nope`, exit 1, nothing copied);
- `module:remove Scratch` while a holder imports it is refused with the
  importing file named (`src/Modules/Holder/Holder.php`, exit 1);
- after a real `module:remove`, `/scratch` answers **404** on a fresh server
  process; `/` and `/docs` still answer **200**.

Restart the server between phases: the built-in server is one long-lived
process, and a class it already declared outlives the file it came from. A
check run against a removed-but-remembered class is a green run of a file that
no longer exists — the same stale-bytecode lesson `docs/kernel-status.md`
records for edits.

## Themes travel as modules (measured 2026-10-11)

A theme arrives with the module that ships it: `ThemeRegistry` discovers every
installed class implementing `Themes\ThemeModule`, `ThemeKernel` resolves
through it (the static table keeps the kernel theme and always wins over
discovery, so no module can shadow the fallback), and `ThemeResolver` accepts
a provider-named theme when it is whitelisted or discovered. A removed module
takes its theme with it — nothing is edited, nothing lingers.

`modules/Erik/` is the proof: a trimmed `ErikTheme` (er-* vocabulary,
dark-first, one critical CSS file) rendering from `ShellContext` +
`PageContext` alone. The showcase Erik cannot live here — it imports
Blog/Locales/Settings, modules this tree does not have — so this one is the
smallest Erik that is still Erik, not a copy that fatals.

The slice also carries a fixture provider answering exactly one question
(`theme()` → `erik`, everything else abstained), because a theme the port
never names is a theme no surface renders: with zero other modules the kernel
theme would win every time and installing Erik would change nothing anyone
can curl. The provider travels with the slice, so removing the module removes
the vote.

- hash `b2bb62c8…` before and after — byte-identical;
- `module:add Erik` → `erik` discovered, `/` answers **200** in the `erik-shell`
  body class with `er-header__brand` chrome and the critical CSS inlined
  (45-line `<style>`, no `dist/` references — the absent Vite manifest
  degrades to the theme's own bytes, not to another theme's);
- `module:remove Erik` → discovery empty, `/` answers **200** back in
  `kernel-shell` with `k-header__brand`.

A theme that cannot be constructed is skipped and logged, never fatal — the
answer to a broken theme is the kernel theme, not a 500 on every surface.
