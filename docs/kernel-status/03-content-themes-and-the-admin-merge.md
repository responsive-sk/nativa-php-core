# Boot 21 — an article written by the module, and a dist you can deploy

Two deliverables, one artifact each: content created through the Blog module's
own write path, and a deployable tree produced by a packaging script.

## The article

`storage/blog/nativa-php-core.md` — a Slovak article about this kernel,
discovered at `/blog` and served at `/blog/nativa-php-core` (200; ten `##`
sections; the feed carries it).

Three decisions worth recording, because each one could have been wrong quietly:

- **It was written by `BlogCrudService::save()`**, not by hand-editing a file.
  The module owns the front-matter serialization, and a hand-written header
  would be a second implementation of it that only *looks* the same until a key
  changes.
- **The category was registered first**, through `BlogServices::taxonomy()`
  (`storage/data/blog-taxonomy.json` now holds `Architektúra`), which is the
  order the `/mark/categories` surface assumes.
- **`author` is the display name and `author_email` is the login.** The admin
  create action writes the *login* into `author` (`ArticlesCreateAction::buildMeta()`),
  and the article page passes `author` straight into the JSON-LD — so an
  article created by that form publishes its author's e-mail address in
  structured data. Measured in the showcase's own content: 210 articles carry
  `author: "Nativa Dev"` against 4 carrying an address. The article follows the
  content convention, and the first draft here demonstrated the failure mode
  before it was fixed (`"author":{"@type":"Person","name":"nativa.dev@example.test"}`).

