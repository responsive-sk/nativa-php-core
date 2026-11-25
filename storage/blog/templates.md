---
title: "Frontend ... spajza so surovinami a hotový produkt"
date: "2026-08-05"
version: "1.1.2"
tags: ["templates", "frontend", "vite", "architecture", "build"]
excerpt: "Čo je Frontend/, čo NIE je, a prečo dist build neobsahuje žiadny Frontend adresár ... dve vrstvy šablón v minimal starter kite."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "templates"
published: true
---

Ľudia počujú „templates“ a predstavia si jeden priečinok s HTML šablónami. V Nativa Minimal sú dve rôzne vrstvy, ktoré toto slovo pokrýva.

Keď pomýlite surovinu s hotovým jedlom, dostanete problémy. Vite build beží len na vývoji. Na produkcii sú len hotové assety. Ale ľudia hľadajú Frontend/ v diste a čudujú sa, že tam nie je.

## Dve vrstvy „templates“

Keď sa povie „templates“, ľudia si predstavia jeden adresár s HTML šablónami. V minimal starter kite sú **dve rôzne vrstvy**, ktoré toto slovo pokrývajú ... a obe je dobré rozlišovať:

1. **Frontend zdrojáky** ... `Frontend/packages/starter/` (CSS/TypeScript, ktoré sa buildujú cez Vite)
2. **PHP renderovacie triedy** ... HTML sa generuje priamo v PHP triedach (`src/Modules/.../Interfaces/HTTP/`)

Žiadny `Frontend/php/` adresár ako vo full CMS tu neexistuje ... zámerný minimalizmus.

## Vrstva 1: Frontend/ je build-time zdroj

```
Frontend/packages/starter/
├── src/
│   ├── styles.css        ← celé CSS stránky (~2 kB)
│   ├── main.ts           ← JS entry (defer, zero forced reflow)
│   └── container-query.ts
├── vite.config.ts        ← Vite build config
├── strip-at-layer.ts     ← Rollup plugin (@layer fallback pre Chrome 70+)
└── package.json
```

`Frontend/` **nikdy nebeží na produkte**. Je to kuchynský zdroj ... suroviny. Vite ho premení na hotové assety:

```
Frontend/packages/starter/src/*.{css,ts}
        ↓  pnpm run build (Vite)
   public/dist/starter/assets/
   ├── main-DJB0c1Pw.css
   └── main-FIzYJiD4.js
        ↓  build-dist.sh
   dist/public/dist/starter/assets/   ← JEDINE toto vidí produkcia
```

## Vrstva 2: PHP triedy renderujú HTML

Starter kit nemá samostatnú view vrstvu ... HTML šablóny sú inline v PHP triedach:

| Trieda | Účel |
|---|---|
| `FrontendLayout` | Hlavný shell ... `<head>`, inline CSS, nonce, footer |
| `HomepageAction` | Homepage ... hero (Cloudinary srcset), feature karty |
| `AuthTemplate` | Login/registrácia |
| `AdminLayout` + Cardboard akcie | Admin panel |

Tieto triedy žijú v `src/`, takže sa do distu kopírujú ako bežný PHP kód (`dist/src/`).

## Runtime na Frontend/ nezávisí

- `FrontendLayout` načíta CSS/JS cez **`AssetManager` z Vite manifestu** v `public/dist/starter/` ... nie z Frontend
- Built CSS sa **inline-uje priamo do `<head>`** (bundle je ~2 kB) ... render-blocking request zmizne z kritickej cesty, LCP sa zlepší, CLS ostáva 0
- Fallback: keď build nebežal (chýba manifest), layout degraduje na minimálny inline critical CSS ... stránka nikdy nie je neostylovaná

## Prečo dist nemá Frontend/

Distribúcia je **hotový produkt**, nie kuchyňa. Porovnanie:

| | Dev (`Frontend/`) | Produkcia (`dist/`) |
|---|---|---|
| Obsah | Suroviny: CSS/TS, Vite config, node_modules | Hotové assety: hashed `.css`/`.js` |
| Build | `make build` ... Vite + dist sync | `rsync dist/` na server |
| Dev procesy | Áno (Vite, testy, map generovanie) | **Žiadne** |

Na remote nebeží žiadny dev proces ... Vite build, `composer generate-all`, testy ani verify skripty tam nemajú čo robiť. Do distu ide len výsledok kuchynskej práce. Preto `dist/` nemá `Frontend/`, `node_modules`, `tests/`, `Makefile` ani `.ai-factory/` ... a je to správne.

## Prakticky

Zmenil si CSS alebo TS v `Frontend/packages/starter/`? Potom stačí:

```
make build    # 1. Vite build → public/dist/  +  dist sync
rsync dist/   # 2. nasadenie hotového produktu
```

Suroviny sa menia v kuchyni, na server ide hotové jedlo.

→ [Theme Architecture](/blog/theme-architecture) — auto-discovery, registry a interface
→ [Build Your Theme](/blog/build-your-theme) — návod krok za krokom
→ [Clean code pre deti](/blog/co-je-cisty-kod-pre-deti) — ako písať kód, ktorý sa číta sám

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — bezpečnostné best practices
- [W3C — Web Standards](https://www.w3.org/standards/) — web štandardy
