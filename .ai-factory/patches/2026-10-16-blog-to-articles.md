# rename blog → articles (prepared, NOT applied)

Status: **prepared, not applied.** Nothing in this tree and nothing in
`../nativa-minimal` has been changed. Upstream is a **dirty** working tree, so the
rename must not be applied there until that is resolved.

Measured on the second pass (2026-10-16): upstream is still dirty — **26 entries**
(20 modified, 6 untracked, counted by `git status --porcelain`), and **four of them
carry `Modules\Blog` themselves**:
`tests/Support/KernelCoupling.php`, `tests/Architecture/KernelCouplingTest.php`,
`tests/Integration/ThemeModuleContractTest.php`,
`openspec/changes/nativa-php-kernel.md`. The rename edits those same files, so
applying it now would interleave the operator's uncommitted work with the sweep and
leave neither separable nor reviewable. The gate is not decoration.

Nothing dirty lives inside `src/Modules/Blog/` itself, so the gate is about
*reviewability*, not about a merge conflict.

## Why upstream first, measured 2026-10-16

`Blog` is not this tree's name. The module exists upstream:

```
../nativa-minimal/src/Modules/Blog/Application/BlogServices.php
    → namespace Modules\Blog\Application;
```

`modules/` here is the committed catalogue of the showcase's modules — imported
verbatim and re-imported when a module is updated — so a local rename makes every
future update a manual re-port, the same reason `modules/` is deliberately
excluded from `cs-fixer`. The tree has a divergence ledger, but it is the
**kernel's** (`bin/build/extract-kernel.sh --check` ↔
`bin/build/kernel-divergence.txt`); the catalogue has none.

| | this tree | upstream |
|---|---|---|
| files in the module | **53** (incl. 5 tests) | **46** |
| namespace | `modules/Blog/` catalogue | `src/Modules/Blog/` |
| imports it | Cardboard ×4, Comments ×1, Questions ×2 | Cardboard ×4, **Core ×3**, Comments ×1, Questions ×2 |
| route-name split | `blog.index`… vs `articles.create`… | **identical split** |

The 53-vs-46 difference is worth knowing before any re-import: the catalogue is an
adaptation, not a byte-copy.

## What is mechanical, and verified

Applied to a scratch copy of the catalogue slice (`cp -a modules/Blog /tmp/ren/Articles`):

- `Modules\Blog` → `Modules\Articles` across the slice → **47 php files, 0 syntax
  failures (`php -l`), 0 leftovers**. **This number is slice-scoped**: it is 47 of
  this tree's 53 catalogue files. It is NOT the size of the job upstream, which is
  the scope correction below.
- Route names are referenced **only in their own `#[Route(name: …)]`** — all 16
  grep hits are declarations, no call site — so renaming them is free.
- The URLs do not move: `/blog`, `/blog/{slug}`, `/blog/tags`, `/blog/categories`,
  `/blog/feed.atom`, `/blog/feed.xml`, `/blog/{slug}/inline`,
  `/blog/series/security-infra/feed.*`, `/authors/{slug}`, `/mark/articles`,
  `/mark/categories`, `/mark/tags`, `/storage/blog-images/{path*}`.

## Scope correction — measured 2026-10-16 (second pass)

The "47 php files" above verifies the **catalogue slice only**. It says nothing
about a consuming tree, and a consuming tree is far larger. Fixed-string
measurement (`grep -rlF 'Modules\Blog'`, whole repo upstream):

| where | files carrying the name | note |
|---|---|---|
| `src/Modules/Blog/` | 40 php (of 46 files) | the slice itself |
| `src/Themes/**` | **34 php** | six themes: moonshine 9, magazine 7, erik 6, basic 4, jednotka 4, starter 4 |
| `src/Modules/Core` | 3 php | the kernel edges the catalogue does not have |
| `src/Modules/Cardboard` | 4 php | |
| `src/Modules/Questions` | 2 php | |
| `src/Modules/Comments` | 1 php | |
| `tests/**` | **49 php** | |
| **total php to rewrite** | **133** | not 46, and not 47 |

