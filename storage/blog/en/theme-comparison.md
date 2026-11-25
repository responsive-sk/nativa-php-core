---
title: "Theme Comparison ... Starter vs Magazine vs Minimal"
date: "2026-08-22"
version: "1.0.0"
tags: ["php", "themes", "comparison", "wcag"]
excerpt: "Comparing the three themes in Nativa Minimal ... when to use which, CSS tokens, WCAG AA contracts, and each layout's specifics."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "theme-comparison"
published: true
---

In Nativa Minimal we have three themes. Each is designed for a
different purpose. In this article we compare when to use which,
what CSS tokens they use, and what their WCAG AA contracts are.

## Overview

| | Starter | Magazine | Minimal |
|---|---|---|---|
| **Style** | Simple, clean | Full-width, hero grid | Light-first, blocky |
| **Entry** | `src/main.ts` | `src/magazine.ts` | `src/minimal.ts` |
| **Shell** | FrontendLayout | MagazineShell | MinimalShell |
| **CSS prefix** | `--st-*` | `--mag-*` | `--min-*` |
| **Font** | System stack | Serif (Georgia) | System stack |
| **CSS loading** | Inline (works without build) | Deferred (media="print") | Deferred (media="print") |
| **Mobile menu** | Hamburger | JS toggle | CSS checkbox |
| **Sidebar** | No | Yes (popular/tags) | Yes (popular/tags) |
| **Hero section** | No | Yes (2fr + 1fr grid) | Yes (centered single) |
| **TOC** | No | Yes (sidebar + mobile FAB) | Yes (sidebar + mobile FAB) |
| **Footer** | Simple | Multi-column | Centered |

## Starter ... the default theme

**When to use:** Simple sites, blogs, portfolios.

**Characteristics:**
- Clean, minimalist layout
- No sidebar
- No hero section
- No TOC
- Simple footer
- Minimal CSS

**CSS tokens:**
```css
--st-accent-strong: #f59e0b;  /* button bg, white text */
--st-accent-soft: #d97706;    /* tag/link text */
--st-accent-hover: #d97706;   /* button hover */
--st-error-ink: #dc2626;      /* error text */
```

**Usage:**
```
/?style=starter
```

## Magazine ... full-width layout

**When to use:** Magazines, news portals, large blogs.

**Characteristics:**
- Full-width sticky header (64px)
- **Serif font** (Georgia) for headings
- Hero grid: **2fr + 1fr layout** (featured + 2 small cards)
- Sidebar with popular articles and tags
- Category sections with horizontal scroll
- TOC in sidebar (desktop) + mobile FAB panel
- Multi-column footer (brand + quick links + feeds)
- JS mobile menu
- Search trigger → `/blog?q=`

**CSS tokens:**
```css
--mag-accent: #f59e0b;        /* decorative (icons, borders) */
--mag-accent-strong: #f59e0b; /* button bg, white text */
--mag-accent-soft: #d97706;   /* tag/link text */
--mag-accent-hover: #d97706;  /* button hover */
```

**Usage:**
```
/?style=magazine
```

## Minimal ... light-first editorial

**When to use:** Clean blogs, minimal portfolios, "less is more".

**Characteristics:**
- Light-first design
- Blocky editorial style
- **System font stack** (no serif)
- DB-driven navigation (SettingsServices::menu)
- CSS-only checkbox mobile menu
- **Count-aware grids**: `--1col` / `--2col` / `--3col`
- Category sections with "View all" links
- Sidebar widgets (popular articles + tags)
- TOC in sidebar (desktop) + mobile FAB panel
- Centered footer
- CTA block on homepage

**CSS tokens:**
```css
--min-accent: #f59e0b;        /* decorative (icons, borders) */
--min-accent-strong: #f59e0b; /* button bg, white text */
--min-accent-soft: #d97706;   /* tag/link text */
--min-accent-hover: #d97706;  /* button hover */
```

**Usage:**
```
/?style=minimal
```

## WCAG AA contracts

All themes must meet WCAG AA (4.5:1 contrast). `BuildCssAuditTest`
tests tokens in **both themes** (dark + light):

| Token | Purpose | Dark value | Light value |
|---|---|---|---|
| `--st-accent-strong` | Button bg + white text | `#f59e0b` | `#d97706` |
| `--st-accent-soft` | Tag/link text on surface | `#fbbf24` | `#92400e` |
| `--st-error-ink` | Error banner text | `#dc2626` | `#dc2626` |
| `--mag-accent-strong` | Button bg + white text | `#b45309` | `#b45309` |
| `--mag-accent-soft` | Tag/link text on surface | `#fbbf24` | `#92400e` |
| `--min-accent-strong` | Button bg + white text | `#b45309` | `#92400e` |
| `--min-accent-soft` | Tag/link text on surface | `#fbbf24` | `#92400e` |

**Decorative tokens** (`--mag-accent`, `--min-accent`) have no AA
requirement ... they're for icons and borders, not text.

### Why amber doesn't work directly?

`#d97706` (amber-600) has only **3.1:1** contrast with white text.
That's why we use darker variants:
- `#b45309` (amber-700) = **5.0:1** ... button bg
- `#92400e` (amber-800) = **7.1:1** ... hover, light theme
- `#fbbf24` (amber-400) = **4.6:1** ... link text on surface

## Adaptive grids (Minimal theme)

Minimal theme has a special feature: **count-aware grids**.

```css
.minimal-grid--1col { grid-template-columns: minmax(0, 1fr); }      /* 1 result */
.minimal-grid--2col { grid-template-columns: repeat(2, minmax(0, 1fr)); }   /* 2 results */
.minimal-grid--3col { grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); } /* 3+ */

/* Desktop: fixed 3-col */
@media (min-width: 900px) { .minimal-grid--3col { grid-template-columns: repeat(3, 1fr); } }

/* Tablet: 2-col */
@media (max-width: 899px) and (min-width: 640px) { .minimal-grid--3col { grid-template-columns: repeat(2, 1fr); } }

/* Mobile: 2-col → 1-col */
@media (max-width: 640px) { .minimal-grid--2col { grid-template-columns: minmax(0, 1fr); } }
```

Tag, category and author pages with few articles don't squeeze into
1/3 width ... they adapt automatically.

## Switching

**Three ways to switch:**

1. **Admin panel** (`/mark/settings`) ... `site.theme` dropdown
2. **Query parameter** (`?style=magazine`) ... preview without changing settings
3. **Env variable** (`THEME=minimal`) ... force theme for entire deployment

**Precedence:** query param > env > setting > starter (default)

## When to use which

| Scenario | Theme |
|---|---|
| Simple blog | Starter |
| Magazine with featured articles | Magazine |
| Clean, minimalist blog | Minimal |
| Company website | Starter or Custom |
| News portal | Magazine |
| Portfolio | Minimal |

## Summary

- **Starter** ... simple, clean, default
- **Magazine** ... full-width, hero grid, sidebar
- **Minimal** ... light-first, blocky, adaptive grids
- All meet WCAG AA (4.5:1)
- Switch via admin, query param or env
- Adding a new theme = 3 files (auto-discovery)

---

→ [Theme Architecture](/blog/theme-architecture): how auto-discovery works
→ [Build Your Own Theme](/blog/build-your-theme): step-by-step guide

## Sources

- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP standards
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [W3C, Web Standards](https://www.w3.org/standards/): web standards
