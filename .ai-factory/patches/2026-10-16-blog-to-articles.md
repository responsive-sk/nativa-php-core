# rename blog → articles (prepared, NOT applied)

Status: **prepared.** Nothing in this tree and nothing in `../nativa-minimal` has
been changed. Upstream is a **dirty** working tree (see below), so the rename
must not be applied there until that is resolved.

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
  failures (`php -l`), 0 leftovers**.
- Route names are referenced **only in their own `#[Route(name: …)]`** — all 16
  grep hits are declarations, no call site — so renaming them is free.
- The URLs do not move: `/blog`, `/blog/{slug}`, `/blog/tags`, `/blog/categories`,
  `/blog/feed.atom`, `/blog/feed.xml`, `/blog/{slug}/inline`,
  `/blog/series/security-infra/feed.*`, `/authors/{slug}`, `/mark/articles`,
  `/mark/categories`, `/mark/tags`, `/storage/blog-images/{path*}`.

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
| `BlogIndexAction` | **open** — see below | the one real collision |

`BlogIndexAction` is the public article list at `/blog`. The admin list at
`/mark/articles` is already `ArticlesIndexAction`. Either:

- **(a)** public → `ArticleListViewAction`, admin untouched; or
- **(b)** public → `ArticlesIndexAction` and admin → `ArticlesAdminIndexAction`
  (consistent `<Plural>IndexAction` for the public surface, admin gets a prefix).

**Recommendation: (a)** — it leaves every existing name in place and adds no new
convention.

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

1. Upstream (`../nativa-minimal`) — **only when its tree is clean**: apply the map
   above (its Blog has 46 files and **Core imports it ×3**, which this tree's
   kernel does not — those Core edges move too), run its own gates, commit.
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
