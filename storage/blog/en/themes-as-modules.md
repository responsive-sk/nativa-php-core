---
title: "Themes for kids ... part 1: a theme is a module"
series: "Themes for kids"
series-part: "1"
date: "2026-10-02"
version: "1.0.0"
published: true
tags: ["php", "architecture", "themes", "modules"]
excerpt: "A theme was not a thing, it was a smear ... now it is a module with a contract, a connector and a test that guards the boundary. Part 1 of the themes series: what changed and where it goes."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "temy-ako-moduly-pre-deti"
---

> [Auto-discovery and a registry](/en/blog/theme-architecture) was one
> answer, [the theme comparison](/en/blog/theme-comparison) another
> viewpoint. This series starts where both ended ... a theme as a module.
> Part 1: the contract, the connector and a boundary guarded by a test.

You are a PHP developer. You add a new page to the blog — say, a monthly
archive. You open the action, add rendering, reach for the chrome in the
layout... and a moment later you are editing a tenth file.

That is not a discipline failure. It is a boundary failure.

A theme was not a thing. It was a smear.

## A theme as a spread diff

How many places did a person have to understand to touch one theme? Three:

1. `FrontendLayout` branched on theme names — five `'erik' === $bundle`
   checks picked the chrome, BEM prefixes and the critical-CSS fallback
   right inside the kernel.
2. Actions assembled theme pages themselves — `erikIndexPage()`, `erikHome()`
   and their sibling helpers imported `Themes\*` sections and glued hero +
   sections + widgets inside the action body.
3. `Themes\ThemeRenderer` was a legacy interface — arrays instead of
   contexts, a `callable $t` instead of resolved translations, no shell
   ownership.

A module is a unit you copy, test and delete. A smear is not a unit — it is
a treasure hunt.

## The contract ... 66 lines

The `0907f3a` refactor built the inverse. Every wired theme implements one
contract:

```php
// src/Themes/ThemeModule.php — the contract (excerpt)
interface ThemeModule
{
    public function name(): string;
    public function bundle(): string;
    public function prefix(): string;

    public function header(ShellContext $context): string;
    public function footer(ShellContext $context): string;
    public function pageWrap(string $content): string;

    public function homepage(PageContext $context): string;
    public function blogIndex(PageContext $context): string;
    // ... blogSearch, blogArticle, blogTags, blogCategories, authorProfile
}
```

Explicit methods, no `__call`. The theme fills in an exchange: name, asset
bundle, BEM prefix, chrome and one method per public surface.

The exchange is complete. Nothing in the contract says "how to look" — the
contract says "what you must give".

## The connector ... 36 lines

Who maps a theme name to a module? One place:

```php
// src/Modules/Core/Interfaces/HTTP/ThemeKernel.php — the connector
final class ThemeKernel
{
    /** @return array<string, class-string<ThemeModule>> */
    private const array MODULES = [
        ThemeResolver::PUBLIC_THEME => StarterTheme::class,
        ThemeResolver::ADMIN_THEME => MoonshineTheme::class,
        ThemeResolver::ERIK_THEME => ErikTheme::class,
    ];

    public static function for(string $theme): ThemeModule
    {
        $class = self::MODULES[$theme] ?? self::MODULES[ThemeResolver::PUBLIC_THEME];

        return new $class();
    }
}
```

`ThemeKernel` is the only file under `src/Modules` allowed to import
`Themes\`. Not an agreement — a test.

`ThemeOwnershipTest` walks all of `src/Modules` and fails on any other
`use Themes\` import. A boundary guarded by a machine is a boundary.

## The kernel owns data, the theme owns HTML

The second half of the exchange: contexts. The action computes data, the
theme composes markup.

```php
// src/Modules/Blog/Interfaces/HTTP/Actions/BlogIndexAction.php — the action today
$module = ThemeKernel::for($theme);

