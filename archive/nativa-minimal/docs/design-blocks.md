# Design Blocks — the cross-theme block matrix

Every public theme ships the same *kinds* of structural blocks — a nav, a
footer, card grids, a hero, a TOC — but each theme renders its own
*variant* of every kind, and no prior artifact says which kinds exist,
which variant each theme picked, or on which surfaces each block appears.
This doc is that single place.

A **block** here is a **structural contract, not geometry**. The contract
says what a kind must provide (nav items from the DB-driven menu, an
active-link state, a mobile collapse). Breakpoints, column counts and
`grid-template` definitions stay in CSS — a theme may implement a variant
any way it likes as long as the contract holds. A theme that provides a
contractual capability counts as shipping the block; one that omits an
optional kind on a surface is still complete.

The matrix is kept honest by `tests/Architecture/DesignBlockVocabularyTest.php`:
it parses the tables below and fails CI when a variant BEM token no
longer exists in the runtime sources or when the surface map names a
surface the `ThemeModule` contract does not have.

**Wired themes:** the product ships `starter` (public, default), `erik`,
`moonshine` (also the admin theme, behind `/mark`), `magazine` (public,
selectable), `basic` and `jednotka`. There is deliberately **no fixed
count** — a theme
is a module (ADR-0003), so wiring another one is an ordinary change rather
than an exception that needs its own ADR.

`basic` (`ba-`) is a **caricature of `starter`**: the same design under a
different prefix, kept wired so the theme factory stays exercised end to end.
`jednotka` (`je-`) is NOT — it is an independent design (warm paper and ink,
hairline rules instead of shadows, a numbered left rail, uppercase mono
labels), and it ships its own `Frontend/packages/jednotka/src/jednotka/`
stylesheet instead of a stamped copy of the shared base.
point (whitelist, `ThemeKernel`, `AssetManager`, the Vite factory, the parity
guards, the form/comments generators, the pnpm workspace and its lockfile
importer). They are fixtures for the theme factory, not extra designs — which
is why their rows sit next to `starter`'s and differ only in the prefix. They
exist so the factory can be run END TO END against themes whose correct
rendering is already known: every guard here treats a factory-made theme
exactly like a hand-made one, and `jednotka` is what proves the guards are
derived from the runtime theme set rather than from a list somebody remembers
to update.
`minimal` is a **token vocabulary without markup**: its pnpm package and
built bundle stay (the WCAG audit reads its token family and
`DesignModelParityTest` requires it in the Lekalo model), but no
`src/Themes/` source survives — no route could reach it. It is therefore
absent from the matrix: a block row documents a contract a page renders, and
there is no page to render its.

## Where this sits

| Artifact | Level | What it leaves open |
|---|---|---|
| `theme-section-split` (2026-09-13) | file organization per theme (`Sections/`, `Chrome/`, `CriticalCss/`, 600-line cap) | which blocks a theme must provide; variants across themes |
| `ui/` + `ui/index.css` | admin components (`.m-*` BEM) | mapping into the same kinds the public themes use |
| `moonshine-patterns` §11 | portability checklist for a new theme | the vocabulary the checklist should verify against |

