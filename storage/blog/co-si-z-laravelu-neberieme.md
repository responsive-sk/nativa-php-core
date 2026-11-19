---
title: "Čo si z Laravelu neberieme: kontajner, Eloquent a Artisan"
date: "2026-11-19"
tags: ["laravel", "php", "nativa", "architektúra", "discovery"]
excerpt: "Laravel je prvá odpoveď na otázku, v čom projekt postaviť, a často správna. Tento strom si z neho berie jednu vec a tri nie: kontajner s fasádami, Eloquent a Artisan. Článok ukazuje, čím ich nahradil a čo ho to stojí."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architektúra"
published: true
---

Laravel je prvá odpoveď na otázku, v čom projekt postaviť.
Je to dobrá odpoveď:
framework pozná trh a človeka na údržbu nájde každý.
Tento strom odpovedá inak.
Nie preto, že by Laravel bol zlý,
ale preto, že odstrániteľnosť je tu požiadavka a nie vlastnosť.

Keď klientov tím Laravel pozná a aplikácia má ostať v jeho rukách,
je Laravel správna voľba.
Jadro bez frameworku by tam bolo drahšie na odovzdanie.
Rozdiel začína tam, kde projekt preberáme my
alebo kde má vydržať dlhšie než dodávateľ.

Tri veci si z Laravelu neberieme.
Jednu áno.
Všetky štyri sú rozhodnutie, nie náboženstvo.

---

## Časť I > Kontajner a fasády

Laravel rieši závislosti kontajnerom a fasádami.
`app(Foo::class)` vráti inštanciu,
`Foo::bar()` ju zavolá staticky
a test ju nahradí mockom.
Tento strom má namiesto toho registre, ktoré plní discovery.

`HomepageSurfaceRegistry` nájde súbor `*HomepageSurface.php`
s atribútom `#[AsHomepageSurface]` v inštalovanom module.
Ak modul nie je nainštalovaný, landing vykreslí jadro.
Cena takej voľby je viditeľná hneď:
nie je čo zavolať staticky.
Každá závislosť je parameter alebo konštruktor, takže sa nedá obísť.

Prepojenie dvoch modulov, ktoré discovery nevidí,
drží `src/composition.php` pod `class_exists` guardom.
Jadro tak ostáva bez importu modulu
a modul bez vedomosti o druhom.

## Časť II > Eloquent

Eloquent mapuje tabuľku na objekt a dotaz na metódu.
Pohodlné je to dovtedy, kým dotaz potrebuje index, ktorý objekt nevidí.
Tento strom drží SQLite, migrácie a `Statement` v module, ktorý dáta vlastní.
Mapovanie je kód, nie konvencia.
Dôsledok je viac riadkov.
Výhoda je rovnaká.
Dotaz je v pull requeste celý, aj s tým, čo ide do `WHERE`.

## Časť III > Artisan

Artisan je konzolová vrstva frameworku:
príkaz nájde aplikáciu a aplikácia nájde kontajner.
`bin/cms` v tomto strome je argv parser bez závislostí.
Beží aj v strome, kde `vendor/` neexistuje,
lebo modulový nástroj sa nesmie oprieť o balíček, ktorý na serveri nie je.
Príkazy modulov sú preto obyčajné triedy v `Interfaces/CLI/Command`
a nie `Symfony\Console` príkazy.
Neznámu voľbu `bin/cms` odmietne skôr, než niečo spraví.
To je celý rozdiel.
Menej pohodlia a menej miest, kde sa dá ticho zabudnúť.

## Časť IV > Čo si berieme

To, čo si z Laravelu berieme, je myšlienka objavovania.
Trieda povie o sebe, čím je:
`#[AsHomepageSurface]`
`#[AsSitemapContributor]`
aj `#[Route]`.
Registre ich prečítajú raz.
Modul tak pribudne skopírovaním a zmizne odstránením, bez zoznamu na úpravu.
Atribút, ktorý nikto neprečíta, je komentár.
Názov súboru je súčasť kontraktu, takže premenovanie je zmena rozhrania.

## Časť V > Ako sa rozhodnúť

Rozhodnutie sa dá zúžiť na tri otázky:

- Kto to bude o päť rokov prevádzkovať?
- Kto to bude o päť rokov meniť?
- Ako rýchlo sa má dať dodávateľ vymeniť?

Keď aplikáciu pozná tím klienta, Laravel je lacnejšia údržba.
Keď ju poznáme len my, jadro bez frameworku je jednoduchšie odovzdanie.
Nie je to o tom, čo je modernejšie.
Je to o tom, kto zostane pri kóde, keď my odídeme.