So upstream step 1 is not "the module plus Core ×3": the **themes are the largest
consumer by far** — 34 files, more than Core, Cardboard, Questions and Comments
combined — and the tests are 49 more. Neither surface is named in the class-name
map or the order below, so a run that follows this file literally would leave a
broken tree in six themes and 49 tests.

### The module name is *data* in one place

`tests/Architecture/KernelCouplingTest.php` does not merely import the namespace —
it holds the name as **ledger data**, keyed on exact strings and carrying the
module label as the entry's subject:

```
'src/Modules/Core/Interfaces/HTTP/Actions/HomepageAction.php::Modules\Blog\Application\BlogServices'
    => ['Blog', 'M', 'the plan moves the home surface out of the kernel; …'],
```

That ledger is the tree's record of *deliberate* kernel coupling. The rename
rewrites its vocabulary without deleting its entries — the coupling is still
deliberate after the rename, only renamed. It is also one of the four dirty files,
which is the sharpest reason for the gate above.

### Never hand-edit these (they carry the name but are not source)

| path | why | instead |
|---|---|---|
| `dist/**` | 95 php files carry the name; `/dist/` is in `.gitignore` with **0 tracked files** — it is build output | rebuild |
| `storage/blog/**` | the operator's **article content** | rename only with the operator, or not at all |
| `storage/logs`, `storage/cache/coverage.xml` | logs, an old coverage report | nothing |
| `openspec/changes/archive/**` | historical records | leave — a record that said `Blog` in September said `Blog` |
| `.ai-factory/rules/generated/**` | generated rules | regenerate |
| `docs/lekalo/trace/*.json` | generated traces, and currently dirty | regenerate after the rename |

`docs/dex/**` (12 php) is a copy of theme code living under `docs/`; no script was
found that generates it, so it needs a human decision rather than a sweep.
`bin/cms` and the live (non-archive) `openspec/` documents carry the name too and
are source-ish: they take the map by hand.

## Rehearsal — the namespace is mechanical, the *name* is data (measured 2026-10-16)

Run on a **read-only** `git archive HEAD` extraction with a real `vendor/` copy
(`/tmp/up-reh`; nothing in `../nativa-minimal` was touched), sweeping
`Modules\Blog` → `Modules\Articles` and the 18-class map over every php file that
carries the name, then running the tree's own suite:

- **131 php files** swept (133 in the working tree — the two extra are the
  uncommitted `KernelCoupling*` files), moving `src/Modules/Blog` →
  `src/Modules/Articles`;
- **0 leftover `Modules\Blog`**, **0 `php -l` failures**;
- suite, same environment both sides:

| | tests | errors | failures |
|---|---|---|---|
| baseline (unswept HEAD) | 1798 | 55 | 60 |
| swept | 1798 | 58 | 65 |

**8 new failures, 0 disappeared** — and every one is `Blog`-as-data, none is the
namespace. That is the whole point: a namespace sweep is verifiably clean and still
leaves a broken tree.

| failing test | cause | shape |
|---|---|---|
| `QuestionsTest` ×2 | `RuntimeException: Template not found: Modules/Blog/…/templates/article-form.phtml` | **template path strings** inside the module — `php -l` passes, it fails only when the article editor opens |
| `InlineScriptByteParityTest` ×2 | `…assets/scripts/article-create.js must exist` | golden-bytes ledger keyed on asset **paths** |
| `AdminDeleteConfirmTest` | `file_get_contents(src/Modules/Blog/…/ArticlesTableRenderer.php)` | path in a test |
| `ThemesBindingParityTest` | `runtime action missing: src/Modules/Blog/…/BlogIndexAction.php` | path-keyed parity ledger |
| `TraceManifestTest` | `logical path segment does not resolve: blog` | the trace manifest keys on the module's lowercase segment — traces must be regenerated |
| `CorePatternTest::testNoNearCapMethods` | allowlisted near-cap methods resurface | `NEAR_CAP_ALLOWLIST` is keyed on **paths**, so the move silently un-declares them |

