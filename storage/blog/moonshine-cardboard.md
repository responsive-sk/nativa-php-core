---
title: "Ako MoonShine inšpiroval Cardboard — admin panel bez frameworku"
date: "2026-08-28"
version: "1.0.1"
tags: ["php", "moonshine", "cardboard", "admin-panel", "design-system"]
excerpt: "MoonShine UI je Laravel balík pre admin panely. My sme z neho vzali len jazyk — tokeny, komponenty, layout — a postavili sme Cardboard v čistom PHP 8.5."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "moonshine-cardboard"
published: true
---

MoonShine UI je populárny Laravel balík pre admin panely. Má krásny design system, tmavý režim, BEM komponenty. Ale my nepoužívame Laravel. A v produkcii nemáme Composer.

Otázka znela: vieme zobrať len jazyk — nie kód — a postaviť niečo rovnako čisté?

Odpoveď je Cardboard.

## Čo je MoonShine UI

MoonShine (`moonshine-software/ui`) je admin panel pre Laravel. Ponúka:

- **Design tokeny** — CSS premenné pre farby, typografiu, medzery
- **BEM komponenty** — `.m-card`, `.m-btn`, `.m-table`, `.m-badge`
- **Sidebar layout** — fixný sidebar s navigáciou
- **Tmavý režim** — cez `data-theme` atribút
- **Formuláre** — inputy, selecty, switche, uploady

Všetko je pekné. Všetko je robustné. A všetko závisí od Laravel.

## Čo sme z neho vzali

Keď sme stavali `/mark` admin panel pre Nativa Minimal, nehľadali sme framework. Hľadali sme jazyk.

MoonShine nám dal:

**Tokeny.** Paletu farieb, ktorá funguje v tmavom aj svetlom režime:

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

**BEM konvencie.** `.m-card`, `.m-btn-primary`, `.m-stat-value` — každý názov povie, čo je a kam patrí.

**Layout pattern.** Fixný sidebar s collapsible toggle, overlay na mobile, hlavný obsah v `margin-left`.

**Tmavý režim.** Jednoduchý inline script v `<head>` — synchronný, CSP-safe, žiadny FOUC:

```html
<script nonce="__CSP_NONCE_PLACEHOLDER__">
(function() {
    var theme = localStorage.getItem('nativa-theme')
        || (matchMedia('(prefers-color-scheme:dark)').matches ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme', theme);
})();
</script>
```

## Čo sme neurobili

Nepoužili sme MoonShine kód. Žiadne Blade komponenty, žiadne Laravel service providery, žiadne `x-moonshine::` direktívy.

Prečo? Lebo Nativa Minimal má pravidlo: **zero-dependency runtime**. Žiadny Composer balík v produkcii. Ani MoonShine.

V `ui/` adresári máme MoonShine-inšpirované Blade šablóny ako referenciu — dizajnový systém, ktorý ukazuje, ako by komponenty mohli vyzerať. Ale admin panel v `/mark` funguje na čistom PHP:

```php
// AdminLayout — žiadne Blade, žiadne šablóny
$content = '<div class="m-card">'
    . '<div class="m-stat-value">' . $userCount . '</div>'
    . '<div class="m-stat-label">Users</div>'
    . '</div>';
```

Každý HTML string je PHP string. Žiadna abstrakcia navyše.

## Cardboard: všetko je Card

Keď sme mali admin panel, prišla otázka: ako zobrazíme štatistiky na dashboarde?

Tradičná cesta: hardcoded HTML bloky v `DashboardAction`. Každá karta je `<div class="m-stat">` s copy-paste štruktúrou. Pridáte novú kartu — skopírujete blok, zmeníte číslo a label.

Cardboard to robí inak. **Každý dashboard prvok je immutable Card objekt.**

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
    ->withSubtitle('3 admin · 12 editorov')
    ->withPriority(100);
