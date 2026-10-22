# Part 08 — the docs surface that served nothing, and the reader layout it got

**Boot 45.** One round, two questions: *what does `/docs` actually publish?* and,
asked while the first was being answered, *why do its lines run the width of a
desktop?*

## What was measured first

`GET /docs` answered **200** with "No guides are published in this deployment." —
on this checkout and on the live deployment, identically. The obvious reading
("the docs are not deployed") was wrong in a specific way: `docs/` was there,
six guides and seven log parts, and the catalogue that decides what `/docs`
serves listed **sixteen keys with zero files behind them**.

Key by key, all sixteen were `UPSTREAM-OK` in `../nativa-minimal/docs/`: the map
was the upstream guide list, copied when the kernel slice was imported and never
followed by the guides themselves. `DocsCatalog::entries()` drops a key whose
file is absent — deliberately, so a deployment without `docs/` reads as a missing
document rather than a broken site — so the stale map degraded to an empty index
instead of a list of 404s. The guard that would have said so did not exist.

Measured before the change, `php -S 127.0.0.1:8123 -t public/`:

| request | answer |
|---|---|
| `GET /docs` | 200, "No guides are published in this deployment." |
| `GET /docs/<each of 13 PUBLISHED keys>` | 404 |
| `GET /docs/kernel-status`, `/docs/modules`, `/docs/themes` | 404 |
| `GET /docs/nope` | 404 |
| `GET /sitemap.xml` | 200, `/` and `/en/` only — no `/docs` |
| `GET /llms.txt` | 200, links `/docs` |

## What changed

**The map is this tree's, in reading order.** `intro` first, then
`core-philosophy` (the boundary rule and the port pattern the module guides
inherit), then `modules`, `pages` (the first guide on the template derived from
the two before it), `themes`, `kernel-status` and its seven parts drawn from the
same round-based log, then `mago-evaluation`, `kernel-shape`,
`name-ownership`. Order in the array is order in the index.

**Three guides were written, not imported.** `docs/intro.md` states the
kernel/module/theme split in one paragraph each and points at the next read;
`docs/core-philosophy.md` states the sentence the tree is built on — *a kernel
file may not import a module* — with its two guards, the three-part port shape,
the closure rule, and "removal is not deletion"; `docs/pages.md` is the first
module guide and therefore the template: **what it serves, what it owns, what it
refuses**, closed by where it sits in the tree.

**The excluded families are named where the map can refuse them.** `clanky/` is
the article archive — `storage/blog/` is the source of truth and `/blog/<slug>`
is where a Slovak dated article is indexed — and `prose/` holds OpenProse
contracts, which are instruction files for an agent rather than prose a public
URL may serve. The upstream families (`security-*`, `PR-summary-*`,
`rhythm-score-matrix`, `admin-templates`, `lekalo/`, `dex/`) stay named so the
next copy is refused by default.

**The reader layout lives in the kernel, once.** `FrontendLayout::readerLayout()`
emits a scoped `<style nonce>` block and wraps the composed document: a sticky
"On this page" rail built from `MarkdownRenderer::extractHeadings()` — the
anchors the renderer itself generated, so a rail link cannot point at an id the
page does not have — and a prose column bounded at **68ch**, because a paragraph
at full width on a 1440 px display is ~180 characters and the reader loses the
line on return. Code blocks and tables opt out so a wide sample is not squashed.
Fewer than two headings means no rail and full width: a one-entry table of
contents is furniture.

Two surfaces call it: `/docs/<key>` (the guide) and `/blog/<slug>` (the article).
That is the point of putting it in the kernel rather than in a theme — the
themes are a copy of `nativa-themes`, where a hand edit is drift the gate reports,
so a rule five themes must each carry and a sixth silently misses belongs on the
calling side. **One rail, never two:** a theme whose article already composes a
contents rail (Boson, Jednotka, Mono and Griffe all do) is left untouched, and
the check is the marker every rail carries — `aria-label="On this page"` — read
off the composed HTML rather than guessed from the theme name.

