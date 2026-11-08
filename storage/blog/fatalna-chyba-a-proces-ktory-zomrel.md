---
title: "Fatálna chyba, ktorú PHP vypísalo, a proces, ktorý potom zomrel"
date: "2026-11-08"
tags: ["php", "xdebug", "phpunit", "segfault", "gdb", "nativa"]
excerpt: "Beh testov skončil s kódom 255 a bez výstupu. Fatálna chyba bola v kóde a PHP ju vypísalo správne, ale Xdebug 3.5.3 zobral proces so sebou pri ukončení requestu. Rozhodla tabuľka šiestich behov a rámec, ktorý pomenoval gdb."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Nástroje"
published: true
---

## Symptóm: beh bez jedinej správy

`vendor/bin/phpunit tests/Content` skončil s kódom 255 a s prázdnym výstupom.
Žiadny červený test, žiadny stack trace, žiadne „OK“.
Len prázdny riadok, kde malo byť hlásenie.
Taký beh sa číta ako rozbitý nástroj, nie ako rozbitý kód,
a presne to ma na hodinu zaviedlo vedľa.

Pomocná metóda v novom teste sa volala `count()`.
Keď som ju premenoval, beh sa vrátil k životu.
Prvá veta bola hotová a znelo to ako vlastnosť PHP 8.5,
takže som ju začal overovať ako chybu jazyka.

## Hypotéza, ktorú zhodilo meranie

Samotná trieda s metódou `count` sa načíta bez problému.
Dokonca aj vtedy, keď nie je zdedená z ničoho.
Minimálna trieda s jedinou metódou prešla,
a to je presne ten okamih, keď má človek prestať veriť prvej vete.

Rozhodujúci detail bol inde.
PHPUnit drží `TestCase::count()` ako `final`.
Vlastná metóda s tým menom teda nie je optimalizácia ani štýl,
je to fatálna chyba pri kompilácii triedy,
a PHP ju vypísalo presne tak, ako má.

Lenže výstup nikto nevidel.
Medzi správnou správou a prázdnym logom bol ešte jeden krok,
ktorý nemá s jazykom nič spoločné.

## Kto naozaj zomrel

`gdb` pomenoval rámec, v ktorom proces skončil:

```text
Program received signal SIGSEGV, Segmentation fault.
0x0000555555d61963 in zend_observer_fcall_end_all ()
#0  zend_observer_fcall_end_all ()
#1  php_request_shutdown ()
#2  ?? ()
```

Zend pri ukončení requestu zatvára rámce, ktoré mu nahlásili observery.
Xdebug je jeden z nich.
Fatálna chyba pri kompilácii ukončí vykonávanie skokom,
a rámec, ktorý sa mal zavrieť, zostane otvorený.
Pri zatváraní sa potom pracuje s pamäťou, ktorá už neplatí.

Jazyk bol v poriadku.
Rozšírenie prežilo správu, ktorú samo vytlačilo, len o pár riadkov.

## Šesť behov, ktoré rozhodli

Podozrenie treba zmerať, nie obhájiť.
Toto je tabuľka, ktorá oddelila PHP od Xdebugu:

| beh | výsledok |
|---|---|
| `php -n` bez extensionov | 255, bez pádu |
| `php -d xdebug.mode=off` | 255, bez pádu |
| `xdebug.mode=develop` | 139 |
| `xdebug.mode=coverage` | 139 |
| `-d opcache.enable_cli=0` | 139 |
| ten istý fatal v súborovom skripte | 255 |

Opcache to nie je, lebo vypnutie nič nezmenilo.
Režim `off` to je, lebo vypnutie chybu odstránilo.
Zvyšné režimy padajú rovnako,
takže nejde o debugger ani o coverage, ale o observer, ktorý je zapnutý vždy.

Zúženie pokračovalo ďalej.
Fatal v súborovom skripte prejde,
fatal v `php -r` bez `require` tiež.
Pád potrebuje oboje naraz:
kód na príkazovom riadku a súbor, ktorý sa do toho istého requestu už skompiloval.

## Prečo to nie je kozmetika

Prázdny výstup nie je len nepríjemný.
V CI sa z chyby v kóde stane záhadný pád nástroja,
a taký nález sa hlási ako „testy padajú náhodne“.
Kto ho bude hľadať, začne pri svojej zmene,
hoci chyba je v extensione a kód je v poriadku.

Druhá vec je horšia.
Keď proces zomrie počas vypisovania chyby,
správa sa stratí práve vtedy, keď ju človek potrebuje najviac.
Rovnaký mechanizmus môže zoštíhliť aj iné hlásenia,
nielen to o `final` metóde.

## Čo som s tým spravil

V kóde nezostala žiadna zmena správania.
Pomocná metóda má iné meno a docblock hovorí, prečo.
Report pre Xdebug má presný postup, backtrace a tabuľku vyššie,
aby ho mohol niekto zopakovať bez môjho stroja.

Kým to niekto neopraví, je tu jeden praktický návyk.
Pri behu, ktorý nemá výstup a nemá ani zmysluplný kód,
skúsiť `XDEBUG_MODE=off`.
Ak sa chyba vráti, bola tam celý čas.

## Ponaučenie

Kód 139 nie je diagnóza, len oznámenie, že proces skončil násilím.
Kód 255 je to isté oznámenie s iným číslom.
Rozdiel medzi nimi pomenuje až rámec v `gdb`,
a ten je jediné miesto, kde sa dá rozlíšiť jazyk od rozšírenia.

Najdrahšia časť nebola oprava.
Bola to dôvera v prvú vetu, ktorá znie rozumne.
Pomohlo až meranie, ktoré ju zhodilo.
