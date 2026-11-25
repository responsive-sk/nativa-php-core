---
title: "Články ako verzie ... párne MINOR sú LTS"
date: "2026-09-17"
version: "1.0.2"
tags: ["writing", "clean-code", "publishing", "architektura"]
excerpt: "Číslo dielu hovorí, kedy článok vznikol. Verzia hovorí, čo sa v ňom zmenilo ... a párne MINOR je vydanie, ktoré smiete linkovať bez strachu. Kolízia čísel 8 a 9 sa tu vyriešila vetvou 9.1 — a stroj teraz stráži tvar, nie úsudok."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Writing"
translation: "articles-as-versions"
published: true
---

Si vývojár. Napíšeš článok, vypustíš ho, o rok ho niekto linkuje do slajdov.

A vtedy príde otázka, na ktorú číslo dielu nemá odpoveď: čo z toho ešte
platí? Či sa to medzitým nezmenilo. A či ten link o ďalší rok nezradí.

Softvér túto otázku vyriešil pred viac než desiatimi rokmi: **verziami**.

Tento repozitár dospel k tomu istému — článok je produkt. Má čitateľov,
má revízie, má vydania. A teraz aj čísla.

## Číslo hovorí, kedy ... verzia hovorí, čo

Diel 9 vznikol 11. septembra. To je všetko, čo ti číslo povie.

Verzia je iné zviera. `1.0.1` ti povie, že sa opravil preklep. `1.1.0`,
že pribudol obsah. `2.0.0`, že článok prerástol do novej štruktúry.

Schéma je prevzatá celá — tri miesta, tri druhy zmien:

| Zmena | Bump | Príklad z tohto repa |
|---|---|---|
| preklep, typografia, rozbitý link | **PATCH** (1.0.**1**) | „diel 8“ → „diel 9.1“ v odkaze |
| obsah: sekcia, tabuľka, dôkaz | **MINOR** (1.**1**.0) | doplnená tabuľka porovnania |
| štruktúra: nová pointa, zlúčenie sekcií | **MAJOR** (**2**.0.0) | prepis do novej štruktúry |
| rozpracovaný návrh | **0.x.y** | 5 draftov dnes nesie `0.1.0` |

Prvá publikovaná verzia je `1.0.0`. Nie `0.9`, nie „takej nejakej“ —
článok, ktorý si prečítal cudzinec, je vydanie.

## Párne MINOR sú LTS

A tu prichádza pravidlo, ktoré túto schému odlišuje od bežného semveru.

**Párne MINOR je LTS vydanie.** `1.2.0`, `1.4.0` — verzie, ktoré sa
menia už len patchmi. Obsah je zamrazený.

Párne čísla sme si nevybrali pre krásu. Parita je binárna — vidno ju v
čísle na prvý pohľad, aj v obálke, aj v adrese.

Nepárne MINOR je dielňa. Tam sa ešte vŕta, presúva sekcie a menia
tabuľky. `1.3.0` ti teda hovorí: čítaj, ale nepodpisuj pod týmto
článok zmluvu.

`1.4.0` hovorí opak: môžeš si ho vytlačiť.

## Kolízia sa rieši vetvou, nie prepisom

Najprv priznáme chybu, ktorá celé verziovanie spustila.

Séria Rust a Lekalo má 15 dielov, ale čísla 8 a 9 existujú dvakrát. Dva
novšie články o témach vyšli 13. a 14. septembra — chronologicky po
dieli 9 — a dostali už obsadené čísla.

Svet lineárneho číslovania by na to reagoval prečíslovaním: polovica
série ide o číslo nižšie, ~30 odkazov sa posúva a každý externý link
„diel 9“ zradí.

Toto miesto spravilo niečo iné — vetvu:

```text
// storage/blog/sk/lekalo-temy-v-modele-pre-deti.md — front matter
series: "Rust & Lekalo"
series-part: 9.1

// storage/blog/sk/lekalo-bindings-pre-deti.md — front matter
series: "Rust & Lekalo"
series-part: 9.2
```

Duológia o témach nadväzuje na diel 9, takže dostala deviatku s minorom —
rovnaký nápad, ktorý poznáš z verziových vetiev.

A obálka? `CoverGenerator` (`src/Modules/Blog/Application/Services/CoverGenerator.php`)
číta `series-part` ako reťazec — ghost číslo aj badge `DIEL 9.1` sa
vykreslili bez jediného riadku zmeny v kóde.

Stráži ho test. `BlogVoiceTest::testSeriesPartPairsWithSeriesAndIsUnique`
pri kolízii hovorí presne: *branch it (9.1), do not reuse*.

## Čo stráži stroj ... a čo vy

Hranica je jasná a oplatí sa ju napísať.

Stroj stráži tvar: `version:` existuje, je to semver, publikované
články nesú `1.0.0` a viac, drafty `0.x`. Plus jedinečnosť
`series-part` v rámci série a jazyka. `testArticleVersionFollowsSemverScheme`
a `testSeriesPartPairsWithSeriesAndIsUnique` — to je celá mechanická
polovica.

Úsudok zostáva váš. Čo bumpnete, či si párne MINOR zaslúži svoju
paritu, či je `1.4.0` zamrazené — na to stroj nemá oči.

Táto časť nie je dobrý kompromis. Je to ten istý vzor ako pri teste
hlasu: kontrakt vynúti tvar, čitateľom ostáva vkus, autorom disciplína.

## Kedy verzie neplatia (a povinná úprimnosť)

Buďme úprimní: verzia nerobí článok dobrým.

`1.4.0` môže byť suchá ako papier a `0.1.0` môže žiť. Verzia popisuje
zmeny, nie kvalitu — na kvalitu si ju musíš prečítať sám.

Druhá úprimnosť je hrubšia. Bez histórie vydaj je bump len tvrdenie.
Test overí, že číslo je tam a má správny tvar — neoverí, že obsah
rástol o MINOR a nie o MAJOR. Príručka to hovorí napriamo:
čo bumpneš, je redakčná disciplína.

A tretia: rovnako ako u hlasu platí, že pravidlo, ktoré dodržiavaš len
preto, že ho stráži CI, nie je pravidlo. Verzovanie bez vôle pamätať si
zmeny je len dekorácia vo front matter.

## Prakticky

1. **Daj článku verziu** ... `version:` vo front matter; prvá
   publikovaná je `1.0.0`, rozpracovaná nesie `0.x.y`.
2. **Bumpni podľa tabuľky** ... preklep = PATCH, nový obsah = MINOR,
   nová štruktúra = MAJOR. A obsah, na ktorý chcú ostatní linkovať,
   vypusti ako párne MINOR.
3. **Linkuj LTS číslom** ... „prečítaj si diel 9.1 vo verzii 1.2.0“
   je odkaz, ktorý o rok nezradí. Nepárny minor linkuj len vtedy, keď vieš, čo čítaš.

Verzia potom odpovedá na otázku „čo sa zmenilo“ ... nie „kedy to
vzniklo“.

## Zdroje

- [Semantic Versioning 2.0.0](https://semver.org/) ... schéma, ktorú články prevzali celú
- [Keep a Changelog](https://keepachangelog.com/) ... disciplína vydaj, z ktorej je bump viac než tvrdenie
- [Nativa Minimal Documentation](/docs/getting-started) ... getting started guide tohto kitu
- [docs/blog-voice.md](/docs/blog-voice) ... príručka, kde žije sekcia Verzie článkov