The vocabulary is deliberately **not modeled in Lekalo** yet — see
[Lekalo horizon](#lekalo-horizon).

## The nine kinds

Each kind is a capability contract. "Typical home" names where the
canonical variant lives in code.

### nav

Contract:

- items come from the DB-driven menu (`SettingsServices::menu()`),
- active-link state derived from the (locale-stripped) current path,
- a mobile collapse: drawer, checkbox hack or burger,
- the utility cluster on public themes: locale switcher + theme toggle.

Typical home: `Chrome/Nav.php` per theme, `StarterTheme::header()` for
starter, `AdminLayout` sidebar for the admin.

### footer

Contract:

- site name / copyright line,
- footer nav (links or the compact admin utility cluster),
- locale switcher and theme toggle on public themes,
- a back-to-top trigger where the shell scrolls.

Typical home: theme `Chrome/Chrome.php` (shell markup), `Chrome/Nav.php` for
the nav itself.

### grid

Contract:

- count-aware card slots (the `--1col`/`--2col`/`--3col` modifier
  family on moonshine, auto-fit on starter and erik),
- an empty state when no cards render,
- used by blog index, taxonomies, author profile, related sections and
  admin data lists (tables are the admin variant).

Typical home: `Sections/Cards.php` per theme, `ui/components.css`
(`.m-table`) for the admin.

### section

Contract:

- a header with title, optional description, optional view-all link,
- a block-level container that groups following content,
- composable: sections stack into page bodies.

Typical home: `Sections/Support.php` helpers (`section()`,
`sectionHeader()`), the starter critical-CSS sections under
`src/Modules/Core/Interfaces/HTTP/assets/critical/`.

### form

Contract:

- a CSRF-hidden input on every POST form,
- labeled fields with accessible `for`/`id` pairs,
- an error slot (banner or per-field) and a submit button.

Typical home: `AuthTemplate.php` (auth flow), `ui/components.css`
(`.m-form`) for the admin, `CommentSection.php` for the shared comment
form, `FormBlockRenderer.php` for the embedded Forms block,
`QuestionBlockRenderer.php` for the reader-questions block.

**The embedded form block is one component, four stylesheets.** Unlike every
other variant above, its markup is built by a shared module
(`Modules\Forms`) that takes the active theme's class prefix as an argument —
exactly the `CommentSection` contract — so the Forms module never imports
`Themes\`. The four `form.css` copies live next to their theme (never in the
duplicated `src/base/`), are generated from `Frontend/packages/starter/src/
form.css` by `bin/generate/generate-form-css.mjs`, and are pinned by
`FrontendFormParityTest`. Each copy's palette is a `--form-*` block of local
custom properties: the rules never name a theme token, so a theme swaps the
block and inherits the geometry. WCAG contracts for the two inks that are
actually chosen per theme (`--form-error-ink`, `--form-hint`) live in
`BuildCssAuditTest`.

**The reader-questions block is one component, SIX stylesheets.** Same
contract, one theme further: `Modules\Questions` renders it on articles AND
on static pages, and `QuestionBlockRenderer::render(..., string $prefix)`
builds every class from the active prefix. Six copies come from
`Frontend/packages/starter/src/questions.css` via
`bin/generate/generate-questions-css.mjs` and are pinned by
`FrontendQuestionsParityTest` — which is also what catches a copy stamped
under a foreign prefix, the defect that once shipped moonshine's editor
stylesheet as `m-`. Its palette is a `--questions-*` block of locals, like
the other two generated blocks. The block carries one extra slot over a plain
form: `__sent`, the PRG confirmation, painted only when the `question-sent`
marker is present — a redirect nobody renders is a silent no-op.

### widget

Contract:

- a titled sidebar block (popular posts, tags cloud, author bio, CTA),
- sticky positioning inside the sidebar layout,
- pure decoration is out of contract: a widget always carries content.

Typical home: `Sections/Widgets.php` per theme.

### hero

Contract:

- a title, a subtitle/description, primary + outline CTA slots,
- an optional media slot (cover image, spotlight cards),
- one hero per page at most.

Typical home: `Sections/Hero.php` (moonshine), `HomepageAction` (starter).

### card

Contract:

- a cover slot (image or placeholder), a badge/category link,
- a title link, a meta row (date, author), an excerpt, a tags row,
- one primary click target: the title link navigates to the article,
- a non-interactive root element: anchors inside the card are sequential
  siblings, never nested (the parser closes an outer anchor early and the
  rest of the card — e.g. the meta row — reflows outside it).

Typical home: `Sections/Cards.php` per theme, `BlogArticlesService` for
the shared starter card, `ui/components.css` (`.m-card`, `.m-stat`) for
the admin.

### toc

Contract:

- a heading-anchor list generated from h2/h3,
- an active state driven by the scrollspy script,
- a desktop surface and a mobile FAB/toggle variant on public themes.

Typical home: `StarterRenderer` (canonical), `Sections/ArticleView.php`
(erik), `Sections/Article.php` (moonshine).

## Variant matrix

Deterministic columns: `Kind | Theme | Variant | Code anchor | Notes`.
The Variant cell carries the BEM token(s) in backticks — that is what the
guard test asserts exists in the runtime sources. Themes: the six wired
themes (`starter` public default, `erik`, `magazine`, `basic` and `jednotka`
publicly selectable, `moonshine` publicly selectable AND the path-based admin
theme),
the admin surfaces (`mark`) and the auth flow (`auth`).

Themes with no server-side markup are deliberately absent. `minimal` ships a
CSS token vocabulary (its pnpm package and built bundle stay, audited by
`BuildCssAuditTest`) but no `src/Themes/` markup, so there is no block
contract to anchor a row on — a row pointing at a file no route can reach
would document a contract nothing honours. `magazine` was in that bucket
until it was revived as a full theme module (ADR-0002 amendment), so it is
now covered kind by kind below.

| Kind | Theme | Variant | Code anchor | Notes |
|------|-------|---------|-------------|-------|
| nav | starter | topbar + CSS dropdown (`st-nav`) | src/Themes/starter/Chrome/Nav.php | DB-driven menu, checkbox mobile drawer, locale + theme cluster |
| nav | erik | dark topbar (`er-nav`) | src/Themes/erik/Chrome/Nav.php | DB-driven menu, mobile-ready chrome, locale + theme cluster |
| nav | moonshine | horizontal topbar (`ms-topnav` in `ms-topbar`) | src/Themes/moonshine/Chrome/Nav.php | DB-driven menu, meatball button opens the stacked mobile drawer; the ADMIN sidebar is a separate surface (mark) |
| nav | magazine | masthead nav (`mag-header__nav` + `mag-mobile-menu`) | src/Themes/magazine/Chrome/Nav.php | DB-driven menu in the masthead, stacked mobile panel under the burger |
| nav | basic | topbar + CSS dropdown (`ba-nav`) | src/Themes/basic/Chrome/Nav.php | the starter chrome under `ba-`; a caricature row exists so the factory fixture is held to the same contract as the designs |
| nav | jednotka | ruled masthead band + numbered drawer (`je-nav`) | src/Themes/jednotka/Chrome/Nav.php | an independent design, not a prefixed starter: a single ink-ruled band with uppercase mono labels, and a full-height drawer whose items are numbered on narrow screens |
| nav | mark | sidebar nav (`layout-sidebar`) | src/Modules/Cardboard/Interfaces/HTTP/AdminLayout.php | hierarchical DB-driven menu, collapse + burger (the admin keeps the fixed rail; the moonshine PUBLIC chrome is the topbar above) |
| nav | mark | top menu (`m-nav`) | src/Modules/Cardboard/Interfaces/HTTP/CompactLayout.php | compact shell variant, hover/focus dropdown groups |
| footer | starter | single-row footer (`st-footer`) | src/Themes/starter/StarterTheme.php | footer nav + locale switcher + theme toggle |
| footer | erik | dark footer (`er-footer`) | src/Themes/erik/assets/critical/shell.css | copyright, footer nav and back-to-top surface |
| footer | moonshine | centered footer (`ms-footer`) | src/Themes/moonshine/Chrome/Chrome.php | copyright line under the main column |
| footer | magazine | multi-column footer (`mag-footer`) | src/Themes/magazine/Chrome/Chrome.php | footer nav columns, copyright line and back-to-top |
| footer | basic | single-row footer (`ba-footer`) | src/Themes/basic/BasicTheme.php | the starter footer under `ba-` — footer nav + copyright |
| footer | jednotka | ruled colophon row (`je-footer`) | src/Themes/jednotka/JednotkaTheme.php | copyright, DB footer nav and a right-aligned stack stamp, separated by a hairline rule — a colophon, not a padded panel |
| footer | mark | sidebar utility cluster (`layout-sidebar__footer`) | src/Modules/Cardboard/Interfaces/HTTP/AdminLayout.php | theme toggle + user + logout instead of a page footer |
| grid | starter | auto-fit card grid (`st-grid`) | src/Themes/starter/StarterTheme.php | home features and blog index share it |
| grid | erik | auto-fit card grid (`er-grid`) | src/Themes/erik/Sections/Cards.php | blog index, taxonomies and author profile |
| grid | moonshine | count-aware grid (`ms-grid`) | src/Themes/moonshine/Sections/Cards.php | card slot modifiers |
| grid | magazine | auto-fill card grid (`mag-grid`) | src/Themes/magazine/Sections/Cards.php | blog index, home latest and taxonomy listings |
| grid | basic | auto-fit card grid (`ba-grid`) | src/Themes/basic/Sections/Hero.php | the home feature grid; the BLOG card grid is the shared renderer's `st-grid` (see the card row) |
| grid | jednotka | two-track index grid (`je-grid` + `je-blog-index__layout`) | Frontend/packages/jednotka/src/jednotka/home.css | the printed-manual shape: a `minmax(0,1fr) 17rem` reading column plus a narrow outer rail that carries series discovery, collapsing to one track below 62em. The card grid itself is the kernel-owned `st-grid`, re-skinned as numbered ruled rows. Page 1 wraps that grid in `je-blog-index__main--lead`, which lifts the newest article into a 2-column spotlight with two supporting cards stacked beside it — erik's hero grid, taken as a WRAPPER CLASS plus one rule on `:first-child`, never a second card renderer, and never on pages 2+ |
| grid | mark | data table (`m-table`) | ui/components.css | sortable headers via AdminLayout::sortableHeader |
| section | starter | content section (`st-section`) | src/Themes/starter/StarterTheme.php | title + block container |
| section | erik | content section (`er-section`) | src/Themes/erik/Sections/Support.php | title + block container |
| section | magazine | section header (`mag-section-header`) | src/Themes/magazine/Sections/Support.php | eyebrow/title + optional view-all link |
| section | moonshine | section block (`ms-section-header`) | src/Themes/moonshine/Sections/Support.php | title/desc + optional view-all |
| section | basic | content section (`ba-section`) | src/Themes/basic/Sections/Support.php | the starter section under `ba-` |
| section | jednotka | numbered unit block (`je-unit` + `je-section`) | src/Themes/jednotka/Sections/Hero.php | every top-level region carries a CSS-counter unit number on the content edge — the same x as the masthead's `00`, never out in the gutter — and the block itself is bounded by a hairline, never a fill |
| form | auth | auth form card (`auth-box`) | src/Modules/Users/Interfaces/HTTP/AuthTemplate.php | labeled fields + CSRF + error slot + footnote |
| form | mark | admin form (`m-form`) | ui/components.css | CSRF field, labels, hints, fieldsets |
| form | starter | comments form (`st-comments__form`) | src/Modules/Comments/Interfaces/HTTP/CommentSection.php | shared by all themes on article pages, guest fields |
| form | erik | search form (`er-search`) | src/Themes/erik/Sections/Controls.php | labeled search control with accessible input |
| form | moonshine | search control (`ms-blog-search`) | src/Themes/moonshine/Sections/Controls.php | GET search box — theme-native re-skin of the starter `st-blog-search`, stacks below 640px |
| form | magazine | shared blog search (`st-blog-search`) | src/Themes/magazine/MagazineTheme.php | magazine keeps the starter search control classes and only renames the input id — no theme-local search skin |
| form | starter | embedded form block (`st-form`) | Frontend/packages/starter/src/form.css | the Forms module block — canonical source, every other theme's copy is generated from it |
| form | erik | embedded form block (`er-form`) | Frontend/packages/erik/src/erik/form.css | generated copy; erik overrides the panel to a raised surface with a shadow |
| form | moonshine | embedded form block (`ms-form`) | Frontend/packages/moonshine/src/moonshine/form.css | generated copy; MoonShine radii, muted token scale |
| form | magazine | embedded form block (`mag-form`) | Frontend/packages/magazine/src/magazine/form.css | generated copy; thin square rules and a solid amber poll bar |
| form | basic | embedded form block (`ba-form`) | Frontend/packages/basic/src/form.css | generated copy; no geometry override — the caricature keeps the starter form, so a drift here is a real defect rather than a design choice |
| form | jednotka | embedded form block (`je-form`) | Frontend/packages/jednotka/src/form.css | generated copy, as for every theme — the block is per-theme by construction because `FormBlockRenderer` builds its classes from the active prefix. The stamp carries the prefix and the `--form-*` aliases; the geometry is the shared form |
| form | starter | reader questions block (`st-questions__form`) | Frontend/packages/starter/src/questions.css | the Questions module block — canonical source, every other theme's copy is generated from it. Renders the APPROVED list above the form, plus `st-questions__sent`, the PRG confirmation |
| form | erik | reader questions block (`er-questions__form`) | Frontend/packages/erik/src/erik/questions.css | generated copy; erik's own COMMENT block is a separate design in `article.css`, but this block is new and starts from the shared shape on erik's palette |
| form | moonshine | reader questions block (`ms-questions__form`) | Frontend/packages/moonshine/src/moonshine/questions.css | generated copy; MoonShine radii and the `m-*` scale, sent note on `--m-success-bg` |
| form | magazine | reader questions block (`mag-questions__form`) | Frontend/packages/magazine/src/magazine/questions.css | generated copy; full width (magazine runs a sidebar layout), thin square rules |
| form | basic | reader questions block (`ba-questions__form`) | Frontend/packages/basic/src/questions.css | generated copy; no geometry override — the caricature keeps the starter block, so a drift here is a real defect rather than a design choice |
| form | jednotka | reader questions block (`je-questions__form`) | Frontend/packages/jednotka/src/questions.css | generated copy, as for every theme — per-theme by construction, because `QuestionBlockRenderer` builds its classes from the active prefix |
| widget | magazine | sidebar widgets (`mag-sidebar-widget`) | src/Themes/magazine/Sections/Widgets.php | popular articles + tag cloud |
| widget | erik | content widgets (`er-widget`) | src/Themes/erik/Sections/Widgets.php | taxonomy links and contextual content |
| widget | moonshine | sidebar widgets (`ms-sidebar-widget`) | src/Themes/moonshine/Sections/Widgets.php | popular + tags + author bio + CTA |
| widget | jednotka | capped outer rail (`je-widget--series`) | src/Themes/jednotka/Sections/Widgets.php | `Support::detectSeries()` groups articles by TAG with no limit, so it returns one entry per tag — 195 on this corpus. The rail lists the biggest 8 and hands over to `/blog/tags`; rendering all of them made a wall of text where a rail belongs, which is why the cap is a hard constant and the overflow is announced rather than silently dropped |
| hero | starter | text hero (`st-hero`) | src/Themes/starter/StarterTheme.php | title + subtitle + media slot |
| hero | magazine | home hero (`mag-home-hero`) | src/Themes/magazine/Sections/Home.php | eyebrow + title + description + actions, feature grid below |
| hero | erik | dark text hero (`er-hero`) | src/Themes/erik/Sections/Hero.php | title + subtitle + CTA slots |
| hero | moonshine | page header (`ms-page-header`) | src/Themes/moonshine/Sections/Hero.php | eyebrow + title + desc in a surface panel |
| hero | basic | text hero (`ba-hero`) | src/Themes/basic/Sections/Hero.php | the starter hero under `ba-` — title + subtitle + media slot |
| hero | jednotka | oversized uppercase statement over a masked plate (`je-hero` / `je-hero__card` / `je-hero__actions` / `je-hero__visual` / `je-button`) | src/Themes/jednotka/Sections/Hero.php | erik's hero COMPOSITION — eyebrow, a card carrying title + description + actions, and a visual layered behind the text — set in this theme's paper: the plate fades in from the bottom of the section behind a mask (so the sentence still owns the first reading line), and the two actions are boxed controls that invert on hover like every other control in the theme |
| card | starter | article card (`st-blog-index__card`) | src/Modules/Blog/Application/Services/BlogArticlesService.php | shared renderer across blog surfaces |
| card | basic | shared renderer card (`st-blog-index__card`) | src/Modules/Blog/Application/Services/BlogArticlesService.php | basic CALLS the shared renderer, so its blog cards stay `st-` while the shell around them is `ba-` — declared in `ThemeDomParityTest::SHARED_BORROWINGS` rather than left as an apparent divergence |
| card | jednotka | shared renderer card (`st-blog-index__card`) | src/Modules/Blog/Application/Services/BlogArticlesService.php | jednotka CALLS the shared renderer, so its blog cards stay `st-` while the shell around them is `je-` — declared in `ThemeDomParityTest::KERNEL_OWNED_CLASSES`, which is theme-independent on purpose, so a factory-made theme does not have to be added to a per-theme list |
| card | erik | article card (`er-card`) | src/Themes/erik/Sections/Cards.php | title, metadata and primary article target |
| card | moonshine | surface card (`ms-card`) | src/Themes/moonshine/Sections/Cards.php | cover + badge + meta + excerpt |
| card | magazine | article card (`mag-card`) | src/Themes/magazine/Sections/Cards.php | cover media, overlay tag, meta row and title |
| card | mark | stat / content card (`m-card`) | ui/components.css | stat cards, filter cards, table wrappers |
| toc | starter | desktop sidebar + mobile FAB (`st-article-toc` / `st-toc-fab`) | src/Themes/starter/StarterRenderer.php | scrollspy-driven active state |
| toc | erik | article sidebar (`er-toc`) | src/Themes/erik/Sections/ArticleView.php | heading-anchor list with scrollspy hook |
| toc | magazine | article sidebar + mobile FAB (`mag-toc` / `mag-toc-mobile`) | src/Themes/magazine/Sections/ArticleView.php | opt-in `data-toc-link` scrollspy with `mag-toc__link--active` |
| toc | moonshine | panel TOC (`ms-toc`) | src/Themes/moonshine/Sections/Article.php | sidebar panel + mobile toggle |
| toc | basic | desktop sidebar + mobile FAB (`ba-article-toc` / `ba-toc-fab`) | src/Themes/basic/BasicRenderer.php | the starter TOC under `ba-`; the scrollspy reads the block off the element, so the shared helper runs unchanged |
| toc | jednotka | sticky sidebar `<aside>` + native `<details>` (`je-article-toc`) | src/Themes/jednotka/JednotkaRenderer.php | an `<aside>` SIBLING of the `<article>` in the shared index grid (`.je-blog-index__layout`, full width, 17rem rail flush right, prose on its measure); no vertical rule beside the column, collapses into a native `<details>` above the article on narrow screens — so this theme ships no TOC FAB at all |

Rows a theme legitimately does not ship (starter and mark have no widget,
mark has no hero) are simply absent — absence of an optional kind on a theme
is part of the matrix, not a gap.

The moonshine blog controls (`ms-blog-search` / `ms-blog-feed` /
`ms-blog-pagination`, `Sections/Controls.php`) are the theme-native
re-skin of the shared blog furniture: the search box is a **form** variant
(row above); the feed links and pagination are **elements** — they carry
no surface presence of their own and live inside the index/search
sections, like badges and buttons do in the admin.

## Surface map

Which kinds appear on which surfaces, and whether they are required or
optional there. Surfaces are the `ThemeModule` page methods plus the admin
(`/mark`) and auth pages.

| Kind | Required on | Optional on | Notes |
|------|-------------|-------------|-------|
| nav | homepage(), blogIndex(), blogSearch(), blogArticle(), blogTags(), blogCategories(), authorProfile(), admin | — | auth pages are standalone: no nav, only a theme toggle |
| footer | homepage(), blogIndex(), blogSearch(), blogArticle(), blogTags(), blogCategories(), authorProfile() | — | admin ends with the sidebar cluster, auth is standalone |
| grid | blogIndex(), blogSearch(), blogTags(), blogCategories(), authorProfile(), admin | homepage(), blogArticle() | admin grids are tables |
| section | homepage(), blogIndex(), blogSearch(), blogArticle(), blogTags(), blogCategories(), authorProfile() | admin | sections compose every public page body |
| edit affordance | — | blogArticle() | not a ninth kind: an inline `<p class="{prefix}-article__edit">` in the article header carrying the kernel-resolved owner link (`PageContext::$editUrl`), plus the inline Markdown editor that link's round trip uses (`PageContext::$inlineEditHtml`, module-owned by `BlogInlineEditor`, appended by the theme after its own article markup). Both are answers to ONE `ArticleWriteGuard::owns` question, so they appear and disappear together — empty for every visitor who would only get a 403, so no theme may synthesize either. The editor's `{prefix}-inline-edit__notice` doubles as the `?saved=1` confirmation. Variant BEM `st-`/`er-`/`ms-`/`mag-`, contract-tested per theme in `ThemeModuleContractTest` (`testEveryThemeRendersTheInlineEditorItIsGiven`, `testNoThemeInventsTheInlineEditor`) and behaviourally in `InlineEditorVisibilityTest`. The editor's toolbar and live preview are client-side and theme-styled (`{prefix}-inline-edit__toolbar` / `__tool` / `__split` / `__preview`), built by `src/inline-editor.ts` only once the disclosure opens — no `ThemeModule` surface, because the theme places module markup rather than deciding what it contains. Contract-tested per theme by `bin/checks/inline-editor-check.mjs`, which is the only place the moonshine `ms-` prefix can be caught. Opening the disclosure promotes the **same** `<details>` to a fixed, full-viewport sheet (`{prefix}-inline-edit[data-editor-fullscreen]`, `__bar`, `__bar-title`, `__bar-close`) — restyled, never moved — so the form, the textarea and the CSRF field keep their DOM position and submission cannot be disturbed by the chrome. `<dialog>` is deliberately unused (its top-layer behaviour and `::backdrop` lag the Chrome 70 baseline). The sheet is script-only by construction, so the no-JS contract has its own proof: `bin/checks/nojs-check.mjs`. Inside the sheet both panes get the same fixed height so their scrollbars run alongside each other, and the preview follows the textarea's position by SOURCE LINE rather than by percentage (`{prefix}-inline-edit__preview` blocks carry `data-editor-line`) — 5,465px of Markdown renders to 4,045px, so a proportional copy drifts the deeper the author goes, and a textarea's 188 source lines wrap into 244 visual rows, which the naive `scrollTop / lineHeight` cannot see |
| form | admin, auth, blogSearch() | blogArticle(), blogIndex() | the comment form on articles; the blog search control on the index and on search results (starter `st-blog-search`, erik `er-search__input`, moonshine `ms-blog-search`) |
| form (embedded) | — | blogArticle(), blogIndex(), homepage() | not a `ThemeModule` method — the Forms block renders INSIDE whatever surface embeds it via `{{form:<slug>}}` or the `form:` front-matter key, on static pages and blog articles. It is theme-styled (`st-form` / `er-form` / `ms-form` / `mag-form`) but theme-agnostic in markup |
| form (reader questions) | — | blogArticle() | also not a `ThemeModule` method — the two surfaces that own the text APPEND the block (`BlogArticleAction`, `PageViewAction`), because a question is about the piece as a whole and has no single anchor in the body. It appears on static pages too, which the embedded-form row above does not: a page is read as a unit, and so is a question about it. Theme-styled (`st-questions` / `er-questions` / `ms-questions` / `mag-questions` / `ba-questions` / `je-questions`), markup owned by `Modules\Questions` |
| widget | — | blogIndex(), blogSearch(), blogArticle(), blogTags(), blogCategories(), authorProfile() | theme choice; starter and mark ship none |
| hero | homepage() | blogIndex(), blogSearch(), blogTags(), blogCategories() | taxonomy heroes only where a theme defines them |
| card | blogIndex(), blogSearch(), blogTags(), blogCategories(), authorProfile() | homepage(), blogArticle(), admin | related/feature grids and search-result grids reuse the card |
| toc | — | blogArticle() | theme choice; moonshine is the only wired theme that can omit it |

## ui/ mapping

Admin components (`.m-*` BEM, `ui/`) map into the same kinds, so the
vocabulary spans the public themes and the admin:

| Kind | Admin component | Anchor |
|------|-----------------|--------|
| nav | `layout-sidebar` sidebar nav, `m-nav` compact top menu | AdminLayout.php, CompactLayout.php |
| footer | `layout-sidebar__footer` utility cluster | AdminLayout.php |
| grid | `m-table` data table | ui/components.css |
| form | `m-form` form, `m-input`, `m-textarea`, `m-fieldset`, `m-hint` | ui/components.css |
| card | `m-card`, `m-stat` stat card | ui/components.css |

Badges (`m-badge`), buttons (`m-btn`) and alerts (`m-alert`) are
**elements**, not blocks: they live inside cards, forms and sections and
carry no surface presence of their own.

The moonshine theme vendors the token file only
(`src/moonshine/tokens.css`, a parity-guarded snapshot of
`ui/tokens.css`): the public bundle never imports `ui/`, which is
inspiration + the admin's component source and is not shipped to the
Docker frontend stage.

## Extending the vocabulary

Add a **variant** (a theme ships a new variant of an existing kind):

1. implement it behind the theme's `Sections/`/`Chrome/` classes
   (respecting the 600-line cap),
2. add one row to the variant matrix with the BEM token in backticks,
3. do it in the same commit as the code — the guard test fails until doc
   and runtime agree.

Add a **kind** (a new structural contract):

1. write the `###` contract subsection under "The nine kinds",
2. add matrix rows for every theme that ships it,
3. add the surface-map row with required/optional marks,
4. keep it a *capability* contract — never geometry,
5. update `ThemeRenderer`/shells as the contract requires.

Both directions update this doc and
`tests/Architecture/DesignBlockVocabularyTest.php` in the same commit.

## Lekalo horizon

The vocabulary is deliberately **not modeled** in
`lekalo/modules/design/` yet (the "postupne" decision): docs are cheap to
edit while the model is not, and a vocabulary that has never been written
as a contract would just be guessed into the model. Promote it into the
semantic model only when ALL of these hold:

1. this matrix survived a full cycle without kind renames (the vocabulary
   is stable),
2. a concrete consumer needs model entries (a theme-scaffolding command,
   a cross-theme contract gate beyond the doc test),
3. a `design.block` entity fits the Model 1.0.0 grammar without new
   definition kinds — identity `[kind, variant, surface]`, resolved by a
   `resolve_blocks` query in the same shape as `design.identity`.

Sketch for the future proposal:

```json
{ "id": "design.block_kind", "kind": "enum",
  "values": ["nav", "footer", "grid", "section", "form", "widget",
             "hero", "card", "toc"] }
{ "id": "design.block", "kind": "entity",
  "identity": ["kind", "variant", "surface"] }
```

Until then this doc is the single source of truth for the block
vocabulary; `docs/lekalo-model.md` points here for anything block-shaped.
