---
title: "OPcache a JIT ... prekompilácia na úrovni jazyka"
date: "2026-08-17"
version: "1.2.1"
tags: ["php", "opcache", "jit", "performance"]
excerpt: "Ako OPcache drží skompilované opcody v pamäti, čo robí opcache.preload a kedy JIT preloží PHP až do natívneho strojového kódu ... tretia časť série o tom, prečo je PHP prekompilované ako Astro."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "opcache-and-jit-precompilation"
published: true
---

V prvej časti sme videli reťazec `zdroják → tokeny → AST → opcody →
exekúcia` a v druhej, ako sa Zend engine vyvíjal v C. Teraz si ukážeme
dve veci, ktoré z PHP robia „prekompilovaný“ jazyk: **OPcache**
a **JIT**. A hneď na úvod jedna dôležitá vec: toto je cache, ktorá nám
nevadí. Nie je to vrstva navyše ... je to kompilátor.

Keď PHP skompiluje skript do opcodov, môže ich držať v pamäti. Nabudúce sa nemusí opäť kompilovať. Toto je OPcache, medzipamäť, ktorá mení PHP z interpretu na takmer kompilovaný jazyk.

Bez OPcache PHP interpretuje každý request odznova. Načíta súbor, parsuje ho, skompiluje do opcodov, vykoná. Desať requestov = desaťkrát rovnaká práca. Na shared hostingu s 128 MB RAM je to smrť.

## OPcache ... opcody v pamäti

Bez OPcache PHP urobí pri **každom** requeste celý reťazec od lexingu po
kompiláciu: prečíta zdroják, sparsuje ho a vyrobí opcody. Je to ako keby
si Astro build spúšťal pri každom návštevníkovi.

OPcache to zmení. Pri prvom requeste skompiluje skript do opcodov
a uloží ich do **zdieľanej pamäte**. Pri druhom requeste už len povie:
„opcody pre súbor mám“ a Zend VM ich rovno vykonáva. Lexing,
parsing a kompilácia sa preskočia.

Presne to isté robí Astro, keď pri build-e predrenderuje stránky do
statického HTML: práca sa spraví raz a potom sa len doručuje. OPcache je
**prekompilácia na úrovni jazyka** ... a dnes je štandardne zapnutá
(v PHP 5.5+ je súčasťou jadra).

## opcache.preload ... warm start

OPcache rieši opcody po prvom requeste. Ale čo ten prvý? Keď PHP
proces naštartuje, ešte nič v pamäti nemá ... každá trieda sa musí
načítať a skompilovať pri prvom použití.

Tu prichádza **opcache.preload**. Povieš PHP, ktoré súbory má načítať
a skompilovať **pri štarte procesu**, nie pri prvom requeste:

```ini
opcache.preload=/cesta/k/preload.php
```

Súbor `preload.php` načíta tvoje kľúčové triedy (napríklad celý náš
`src/`) ešte pred tým, než príde prvý request. Výsledok je presne to,
čo sme si v článku o filozofii stacku nazvali **warm start**: prvý
request neplatí žiadne oneskorenie za kompiláciu, lebo všetko už je
v pamäti.

## JIT ... až do strojového kódu

OPcache končí pri opcodoch ... tie Zend VM interpretuje. **JIT** (Just
In Time, PHP 8.0+) ide ďalej: sleduje, ktoré časti kódu sa opakovane
vykonávajú, a tie **kompiluje do natívneho strojového kódu**. Potom
ich procesor vykonáva priamo, bez interpreteru.

PHP JIT je tzv. **tracing JIT** ... nesnaží sa skompilovať všetko, ale
sleduje „horúce“ slučky a kompiluje práve tie. Kompilácia beží cez
dynasm priamo v pamäti, takže aj samotný JIT je C kód robiaci strojový
kód.

Dôležitá nuansa: JIT nie je automatické zrýchlenie pre každý web.
Kompilácia má réžiu, takže pre content-heavy weby (blog, dokumentácia)
je interpretácia opcodov + OPcache často rýchlejšia. JIT žiari tam,
kde je veľa výpočtov ... matematika, spracovanie dát, dlhé slučky. Náš
stack je presne ten prvý prípad: nepotrebujeme ďalšiu vrstvu, ktorá by
sa snažila urýchliť to, čo už beží ako C volania.

## Prečo nám táto cache nevadí

V článku o filozofii sme vyhodili cache, ktorá spomaľovala LCP. Nebol
to rozpor ... to bola **HTTP/response cache**, ďalšia vrstva medzi
serverom a návštevníkom. OPcache a JIT sú iná vec: sú to vrstvy
**pod** naším kódom, ktoré kompilujú, nie medzi ním a používateľom.
Jedna pridáva oneskorenie, druhá ho odstraňuje. To je presne rozdiel
medzi „pridávať vrstvy“ a „presúvať prácu do C“.

- **OPcache** drží skompilované opcody v zdieľanej pamäti ... requesty
  2..N preskočia lexing, parsing aj kompiláciu
- Je to „prekompilácia“ v Astro zmysle, len na úrovni jazyka
- **opcache.preload** načíta kľúčové triedy pri štarte procesu ...
  žiadny cold start, žiadne oneskorenie prvého requestu
- **JIT (PHP 8.0+)** kompiluje horúce slučky až do natívneho
  strojového kódu (tracing JIT, dynasm)
- JIT nie je pre každý web ... pre content-heavy weby je interpretácia
  + OPcache zvyčajne rýchlejšia
- OPcache/JIT sú cache **pod** kódom, nie medzi kódom a návštevníkom ...
  kompilujú, nepridávajú vrstvu

V štvrtej časti série sa pozrieme na C rozšírenia ... ako sa k Zend
enginu pripája C kód a prečo je SQLite v C najrýchlejšia cesta k dátam.


→ [Zend engine a opcody](/blog/zend-engine-a-opcody): ako PHP naozaj beží
→ [C rozšírenia](/blog/c-rozsirenia-php-api): ako sa k Zend enginu pripája C kód
→ [História PHP](/blog/historia-php-od-fi-po-8): od nástroja jedného človeka po Zend Engine 4

## Zdroje

- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
- [Web.dev, Performance](https://web.dev/performance): Core Web Vitals
- [Lighthouse Documentation](https://developer.chrome.com/docs/lighthouse/): Lighthouse nástroj
