---
title: "Flow a ADR mindset za minimal starter kitom"
date: "2026-08-04"
version: "1.0.2"
tags: ["php", "architecture", "openspec", "adr", "workflow"]
excerpt: "OpenSpec-native workflow, zero-dependency runtime a ADR mindset za stavbou minimal PHP 8.5 starter kitu from scratch."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "flow-and-adr-mindset"
published: true
---

Keď staviate dom od základov, nepýtate sa „akú farbu má mať stena?“ — pýtate sa „kde bude nosná stena, kde okno a kde dvere?“. Architektonické rozhodnutia prichádzajú pred farbou.

S webom je to rovnaké. Pred prvým riadkom kódu sme urobili päť rozhodnutí, ktoré definovali celý projekt. Každé má svoj ADR — Architecture Decision Record.

## Päť rozhodnutí

| # | Rozhodnutie | Voľba | Prečo |
|---|-------------|-------|-------|
| ADR-1 | From-scratch vs fork | **From scratch** | Žiadna legacy záťaž, auditovateľný riadok po riadku |
| ADR-2 | Jedna DB vs per-module | **Per-module SQLite** | Izolácia modelu, prirodzené pridávanie modulov |
| ADR-3 | Framework vs zero-dep | **Zero-dependency** | Produkcia bez vendor/, plná kontrola |
| ADR-4 | Routing | **`#[Route]` atribúty** | Sken akcií, route-name → URL generovanie |
| ADR-5 | Frontend baseline | **Chrome 70 + fallbacky** | Rovnaká stratégia ako cascade téma |

## From scratch — prečo nie fork

Keď forknete existujúci projekt, zdedíte všetky jeho rozhodnutia — dobré aj zlé. Nevidíte, prečo boli urobené. Nevidíte, kde sú kompromisy.

Keď staviate from scratch, každý riadok je vedomé rozhodnutie. Auditujete každú líniu. Nič nie je „tak to bolo predtým“.

## Per-module SQLite — izolácia ako filozofia

Jedna veľká databáza = jeden veľký problém. Keď zmeníte schému v users module, môžete rozbiť blog module.

Každý modul má svoj vlastný `.db` súbor. Žiadne cross-database JOINs. Žiadne zdieľané tabuľky. Izolácia, ktorá sa nedá porušiť.

## Zero-dependency — produkcia bez vendor/

V produkcii nie je Composer. Žiadny `vendor/` adresár. Len PHP triedy a vlastný PSR-4 autoloader v `init.php`.

Prečo? Pretože každá závislosť je zmluva. A zmluva s niekým, koho nepoznáte, môže byť nebezpečná.

## Security by default

Bezpečnosť nie je feature — je to základ:

- Strict CSP s per-request nonce
- DB-backed rate limiting
- CSRF na všetkých state-changing POSToch
- Admin role gate na `/mark/*`

Stavanie from scratch nie je o tom, že všetko robíte sami. Je o tom, že **každé rozhodnutie je vedomé**. ADR je záznam toho, prečo ste sa rozhodli tak, ako ste sa rozhodli. O pol roka sa vrátite a viete, prečo.

→ [OpenSpec pre deti](/blog/co-je-openspec-pre-deti) — prečo sa zmeny nepíšu priamo do spec
→ [Anatómia change](/blog/anatomia-change-pre-deti) — päť súborov, päť otázok
→ [Clean code pre deti](/blog/co-je-cisty-kod-pre-deti) — ako písať kód, ktorý sa číta sám

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — bezpečnostné best practices
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
- [W3C — Web Standards](https://www.w3.org/standards/) — web štandardy
