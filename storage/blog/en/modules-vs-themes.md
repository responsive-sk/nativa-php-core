---
title: "Modules and themes ... who owns the markup"
date: "2026-09-22"
version: "1.0.0"
tags: ["architecture", "modules", "themes", "templates"]
excerpt: "A module says WHAT to show, a theme says HOW it looks ... and admin .phtml templates are a third thing. A map of where markup lives in Nativa Minimal — and why never the other way around."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "moduly-vs-temy"
published: true
---

You're building a new screen in Nativa Minimal. You look for a home for the HTML ... `src/Modules/` holds `.phtml` files, `src/Themes/` holds PHP classes and `Frontend/` holds only CSS and TypeScript.

Three places, three answers. None of them is an accident — each has an owner.

The question "why do we have templates and modules" is really two questions. First: who owns the markup of an admin screen? Second: who owns the markup of a public page? The answers differ ... and that's what this article is about.

## Three markup layers

`docs/template-map.md` maps the whole kit ... here's the core:

| Layer | Mechanism | Markup home |
|---|---|---|
| Public frontend | `FrontendLayout` shell + `ThemeRenderer` | `src/Themes/*/` (PHP classes) |
| Admin (`/mark`) | `AdminLayout` + `ViewRenderer` | `src/Modules/*/Interfaces/HTTP/templates/*.phtml` |
| Auth | `AuthTemplate::render()` | self-contained layout in the Users module |

Shared helpers for all three: `InlineCss::files()`, `InlineJs::file()`, `ViewRenderer` — all in `src/Modules/Core/Application/Services/View/`.

Client assets (CSS and TypeScript) live only in `Frontend/packages/starter/` and Vite builds them into `public/dist/starter/`. The pantry and the finished meal — we covered that in [the Frontend article](/en/blog/templates).

## A module ... WHAT the system does

A module is a vertical slice through a feature. `Blog` owns articles from the file in `storage/blog/` down to the `/blog/{slug}` route. `Users` owns auth, sessions and RBAC. `Media` owns uploads and their metadata.

The structure is always the same:

```
src/Modules/Blog/
├── Domain/           # entities and value objects
├── Application/      # services — BlogArticlesService, BlogSearchService
└── Interfaces/HTTP/  # actions, middleware, admin templates
```

`Domain` never imports another module's `Infrastructure` ... `tests/Architecture/ModuleIsolationTest.php` guards that. Modules are about logic — markup is their last meter, not their core.

## Admin templates live in the module

An admin screen is part of the feature the module manages. That's why `.phtml` doesn't sit in a shared `templates/` directory ... it sits under the module:

```
src/Modules/Media/Interfaces/HTTP/templates/
├── media-grid.phtml
├── media-upload-form.phtml
└── ...
```

`ViewRenderer` renders them:

```php
// src/Modules/Media/Interfaces/HTTP/Actions/MediaIndexAction.php — grid cell
return ViewRenderer::render(
    'Modules/Media/Interfaces/HTTP/templates/media-grid.phtml',
    ['thumb' => $thumb, 'urlEscaped' => $urlEscaped, /* ... */],
);
```

`ViewRenderer` is an output-buffered include ... the template receives ONLY the variables passed to `render()` (`EXTR_SKIP`), `$this` is unavailable and a guard clause `$viewRendererGuard = true;` must open the file. `TemplateHygieneTest` guards it ... a template included by anything other than this renderer fails fast, instead of struggling with undefined variables.

The wrapper comes from `AdminLayout` (or `CompactLayout`, if you set that in `/mark/settings`). The module owns the content, the admin shell owns the chrome — and never the other way around.

## Themes ... HOW it looks

The public frontend is a different case. You don't want one markup — you want swappable looks. That's why `src/Themes/` exists:

```
src/Themes/
├── ThemeRenderer.php    # interface — 6 methods
├── ThemeManifest.php    # interface — 4 declarations
├── ThemeRegistry.php    # auto-discovery
├── starter/             # default — the shell IS FrontendLayout
├── magazine/            # mag-* classes on --mag-* tokens
├── minimal/             # minimal-*, light-first
└── moonshine/           # ms-* chrome, dark-first
```

The interface says it all:

