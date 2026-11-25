---
title: "Rust a Lekalo pre deti ... diel 16: komentáre — posledná reťaz, ktorá čaká na manifest"
series: "Rust & Lekalo"
series-part: "16"
date: "2026-09-27"
version: "1.0.2"
published: true
tags: ["rust", "lekalo", "trace-manifest", "comments", "openspec"]
excerpt: "V modeli Lekalo stojí sedem modulov. Šesť z nich má svoj trace manifest — reťaz, ktorá spája špecifikáciu s testami. Komentáre sú siedmy. Ich reťaz vedie cez čakáreň: hosť napíše, admin schváli, článok to ukáže. Až potom príde manifest."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "comments-the-last-chain"
---

> [Diel 15](/blog/lekalo-trace-manifest-pre-deti) postavil trace manifest —
> reťaz s hashmi na oboch koncoch. Dnes 16 — komentáre. Modul, ktorý
> reťaz ešte nemá, a napriek tomu je to modul, ktorého príbeh poznáš
> najlepšie. Lebo si ho zažil. Komentoval si niekedy niečo na webe?

V modeli Lekalo stojí sedem modulov. `users`, `blog`, `audit`, `pages`,
`themes`, `design`, `comments`. A teraz malé spočítanie: šesť z nich už
má svoju reťaz — [users](/blog/lekalo-pre-deti),
[blog](/blog/lekalo-model-rastie-pre-deti),
[audit](/blog/lekalo-trace-manifest-pre-deti) i
[pages](/blog/lekalo-trace-manifest-pre-deti), themes a design
(posledné dva sa delia o jeden manifest). Šesť reťazí v piatich
dokumentoch, v ktorých nie je veta „testy niečo strážia“, ale dáta:
uzol po uzle, relácia po relácii, hash po hashe.

Ostáva jeden. `comments`. Jediný modul v `lekalo/modules/`, ktorého
manifest v `docs/lekalo/trace/` neleží.

A tu je vec, ktorá ma na tom baví najviac: práve comments je modul,
ktorého model je najmladší a najchudobnejší. Má tri súbory — príkazy,
entity, politiky. Nemá udalosti, nemá scenáre, nemá väzby na endpointy.
Čo to znamená, to si ukážeme o chvíľu. Najprv ale príbeh.

## Hosť, čakáreň a článok

Komentár na tomto blogu prežije život v troch dejstvách. Nie je to nič zložité — a presne preto je to dobrý príbeh pre model.

Prvé dejstvo: **hosť napíše**. Človek bez účtu príde na článok, vypíše
meno, e-mail a text. Formulár letí na `POST /blog/{slug}/comments`. Za
ním stoja traja strážcovia. Token CSRF — stránka chce dôkaz, že formulár
poslala naša stránka. Per-IP throttle — tá istá adresa nemôže za minútu
zaplaviť čakáreň stovkou textov. A tretia vec, ktorú strážcovia
neriešia: existuje článok, pod ktorý komentár patrí?

Kým prejdú všetky tri, komentár **ešte neexistuje pre svet**. Vznikne
v databáze so statusom `pending`. Čakáreň. Hosť vidí potvrdenie —
„ďakujeme, komentár čaká na schválenie“ — ale na článku sa neukáže.
Nik. Ani na sekundu.

Druhé dejstvo: **admin rozhodne**. V `/mark/comments` stojí poradie
čakajúcich. Schváliť. Odmietnuť. Zmazať. Tri tlačidlá, tri koncovky, a žiadna z nich nerobí nič iné než mení jeden stĺpec:
`pending` → `approved` alebo `pending` → `rejected`. Zmazať je
výnimka — riadok zmizne navždy a jeho identifikátor už nikdy neodpovie.

Tretie dejstvo: **článok ukáže**. Len komentáre so statusom `approved`
si článok vezme a vykreslí. Rejected sa skryje. Pending sa skryje.
Svet vidí len to, čo prešlo čakárňou.

## A teraz ten model

Príbeh vyššie je runtime — ručne písané PHP. Model Lekalo ho zapíše
iným jazykom: deklarácie. A tu sa vrátime k vete, ktorú som odložil:
comments model má tri súbory, ale chýbajú mu tri ďalšie.

V [dieli 15](/blog/lekalo-trace-manifest-pre-deti) sme povedali, že
reťaz vedie cez **symboly** — deklarácie v modeli. Comments ich má:
`comments.submit`, `comments.approve`, `comments.reject`,
`comments.delete`, a k nim efekty — vloženie riadka, zmena stavu,
vymazanie. Politiky sú traja strážcovia z prvého dejstva: throttle,
identita, existujúci článok.

