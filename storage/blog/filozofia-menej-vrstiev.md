---
title: "Menej vrstiev ... záver série o tom, prečo je PHP rýchle"
series: "Prečo je PHP rýchle"
series-part: "3"
date: "2026-08-19"
version: "1.0.1"
tags: ["php", "philosophy", "performance"]
excerpt: "Záverečná časť série: prečo rýchlosť PHP pochádza z C a z odstránenia vrstiev, ako to vyzerá v našom stacku a aké pravidlo si z tridsaťročnej histórie jazyka odnášame."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "philosophy-fewer-layers"
published: true
---

Rýchlosť PHP nepochádza z „lepšieho frameworku“. Pochádza z C a z toho, že medzi kódom a dátami nestojí nič navyše.

Toto je posledná časť série. Prešli sme Zend engine, históriu PHP,
OPcache, JIT aj C rozšírenia. Teraz si sadneme a povieme si, čo z toho
všetkého zostáva ... pretože celá séria mala jedinú pointu, a tá pointa
je zároveň filozofiou nášho stacku.

Každá vrstva navyše spomaľuje. Cache medzi kódom a návštevníkom. Framework medzi kódom a PHP. Node.js medzi kódom a serverom. Všetko je réžia, ktorá nikdy nezmizne.

| Časť | Záver |
|---|---|
| 1. Zend engine | PHP je kompilátor + virtuálny stroj v C; opcody sú prekompilovaný kód |
| 2. História | Každý rýchlostný skok PHP bol nový C kód ... nie nová vrstva |
| 3. OPcache + JIT | Prekompilácia na úrovni jazyka; JIT kompiluje až do strojového kódu |
| 4. C rozšírenia | Built-in funkcie sú C knižnice; SQLite znamená priame C volania k dátam |

Všetky štyri časti dokazujú z iného uhla jednu vetu:

> **Rýchlosť PHP pochádza z C a z odstránenia vrstiev.**

Nie z „lepšieho frameworku“, nie z ďalšej cache, nie z väčšieho
servera. Z C kódu, ktorý robí prácu, a z toho, že medzi ním a tvojim
kódom nie je nič, čo by ju spomaľovalo.

## Ako to vyzerá v našom stacku

Táto séria nebola teória ... náš stack je praktická ukážka presne tých
pravidiel:

- **Zero-dependency runtime** ... medzi naším kódom a C funkciami nie je
  ani jedna interpretovaná vrstva. Žiadny framework, žiadny vendor,
  žiadne „PHP v PHP“.
- **Native PHP templates** ... žiadny Twig, žiadny Blade, žiadna
  kompilácia šablón. Kód je už PHP; nie je čo prekladať.
- **Per-module SQLite** ... každý dotaz je priame C volanie do C
  knižnice. Žiadny DB server, žiadna sieťová latencia.
- **Cache experiment** ... keď cache spomaľovala LCP, vyhodili sme ju
  a dostali sme 100/100. Vrstva navyše nepridávala hodnotu; odoberala
  ju (viac v článku [o filozofii nášho stacku](/blog/filozofia-super-rychleho-php)).
- **OPcache + preload** ... prekompilácia na úrovni jazyka: opcody
  v pamäti, kľúčové triedy načítané pri štarte. Warm start bez
  akýchkoľvek vrstiev.

## Pravidlo do budúcna

Z tridsaťročnej histórie PHP si odnášame jedno rozhodovacie pravidlo,
ktoré aplikujeme na každú novú vec:

1. **Potrebuješ rýchlosť?** Presuň prácu do C ... alebo zisti, že ju C
   už robí. Nepridávaj vrstvu.
2. **Chceš cache?** Rozlišuj: OPcache/JIT kompilujú **pod** kódom
   (áno), response cache je vrstva **medzi** kódom a návštevníkom
   (pozor).
3. **Chceš edge/CDN?** V poriadku ... je to vonkajšia vrstva, nie medzi
   kódom a dátami. Statický HTML z blízka + PHP jadro bez závislostí.

Menej vrstiev nie je minimalizmus pre minimalizmus. Je to inžinierske
pravidlo, ktoré PHP dokazuje už tridsať rokov: zakaždým, keď PHP
potrebovalo byť rýchlejšie, presunulo prácu do C a zjednodušilo to,
čo medzi C a vývojárom stálo. My robíme to isté ... od Zend enginu
v roku 1997 až po náš stack dnes.

- Celá séria v jednej vete: **rýchlosť pochádza z C a z odstránenia
  vrstiev**
- Engine, história, OPcache/JIT aj C rozšírenia to dokazujú z rôznych
  uhlov
- Náš stack: zero-dep runtime, native templates, per-module SQLite,
  žiadna cache navyše ... všetko podľa toho istého pravidla
- Rozhodovacie pravidlo: presúvaj prácu do C, nepridávaj vrstvy;
  OPcache/JIT sú pod kódom, response cache je medzi kódom
  a návštevníkom
- Menej vrstiev = viac C = rýchlejšie. To je celá filozofia.

→ [Super rýchle PHP](/blog/filozofia-super-rychleho-php) — PHP C chunks filozofia
→ [Performance pre deti](/blog/performance-pre-deti) — LCP a rýchlosť
→ [Cache wrappers](/blog/cache-wrappers-dobry-slucha-zly-pan) — kedy cache pomáha

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — bezpečnostné best practices
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
- [W3C — Web Standards](https://www.w3.org/standards/) — web štandardy
