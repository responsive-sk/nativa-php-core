---
title: "Pravidlá hlasu pre deti ... keď sa štýl stane testom"
date: "2026-09-17"
version: "1.0.2"
tags: ["writing", "clean-code", "testing", "architektura"]
excerpt: "Štýlová príručka, ktorú nikto nevynucuje, je želanie. Z hlasu tohto blogu sa stal architekturný test ... Zdroje musia byť reálna sekcia, emoji zakázané, dvojice jazykov prepojené obojsmerne. A áno, aj tento článok musí test prežiť."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Writing"
translation: "voice-rules-become-a-test"
published: true
---

Si vývojár, ktorý píše. Niekto povie „štýlová príručka“ a tvoj mozog si to
potichu založí do šuplíka s nápisom „dokument, ktorý nikto neotvorí“.

Ten šuplík poznáme. Preto sme z príručky urobili niečo, čo šuplík neprežije:
**architekturný test**.

## Príručka, ktorú nikto nevynucuje, je želanie

Tento blog má príručku hlasu — [`docs/blog-voice.md`](/docs/blog-voice).
Tri pravidlá: kámoš, ktorý nelichotí. Pravda s deklarovaným typom. A cast
na reálny kód z repozitára.

Niečo vyše týždňa žila ako obyčajný Markdown.

A presne tak dlho bola želaním. Pretože príručka, ktorú nevynucuje CI,
pôsobí presne ako komentár „toto zrefaktorovať neskôr“ ... dobré úmysly,
ktoré čakajú na čitateľa, ktorý nikdy nepríde.

V tomto projekte máme na to pravidlo: **čo sa dá zmluvne skontrolovať,
nezostáva na dobrej vôli.** `bin/verify-build.php` zmluvne kontroluje build.
`BuildCssAuditTest` výpočtom overuje WCAG kontrasty. Prečo by mal hlas
článkov byť výnimkou?

## Čo test meria ... a čo nie

Nový test (`tests/Architecture/BlogVoiceTest.php`) stráži tri kontrakty.

**Prvý: Zdroje sú reálna sekcia.** Nadpis `## Zdroje` musí stáť na vlastnom
riadku a sekcia musí obsahovať aspoň jeden odkaz. To nie je šikanizmus ...

Ešte tento týždeň nám grep tvrdil, že 191 článkov má Zdroje. Bola to
polopravda: test hľadal reťazec, nie sekciu. Dva články mali nadpis zahryznutý
do steny textu — `... výkon nášho PHP kódu. ## Zdroje - [DeepSeek]...`
v jedinom riadku. Substring test videl `## Zdroje` a spokojne mlčal.

**Druhý: nula emoji.** Zelený check, červený krížik aj ozubené koliesko
nepatria do porovnávacích tabuliek — slová nesú status lepšie a vydržia
v termináli, vo feede aj vo vyhľadávaní.

Pozor na jeden detail: **šípka → je dovolená.** Patrí k rukopisu hlasu
(`→ [Part 2]` pri odkazoch). Zakázané sú piktografy a symboly
(U+1F000+, U+2600–27BF), nie U+2192. Kontrakt, ktorý by zakázal vlastný
rukopis, nie je kontrakt, ale omylom.

**Tretí: dvojice jazykov smerujú obojsmerne.** `translation:` musí ukazovať
na existujúci súbor v druhom jazyku a ten späť. Per-jazyk slugy
(`preco-rust-pre-deti` ↔ `why-rust`) sú len potom úprimné.

A jedna výnimka, ktorú test rešpektuje: **drafty.** `published: false`
znamená „rozpracované“ — test ich nechá pokoj, kým sa nerozhodne.

## Kto testuje test, ktorý píše o sebe?

A tu prichádza okamih, ktorý si zaslúži vlastný odstavec.

Tento článok prešiel testom, o ktorom píše — inak by ste ho nečítali.

Nehnevám sa na neho. Test nepozná žiadnu príručku — pozná iba riadky.
Nevedel, že mu ich píšem. A predsa to nie je bludný kruh: príručka je text,
test je zmluva. Text o zmluve, ktorý zmluvu poruší, by bol vtip horší
než akýkoľvek obrázkový znak.

Dá sa to napísať dvoma riadkami:

```
pravidlo bez testu je želanie
test bez pravidla je cenzor
```

Test nesúdi vkus — nesúdi vtipy, metafory ani to, či sa veta dá povedať
ľahšie. Posudzuje len to, čo sa dá skontrolovať riadok po riadku.
Vkus zostáva čitateľovi. Dohoda zostáva CI.

## Ako to dopadlo ... čísla, nie dojmy

Prázdne tvrdenie by tu nemalo miesto ... tak dostane číslo.

Pred napísaním testu bežal audit: 191 článkov, 3 bez reálnej sekcie Zdroje,
11 súborov s emoji, 0 rozbitých dvojíc jazykov.

Všetko to boli opravy, žiadne výnimky: dva články sa museli prepísať
(stena textu → poriadny Markdown), token-harbor si premenoval
`## References` na `## Sources`, tabuľky v ôsmich súboroch vymenili
checky a krížiky za `áno`/`nie`.

Celé to stojí na jednom inštinkte, ktorý tento repozitár opakuje do
omáčky: **kontrakt, overovaný buildom, ktorý kričí pri porušení.**
Ako `bin/verify-build.php`, ako `CoverCodeIsFromArticleTest`, ako
`BlogSearchTest`. Rozdiel je jediný: tentoraz strážime nie kód, ale slová.

## Kedy pravidlo neplatí (a povinná úprimnosť)

Buďme úprimní: mechanický test nevie, či je článok dobrý.

Môže odhaliť stenu textu, hlásiť emoji, rozbitú dvojicu jazykov. Nevie,
či je rytmus správny, či úprimná sekcia úprimne úprimná, alebo či veta
niečo hlboké povie ... alebo len dobre znie. Rytmus riadkov ostáva
ručným remeslom — test vám nanajvýš skontroluje, že zdroják nie je stena.

A test tiež nevie, kedy je výnimka odôvodnená. Preto má drafty výnimku
a šípku → dovolenú — kontrakt vymedzený tak, aby bránil nesprávnemu,
nie všetkému.

Zvyšok — kámoš, ktorý nelichotí — test neskontroluje. To ostáva na vás.

## Prakticky

1. **Píš článok ako doteraz** ... príručka je v `docs/blog-voice.md`,
   test nevyžaduje žiadnu novú syntax.
2. **Skontroluj tri veci pred pushom** ... `## Zdroje` na vlastnom riadku
   s aspoň jedným odkazom, nula emoji (šípka → je v poriadku), a
   `translation:` ukazujúci na existujúce zrkadlo, ktoré ukazuje späť.
3. **Ak test spadne, prečítaj si správu** ... vždy hovorí presne to,
   čo článku chýba. Nepísal som ho pre seba.

Test napísal ten istý hlas, ktorý teraz stráži. A článok o ňom píše
rovnaký hlas. Ak ste dočítali sem, kontrakt drží z oboch strán.

## Zdroje

- [PHPUnit — Data Providers](https://docs.phpunit.de/en/12/data-provider.html) ... vzor, na ktorom test stojí
- [Markdown — Daring Fireball](https://daringfireball.net/projects/markdown/) ... formát, v ktorom žije celý korpus
- [Nativa Minimal Documentation](/docs/getting-started) ... getting started guide tohto kitu
- [docs/blog-voice.md](/docs/blog-voice) ... príručka, ktorej mechanická polovica je teraz zmluva