```php
// src/Themes/ThemeRenderer.php — six methods, each returns an HTML string
public function blogIndex(array $articles, callable $t): string;
public function blogArticle(array $article, array $headings, ?array $related, ?array $prev, ?array $next, callable $t): string;
// + homepage(), blogTags(), blogCategories(), authorProfile()
```

Registration doesn't exist — `ThemeRegistry` scans `src/Themes/*/Manifest.php` with `glob()` and that's it. Every theme is self-contained: `Manifest.php`, the `Shell.php` and `Renderer.php` facades, markup in `Sections/`, chrome in `Chrome/`, critical CSS in `assets/critical/*.css`. The cap is 600 lines per file ... guarded by `ThemeFileSizeTest`.

Switching a theme is a one-setting affair — `?style=moonshine` in the URL, the `THEME` env var or `site.theme` in `/mark/settings`. `ThemeResolver` decides, in that order; invalid values fall back to `starter`.

## The module says WHAT ... the theme says HOW

You can see it best in `BlogIndexAction`:

```php
// src/Modules/Blog/Interfaces/HTTP/Actions/BlogIndexAction.php — data
$articles = $service->all($locale);
$viewCounts = BlogArticleHelper::viewCounts($articles);

// ... and markup by theme
return match ($theme) {
    'magazine' => $this->magazineIndexPage($service, $locale),
    'minimal' => $this->minimalIndexPage($service, $locale),
    'moonshine' => $this->moonshineIndexPage($service, $locale),
    default => $this->indexPage($service, $locale, $page),
};
```

The action collects articles through `BlogArticlesService` — that's the module. It calls the theme's renderer — that's the look. It wraps everything in `FrontendLayout::render(..., theme: $theme)` — that's the shell.

| | Module | Theme |
|---|---|---|
| Answers | WHAT is shown | HOW it looks |
| Markup | `.phtml` for admin | `Sections/` for public surfaces |
| Changes when | you add a feature | you swap the look |

When you want a new theme, you don't touch modules. When you want a new public surface, you add a method to `ThemeRenderer` and implement it in all four themes. When you want a new admin screen, you write a `.phtml` under the module. Three intents, three paths — each with one owner.

## When this doesn't hold

Being honest: this is not perfect polymorphism.

First, the actions don't call `ThemeRegistry::renderer()->blogIndex(...)` the way you'd expect from interface dispatch. They call `match ($theme)` with direct `MagazineRenderer::blogIndex(...)` calls. The interface and the registry exist and actions may use them, but today's state is a match chain ... adding a fifth theme means reaching into the match arms across the actions. Not 12 files like before the refactor, but not a zero diff either.

Second, the admin is not themeable. Four themes exist for the public frontend only. `/mark`, `/debug` and auth pages always keep their own layouts — `m-*` tokens, `--auth-*` tokens, their own CSS.

Third, starter is the exception. It has no `Sections/` and no `Chrome/` — its shell IS `FrontendLayout` and `StarterRenderer` is a single class. That's why `ThemeRegistry::shellClass('starter')` returns `null` ... the default shell is used directly.

## Practically

1. **New admin screen** ... a `.phtml` under the owning module's `Interfaces/HTTP/templates/` + `ViewRenderer::render()` + the `AdminLayout` wrapper.
2. **New public surface** ... a new method on `ThemeRenderer` + an implementation in every theme's `Sections/` + rendering through `FrontendLayout::render()`.
3. When in doubt, ask who owns the feature ... the module owns the logic and the admin markup, the theme owns the public look. The question "where do I write the markup" then gets an answer ... "who owns it", not "what is it, actually".

→ [Theme Architecture](/en/blog/theme-architecture) — auto-discovery, registry and interface
→ [Frontend ... a pantry of raw ingredients and the finished product](/en/blog/templates) — the two "templates" layers
→ [Module Isolation](/en/blog/module-isolation) — why `Domain` never imports another module's `Infrastructure`

## Sources

- [PHP Manual](https://www.php.net/manual/) — the official PHP documentation
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP standards
- [MDN HTML elements reference](https://developer.mozilla.org/en-US/docs/Web/HTML/Element) — markup as a standard, not a framework property
- [Nativa Minimal Documentation](/docs/getting-started) — this kit's official getting started guide
