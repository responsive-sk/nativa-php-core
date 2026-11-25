---
title: "Theme Comparison ... Starter vs Magazine vs Minimal"
date: "2026-08-22"
version: "1.0.2"
tags: ["php", "themes", "comparison", "wcag"]
excerpt: "Porovnanie troch tém v Nativa Minimal ... kedy použiť akú, CSS tokeny, WCAG AA kontrakty, a špecifiká každého layoutu."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "theme-comparison"
published: true
---

Tri témy, tri štýly, jedno jadro. Starter, Magazine, Minimal, každá pre iného človeka.

V Nativa Minimal máme tri témy. Každá je navrhnutá pre iný účel.
V tomto článku porovnáme kedy použiť akú, aké CSS tokeny používajú,
a aké sú ich WCAG AA kontrakty.

Máme tri témy. Jedna pre tých, čo chcú jednoduchosť. Druhá pre tých, čo chcú magazín. Tretia pre tých, čo chcú svetlo. Ktorá je pre vás?

Každá téma má inú filozofiu. Starter je minimalistický. Magazine je bohatý. Minimal je svetlý. Ale keď si vyberiete zlú, celý web vyzerá, ako keby ste nasadili PDF na Instagram.

## Prehľad

| | Starter | Magazine | Minimal |
|---|---|---|---|
| **Štýl** | Jednoduchý, čistý | Plný šírky, hero grid | Light-first, blocky |
| **Entry** | `src/main.ts` | `src/magazine.ts` | `src/minimal.ts` |
| **Shell** | FrontendLayout | MagazineShell | MinimalShell |
| **CSS prefix** | `--st-*` | `--mag-*` | `--min-*` |
| **Font** | System stack | Serif (Georgia) | System stack |
| **CSS loading** | Inline (funguje bez buildu) | Deferred (media=„print“) | Deferred (media=„print“) |
| **Mobilné menu** | Hamburger | JS toggle | CSS checkbox |
| **Sidebar** | Nie | Áno (popular/tags) | Áno (popular/tags) |
| **Hero section** | Nie | Áno (2fr + 1fr grid) | Áno (centered single) |
| **TOC** | Nie | Áno (sidebar + mobile FAB) | Áno (sidebar + mobile FAB) |
| **Footer** | Jednoduchý | Multi-column | Centered |

## Starter ... predvolená téma

**Kedy použiť:** Jednoduché stránky, blogy, portfóliá.

**Charakteristika:**
- Čistý, minimalistický layout
- Žiadna sidebar
- Žiadny hero section
- Žiadny TOC
- Jednoduchý footer
- Minimálne CSS

**CSS tokeny:**
```css
--st-accent-strong: #f59e0b;  /* button bg, white text */
--st-accent-soft: #d97706;    /* tag/link text */
--st-accent-hover: #d97706;   /* button hover */
--st-error-ink: #dc2626;      /* error text */
```

**Použitie:**
```
/?style=starter
```

## Magazine ... plný šírky layout

**Kedy použiť:** Magazíny, spravodajské portály, veľké blogy.

**Charakteristika:**
- Plný šírky sticky header (64px)
- **Serif font** (Georgia) pre nadpisy
- Hero grid: **2fr + 1fr layout** (featured + 2 small cards)
- Sidebar s popular articles a tags
- Category sections s horizontal scroll
- TOC v sidebar (desktop) + mobile FAB panel
- Multi-column footer (brand + quick links + feeds)
- JS mobilné menu
- Search trigger → `/blog?q=`

**CSS tokeny:**
```css
--mag-accent: #f59e0b;        /* dekoratívny (ikony, bordery) */
--mag-accent-strong: #f59e0b; /* button bg, white text */
--mag-accent-soft: #d97706;   /* tag/link text */
--mag-accent-hover: #d97706;  /* button hover */
```

**Použitie:**
```
/?style=magazine
```

## Minimal ... light-first editorial

**Kedy použiť:** Čisté blogy, minimálne portfóliá, „menej je viac“.

**Charakteristika:**
- Light-first dizajn
- Blocky editorial štýl
- **System font stack** (žiadne serif)
- DB-driven navigácia (SettingsServices::menu)
- CSS-only checkbox mobilné menu
- **Count-aware grids**: `--1col` / `--2col` / `--3col`
- Category sections s „View all“ linkmi
- Sidebar widgets (popular articles + tags)
- TOC v sidebar (desktop) + mobile FAB panel
- Centered footer
- CTA blok na homepage

