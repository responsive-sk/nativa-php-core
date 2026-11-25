---
title: "Bonus pre členov ... vydávanie: článok ako produkt s číslom"
date: "2026-09-17"
version: "1.0.2"
tags: ["publishing", "versioning", "series", "workflow", "architecture"]
excerpt: "Ôsmy bonus len pre registrovaných. Softvér nepíše „verzia: nejaká“ ... a článok tiež nie. Ako vyzerá publish workflow, v ktorom návrh nesie 0.x, prvé vydanie 1.0.0, kolízia čísel sa vetví a párne MINOR je mraz, ktorý linky neprezradia — na reálnom front mattere a testoch tohto repa."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Bonus"
members_only: true
translation: "bonus-shipping"
published: true
---

> Toto je ôsmy **bonus pre členov**. Predchádzajúce diely: [zámok
> members-only](/blog/bonus-members-only-pre-deti), [immutable audit
> log](/blog/bonus-audit-log-pre-deti), [fulltext search cez
> FTS5](/blog/bonus-fts-search-pre-deti), [komentáre s
> moderáciou](/blog/bonus-komentare-pre-deti), [média ako cudzie
> súbory](/blog/bonus-media-pre-deti), [debug ako
> retroskop](/blog/bonus-debug-pre-deti) a [jazyky ako
> zmluva](/blog/bonus-jazyky-pre-deti). Dnes workflow, ktorým samotné
> bonusy vznikajú ... článok ako produkt s číslom.

Si vývojár. Vydávaš softvér — verzie, tagy, changelog. A vedľa toho
píšeš články, ktoré „vyletia aj tak“.

Rozdiel medzi týmito dvoma svetmi je menší, než si myslíš.

Softvér bez verzie nevieš linkovať — `latest` je sľub, nie adresa.
Článok bez verzie je to isté: dnes platí, zajtra sa zmení a tvoj link
v slajdoch mlčí.

Tento repozitár preto vydáva články ako softvér. Tu je ten workflow —
zvnútra.

## Pipeline: návrh → vydanie → mraz

Každý článok v korpus prechádza tromi stavmi a každý má svoje číslo:

| stav | číslo | príklad z tohto repa |
|---|---|---|
| návrh | `0.x.y` | 5 draftov dnes nesie `0.1.0` |
| prvé vydanie | `1.0.0` | väčšina zo 191 článkov |
| mraz (LTS) | párny MINOR | `1.2.0`, `1.4.0` |

Návrh je pred-verzia. `published: false` vo front mattere hovorí
svetu „nepozerať sa“ — a testy ho nechajú pokoj, kým sa rozhodne.

Prvé vydanie je okamih, keď článok prečíta cudzinec. Od tej chvíle je
zmluva — mení sa, ale verejne a označene.

A mraz je vedomý akt: obsahový sweep (rytmus, Zdroje, front matter)
započítate ako MINOR, bumpnete na párne číslo a odvtedy meníte už len
patche. Diel 1 série Rust a Lekalo to spravil presne takto — `1.2.0`
→ doplnená sekcia → `1.4.0` → opäť zamrazený.

## Kolízia sa vetví, neprepisuje

Čísla v sérii nie sú len ozdoba — sú adresy. A adresy sa neobsadzujú
dvakrát.

Stalo sa aj takto: duológia o témach vyšla po dieli 9, ale deviatka
bola obsadená. Lineárny svet by prečísloval polovicu série — a ~30
externých odkazov „diel 9“ by zradilo.

Toto repo otvorilo vetvu:

```text
// storage/blog/sk/lekalo-temy-v-modele-pre-deti.md
series: "Rust & Lekalo"
series-part: 9.1
```

`9.1` nie je „desiaty diel“. Je to súrodenec deviatky — nadväzuje na
nej, neobsadzuje ďalšie miesto. Ghost číslo na obálke aj badge
`DIEL 9.1` vykreslil existujúci generátor bez zmeny kódu, lebo
`series-part` sa číta ako reťazec.

Stráži to `BlogVoiceTest::testSeriesPartPairsWithSeriesAndIsUnique`
— pri kolízii hovorí presne: *branch it (9.1), do not reuse*.

## Stroj stráži tvar, redakcia význam

Hranica medzi tým, čo overuje CI a čo je disciplína, je jasná:

| overuje test | overuje človek |
|---|---|
| `version:` existuje a je semver | či bump zodpovedá skutočnej zmene |
| publikované ≥ `1.0.0`, drafty `0.x` | či si párny MINOR zaslúži mraz |
| `series-part` v páre so `series:` | či je vetva správne miesto |
| `series-part` unikátny v rámci (séria, jazyk) | či je obsah pripravený |

Test nevie, či je článok dobrý. Vie len, že čísla majú tvar — a že
kolízia nekričí potichu. Presne ako pri [hlasovej
zmluve](/blog/pravidla-hlasu-pre-deti): tvar je kontrakt, význam je
remeslo.

## Obálka ako change log

Ku každému vydaniu patrí aj viditeľnosť — a tá rieši pečiatka na
obálke.

`CoverGenerator` renderuje `version:` do pravého dolného rohu:
LTS diel (párny nenulový MINOR) nesie akcent a label `v1.4.0 · LTS`,
vývojové verzie a drafty sú stlmené. Verzia je render-only — bump
zmení pečiatku a nič iné, čo drží deterministický test.

Čitateľ tak vidí na prvej pohľad, čo linkuje: zamrazený diel, alebo
dielňa. Rovnaký princíp ako tableta s dátumom expirácie — informácia
na obale, nie v prílohe.

## Kedy workflow neplatí (a povinná úprimnosť)

Verziovanie nerobí článok dobrým.

`1.4.0` môže byť suché ako papier a `0.1.0` môže žiť. Číslo popisuje
zmeny, nie kvalitu — na kvalitu sa číta obsah.

Druhá úprimnosť: bez histórie vydaj je bump len tvrdenie. Test overí
tvar, nie pravdu o obsahu — „doplnil som MINOR“ môže byť aj „preklepol
som si číslo“. Príručka to hovorí napriamo: čo bumpneš, je redakčná
disciplína.

A tretia: legacy série sú zámerne bez `series:`/`version:` migrácie.
Zavádzanie nových ID do starých článkov by prekreslilo ~50 obálok
bez zisku — workflow platí pre nové diely, minulosť sa nedokopáva.

## Prakticky

1. **Píš ako draft s `0.x`** ... `published: false` + `0.1.0` — nič
   sa nestráca, nič nezradí.
2. **Vydaj ako `1.0.0`** ... prvé čítanie cudzincom je vydanie,
   nie pokračovanie návrhu.
3. **Linkuj párne MINOR** ... „diel 9.1 vo verzii 1.2.0“ je adresa,
   ktorá o rok nezradí. Nepárny minor je dielňa.

Článok potom neletí do sveta ... vydáva sa.

## Zdroje

- [Semantic Versioning 2.0.0](https://semver.org/) ... schéma, ktorú články prevzali celú
- [Keep a Changelog](https://keepachangelog.com/) ... disciplína vydaj
- [docs/blog-voice.md](/docs/blog-voice) ... príručka, kde žijú sekcie Série a Verzie článkov
- [Nativa Minimal Documentation](/docs/getting-started) ... getting started guide tohto kitu