That last one is a real defect in the admin form, not a content problem. It is
recorded here rather than fixed: the fix belongs to the action (stamp the
resolved user's name next to the address it already stamps), and it is an
upstream file — changing it here would add a divergence entry for a bug that
should be fixed once, where it lives.

## The dist

`bin/build/build-dist.sh` — ported from upstream's script of the same name,
which produces exactly this kind of artifact (all real file copies, no symlinks,
so a plain FTP upload works), with three differences that are the kernel's:

1. **`bin/cms` stays.** Upstream deletes its console from the dist because that
   console extends `symfony/console`, a dev dependency. Here the console *is*
   the production tool (`module:add/remove`, `migrate`) and it is plain PHP by
   design — a dist that dropped it could not manage its own modules.
2. **Assets are not rebuilt inside the script.** The pnpm workspace is committed
   and `make assets` is the one build path, so the script delegates instead of
   keeping a second copy of the pnpm invocation. `--skip-assets` uses the
   existing `public/dist` and *fails* when there is none, rather than shipping a
   dist with no stylesheet.
3. **Databases are migrated, never seeded or copied.** The kernel has no
   seeders, and the developer's `storage/data/` is local state. `php bin/cms
   migrate` runs inside the artifact: nine databases, real schemas, fresh
   `config.db` defaults, 168 locale rows — and it doubles as the proof that the
   artifact boots with no `vendor/` at all.

What it ships: `src/` (the installed slices travel with the tree), `config/`,
`public/` (including the built `dist/`), `bin/cms`, `modules/` (the catalogue,
so the deployed tree can still manage modules), `docs/`, `README.md`,
`.env.example`, a fresh storage skeleton, content from `storage/{blog,pages,blog-images}`,
and `.build-info`. What it excludes, with a reason per line in the script:
history, CI, `tests/`, `Frontend/` sources, `vendor/`, `bin/build/`, the dev
tool configs, `.env*`, runtime storage state, the Lekalo model and the repo's
own ignore rules.

## Measured, end to end

```
bin/build/build-dist.sh          # 3.0 s including the asset build
  → dist/  5.3 MB, 630 files, vendor/: absent
  ok  /  200   /sitemap.xml  200   /robots.txt  200   /docs  200   /nope  404
  --  /mark  200   (the admin IS installed in this tree; not asserted)
  error log is clean
```

Then, against the artifact itself rather than the source tree:

| check | result |
|---|---|
| `GET /blog/nativa-php-core` from `dist/public` | 200, H1 + 10 sections |
| `php dist/bin/cms module:add Scratch` → `GET /scratch` | 200 |
| `php dist/bin/cms module:remove Scratch` → `GET /scratch` | 404 |
| `dist/vendor` | does not exist — and the `/mark` 200 above is the admin module serving with no Composer packages at all |

The last row is the interesting one: the admin theme's stylesheet comes from
`public/dist/moonshine/manifest.json`, so that page is the artifact's own proof
that `make assets` output, the module catalogue, the migrator runner and the
zero-dependency runtime all work in the same tree.

## Two `Modules` trees — and why the artifact ships one of them

The first version of this script shipped both, and the first person to open
`dist/` asked the obvious question: *"modules twice?"*. They are not copies of
each other — but the artifact does not need both, and the default now ships
one:

| path in the repo | what it is | size | shipped by default |
|---|---|---|---|
| `modules/<Name>/` | the **catalogue**: slices waiting to be installed | 1.8 MB, 16 | **no** (`--with-catalogue`) |
| `src/Modules/<Name>/` | the **installed copies**: what actually runs | 2.3 MB, 14 | yes |

Measured overlap, in the build that does ship both: **12 of the 14 installed slices are byte-identical to their
catalogue counterpart** (815 KiB of payload exists twice). Two differ, each for
a reason that is state, not a bug:

- `Blog` — the catalogue gained `tests/` this session; the installed copy
  predates it. (`diff -rq`: *Only in modules/Blog: tests*.)
- `Cardboard` — the installed copy still carries six JS files under
  `Interfaces/HTTP/assets/scripts/` that the catalogue deliberately dropped in
  `bbac4ee` ("kit assets un-coupled"), and **nothing references them**: a grep
  for `admin-shell.js`, `admin-theme-init.js`, `bulk-actions.js`,
  `compact-shell.js` across the module's PHP and templates finds no call site.
  They are stale leftovers in the working tree, not a missing file in the
  catalogue — a fresh `module:add Cardboard` produces the leaner, intended
  slice.

**Default: `modules/` is not shipped.** A deployment runs what is installed;
installing happens in the repo, which is where the catalogue belongs (it is
source, not runtime). Measured: **3.6 MB / 384 files**, and `dist/` carries the
word Modules once — `src/Modules/`. The capability is gone *cleanly* rather than
half-broken: `module:list` reports `Catalogued (0): (empty catalog)`,
`module:add Scratch` exits **1** with `cms: Module 'Scratch' is not in the
catalog (modules/Scratch/).`, and `module:remove` — which only deletes — still
works (verified by removing `Mono` from the artifact; two assertions, not one:
the command exits 0 and the directory is gone).

**`--with-catalogue` is the opt-in**, for a deployment that must install
modules on the target: **5.3 MB / 630 files**, and it reports the duplication it
brings instead of leaving it to be discovered inside the artifact:

```
==> Catalogue in the artifact (module:add works here)
    note: Blog — installed copy DIFFERS from the catalogue
    note: Cardboard — installed copy DIFFERS from the catalogue
    12 slice(s) byte-identical to the installed copy (a second copy of what runs)
    2 drifted, 2 not installed
```

# Boot 22 — testing the `erik` theme, and the asset defect it found

The question was "can we test the erik theme too?" — and testing it is what
found the bug, because the theme **rendered**: `er-` classes, correct markup,
200 everywhere. What it did not do was load its own stylesheet.

## What the theme is

`erik` is not a kernel theme. It arrives with the `Erik` module (3 files:
`Theme/ErikTheme.php`, `assets/critical/erik.css`,
`Interfaces/HTTP/ErikShellContextProvider.php`), and installing that module is
what makes it active:

```
php bin/cms module:add Erik          # 3 file(s) copied
ThemeRegistry::all()  → erik, mono
ThemeKernel::for('erik') → Modules\Erik\Theme\ErikTheme   (bundle=erik, prefix=er)
```

Note what that says about this tree: `settings.theme` is still `kernel`, and
`ThemeResolver::resolvePath('/')` answers `erik` anyway — the Erik **and** Mono
modules both ship a provider that hardcodes its theme
(`#[AsShellContextProvider(priority: 0)]`, "a real deployment answers `theme()`
from its settings module instead" — the Erik provider's own comment). Providers
win over the setting, first non-null in (priority, discovery) order, so with
both installed `erik` is active; removing Erik makes **`mono`** active, not the
kernel theme. The kernel theme is what you get when neither is installed.

## The defect: the built stylesheet was never used

`FrontendLayout` inlines the theme's CSS rather than linking it (deliberate —
it removes a render-blocking request). It asks `AssetManager` for the active
theme's name (`$themeModule->name()`, which is also what names the body class
`<body class="erik-shell">`), and `AssetManager::knownBundles()` was:

```php
[ ThemeResolver::ADMIN_THEME, ...ThemeResolver::PUBLIC_WHITELIST, 'minimal' ]
```

— a **static** list: `moonshine`, `kernel`, `minimal`. `erik` is not in it, so
the bundle resolved to `kernel`, the manifest under
`public/dist/kernel/manifest.json` does not exist, and the layout fell back to
the theme's critical file. Measured on `/`:

| | inlined CSS | `.er-` rules |
|---|---|---|
| before | **706 chars** (the 1119-byte critical file, minified) | 12 |
| after | **35 261 chars** — byte-equal to `public/dist/erik/assets/erik-A5GsJi84.css` | 412 |

A page styled with 2 % of its stylesheet and nothing in the log to say so. It is
the same shape as the defect `ThemeKernel`'s docblock already warns about (a
theme served another theme's stylesheet is "indistinguishable from a page that
works"), one layer down: this time the fallback was *correct* for the kernel's
own theme and silently starved a discovered one.

## The fix

The bundles this tree has are discovered the way its themes are.
`ThemeRegistry::bundles()` asks each discovered theme for `bundle()` — asked,
not derived from the theme's name, because the contract has both for a reason —
skips a theme that cannot be constructed (the same rule `all()` already
follows), and memoizes, since `AssetManager` reads the list from its
constructor (200 calls: 1.75 ms). `AssetManager::knownBundles()` adds it to the
static three. `ThemeRegistry::reset()` clears the memo alongside `$themes`.

Verified after the fix: `/`, `/blog`, `/blog/nativa-php-core`, `/docs` all
inline the 35 261-char build (412 `.er-` rules) and the `erik` JS bundle is
referenced; `/nope` still 404s; the error log stays empty. Removing Erik returns
the tree to `mono` with 19 058 chars of its own critical CSS and no `.er-`
rules — the discovery is not sticky. `AssetManager('kernel')` still reports
`available() = no` (there is no `public/dist/kernel/`), so the kernel-only tree
keeps the critical-CSS fallback it had.

## The tests

Two files, split the way this tree splits tests. `tests/` is inside PHPStan's
scope and `modules/` is not, so the half that names `ErikTheme` lives beside
the module and the half that names only kernel classes lives in the harness.

`modules/Erik/tests/ErikThemeTest.php` (the fixture): the theme is discovered,
`name()` and `bundle()` both answer `erik`, the bundle is in
`AssetManager::knownBundles()`, the rendered `<style>` block is byte-equal to
`public/dist/erik/assets/erik-A5GsJi84.css` **and** not the critical subset
(which is named explicitly, so the assertion cannot pass by the fallback
growing), the `.er-` vocabulary is present in quantity, the document wears
`erik-shell` + `er-header`/`er-main`/`er-footer` and no sibling's `k-`/`mo-`
class, and the live homepage — dispatched through `Kernel` — carries the
active theme's build.

`tests/Theme/ThemeBundleDiscoveryTest.php` (the door, closed for the next
theme): every theme `ThemeRegistry` discovers must declare a bundle
`AssetManager` knows, and every discovered theme whose build sits under
`public/dist/` must render a document containing that build's bytes. It walks
the same discovery `ThemeResolver` uses — and holds the same "no module class
names" line as every other file under `tests/` — so it runs and passes in the
kernel-only tree too, where the discovered set is simply empty.

Two details in those files are load-bearing, and both were measured:

- **The skip guard is the manifest on disk, never `AssetManager::isAvailable()`.**
  The first draft guarded the byte-parity assertions on `isAvailable()` — the
  exact call the defect breaks — so with the fix removed the test *skipped*
  instead of failing. Guarding on the path makes it fail, which is the whole
  job.
- **The live-surface test asserts against the resolver's answer, not against
  `erik`.** Installing another provider re-points the test instead of breaking
  it, while the defect still fails it.

Five tests now fail with the fix removed and pass with it in place — checked by
reverting only the `...ThemeRegistry::bundles()` line:

| | without the fix | with the fix |
|---|---|---|
| `ErikThemeTest::themeIsDiscoveredAndItsBundleIsKnownToAssetManager` | fails | passes |
| `ErikThemeTest::layoutInlinesTheBuiltStylesheetAndNotTheCriticalSubset` | fails | passes |
| `ErikThemeTest::liveSurfacesInlineTheActiveThemesOwnBuild` | fails | passes |
| `ThemeBundleDiscoveryTest::everyDiscoveredThemesBundleIsAKnownAssetBundle` | fails (`erik`, `mono`) | passes |
| `ThemeBundleDiscoveryTest::everyDiscoveredThemeWithABuildRendersItsOwnStylesheet` | fails | passes |

The whole suite: 24 tests, 76 assertions, 1 skip (the pre-existing Cardboard
one) — plus `phpstan`, `rector --dry-run` and `php-cs-fixer --dry-run` clean.

One gap the second test now covers but the kernel does not yet close: nothing
today has `name() !== bundle()`, while `FrontendLayout` hands `name()` to
`AssetManager` where the contract offers `bundle()`. A theme that renames its
bundle would be served under its name — the same wrong-stylesheet shape, one
step further in. The guard fails on it (`"does not inline bundle X"`) instead
of staying silent, which is why the assertion was written against the
declared bundle rather than against the theme name.

## State of the tree afterwards

Erik was installed to run this test and **uninstalled again** afterwards —
`bin/cms module:remove Erik`, which is a change to `src/Modules/` and therefore
gitignored. The live theme is `mono` once more: `/`, `/blog`, `/docs` all serve
its own 19 056-char critical CSS (211 `.mo-` rules, zero `.er-`), body
`mono-shell`, and `/nope` still answers 404.

That is why the erik suite SKIPS in this working copy — `ErikThemeTest` and the
build half of `ThemeBundleDiscoveryTest` guard on `src/Modules/Erik` being
installed, exactly as `TaxonomyRegistryTest` guards on Blog. Suite in this
state: 24 tests, 49 assertions, 6 skips. `bin/cms module:add Erik` puts it back
and all six run.

# Boot 23 — the menu nobody asked for (2026-10-12)

The Mono work ended with a styled page and a navbar carrying a brand and
nothing else. The vendored CSS was fine; the document simply had no
`mo-navbar__menu` in it. Asked to "add the nav menu", the honest first step was
to find out who was supposed to answer it — and the answer was nobody.

## What was missing

Boot 3 moved navigation behind the shell port. Upstream, `FrontendLayout` read
it straight from `SettingsServices::menu()->menu('header'|'footer', $path)`;
here that call became `ShellContextProviderRegistry::navigation()`, whose merge
rule is **first provider that returns a non-empty list for that slot** — and no
installed module implemented `navigation()` at all. Only the two theme fixtures
did, and both answer `[]` on purpose. The rule is also the reason the failure
was invisible: an empty answer *is* the "no menu" answer, so an unasked question
renders exactly like a site with no menu configured.

Measured: `header: 0 items`, `footer: 0 items` on every path, and
`mo-navbar__menu` absent from `/`, `/blog`, `/docs` and the article.

## The fix

`Settings` owns the `menu_items` table, its `MenuService` already builds the
nested tree, filters `is_active` and marks the current path active — it was the
owner without a voice. So it got one:

`modules/Settings/Interfaces/HTTP/SettingsShellContextProvider.php` (+ the
installed copy under `src/Modules/Settings/`), `#[AsShellContextProvider]`,
answering `navigation()` only. Site name, theme and locale are answered by other
providers; a second answer would be a second source. `MenuItemDTO` → `ShellNavItem`
is a straight map (label, href, active, external, children one level down).

The rows come from the same seed upstream has: header `Home /`, `Articles /blog`,
`Admin /mark`; footer `Privacy /privacy`, `Cookies /cookies`. `Admin` in a public
navbar is upstream's own seed, kept as parity — hiding it is an operator choice
in `/mark/settings`, not a kernel decision. **(Boot 24 revised this: for a reader
who is not signed in the item is a 302 to `/login`, so the provider now withholds
admin hrefs from anonymous visitors and keeps them for editors.)**

Cost, because the nav is now read on every public render: **0.24 ms per page**
for both slots (1000 iterations, SQLite, `SettingsServices` memoizes the
service), against 0.63 ms for a full mono render.

## The trap it sprung

The provider was written, copied, and **not discovered**: the registry still
listed one provider, and the menu stayed empty. The cause was the discovery
mechanism itself. `ShellContextProviderRegistry::load()` resolves the FQCN with
the FIRST `class <name>` match in the file — a text-first rule, the same shape
as `Router::mayDeclareRoute` — and the docblock's phrase "This class says the one
thing…" made it resolve a type named `says`. The class did not exist, so the file
was skipped, silently, with the menu still gone.

Rewording is the whole fix here (the note is now phrased without the keyword),
and it is what surfaced the fragility: **prose can shadow discovery in any of
this tree's text-first registries**, and the failure is invisible. The robust
change — anchor the pattern to a declaration line rather than the first mention —
touches `ShellContextProviderRegistry`, `ThemeRegistry` and `MiddlewareRegistry`,
and is deliberately NOT in this commit; it is recorded here as a known trap.
**(Boot 24 closed it — and found a fourth copy of the resolver in
`MarkdownBlockRegistry`.)**

## Mono's navbar on a phone

