---
title: "Frontend Refactor, Part 1: Moonshine Falls Into Sections"
date: "2026-09-13"
version: "1.0.0"
tags: ["php", "refactoring", "moonshine", "architecture", "frontend"]
excerpt: "The 751-line Shell and 659-line Renderer in the moonshine theme fell into sections — critical CSS in eight classes with proven byte-parity, chrome in its own classes, and a renderer facade with zero magic. Along the way, the refactor showed why a class name can collide with a PHP 8.5 import."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "refaktor-frontendu-pre-deti"
published: true
---

> This is part 1 of the **Frontend Refactor** series — about breaking up
> oversized theme files into sections without a single visual change.
> Every number below is live output from the repository. Nothing is
> invented. [Read the Slovak version](/blog/refaktor-frontendu-pre-deti).

## 1. Why — and an honest rule distinction

It all started with a complaint: "files too big, 600+ lines > RULES!".
The truth is nuanced and worth stating plainly:

- **The enforced rule in this project is method-level** — the chunky
  rule, max 60 lines per method body, guarded by an architecture test.
  That rule never flagged the moonshine theme. No CI gate was red.
- **600+ lines per file was our own threshold** — a maintenance rule,
  not a machine's. `Shell.php` had 751 lines, `Renderer.php` 659,
  `moonshine.css` 1315. Editing one thing meant scrolling through
  1300 lines.

That is exactly why the refactor didn't stop at moving code around —
the threshold becomes an architecture test that fails if any theme
file ever grows back past 600 lines. More on that in part 3.

## 2. The map, before and after

```
Before                            After (moonshine)
─────────────────────────────     ─────────────────────────────
Shell.php            751 lines    Shell.php            229 (facade)
Renderer.php         659 lines    Renderer.php         293 (facade)
moonshine.css       1315 lines    CriticalCss/Ms*    8 classes
                                  Chrome/{Nav,Controls,Scripts}
                                  Sections/{Support,Cards,
                                            Widgets,Article,
                                            Hero,BlogIndex}
```

The deal: no method changed, none disappeared, nothing was merged.
Addresses changed, not behavior.

## 3. Three kinds of sections

**CriticalCss/Ms\*** — eight classes (`MsTokens`, `MsSidebar`,
`MsTopbar`, `MsMobile`, `MsContent`, `MsWidgets`, `MsArticle`,
`MsToc`), each with a single `render(): string` method holding a
heredoc moved word for word from the original `criticalCssMs*()`
helpers. The `Shell::criticalCss()` facade chains them in the exact
original order.

**Chrome/** — shell furniture: `Nav` (sidebar navigation, burger,
collapse), `Controls` (theme toggle, locale switcher, `chromeLabels()`,
deferred CSS) and `Scripts` (theme init, drawer, back-to-top).

**Sections/** — the renderer's body: `Support` (shared vocabulary:
`t()`, dates, hrefs, wrappers), `Cards`, `Widgets`, `Article`, `Hero`,
`BlogIndex`. `Renderer.php` stays a facade — each of the ~20 public
static methods is an explicit one-line delegate. No `__call`: that's
a C-pattern ban — transparency over shortcuts.

## 4. Byte-parity: the trap waiting in heredocs

The series has one simple invariant: **critical CSS must come out
byte-identical**. Snapshot before the move:

```bash
php -r 'require "src/init.php";
file_put_contents("/tmp/ms-critical-before.txt",
  Themes\moonshine\Shell::criticalCss());'
# 41,140 bytes
```

After the move, compare — and the first run reported a difference.
The cause is subtle and classic PHP heredoc behavior: **the heredoc
closing marker eats the trailing newline**. The original chain of
eight heredocs therefore had spots with *zero* newlines between parts
(and one spot, tokens→sidebar, with one — they shared a heredoc).
The new classes each wrote their own spacing — and the mismatch was
baked in.

The second trap was in my own extraction script: `rindex("/**")`
grabbed one docblock too early, because one of the original methods
**had no docblock**. Python silently cut it, PHP silently swallowed
it — and the snapshot caught it within seconds.

That is precisely why the parity drill is not a formality:

```bash
php -r 'require "src/init.php";
echo Themes\moonshine\Shell::criticalCss()
  === file_get_contents("/tmp/ms-critical-before.txt")
  ? "BYTE-PARITY OK" : "MISMATCH";'
# BYTE-PARITY OK (41,140 bytes)
```

## 5. PHP 8.5: when a class name collides with an import

The most interesting error arrived from PHPStan, which loads **every**
file through its bootstrap. `Sections/Article.php` declared a class
`Article` and also did `use Modules\Blog\Domain\Entities\Article;`
— under the same short name. PHP 8.5 fatals on that:

```
Fatal error: Cannot redeclare class Themes\moonshine\Sections\Article
(previously declared as local import)
```

The interesting part: PHPUnit swallowed it (it only loads the classes
it needs); PHPStan's full load exposed it. Lesson: name sections after
the *job*, not the entity — the magazine theme's article section is
therefore called `ArticleView`.

## 6. Mirror: magazine and CSS with its own boundaries

The pattern moved to the magazine theme (Shell 752 → 196, Renderer
576 → 249). Something new appeared there — **splitting CSS**.
`magazine.css` (423 lines) already had its own section comments
(`── Header ──`, `── Cards ──`…), so the files
`src/magazine/{index,tokens,shell,content,article,utilities}.css`
were cut along the original boundaries, and `index.css` is an ordered
`@import` chain — because import order IS the cascade.

The invariant is hardware-grade there too:

```bash
cmp public/dist/starter/assets/magazine-CeqghFH8.css /tmp/mag-built-before.css
# identical — same content hash even after a rebuild
```

The same hash `CeqghFH8` means Vite saw exactly the same rules in
exactly the same order. And `AssetManager` — manifest keys, hashed
names — didn't change at all.

## 7. What you take away

- **Sections along real seams, not a paper plan.** The plan assumed
  `Sections/Home.php` — reality: home/taxonomy markup lives in the
  action classes, the theme only renders building blocks. Fake
  sections would have been a new kind of chaos.
- **A facade without magic is cheap and readable.** 20 delegates,
  one line each.
- **Parity is proven with snapshots, not conviction.** Heredoc
  newlines, missing docblocks and same-named imports — all of it is
  silent until you make it loud.

In the next part: the minimal theme, the `ThemeFileSizeTest`
regression guard (the 600-line ceiling enforced by an architecture
test), and the docs.

→ [How MoonShine Inspired Cardboard](/en/blog/moonshine-cardboard) —
where the themes' token and component language comes from
→ [Theme Architecture](/en/blog/theme-architecture) — why themes have
a Shell/Renderer/Manifest at all

## Sources

- [WCAG 2.2 — Contrast (Minimum)](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html) — the contract `BuildCssAuditTest` computes from shipped tokens
- `openspec/changes/2026-09-13-theme-section-split/` — the plan for the
  whole series
- `src/Themes/moonshine/` — the resulting structure (CriticalCss/, Chrome/,
  Sections/)
- `tests/Css/BuildCssAuditTest.php` — the WCAG contracts that stayed green
  against the byte-identical bundle