if ('' !== $query) {
    $content = $module->blogSearch(
        PageContext::forBlogSearch($request, $theme, $locale, $query, $result, $results),
    );
} else {
    $articles = $service->all($locale);
    $content = $module->blogIndex(
        PageContext::forBlogIndex($request, $theme, $locale, $articles, page: $page, perPage: self::PER_PAGE),
    );
}
```

Every surface is one call. `PageContext` carries articles, view counts,
pagination — what the action computed. `ShellContext` carries menus, locale
data and sign-in state — what the chrome needs.

| | The kernel owns | The theme owns |
|---|---|---|
| Data | articles, menu DTOs, translations, auth state | — |
| HTML | — | chrome, locale switcher, user link, page bodies |
| SEO head | canonical, hreflang, OG, JSON-LD | — |
| CSS/JS wiring | nonce placeholder, `<body class>` from the module name | critical-CSS list, theme-init script |

`FrontendLayout` today has no branch on a theme name. It is a head
connector — and that is its whole job.

## Before and after

| | Before | After |
|---|---|---|
| Add a public surface to themes | action + layout + theme — up to ~10 files | contract + 3 modules, one method each |
| Add a whole theme | folder + whitelist + select + 6 actions | folder + 1 line in `ThemeKernel::MODULES` |
| `use Themes\` under `src/Modules` | six actions + layout | **1** — `ThemeKernel` |
| Branches on a theme name in `FrontendLayout` | 5 | **0** |
| How you notice a boundary breach | reading | `ThemeOwnershipTest` fails |

Numbers from the repo: the contract is 66 lines, the connector 36, `src/Themes/`
carries 57 PHP files, and the whole `FrontendLayout` file is 461 lines — zero
theme-name comparisons among them.

## When this does not hold

Let us be honest — three things this model does not solve.

A theme is **not a module in the DDD sense**. No routes, no migrations, no
database; presentation does not get `ModuleInterface::register()`. A folder
under `src/Themes/` is not a vertical slice — it is a presenter. If you came
for DDD slices, the answer is a deliberate "no".

The contract **has a price**. A new public surface means a method in all
three modules. That is not an accident — if you cannot afford to write a
surface three times, maybe it does not belong in the shared contract, only
in one theme.

And the legacy `Themes\ThemeRenderer` **still lives**. The renderer facades
keep its six methods as one-line delegates. Folding it into `ThemeModule` is
planned for later — doing both in one commit would be two changes in one.

## The vision ... a folder you can delete

Where does this grow? Three directions — all grounded in what already stands
in the repo.

A theme is a **folder**. `starter`, `erik`, `moonshine` — each carries its
own `Chrome/`, `Sections/`, `assets/critical/` and leaves no trace in the
kernel. Adding a fourth theme means writing a folder and adding a line.
Removing a theme means `rm -r` and a line. We do not need ten themes at
all — we need themes you can carry.

The block vocabulary is **held by a matrix**. Nine structural kinds — nav,
footer, grid, section, form, widget, hero, card, toc — are written down in
`docs/design-blocks.md`, and `DesignBlockVocabularyTest` checks that the
variants from the matrix really exist in the code. The document and the
runtime cannot drift apart.

And the model **tells the same story as the code**. Lekalo models the theme
modules and the design tokens; `ThemesModelParityTest` checks that the
variants in the model are the same variants as in the resolver. Design here
is not paint — it is a contract with WCAG contrast ratios, guarded by a
machine.

That is the vision: a theme as a product with a contract, tests and a model.
Not a theme as a trick in a version line.

## What comes next

In part 2 we add a fourth theme step by step — a folder, the contract, one
line in the connector and its own Vite entry. We will see what the contract
asks and what it does not ... and where we stop before a speculative
abstraction is born.

## Sources

- [PHP Manual](https://www.php.net/manual/) — the official PHP documentation
- [Vite](https://vite.dev/) — official documentation of the build tool
- [PHPUnit](https://phpunit.de/) — the testing framework documentation
- [Nativa Minimal Documentation](/docs/getting-started) — the kit's official guide
