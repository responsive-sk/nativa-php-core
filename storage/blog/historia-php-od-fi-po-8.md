---
title: "História PHP ... od nástroja jedného človeka po Zend Engine 4"
series: "Prečo je PHP rýchle"
series-part: "2"
date: "2026-08-16"
version: "1.0.1"
tags: ["php", "history", "zend", "engine"]
excerpt: "Časová os PHP od PHP/FI (1994) cez zrod Zend engine až po dnešné PHP 8.5 ... a prečo každý veľký rýchlostný skok znamenal nový engine napísaný v C. Druhá časť série o PHP C chunks."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "php-history-from-fi-to-8"
published: true
---

Rasmus Lerdorf neplánoval napísať jazyk. V roku 1994 chcel len jednu vec — počítať, koľko ľudí číta jeho osobnú stránku.

O tri roky neskôr sa dvaja študenti — Zeev Suraski a Andi Gutmans — pokúsili použiť PHP na univerzitný projekt. Narazili na limity pôvodného parsera. Namiesto sťažovania sa ho **prepísali**. Výsledok: PHP 3 a úplne nový engine, ktorý pomenovali Zend — z prvých písmen mien **Ze**ev + **A**nd**i**.

PHP bolo pomalé. Nie preto, že by bolo zlé — ale preto, že medzi kódom a dátami stálo priveľa vrstiev. Každá verzia to riešila rovnako: prepísať engine v C.

## Časová os

| Rok | Verzia | Engine | Čo priniesla |
|---|---|---|---|
| 1994 | PHP/FI | ... | Rasmus Lerdorf píše nástroj pre svoju stránku |
| 1997 | PHP 3 | Zend Engine 1 | Zeev a Andi prepisujú parser, zrod Zend engine |
| 2000 | PHP 4 | Zend Engine 1 | Zrelší runtime, session support |
| 2004 | PHP 5 | Zend Engine 2 | Nový object model, exceptions, PDO |
| 2015 | PHP 7 | Zend Engine 3 | Dvojnásobná rýchlosť, polovičná pamäť (PHPNG) |
| 2020 | PHP 8.0 | Zend Engine 4 | JIT, union types, match |
| 2025 | PHP 8.5 | Zend Engine 4 | Aktuálna verzia |

## Každý skok = nový C kód

Pozrite sa na vzorec v tabuľke:

- **PHP 5** — nový object model v C, lebo starý nemohol uniesť objekty, ktoré jazyk potreboval.
- **PHP 7** — projekt PHPNG prepísal datové štruktúry a exekučnú slučku. Dvojnásobná rýchlosť, polovičná pamäť. Žiadna zmena syntaxe, žiadna nová vrstva — nový C kód pod kapotou.
- **PHP 8.0** — pribudol JIT, ktorý horúce cesty kompiluje až do natívneho strojového kódu. Zase: C.

PHP sa nikdy nezrýchlilo pridaním abstrakcie. Zrýchlilo tým, že sa viac práce presunulo do C.

## Čo v histórii nenájdeme

PHP 6 nikdy nevyšlo. Malo priniesť natívnu podporu Unicode, projekt sa nedokončil a číslo 6 sa preskočilo. Namiesto neho prišlo PHP 7 s PHPNG.

Keď sa veľký nápad nepodarí, PHP nespomalí. Prepíše engine.

## Prečo na tom záleží

História PHP nie je len časová os verzií. Je to príbeh o jednom opakujúcom sa vzore: **zakaždým, keď PHP potrebovalo byť rýchlejšie, niekto prepísal engine v C.** Žiadna vrstva navyše. Žiadny middleware. Nový C kód.

Vzorec histórie: rýchlosť pochádza z C a z odstránenia vrstiev. Presne tá filozofia, na ktorej stojí náš stack.

→ [Zend engine a opcody](/blog/zend-engine-a-opcody) — ako PHP naozaj beží
→ [OPcache a JIT](/blog/opcache-a-jit-prekompilacia) — prekompilácia na úrovni jazyka
→ [C rozšírenia](/blog/c-rozsirenia-php-api) — ako sa k Zend enginu pripája C kód

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — bezpečnostné best practices
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
- [W3C — Web Standards](https://www.w3.org/standards/) — web štandardy