### `Blog` the word, in places a backslash sweep cannot see

| carrier | where | why it is not a sweep |
|---|---|---|
| `ViewRenderer::render('Modules/Blog/Interfaces/HTTP/templates/*.phtml')` ×3 | inside the module | path strings, forward slashes |
| `FrontendLayout::render('Blog', …)` — 8 occurrences | tests | a **layout name**; layouts may be configured outside code |
| `['Blog', '/mark/articles', 'admin', 70, 'rss']` | `SettingsDatabaseMigrator` | seeds a **menu row** — deployments already ran it; changing it is a migration, not a rename |
| `['id' => '_blog', 'label' => 'Blog', 'href' => '/mark/articles']` | `AdminNavService` | admin nav identity |
| `['key' => 'nav.blog', …]` | `Locales/…/SeedData` | a **translation key** referenced from theme templates; renaming orphans translations |
| `['@type' => 'ListItem', 'name' => 'Blog', …]` | `StructuredDataService` | JSON-LD, user-visible |
| `Sections\BlogIndex` + `BlogIndexTest` ×6 themes | themes, tests | theme section names; **not** in the class map |
| "Back to Blog", "Read the Blog" | theme copy | prose — should probably NOT be renamed |

So the honest shape of this work is: **the namespace rename is mechanical and
verified; the module's *name* is a product decision in at least six further places,
three of which (the seeded menu row, the translation key, the layout name) cannot
be swept at all.** The list above is the decision list, not a to-do list.

## What needs a decision: 18 class names

A mechanical `Blog` → `Articles` swap is wrong twice over: `BlogArticleAction`
would become `ArticleArticleAction`, and `BlogIndexAction` would become
`ArticlesIndexAction`, which is **already taken** by the admin list
(`GET /mark/articles`). Proposed map, mirroring the sibling modules
(`QuestionsServices`, `QuestionsDatabaseMigrator`, `PagesMetric`,
`PageCrudService`, `PageViewAction`):

| now | proposed | note |
|---|---|---|
| `BlogServices` | `ArticlesServices` | mirrors `QuestionsServices` |
| `BlogDatabaseMigrator` | `ArticlesDatabaseMigrator` | mirrors `QuestionsDatabaseMigrator` |
| `BlogMetric` | `ArticlesMetric` | mirrors `PagesMetric` |
| `BlogCrudService` | `ArticleCrudService` | mirrors `PageCrudService` |
| `BlogArticleHelper` | `ArticleHelper` | |
| `BlogArticlesService` | `ArticleCatalog` | discovers + normalises articles from storage; `Catalog`/`Discovery` are house words |
| `BlogArticleAction` | `ArticleViewAction` | public page, mirrors `PageViewAction` |
| `BlogAuthorResolver` | `AuthorResolver` | |
| `BlogSearchService` | `ArticleSearchService` | |
| `BlogFeedService` | `ArticleFeedService` | |
| `BlogFeedAction` | `ArticleFeedAction` | |
| `BlogSeriesFeedAction` | `SeriesFeedAction` | |
| `BlogCategoriesAction` | `ArticleCategoriesAction` | `CategoriesManageAction` (admin) untouched |
| `BlogTagsAction` | `ArticleTagsAction` | `TagsManageAction` (admin) untouched |
| `BlogInlineEditAction` | `ArticleInlineEditAction` | |
| `BlogInlineEditor` | `ArticleInlineEditor` | |
| `ServeBlogImageAction` | `ServeArticleImageAction` | URL stays `/storage/blog-images/…` |
| `BlogIndexAction` | `ArticleListViewAction` | decided 2026-10-16 — see below |

