---
title: "How MoonShine Inspired Cardboard: An Admin Panel Without a Framework"
date: "2026-08-28"
version: "1.0.0"
tags: ["php", "moonshine", "cardboard", "admin-panel", "design-system"]
excerpt: "MoonShine UI is a Laravel admin panel package. We took only its language, tokens, components, layout, and built Cardboard in pure PHP 8.5."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "moonshine-cardboard"
published: true
---

MoonShine UI is a popular Laravel package for admin panels. It has a beautiful design system, dark mode, BEM components. But we don't use Laravel. And in production, we have no Composer.

The question was: can we take only the language, not the code, and build something equally clean?

The answer is Cardboard.

## What Is MoonShine UI

MoonShine (`moonshine-software/ui`) is an admin panel for Laravel. It offers:

- **Design tokens**: CSS custom properties for colors, typography, spacing
- **BEM components**: `.m-card`, `.m-btn`, `.m-table`, `.m-badge`
- **Sidebar layout**: fixed sidebar with navigation
- **Dark mode**: via `data-theme` attribute
- **Forms**: inputs, selects, switchers, uploads

It's all nice. It's all robust. And it all depends on Laravel.

## What We Took From It

When we built the `/mark` admin panel for Nativa Minimal, we weren't looking for a framework. We were looking for a language.

MoonShine gave us:

**Tokens.** A color palette that works in both dark and light mode:

```css
:root {
    --m-primary: #7c3aed;      /* MoonShine purple */
    --m-surface: #1e293b;
    --m-border: #334155;
}
:root[data-theme="light"] {
    --m-surface: #ffffff;
    --m-border: #e2e8f0;
}
```

**BEM conventions.** `.m-card`, `.m-btn-primary`, `.m-stat-value`: every name says what it is and where it belongs.

**Layout pattern.** Fixed sidebar with collapsible toggle, mobile overlay, main content in `margin-left`.

**Dark mode.** A simple inline script in `<head>`: synchronous, CSP-safe, no FOUC:

```html
<script nonce="__CSP_NONCE_PLACEHOLDER__">
(function() {
    var theme = localStorage.getItem('nativa-theme')
        || (matchMedia('(prefers-color-scheme:dark)').matches ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme', theme);
})();
</script>
```

## What We Didn't Do

We didn't use MoonShine code. No Blade components, no Laravel service providers, no `x-moonshine::` directives.

Why? Because Nativa Minimal has a rule: **zero-dependency runtime**. No Composer packages in production. Not even MoonShine.

In the `ui/` directory we have MoonShine-inspired Blade templates as a reference, a design system showing what components could look like. But the admin panel in `/mark` runs on pure PHP:

```php
// AdminLayout — no Blade, no templates
$content = '<div class="m-card">'
    . '<div class="m-stat-value">' . $userCount . '</div>'
    . '<div class="m-stat-label">Users</div>'
    . '</div>';
```

Every HTML string is a PHP string. No extra abstraction.

## Cardboard: Everything Is a Card

Once we had the admin panel, the question was: how do we display statistics on the dashboard?

The traditional way: hardcoded HTML blocks in `DashboardAction`. Every card is a `<div class="m-stat">` with copy-pasted structure. Add a new card, copy the block, change the number and label.

Cardboard does it differently. **Every dashboard element is an immutable Card object.**

```php
Card::make('users', 'Users', 42)
    ->withChart(new Donut([
        ['value' => 30, 'color' => 'var(--m-primary)'],
        ['value' => 12, 'color' => 'var(--m-info)'],
    ]))
    ->withActions(
        CardLink::make('List', '/mark/users'),
        CardLink::make('New', '/mark/users/create'),
    )
    ->withSubtitle('3 admins · 12 editors')
    ->withPriority(100);
```

Every `with*()` returns a **new instance**: no mutation. A card is a value, not a state.

## Why Immutable

MoonShine uses mutable components,`->column()`, `->badge()`, `->action()` chain changes on the same instance. In Laravel this works because the component lives for one request.

In Nativa Cardboard we wanted something different: **cards as values**. Like `Money` or `DateInterval`: when you want to change it, you create a new one. You test by comparing. You can't break state.

```php
$original = Card::make('media', 'Media', 15);
$withChart = $original->withChart(new Sparkline([5, 8, 12, 15]));

// $original is still without chart. No surprises.
```

## SVG Charts: No JavaScript

Dashboard cards can have mini charts. Sparklines for trends, donuts for distribution, bars for comparison.

All inline SVG. No Canvas, no Chart.js, no D3. Just `<polyline>`, `<circle>`, `<rect>`:

```php
$chart = new Donut([
    ['value' => 5, 'color' => 'var(--m-primary)'],  // SK
    ['value' => 3, 'color' => 'var(--m-info)'],      // EN
]);
// Renders: <svg><circle stroke-dasharray="..."/></svg>
```

Why SVG and not JavaScript? Because:

1. **CSP-safe**: no extra inline scripts
2. **Instant**: no waiting for library loading
3. **Tiny**: one donut is ~200 bytes. Six cards = 1.2KB
4. **Zero-dependency**: just like the rest of the project

## CardboardRegistry

Cards aren't registered directly in the action. A registry collects them:

```php
CardboardRegistry::register(
    Card::make('articles', 'Articles', $count)
        ->withChart(new Donut([...]))
        ->withPriority(60)
);
```

`DashboardAction` just iterates `CardboardRegistry::all()` and renders. Add a new card, register it, no change in the Dashboard.

The registry is a static array. No DI, no magic methods. As simple as `getenv()`.

## C-Pattern and Architecture

Cardboard follows the Nativa Minimal C-pattern rules:

- **No magic methods**: no `__call`, `__get`
- **No Reflection** outside `Modules\Core`
- **No `eval()`**
- **Methods under 60 lines**: `registerStatCards()` splits into `registerAccessCards()` and `registerContentCards()`
- **No speculative interfaces**: `ChartContract` has 3 implementations (Sparkline, Donut, Bar); `Card` is used directly (one implementation = no interface)

Every rule has its test in `CorePatternTest`. Break it and CI fails.

## The Result

An admin panel at `/mark` with a dashboard that:

- Shows 7 stat cards with live values
- Has SVG donut charts on Users (role distribution), Articles (SK/EN), Pages (SK/EN)
- Has action links on every card (List, New, Upload)
- Works in both dark and light mode
- Runs without a single Composer package in production
- Has 639 tests, PHPStan level 9, 0 CS-Fixer violations

And the entire Cardboard module is 8 new files.

→ [Clean Code for Kids](/blog/co-je-cisty-kod-pre-deti): how to write code that reads itself
→ [Philosophy of Fewer Layers](/blog/filozofia-menej-vrstiev): why fewer abstractions = fewer bugs
→ [Theme Comparison](/blog/theme-comparison): comparing starter, magazine, and minimal themes

## Sources

- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP standards
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
- [W3C, Web Standards](https://www.w3.org/standards/): web standards
