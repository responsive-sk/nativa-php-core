---
title: "Frontend ... a pantry of ingredients and a finished product"
date: "2026-08-05"
version: "1.1.0"
tags: ["templates", "frontend", "vite", "architecture", "build"]
excerpt: "What Frontend/ is, what it ISN'T, and why the dist build contains no Frontend directory ... two layers of templates in the minimal starter kit."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "templates"
published: true
---

## Two layers of "templates"

When people hear "templates", they imagine one directory with HTML
templates. In the minimal starter kit there are **two different layers**
that this word covers ... and it's good to distinguish them:

1. **Frontend sources** ... `Frontend/packages/starter/` (CSS/TypeScript,
   which are built via Vite)
2. **PHP rendering classes** ... HTML is generated directly in PHP classes
   (`src/Modules/.../Interfaces/HTTP/`)

There is no `Frontend/php/` directory as in the full CMS ... that's
intentional minimalism.

## Layer 1: Frontend/ is a build-time source

```
Frontend/packages/starter/
├── src/
│   ├── styles.css        ← the whole page CSS (~2 kB)
│   ├── main.ts           ← JS entry (defer, zero forced reflow)
│   └── container-query.ts
├── vite.config.ts        ← Vite build config
├── strip-at-layer.ts     ← Rollup plugin (@layer fallback for Chrome 70+)
└── package.json
```

`Frontend/` **never runs in production**. It's a kitchen source ... the raw
ingredients. Vite turns it into finished assets:

```
Frontend/packages/starter/src/*.{css,ts}
        ↓  pnpm run build (Vite)
   public/dist/starter/assets/
   ├── main-DJB0c1Pw.css
   └── main-FIzYJiD4.js
        ↓  build-dist.sh
   dist/public/dist/starter/assets/   ← production sees ONLY this
```

## Layer 2: PHP classes render the HTML

The starter kit has no separate view layer ... HTML templates are inline in
PHP classes:

| Class | Purpose |
|---|---|
| `FrontendLayout` | Main shell ... `<head>`, inline CSS, nonce, footer |
| `HomepageAction` | Homepage ... hero (Cloudinary srcset), feature cards |
| `AuthTemplate` | Login/registration |
| `AdminLayout` + Cardboard actions | Admin panel |

These classes live in `src/`, so they're copied into dist as regular PHP
code (`dist/src/`).

## The runtime doesn't depend on Frontend/

- `FrontendLayout` loads CSS/JS via the **`AssetManager` from the Vite
  manifest** in `public/dist/starter/` ... not from Frontend
- The built CSS is **inlined directly into `<head>`** (the bundle is ~2 kB)
  ... the render-blocking request disappears from the critical path, LCP
  improves, CLS stays 0
- Fallback: when the build hasn't run (manifest missing), the layout
  degrades to minimal inline critical CSS ... the page is never unstyled

## Why dist has no Frontend/

The distribution is a **finished product**, not a kitchen. A comparison:

| | Dev (`Frontend/`) | Production (`dist/`) |
|---|---|---|
| Content | Ingredients: CSS/TS, Vite config, node_modules | Finished assets: hashed `.css`/`.js` |
| Build | `make build` ... Vite + dist sync | `rsync dist/` to the server |
| Dev processes | Yes (Vite, tests, map generation) | **None** |

No dev process runs on the remote ... Vite build, `composer generate-all`,
tests or verify scripts have nothing to do there. Only the result of the
kitchen work goes into dist. That's why `dist/` has no `Frontend/`,
`node_modules`, `tests/`, `Makefile` or `.ai-factory/` ... and that's
correct.

## In practice

Changed some CSS or TS in `Frontend/packages/starter/`? Then just:

```
make build    # 1. Vite build → public/dist/  +  dist sync
rsync dist/   # 2. deploy the finished product
```

Ingredients change in the kitchen, the finished meal goes to the server.

## Sources

- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP standards
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [W3C, Web Standards](https://www.w3.org/standards/): web standards