Mono's vendored CSS hides `.mo-navbar__menu` below 768px and opens it by adding
`mo-navbar--menu-open` from the live site's JavaScript — which this tree does not
ship (mono has no build; `criticalCss()` is the whole theme). So the menu the
port had just filled would have been unreachable on a narrow screen. One media
query in `mono.css` shows it inline and scrollable under 768px instead:no JS, no markup change, the same always-visible shape the kernel theme's `k-nav`
already has. Desktop is untouched. **(Boot 25 replaced the always-visible strip
with the theme's own meatball — a checkbox plus a label, still no JS.)**

## Verified

- registry discovers 2 providers; `header: [*Home → /] [Articles → /blog] [Admin → /mark]`, `footer: [Privacy] [Cookies]`; `/` marks Home active, `/blog` and the article mark Articles;
- live on `:8000`: `/`, `/blog`, `/blog/nativa-php-core`, `/docs` all 200, each with `mo-navbar__menu` and the active link correct;
- the same rows render in the kernel theme (`k-nav`) and its footer — the port feeds every theme, not just the one that prompted this;
- chromium at **390px and 1280px**: menu `display:flex`, three links, inside the viewport, nothing clipped;
- `phpunit` 28/28 (6 skips), `phpstan`, `rector --dry-run`, `php-cs-fixer --dry-run` clean; `extract-kernel --check` OK (246 identical, 42 recorded divergences — the new file needed no entry).
- 4 of those tests are new (`modules/Settings/tests/SettingsShellContextProviderTest.php`): the header and footer answers, the active item under a nested path, and the abstentions. They build their own temp `config.db` with the Settings migrator — not `DatabaseMigrator`, which would run every module's migrator and touch the working tree's real databases — and **3 of the 4 fail when `navigation()` is reverted to `[]`**, so the silent-empty answer that caused this cannot come back unnoticed.

# Boot 24 — the admin link a visitor may not see, and the prose that ate discovery (2026-10-12)

Two asks: hide the admin link from anonymous readers while editors keep it, and
close the trap Boot 23 recorded and left open. The first one turned out to have a
second half nobody had asked for.

## The admin link

Boot 23 left `Admin` (→ `/mark`) in the header for everyone, on the grounds that
hiding it was an operator's choice in `/mark/settings` rather than a kernel
decision. That reading missed a direction: for a reader who is not signed in the
item is a 302 to `/login` — a dead end dressed as navigation, in every theme, on
every public page. So the provider that answers the slot now answers it per
visitor: an item whose href is `/mark` or under it is dropped unless the visitor
is signed in, children included by the same rule however deep the menu nests (a
nested `Moderation → /mark/comments` goes with it while its parent stays).

"Signed in" is read the way the kernel already reads it — `$_SESSION['auth']['user_id']`,
behind the guard that keeps a public page from handing a session to a reader who
never logged in (`FrontendLayout::sessionHasAuth()`). The auth PORT is not asked
because its `identity()` takes a `Request` and the shell port carries none:
asking would mean inventing a second answer to a question the kernel already
answers for itself.

**Measured on the live server**, with a real session in `storage/sessions` and its
cookie:

| request | navbar |
|---|---|
| guest `GET /blog` | `['Home', 'Articles']` |
| editor `GET /blog/nativa-php-core` | `['Home', 'Articles', 'Admin']` |
| guest `GET /blog/nativa-php-core` | `['Home', 'Articles']` |

## The leak the nav half exposed

Verifying that table found the other half: a page cache that does not know WHO a
page was rendered for. `PageCacheService` keys an entry by method + path + query +
locale and `PageCacheMiddleware` writes one on every cacheable response — while
the shell chrome advertises `/mark` to exactly one class of visitor. An editor's
`/` stored under that shared key is an anonymous reader being handed a link to the
admin surface, and it is not served back to the session that produced it:
`RateLimitMiddleware` serves the cache to **whoever trips the limit**.

`isCacheable()` now refuses a response rendered for a signed-in visitor
(`renderedForASignedInVisitor()` — same session read, same guard). The anonymous
render of the same URL is still cached: a policy that quietly stopped caching the
site would silence the leak and this test with it.

**This tree never populated that cache**: `PageCacheMiddleware` needs a
`PageCacheService`, so `#[AsMiddleware]` discovery skips it (zero-argument classes
only) and the kernel-only root wires nothing by hand — `storage/cache/pages` held
0 files after every request above. Upstream is the other case:
`../nativa-minimal/public/index.php:127` constructs it. The rule is latent here
and live there, which is the shape a kernel-owned policy has to be written for.
`PageCacheService.php` is now a recorded DIVERGED file.

## The prose trap, closed

Boot 23 recorded the discovery defect and deliberately did not fix it. Four
registries resolve an FQCN from file text the same way —
`ShellContextProviderRegistry`, `ThemeRegistry`, `MiddlewareRegistry` and
`MarkdownBlockRegistry` — and all four took the FIRST `class <name>` mention in
the file, so a docblock sentence ("This class says the one thing…") resolved a
type named `says`; the class did not exist, the file was skipped, and the
provider, theme or middleware disappeared with no error and no log line.

A declaration now has to START something: the pattern is anchored to a line start
(or a statement, after `;`) with the modifiers PHP allows in front
(`final|abstract|readonly`). The `;` half is not decoration — a compact but legal
`namespace Demo; class Thing {}` has to keep resolving, and too strict a heuristic
trades one silent skip for another, which is the failure mode this whole exercise
is about.

`tests/Discovery/TextFirstFqcnTest.php` pins all four copies at once (12 cases:
both prose-shadowing halves, one real declaration shape per modifier, and a file
with no declaration answering null rather than guessing). Against `HEAD`'s
resolver **3 of the 12 fail** — the docblock's own phrase "a class of things"
resolving as the type name `Demo\of`.

## Verified

- live `:8000`, fresh server — opcache's 180s revalidate means a running server answers from the front of the last three minutes, so every response above was read after a restart;
- guest/editor/guest navbar exactly as tabulated, `200` on all three, nothing in the server log;
- `storage/cache/pages`: 0 files after a guest render and 0 after an editor render — the middleware is not wired here, which is why the rule is recorded rather than demonstrated in this tree;
- `phpunit` 51 tests (6 skipped), `phpstan`, `rector --dry-run`, `php -l` and `php-cs-fixer --dry-run` (84 files) clean;
- the negative checks, so the two new rules cannot be dropped unnoticed: with the cache rule removed **2 of the 8** `tests/Cache/PageCacheServiceTest.php` cases fail (the editor's page lands in the cache), and with `HEAD`'s resolver **3 of the 12** discovery cases fail;
- `extract-kernel --check` OK (243 identical, 45 recorded divergences) — the three kernel files this boot rewrote (`PageCacheService`, `MiddlewareRegistry`, `MarkdownBlockRegistry`) are recorded as DIVERGED with their reasons. Upstream still carries both defects; the record is what says so.

# Boot 25 — mono gets the meatball, and two seeded links that were 404s (2026-10-12)

Reported as "the mono nav has no menu on mobile; cardboard has one". Measured
first, and the strip Boot 23 added **was** there in this checkout: at 390px and
320px on `/`, `/blog`, the article and `/docs`, `.mo-navbar__menu` computed
`display:flex` with its links visible, unoccluded (`elementFromPoint` returned
the link itself), for guests and for a signed-in editor — and the rule that does
it is in the served bytes. So the report was really about *pattern*, not
presence: cardboard's nav is a button that opens a drawer, and mono's was a
permanently visible swipeable strip.

## What mono does now

The theme's own meatball — the vendored snapshot already ships
`.mo-navbar__toggle`, `--meatball`, `--close` and `mo-icon-btn`, and expects the
live site's JS to add `mo-navbar--menu-open`; this tree ships no JS, so the open
state is a **form control** and the styling keys off `:checked`, in the idiom the
shared layout uses for the starter nav and Erik repeats in `er-nav__toggle-input`:

- `MonoTheme::menuToggleInput()` renders a visually hidden
  `input[type=checkbox]#mo-nav-toggle`, named by `aria-label` (both are dropped
  when the header has no items — a toggle that opens nothing is worse than none);
- `MonoTheme::menuToggleLabel()` renders the meatball + close icons as a `label`
  in the actions cluster;
- `mono.css` keys the open state on `.mo-navbar__toggle-input:checked ~ …`.

The mechanism rests on **DOM order**: one `~` selector has to reach both the menu
and the label from the input, which only works while the input is first and the
label comes after the menu. `modules/Mono/tests/MonoThemeTest.php` pins that
order, the checkbox contract and the `:checked` CSS, because nothing in a
stylesheet enforces it.

The open state is deliberately scoped to `@media (max-width:767px)`: without the
query, a menu left checked in portrait would follow the reader into landscape as
an absolute overlay — and the control that could close it, the meatball, is
hidden from 768px up.

## Measured (chromium, fresh server)

| width | closed | after tapping the meatball | desktop |
|---|---|---|---|
| 390px | menu `none`, toggle `flex`, meatball `block`, close `none` | `checked`, menu `flex`, 2 links visible, meatball `none`, close `block` | 1280px: menu `flex`, toggle `none` |
| 320px | same | same (signed in: Home, Articles, Admin — all inside the opened panel, none clipped) | — |

Also: `Tab` reaches the checkbox (first focusable in the navbar), `Space` opens
the menu, and following a link leaves the state behind (`checked:false`, menu
`none` on the next page — it is markup state, not persisted). The focus ring
rule matches and changes the computed style (deleting it flips `outline-style`
from `solid` to `none`); the headless build reports `outline-width: 0px` even for
an inline literal, so the ring was verified by matched-rule, not by pixels.

## Two seeded menu links that were dead ends

While sweeping the nav for this, every path in it was requested: the seed's
footer rows `Privacy -> /privacy` and `Cookies -> /cookies` **both 404** in this
tree — there is no route for either (the kernel serves `/`, `/blog`, `/docs`,
`/mark`, `/storage/*`, the feeds and the auth pages). A fresh install therefore
shipped a footer with two dead links.

The seed now carries one row the kernel can serve — `Docs -> /docs` — and the
rule that produced it is pinned by
`SettingsShellContextProviderTest::theSeedAdvertisesNoRouteTheKernelDoesNotServe()`:
*the seed may only advertise routes the tree it ships to serves.* A deployment
with its own legal pages adds them in `/mark` → Navigation; the seed is a
starting point, not a policy. Existing databases keep their rows — the migrator
seeds idempotently and never rewrites what an operator may have edited.

## Verified

- live `:8000` (restarted, so no opcache staleness): toggle closed → open → closed at 390px and 320px, guest and editor, on the homepage;
- keyboard: `Tab` → checkbox focused, `Space` → menu opens;
- 1280px unchanged: menu visible, toggle `display:none`;
- `phpunit` 56 tests (6 skipped), `phpstan`, `rector --dry-run`, `php-cs-fixer --dry-run` and `php -l` clean; `extract-kernel --check` unchanged (the mono theme and the Settings migrator are module-owned, not part of the kernel slice).

# Boot 20 — CardboardKit merges into Cardboard (single admin module)

Boot 14 split the admin overlay into a leaf kit + admin app to break the
`Cardboard ↔ everything` cycle. The two namespaces (`CardboardKit` vs
`Cardboard`, including `CardboardRegistry` living in the Kit and `Sparkline`
living in the app) read as duplication. Decision: **only `Cardboard` stays**.

What moved (catalogue `modules/`, synced verbatim into `src/Modules/`):

- Kit files → same relative paths under `Cardboard/` (`Domain/Model`,
  `Domain/Contracts/Chart+Metric`, `Application/Services/AdminLocale,
  IconRegistry, BulkActions`, `Interfaces/HTTP/AdminLayout+CompactLayout,
  templates, assets/scripts`, `Infrastructure/Attributes/AsMetricProvider,
  Registry/*`). `Sparkline/Bar/Donut` finally sit side by side.
- Deleted as a port: `AdminContextProvider`, `AsAdminContextProvider`,
  `AdminContextRegistry`, `CardboardAdminContext`. The layout calls its own
  `CardboardServices` directly (try/catch → `Guest`/sidebar/`[]` fallback).
- `modules/CardboardKit/` deleted.

Cycle discipline (the catalog derives closures from `^use Modules\` lines):

- Cardboard keeps `use` only for self, `Core`, `Database`, `Security`,
  `Settings`, `Outbox` — none of which import Cardboard back.
- `Audit/Blog/Media/Pages/Users` references inside Cardboard are
  fully-qualified inline (same runtime behaviour with the full set
  installed, invisible to the closure scanner). `AdminNavService` and
  `BulkEntityGate` carry local permission-string tables for the same reason.
- Measured closures: Cardboard → `Outbox, Security, Settings`;
  Blog/Pages/Users/Audit/Media → Cardboard. Acyclic; `module:show` green.

**Correction — Boot 26 (part 07).** The last line above is wrong, and nothing
had measured it: `module:show` prints a closure, it never walks the graph. The
merge put `Outbox → Cardboard` back (the metric subsystem it absorbed, plus
`OutboxAdminAction`), and `Security → Outbox` was already there through a test
file — so the real graph was `Cardboard → Security → Outbox → Cardboard`, a
cycle no install order satisfies. A fresh clone therefore could not install the
catalogue at all (`no progress in round 2`). Both observability edges are now
guarded, Cardboard's closure is `Security, Settings, Validation`, and Security
is a leaf — measured, with the install order in part 07.

## Verified

- `composer phpstan` clean, `composer test` 56/147 (6 skipped, same skips),
  `php -l` on all touched files clean;
- live `php -S` (fresh, restarted): `/mark` 200, `/mark/users` 200, sidebar
  chrome + `mark` CSS + `Guest` fallback, 0× `CardboardKit` in the HTML,
  0× `[KERNEL] Error`;
- `module:show Cardboard` routes (6× `/mark/*`) + assets (9× scripts) intact.

