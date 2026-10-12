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

## A second theme: Mono (measured 2026-10-11)

`modules/Mono/` carries the evan70.github.io/mono vocabulary (navbar, hero,
feature cards, footer) as `MonoTheme`. The CSS is a vendored, prefixed
snapshot of the live site's two stylesheets (2026-10-11, ~19 kB → `mo-`):
the source classes are unprefixed and would collide, the `@font-face` webfonts
are dropped (their files are not vendored — system stacks instead), and the
hero photo is dropped (an external webp the kernel must never fetch at
runtime; the gradient stays). The adaptation script caught its own blind spot
on the first run: compound selectors (`.a.b`) skip the regex lookbehind, so
four second-classes shipped unprefixed until a second pass closed them —
verified by grepping the vendored file for unprefixed selectors (zero left).

What the preview page never had is designed in its idiom, not ported: the
locale switcher (navbar links), the data-driven nav, the blog views (card
grids). Every `mo-` class the markup emits has a CSS rule (checked
mechanically). The hero title renders `mo-is-visible` server-side — on the
live site scroll-JS adds it, but this tree ships no such JS and without the
class the H1 keeps `opacity:0` (a homepage that renders its own title
invisible; caught over HTTP, fixed, re-measured).

### The article view (measured 2026-10-12)

The vendored snapshot is a LANDING PAGE — navbar, hero, feature cards, footer.
The kernel serves surfaces that page never had, and each of them reaches the
DOM wearing `mo-` classes no rule matched. On `/blog/nativa-php-core`, before
this pass: **27 `mo-` names without a rule** (20 classes — 9 comments, 11
questions — plus 7 `id` attributes, which need none), and the article's own
elements bare: no `pre`, no `code`, no `table`, no `th`/`td`.

Same defect class the Frontend packages already guard against, in a tree that
cannot run their generator: `CommentSection` and `QuestionBlockRenderer` build
every class from the ACTIVE THEME'S prefix, so `mo-comments` can only ever
match rules written for mono, and mono has no package under
`Frontend/packages/` for `bin/generate/generate-*-css.mjs` to stamp.

Added to the vendored file, in its idiom (mono tokens, minified, one rule per
line): prose (`pre`, `code`, `pre code`, `table`, `th`/`td`), the two reaction
blocks (`mo-comments*`, `mo-questions*` — all 33 classes the two renderers can
emit), and the two structural wrappers the docs/pages surfaces emit
(`mo-section`, `mo-article__body`).

| | before | after |
|---|---|---|
| inlined stylesheet on the article page | 19 056 chars | **23 827 chars** |
| `mo-` classes without a rule, across `/`, `/blog`, `/docs`, the article | 27 on the article; `mo-section` on `/docs` | **0** |

Verified in chromium over the running server, in both colour modes — the rules
name mono tokens only, so `[data-theme=light]` needs no second copy:

- **dark**: button `rgb(212, 175, 55)` on ink `rgb(10, 10, 10)`, `pre` bg
  `#1a1a1a` with a `#2d2d2d` border and 8px radius, `pre > code` reset to
  transparent / 0 padding, `th` bg `#1a1a1a` weight 600, `td` bottom border
  1px `#2d2d2d`, the honeypot `display:none`;
- **light**: the same rules through the light palette (`#f8fafc` surfaces,
  `#e2e8f0` borders, `#926f00` button) — the light block shipped dormant all
along and needed nothing new;
- the class check re-run over every served page: zero `mo-` classes without a
  rule.

One rule has no element to style in this deployment: `mo-article__body` is
emitted by `DocsPageAction` / `PageViewAction` for a guide or page that has
content, and no guide is published here — so it was verified as a parsed rule
(the browser accepted it) rather than against a rendered element.

Not covered, recorded so the next reader does not mistake them for present:
`blockquote` and the article body's headings/lists still render with browser
defaults, and `mo-inline-edit*` (9 classes from `BlogInlineEditor`, rendered
only for a signed-in editor) has no rules at all.

The slice carries the same one-question fixture provider (`theme()` → `mono`).
Never install Erik and Mono together: both vote, the merge rule takes the
first, and a theme decided by glob order is a theme nobody chose.

- hash identical before and after the round trip;
- `module:add Mono` → `/` answers **200** in `mono-shell`, hero H1 visible,
  the full CSS inlined, zero runtime fetches (no `url(`, no `@import`, the
  single `http` href is the page's own canonical);
- `module:remove Mono` → `/` answers **200** back in `kernel-shell`.

The first hash pair of this round trip mismatched — honestly recorded: two
catalog fixes (an unprefixed `btn--sm` in the markup, the missing
`mo-is-visible`) landed between H0 and H1, so the hashes compared different
trees. The lifecycle was clean; the protocol was not. Re-run on the frozen
tree: identical.

### The white page (measured 2026-10-11, fixed)

Mono rendered a white page in a real browser while every curl check was
green: the vendored header comment said `No @charset/@import/@layer`, and
`InlineCss::stripForbiddenAtRules()` — which cannot tell an at-rule from its
name inside a comment — ate from `@charset` to the next semicolon, which lay
past `*/` inside `:root{--brand-gold:#d4af37;`. The `:root` opener died, every
`var(--…)` went undefined, `body` lost its background. Curl saw a 200 with a
`<style>` block; only a browser saw the white.

Two fixes, both committed: the stripper removes `/*…*/` comments BEFORE
at-rules (a safety net that corrupts what it guards is not one), and both
theme headers no longer name the at-rules even in prose. Re-measured:
`:root{--brand-gold:#d4af37;` and the `body` rule present in the served
`<style>`, zero comment residue. Erik's header had the same trap and survived
by luck — its first semicolon fell inside the comment.

### The dev variant switcher (measured 2026-10-12)

The upstream `navbar__actions` carries dev-preview palette switchers
(`dev-theme-switcher` toggling `neon.css`, a second button `fire-show.css`);
both stylesheets are `:root` variable overrides only (~1–3 kB), which is what
makes the port cheap. Mono keeps the shape but not the palettes: `ember`
(warm orange on near-black) and `glacier` (ice-cyan) live in the vendored
`mo-variants.css` as `html[data-variant]` blocks, the upstream webfont
overrides dropped (no external dependencies — the system stack stays).

The first button keeps the upstream `id="dev-theme-switcher"`; both key off
`data-variant-toggle`, mutually exclusive, second click toggles off, choice
persisted to `localStorage` (`nativa-variant`). The tracker is the same
vendored no-build twin idiom as the highlighter and the scrollspy
(`mo-variant-switcher.js`, ungated — its buttons precede every page), and the
persisted variant restores pre-paint from `MonoTheme::themeInitScript()`, which
reproduces the shared dark/light restore plus the variant. Without JS the
buttons are inert and the page stays in the default palette.

Verified over CDP against the running server: click → `data-variant="ember"`
with `--brand-gold: #ff7a1a` computed, `aria-pressed` synced, reload restores
pre-paint, second click clears. `MonoThemeTest` pins the ids, the stylesheet
selectors, and the init-script restore.