```

Každý `with*()` vráti **novú inštanciu** — žiadna mutácia. Karta je pointer, nie stav.

## Prečo immutabilita

MoonShine používa mutabilné komponenty — `->column()`, `->badge()`, `->action()` reťazia zmeny na rovnakej inštancii. V Laravel to funguje, lebo komponent žije jeden request.

V Nativa Cardboard sme chceli niečo iné: **karty ako hodnoty**. Rovnako ako `Money` alebo `DateInterval` — keď chcete zmeniť, vytvoríte novú. Testujete porovnaním. Nedá sa pokaziť stav.

```php
$original = Card::make('media', 'Media', 15);
$withChart = $original->withChart(new Sparkline([5, 8, 12, 15]));

// $original je stále bez chartu. Bez prekvapení.
```

## SVG Charts — žiadne JavaScript

Dashboard karty môžu mať mini grafy. Sparkline pre trend, donut pre distribúciu, bar pre porovnanie.

Všetko je inline SVG. Žiadne Canvas, žiadne Chart.js, žiadne D3. Len `<polyline>`, `<circle>`, `<rect>`:

```php
$chart = new Donut([
    ['value' => 5, 'color' => 'var(--m-primary)'],  // SK
    ['value' => 3, 'color' => 'var(--m-info)'],      // EN
]);
// Renderuje: <svg><circle stroke-dasharray="..."/></svg>
```

Prečo SVG a nie JavaScript? Lebo:

1. **CSP-safe** — žiadne inline skripty navyše
2. **Okamžité** — žiadne čakanie na načítanie knižnice
3. **Tiny** — jeden donut je ~200 bajtov. Šesť kariet = 1.2KB
4. **Zero-dependency** — presne ako celý projekt

## CardboardRegistry

Karty sa neregistrujú priamo v akcii. Registry ich zbiera:

```php
CardboardRegistry::register(
    Card::make('articles', 'Articles', $count)
        ->withChart(new Donut([...]))
        ->withPriority(60)
);
```

`DashboardAction` len iteruje `CardboardRegistry::all()` a renderuje. Pridáte novú kartu — zaregistrujete ju, žiadna zmena v Dashboarde.

Registry je statický array. Žiadne DI, žiadne magic methods. Jednoduchý ako `getenv()`.

## C-Pattern a architektúra

Cardboard dodržiava C-pattern pravidlá Nativa Minimal:

- **Žiadne magic methods** — žiadne `__call`, `__get`
- **Žiadna Reflexia** mimo `Modules\Core`
- **Žiadne eval()**
- **Metódy do 60 riadkov** — `registerStatCards()` sa delí na `registerAccessCards()` a `registerContentCards()`
- **Žiadne speculative interfaces** — `ChartContract` má 3 implementácie (Sparkline, Donut, Bar), `Card` sa používa priamo (jedna implementácia = žiadny interface)

Každé pravidlo má svoj test v `CorePatternTest`. Ak ho porušíte, CI padne.

## Výsledok

Admin panel na `/mark` s dashboardom, ktorý:

- Ukazuje 7 štatistických kariet s aktuálnymi hodnotami
- Má SVG donut grafy na Users (rolovanie), Articles (SK/EN), Pages (SK/EN)
- Má action links na každej karte (List, New, Upload)
- Funguje v tmavom aj svetlom režime
- Beží bez jediného Composer balíka v produkcii
- Má 639 testov, PHPStan level 9, 0 CS-Fixer violations

A celý Cardboard modul je 8 nových súborov.

→ [Clean code pre deti](/blog/co-je-cisty-kod-pre-deti) — ako písať kód, ktorý sa číta sám
→ [Filozofia menej vrstiev](/blog/filozofia-menej-vrstiev) — prečo menej abstrakcií = menej chýb
→ [Theme comparison](/blog/theme-comparison) — porovnanie starter, magazine a minimal tém

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — bezpečnostné best practices
- [W3C — Web Standards](https://www.w3.org/standards/) — web štandardy