**CSS tokeny:**
```css
--min-accent: #f59e0b;        /* dekoratívny (ikony, bordery) */
--min-accent-strong: #f59e0b; /* button bg, white text */
--min-accent-soft: #d97706;   /* tag/link text */
--min-accent-hover: #d97706;  /* button hover */
```

**Použitie:**
```
/?style=minimal
```

## WCAG AA kontrakty

Všetky témy musia spĺňať WCAG AA (4.5:1 kontrast). `BuildCssAuditTest`
testuje tokeny v **oboch témach** (dark + light):

| Token | Účel | Dark hodnota | Light hodnota |
|---|---|---|---|
| `--st-accent-strong` | Button bg + white text | `#f59e0b` | `#d97706` |
| `--st-accent-soft` | Tag/link text na surface | `#fbbf24` | `#92400e` |
| `--st-error-ink` | Error banner text | `#dc2626` | `#dc2626` |
| `--mag-accent-strong` | Button bg + white text | `#b45309` | `#b45309` |
| `--mag-accent-soft` | Tag/link text na surface | `#fbbf24` | `#92400e` |
| `--min-accent-strong` | Button bg + white text | `#b45309` | `#92400e` |
| `--min-accent-soft` | Tag/link text na surface | `#fbbf24` | `#92400e` |

**Dekoratívne tokeny** (`--mag-accent`, `--min-accent`) nemajú AA
požiadavku ... slúžia na ikony a bordery, nie na text.

### Prečo amber nefunguje priamo?

`#d97706` (amber-600) má len **3.1:1** kontrast s bielym textom.
Preto používame tmavšie varianty:
- `#b45309` (amber-700) = **5.0:1** ... button bg
- `#92400e` (amber-800) = **7.1:1** ... hover, light theme
- `#fbbf24` (amber-400) = **4.6:1** ... link text na surface

## Adaptívne griddy (Minimal téma)

Minimal téma má špeciálnu vlastnosť: **count-aware grids**.

```css
.minimal-grid--1col { grid-template-columns: minmax(0, 1fr); }      /* 1 výsledok */
.minimal-grid--2col { grid-template-columns: repeat(2, minmax(0, 1fr)); }   /* 2 výsledky */
.minimal-grid--3col { grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); } /* 3+ */

/* Desktop: 3-col fixnute */
@media (min-width: 900px) { .minimal-grid--3col { grid-template-columns: repeat(3, 1fr); } }

/* Tablet: 2-col */
@media (max-width: 899px) and (min-width: 640px) { .minimal-grid--3col { grid-template-columns: repeat(2, 1fr); } }

/* Mobile: 2-col → 1-col */
@media (max-width: 640px) { .minimal-grid--2col { grid-template-columns: minmax(0, 1fr); } }
```

Tagy, kategórie a autor stránky s malým počtom článkov sa nezmenšujú
do 1/3 šírky ... automaticky sa prispôsobia.

## Prepínanie

**Tri spôsoby prepínania:**

1. **Admin panel** (`/mark/settings`) ... `site.theme` dropdown
2. **Query parameter** (`?style=magazine`) ... preview bez zmeny nastavení
3. **Env premenná** (`THEME=minimal`) ... vynúti tému pre celé nasadenie

**Prednosť:** query param > env > setting > starter (default)

## Kedy ktorú

| Scenár | Téma |
|---|---|
| Jednoduchý blog | Starter |
| Magazín s featured článkami | Magazine |
| Čistý, minimalistický blog | Minimal |
| Firemná stránka | Starter alebo Custom |
| Spravodajský portál | Magazine |
| Portfólio | Minimal |

- **Starter** ... jednoduchý, čistý, predvolený
- **Magazine** ... plný šírky, hero grid, sidebar
- **Minimal** ... light-first, blocky, adaptívne griddy
- Všetky spĺňajú WCAG AA (4.5:1)
- Prepínanie cez admin, query param alebo env
- Pridanie novej témy = 3 súbory (auto-discovery)

---

→ [Theme Architecture](/blog/theme-architecture): auto-discovery, registry a interface
→ [Build Your Theme](/blog/build-your-theme): návod krok za krokom
→ [Clean code pre deti](/blog/co-je-cisty-kod-pre-deti): ako písať kód, ktorý sa číta sám

## Zdroje

- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): bezpečnostné best practices
- [W3C, Web Standards](https://www.w3.org/standards/): web štandardy
