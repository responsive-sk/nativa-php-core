# Blog Images Guide

## Formát obrázkov

Systém používa obrázky s rozmermi **720×360** (pomer 2:1) vo formáte WebP.
Cloudinary URL formát:
```
https://res.cloudinary.com/epithemic/image/upload/c_fill,w_720,h_360,f_webp,q_auto/v{VERZIA}/blog/{NAZOV}.webp
```

## Ako pridať image do front matter

Pridaj pole `image` do YAML front matter akéhokoľvek článku:

```markdown
---
title: "Názov článku"
date: 2026-08-10
tags: [php, types]
excerpt: "Popis..."
author: Nativa Dev
author_email: admin@example.test
category: Architecture
image: "https://res.cloudinary.com/epithemic/image/upload/c_fill,w_720,h_360,f_webp,q_auto/v123456/blog/my-image.webp"
---
```

**Ak `image` nie je definované**, systém automaticky použije jeden z poolu 4 Cloudinary obrázkov.

---

## Mapping článkov na obrázky

### PHP Types séria (6 článkov)

| Článok | Téma | Odporúčaný obrázok |
|--------|------|-------------------|
| `php-typy-pre-deti` | Úvod do typov | Dark mode IDE s PHP kódom, alebo abstraktný "type system" vizuál |
| `scalar-casty-pre-deti` | Scalar casts | Kalkulačka/prevodník, alebo explicitný cast v kóde |
| `typuj-pred-iteraciou` | Normalizácia na hranici | Flux diagram alebo pipeline vizuál |
| `array-list-dto-pre-deti` | Polia, DTO, mixed | Štruktúrované dáta, tree/grid štruktúra |
| `union-nullsafe-enumy-pre-deti` | Union types, enumy | Rozvetvenie/rozhodovací strom |
| `chunky-rule-pre-deti` | 60-line rule | Refaktoring, delenie kódu, puzzle |

### OpenSpec séria (4 články)

| Článok | Téma | Odporúčaný obrázok |
|--------|------|-------------------|
| `co-je-openspec-pre-deti` | Úvod do OpenSpec | Dokumentácia, specifikácia |
| `anatomia-change-pre-deti` | Anatómia zmeny | Workflow diagram, pipeline |
| `zivotny-cyklus-change-pre-deti` | Životný cyklus | Lifecycle kruhový diagram |
| `spec-ako-zdroj-pravdy-pre-deti` | Spec ako zdroj pravdy | Centralizovaný zdroj, single source |
| `openspec-a-ai-factory-pre-deti` | OpenSpec + AI Factory | AI/ML vizuál, robot/agent |

### Ostatné články

| Článok | Téma | Odporúčaný obrázok |
|--------|------|-------------------|
| `hello-world` | Úvod do projektu | Welcome, bootstrap, starting point |
| `flow-a-adr-starter-kitu` | ADR mindset | Rozhodovanie, architektúra |
| `templates` | Build systém | Build pipeline, Vite, kompilácia |

---

## Zdroje free obrázkov

### Unsplash (najlepšie pre code)
- https://unsplash.com/s/photos/php-code
- https://unsplash.com/s/photos/dark-mode-ide
- https://unsplash.com/s/photos/programming-screen
- https://unsplash.com/s/photos/software-architecture
- https://unsplash.com/s/photos/clean-code

### Pexels
- https://www.pexels.com/search/php/
- https://www.pexels.com/search/programming/
- https://www.pexels.com/search/software-development/

### Pixabay (dobré pre ilustrácie)
- https://pixabay.com/images/search/php/
- https://pixabay.com/images/search/programming/
- https://pixabay.com/images/search/code/

### Špeciálne zdroje
- **StockSnap.io** — kvalitné workspace obrázky
- **Pikwizard** — ľudia pri práci s kódom
- **unDraw** — open-source ilustrácie (https://undraw.co/illustrations)

---

## Ako nahrať na Cloudinary

1. Prihlás sa na https://cloudinary.com
2. Uploadni obrázok do priečinka `blog`
3. Použi transformáciu: `c_fill,w_720,h_360,f_webp,q_auto`
4. Skopíruj celú URL a pridaj ju do front matter

### Príklad Cloudinary URL
```
https://res.cloudinary.com/epithemic/image/upload/c_fill,w_720,h_360,f_webp,q_auto/v1782250908/blog/moj-novy-obrazok.webp
```

---

## Alternatíva: Screenshot kódu

Ak chceš screenshoty priamo z IDE, môžeš použiť:
- **Carbon** (https://carbon.now.sh) — krásne screenshoty kódu
- **Ray.so** (https://ray.so) — moderné screenshoty
- **Prism.js** online — syntax highlighting

Potom screenshot nahraj na Cloudinary a použij ako `image`.

---

## Testovanie

Po pridaní image do front matter:
1. Spusti `composer test` — všetky testy by mali prejsť
2. Otvor `/blog` v prehliadači — obrázok by sa mal zobraziť na kartičke
3. Otvor konkrétny článok — obrázok sa zobrazí v hlavičke (ak je pridaný do `BlogArticleAction`)