A note on why the theme contract did NOT grow a `$headings` argument: PHP
requires an implementation to accept every optional parameter the interface
declares, so adding one would break every installed theme at load time. Measured
here — Boson answered 500 with a signature incompatibility until the interface
was reverted.

The generic index floor gained the same measure and a grouping by the key's first
segment — a group is a directory under `docs/`, and its label is that directory's
name made readable, never a second list the kernel would keep in sync.

## Measured after

Same server, restarted (the built-in server is one long-lived process and keeps
the bytecode it already compiled — the lesson of part 01):

| request | answer |
|---|---|
| `GET /docs` | 200, **16 guide links**, grouped |
| the 16 keys, incl. `kernel-status/01…07` | **200 each** |
| `GET /docs/nope` | 404, the theme's own error page ("That guide is not published.") |
| `GET /sitemap.xml` | 200, `/` and `/en/` — still no `/docs` (noindex surface) |
| `GET /llms.txt` | 200, still links `/docs` |

Rail entries, counted off the served HTML: `intro` 4, `core-philosophy` 5,
`pages` 5, `modules` 23, `kernel-status` 4, `kernel-shape` 4.

Gates: `composer test` **636 tests / 5 592 assertions / 1 skip**, `phpstan`
level 9 clean, `cs-fix-dry` 0 of 125, `rector-dry` OK, `make lekalo-check`
23 modules valid, `make mago-guard` **0 issues**, `make mago-lint` **0 findings**,
`make themes-check` identical=19 diverged=0.

## Two new guards, each stated rather than implied

`tests/Architecture/DocsCatalogContractTest` (38 cases) holds the map to the
tree: every PUBLISHED key resolves to a file, every guide declares its own H1
(the renderer cuts it out by position and the layout renders it),
`rewriteLinks()` only ever rewrites a target this surface can serve, `isSafeKey()`
refuses `..`/backslash/URL escape before any file is opened, a deployment without
`docs/` renders the empty state instead of failing, and the private families stay
private. Its `clanky` check names the two drafts that have no blog pendant
(`model-bez-vazby`, `posledna-evolucia`) instead of demanding a pendant today: a
draft nobody remembers is the defect, not a draft that is not published yet.

`tests/Http/DocsSurfaceDiscoveryTest` (4 cases) states the arrangement between
the three surfaces that could advertise docs: the sitemap lists **no** docs URL
(`/docs` is `noindex` with no canonical, and a sitemap entry for a noindex page
contradicts the page), `llms.txt` **does** link `/docs` (its empty state is a 200
page, so the link never promises a 404), and neither surface names a module route.

`tests/Http/ReaderLayoutTest` (6 cases) holds the reading surface: the rail is
built from the headings it is given, a document that already carries one is left
byte-identical (never two), fewer than two headings means no rail and the full
measure, code and tables keep the full width, the style block carries the nonce
placeholder, and rail text is escaped.

## What this round did not close

- **`storage/pages/` is empty**, so `/{slug}` has nothing to render and the
  `pages` guide describes a module with no content behind it in this checkout.
  That is the tree's state, not a defect this round introduced.
- **Only `intro`, `core-philosophy`, `pages`, `modules`, `themes` and the log
  are prose someone edited this round.** `kernel-shape`, `name-ownership` and
  `mago-evaluation` are unchanged bodies now reachable through `/docs` — their
  own staleness is their own round's business.
- **The Boson `bo-docs__text` card copy has no measure of its own**; it is
  bounded by its grid column (320 px minimum), which reads well, but a theme
  with a single-column index would need the `data-docs-prose` attribute the
  generic floor now carries.
- **The prose experiment is a draft.** `docs/prose/docs-freshness.prose.md` is
  outside PUBLISHED on purpose; nothing runs it in CI. The enforced half is the
  contract tests above.