`BlogIndexAction` is the public article list at `/blog`. The admin list at
`/mark/articles` is already `ArticlesIndexAction`. Either:

- **(a)** public → `ArticleListViewAction`, admin untouched; or
- **(b)** public → `ArticlesIndexAction` and admin → `ArticlesAdminIndexAction`
  (consistent `<Plural>IndexAction` for the public surface, admin gets a prefix).

**Decided 2026-10-16: (a)** — it leaves every existing name in place and adds no new
convention. Option (b) would rename a name that is already correct (`ArticlesIndexAction`
for the admin list at `/mark/articles`) purely to free the pattern for the public
surface, which is the tail wagging the dog.

Route names: `blog.index` → `articles.index`, `blog.article` → `articles.article`,
`blog.tags`(`.show`) → `articles.tags`(`.show`), `blog.categories`(`.show`) →
`articles.categories`(`.show`), `blog.feed.atom`/`.rss` → `articles.feed.*`,
`blog.series.security-infra.*` → `articles.series.security-infra.*`,
`blog.inline.save` → `articles.inline.save`, `storage.blog-images` →
`articles.image`.

## Data paths (not URLs — decide separately)

- **The DB file follows the module name**: `storage/data/blog.db` →
  `articles.db`, automatically. On a deployment that means a fresh database
  unless the file is renamed with it (and its `-wal`/`-shm`/`-journal`).
- `storage/blog/{sk,en}` — the article files. The smoke gate's `tree_files`
  comparison does not care, but an operator's content does. Rename it in the same
  change or record why not.
- `/storage/blog-images/` is a **URL** and stays. `storage/blog-images/` is the
  directory it serves — same word, different meaning.

## Order

1. Upstream (`../nativa-minimal`) — **only when its tree is clean**: sweep the
   namespace across the **133 php files** in the scope correction above (the map
   handles the 18 class names; the sweep is namespace-only), including the 34 theme
   files and the 49 test files, plus `bin/cms` and the live `openspec/` documents.
   Then rebuild `dist/`, regenerate the traces, run its own gates, commit.
2. This tree: `git mv modules/Blog modules/Articles`, the same map (53 files),
   then the importers in the catalogue — `Cardboard/{BulkDeleteAction,
   BulkPublishAction, UsersAction, CardboardServices}.php`,
   `Comments/…/CommentSubmitAction.php`, `Questions/{SubjectResolver.php,
   …/QuestionSubmitAction.php}` — plus `bin/cms` (`GenerateImagesCommand`,
   `generate-images` needs the Blog module), `tests/` (2 files), `Makefile`
   (`LEKALO_MODULES ?= users,blog`), and the CI comments in `.github/workflows/ci.yml`.
3. The model: `git mv lekalo/modules/blog lekalo/modules/articles`, every `blog.*`
   id → `articles.*`, the **6 slices outside it** that reference `blog.*`
   (`comments/*`, `questions/*`), and the endpoint route names above. The slice
   directory is bound to the catalogue module name by `ModelCatalogueContractTest`,
   which is why the model cannot be renamed on its own.
4. Reinstall: `php bin/cms module:remove Blog --force && php bin/cms module:add
   Articles`, then reinstall the importers (`Cardboard`, `Comments`, `Questions`)
   so their refreshed `use` lines reach `src/Modules/` — a catalogue edit is
   invisible until the module is reinstalled, and `bin/build/install-catalogue.sh`
   already knows the closure order (`Cardboard ↔ Outbox` is circular).
5. Gates: `composer test`, `make analyze`, `make lint`, `make lekalo-check`,
   `make admin-smoke`, and the modules CI job the catalogue install feeds.

## What this file deliberately does not do

It does not rename anything, and it does not touch `../nativa-minimal`, whose
working tree carries uncommitted work in `AGENTS.md`, `Frontend/`, `bin/checks/`
and `docs/lekalo/trace/`. The scratch verification above ran in `/tmp` only.
