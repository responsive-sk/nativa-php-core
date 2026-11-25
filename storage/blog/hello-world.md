---
title: "Hello World ... Minimal Starter Kit"
date: "2026-08-04"
version: "1.0.2"
tags: ["php"]
excerpt: "Prvý príspevok v novom minimal starter kitu. Čo je cieľom projektu a ako je postavený."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Starter Kit"
views: "0"
translation: "hello-world"
published: true
---

Predstavte si, že chcete postaviť web. Nie WordPress. Nie Laravel. Niečo, čo beží bez jediného Composer balíka v produkcii. Len PHP 8.5 a myšlienka: „Čo keby sme potrebovali len to, čo už máme?“

## Prázdny index.php

Prvý deň: `public/index.php`. Žiadny framework, žiadne závislosti. Len prázdny súbor a otázka: čo potrebujeme?

Odpoveď bola prekvapivo krátka:

- **Router**: aby request našiel svoju akciu
- **Middleware**: aby sme mohli pridať bezpečnosť bez zásahu do logiky
- **Databáza**: aby sme mohli niečo uložiť
- **Auth**: aby sme vedeli, kto je prihlásený

Žiadne ORM. Žiadny container. Žiadne magic methods. Len PHP triedy, ktoré si navzájom volajú metódy.

## Ako funguje zero-dependency

Keď odstránite Composer z produkcie, zostane vám len to, čo máte v `src/`. Autoloader? Vlastný, 15 riadkov v `init.php`. Routing? Atribúty `#[Route]` na akciách, žiadna generovaná mapa. Databáza? SQLite, jedna súborová databáza na modul.

```php
// Takto vyzerá route v Nativa
#[Route(path: '/blog', method: 'GET', name: 'blog.index')]
final class BlogIndexAction
{
    public function handle(Request $request): Response
    {
        // jednoduchá, čistá, čitateľná akcia
    }
}
```

Žiadna registrácia. Žiadna konfigurácia. Router nájde všetko cez `#[Route]` atribúty pri spustení.

## Ako fungujú články

Blog články žijú v `storage/blog/` ako `.md` súbory s YAML front matter:

```markdown
---
title: "Názov článku"
date: 2026-08-04
tags: [php, architecture]
excerpt: "Krátky popis"
author: Nativa Dev
category: Architecture
---
```

Telo článku je bežný markdown. Články sa indexujú dynamicky z adresára, žiadna ručná registrácia. Napíšete súbor, je na svete.

Voliteľné `author_email` prepojí autora s reálnym users účtom: ak email sedí na aktívneho používateľa (case-insensitive), index aj článok zobrazia jeho `name` z users databázy (rename v `/mark/users` sa prejaví automaticky) ... s avatarom a odkazom na `/authors/{id}`.

Avatar sa nastavuje v `/mark/users` (upload obrázku); bez avatara sa renderuje placeholder s iniciálkou. Avatary sú self-hostované v `storage/uploads/` a servírované cez `/storage/uploads/{path}` (len whitelistované typy, žiadne traversal).

## Prečo zero-dependency

Pretože každá závislosť je zmluva. Zmluva s niekým, kto môže zmeniť pravidlá. Keď používate Composer balík v produkcii, dôverujete niekomu, koho nepoznáte. Keď píšete vlastný kód, dôverujete sebe.

Zero-dependency nie je o tom, že nič nepoužívate. Je o tom, že viete presne, prečo používate to, čo používate.

Backward compatibility nie je len technický koncept, je to rešpekt k ľuďom, ktorí ešte neprišli. K starým zariadeniam, k starým prehliadačom, k ľuďom, ktorí čítajú prvýkrát.

→ [Clean code pre deti](/blog/co-je-cisty-kod-pre-deti): ako písať kód, ktorý sa číta sám
→ [OpenSpec pre deti](/blog/co-je-openspec-pre-deti): prečo sa zmeny nepíšu priamo do spec
→ [Performance pre deti](/blog/performance-pre-deti): prečo sa obrázky nesťahujú zbytočne veľké

## Zdroje

- [PHP.net, PHP: Hypertext Preprocessor](https://www.php.net/): oficiálna PHP stránka
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP Framework Interop Group štandardy
- [SQLite.org, About](https://www.sqlite.org/about.html): SQLite embedded database
- [Vite, Why Vite](https://vitejs.dev/guide/why.html): prečo Vite namiesto Webpack
- [Nativa Minimal, Getting Started](/docs/getting-started): oficiálny návod na spustenie