Čo model nevie? Nevie povedať, **čo sa stane, keď príkaz prejde**. V
audite to vie — `audit.entry_appended`. V comments by to bolo
`comments.comment_created`: hosť napísal, riadok stojí v čakárni.
Nevie povedať, **ako tok vyzerá od začiatku do konca** — scenár
`moderation_lifecycle`, ktorý by spájal všetky tri dejstva do jedného
príbehu. A nevie povedať, **kde reťaz začína vo webe** — väzba, ktorá
povie „`comments.submit` žije na `POST /blog/{slug}/comments`“.

Tri diery. Presne tie isté, ktoré audit a pages dostali v dieli 15 —
`events.yaml`, `scenarios.yaml`, `bindings.yaml`. Súbor súbor súbor,
trikrát tri definície. A po ich doplnení sa stane malý zázrak: model
zrazu vie o komentároch toľko, koľko vie o audite.

## Prečo je to posledná reťaz

Po doplnení tých troch súborov zostane posledný krok: manifest.
Reťaz — requirement → symbol → súbor → scenár → test → brána —
zapísaná v `docs/lekalo/trace/comments.trace.json`.

Dva požiadavky zo špecifikácie. Prvá hovorí: hosť môže komentovať,
komentár čaká v čakárni, admin rozhoduje. Druhá hovorí: admin má na to
rozhranie. Symboly sú tie, čo ste čítali vyššie. Artefakty — súbory,
ktoré to nesú: `CommentsService`, repozitár, akcie pre submit aj pre
moderáciu. Scenár — ten príbeh troch dejstiev. Testy? `CommentsTest` a
`CommentsMetricTest`. Brána? `nativa.phpunit`, tá istá, ako pri
všetkých ostatných reťaziach.

A keď bude manifest stáť, stane sa niečo, na čo sa série pýtala od
[dielu 15](/blog/lekalo-trace-manifest-pre-deti): **sto percent
pokrytia**. Sedem modulov v modeli, sedem reťazí v dokumentácii.
Žiadny modul nezostane len príbehom — každý bude mať reťaz, ktorá
hovorí, čím je požiadavka pokrytá, nielen že je.

## Úprimná sekcia

Prvá úprimnosť: chudobný model nie je zlý model. Desať nových
definícií nerobí comments dôležitejším — robí ho **opísateľným**.
Rozdiel je dôležitý. Model nie je zdvorilostná hádzaná dát o kóde;
je to mapa, v ktorej sa dá nájsť cesta.

Druhá úprimnosť: príbeh troch dejstiev je zjednodušenie. V skutočnom
kóde je viac vetiev — čo sa stane, keď hosť nechá prázdny mejl, keď
text prekročí limit, keď admin schváli komentár pod článkom, ktorý
medzitým zmizol. Scenár v modeli nesie hlavný tok, nie každú vetvu.
To nie je klam — to je výber. Mapa, ktorá by niesla všetko, by bola
terén.

Tretia úprimnosť: manifest ešte neexistuje. Tento článok je príbeh o
tom, čo reťaz bude — nie dôkaz, že stojí. Dôkaz príde s JSON v
`docs/lekalo/trace/`, a až guard test v ňom prepočíta hashe. Kým nie,
je to sľub. A sľuby v tejto sérii vždy niečo stáli — presne ako v
[dieli 15](/blog/lekalo-trace-manifest-pre-deti), kde sľub z dielu 10.2
dozrel až o päť dielov neskôr.

## Čo bude ďalej

Manifest. Reťaz s hashmi pre komentáre, posledná zo
siedmich. Keď padne, padne aj posledná diera v pokrytí modelu — a
séria môže rozprávať ďalší príbeh o niečom novom, nie o chýbajúcom.

Pravidlo, ktoré si z dnes odnes: model nepopisuje kód, model popisuje
**tok**. Kým máš len príkazy, vidíš slovesá. Kým pridáš udalosti,
scenáre a väzby, vidíš príbeh — kto čo spustil, čo sa stalo, kde to
žije na webe. A až keď vidíš príbeh, vieš ho zapísať ako reťaz s
hashmi, ktorú stráži stroj.

## Zdroje

- [Lekalo](https://github.com/ichinya/lekalo) — repozitár projektu (`lekalo load --ir --json`, `trace validate`)
- [docs/lekalo-model.md](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md) — recept na IR digest a konvencie modelu
- [Diel 15 — trace manifest](/blog/lekalo-trace-manifest-pre-deti) — reťaz s hashmi na oboch koncoch
- `tests/Integration/CommentsTest.php` — testy moderovacej reťaze, ktorú bude manifest dokazovať
