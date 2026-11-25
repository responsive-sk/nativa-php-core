---
title: "Rust a Lekalo pre deti ... diel 3: rust tooling — workspaces, CLI a výstup, ktorému môžeš veriť"
series: "Rust & Lekalo"
series-part: "3"
date: "2026-09-06"
version: "1.0.1"
tags: ["rust", "lekalo", "tooling", "cli", "architektura"]
excerpt: "Diel 3 série. Ownership bol mentálny model; tooling je to, ako s ním žiješ. Rozoberieme Lekalo CLI — two-crate cargo workspace, clap parsovanie, exit protokol (0/1/3/4/5) a byte-identical kanonický výstup — a prečo je deterministický výstup celý point."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "rust-cli-tooling"
published: true
---

> Toto je diel 3 série **Rust a Lekalo**. V [dieli 2](/blog/ownership-pre-deti)
> sme sa naučili mentálny model: hodnoty sa vlastnia, požičiavajú a uvoľňujú
> na konci scope. To je teória. Dnes príde prax — ako vyzerá skutočný Rust
> CLI nástroj, keď ho otvoríš. A máme po ruke dokonalý exemplár: **Lekalo**,
> nástroj, ktorý modeluje náš projekt. Ďalšie diely: [diel 4 ... kvalita
> v Ruste](/blog/rust-testovanie-pre-deti), [diel 5 ... sémantický
> model](/blog/semanticky-model-pre-deti) a [diel 6 ... Lekalo deep
> dive](/blog/lekalo-pre-deti).

Varovanie na začiatok: na pochopenie tohto dielu nepotrebuješ rozumieť celému
Lekalu. Potrebuješ jednu myšlienku — že CLI sa dá postaviť tak, aby jeho
výstup bol *dôveryhodný* spôsobom, akým PHP tooling málokedy býva. Všetko
tu je v službe tejto myšlienky.

## Cargo: build nástroj a package manager v jednom

PHP má Composer na závislosti a, no, PHP na všetko ostatné. Rust má
**cargo**, ktoré je oboje: rieši závislosti, buildí, testuje, spúšťa a
balí. `rustc` skoro nikdy nespúšťaš priamo — píšeš `cargo build`
a cargo orchesteruje kompilátor.

Lekalo je **cargo workspace**: jedno repo, viacero crates (Rust balíkov),
jeden zdieľaný lockfile a build adresár. Má presne dva crate-y:

- **`lekalo-core`** — kde žije každé rozhodnutie: načítanie modelov, build
  IR, konštrukcia grafov, projekcia impactu. Core vlastní pravidlá.
- **`lekalo-cli`** — zámerne tenký: vyberie command, zrendruje výstup
  a namapuje exit kódy. Neobsahuje žiadnu doménovú logiku.

To rozdelenie je architektonické, nie náhodné. Ak je CLI hlúpe, tak je každé
správanie — traversal grafu, impact projekcia, validácia — testovateľné bez
volania príkazového riadku a každý príkazový riadok je len tenká projekcia
toho istého core. Toto zrkadlí to, čo toto repo robí so svojimi modulmi:
`Modules\Core` vlastní kernel a každý feature modul je tenký slice na ňom.
Rovnaký inštinkt, iný jazyk.

## Clap: parsovanie, ktoré ťa nikdy neprekvapí

De-facto CLI parser v Ruste je **clap**. Lekalo ho používa s konkrétnou
voľbou zdokumentovanou v jeho CLI kontrakte: *clap parsing always uses its
non-terminating parse path*. To znamená, že chyby argumentov sa nepretavia
do nečakaného správania — mapujú sa na definovaný fail: **exit 1**, nikdy
nie clapov default exit 2.

Prečo na takom detaile záleží? Pretože nástroj, ktorý je *predvídateľný
v zlyhaní*, je nástroj, ktorý sa dá skriptovať. CI pipeline-y, editory,
AI agenti — všetci sa rozhodujú podľa exit kódov. Ak tvoj nástroj niekedy
exitne 2 a niekedy 1 podľa toho, ktorá vrstva chybu zachytila, každý
konzument musí riešiť oboje. Lekalo kontrakt to kolabuje na jednu stabilnú
odpoveď: syntax chyba je exit 1, bodka.

## Exit protokol: 0/1/3/4/5

Keď sme pri exit kódoch — Lekalo definuje uzavretú množinu a stojí za
prečítanie, lebo ukazuje úroveň zámernosti, akú tento druh toolingu má:

| Exit | Význam | Stream |
|---|---|---|
| `0` | valid / úspech | stdout |
| `1` | invalid (chybný vstup, usage) | stderr |
| `3` | dobre sformované, ale *denied* (policy odmietnutie) | stdout |
| `4` | rozpoznaná capability nedostupná v tomto builde | stdout |
| `5` | nepodporovaná verzia model kontraktu | stderr |

Zaujmú dva detaily. Po prvé, **status vlastní exit**: závažnosť diagnostiky
nikdy nepočíta exit kód — počíta ho DomainResult. Po druhé, *denied*
(exit 3) je zámerne odlišné od *invalid* (exit 1): dobre sformovaný request,
ktorý porušuje policy, je iný výsledok než chybný — a ide na stdout so
štruktúrovaným JSON, nie do stderr šumu. To rozlíšenie — invalid ≠ denied —
je presnosť, ktorú PHP aplikácie zvyčajne nechávajú na konvenciu. Tu je to
kontrakt.

## Výstup, ktorému môžeš veriť: kanonické bajty

Tu je myšlienka, ktorá všetko zapadne. Lekalo core sľub je
**deterministický výstup**: pre rovnaký vstup je kanonický model, graph
export, impact projekcia *byte-identical* naprieč opakovanými behmi, naprieč
frontendami (YAML vs JSON), naprieč platformami. `lekalo graph export --json`
produkuje zakaždým presne tie isté bajty.

JSON wire kontrakt to presne hovorí: UTF-8, dvojmedzerové odsadenie, presne
jeden koncový line feed a — kriticky — *žiadne ANSI escapy, žiadne
timestampy, žiadne absolútne cesty, žiadny echo raw argumentov*. Výstup je
čistá funkcia vstupu.

Teraz sa zamysli, čo toto repo robí v `bin/verify-build.php`: *zmluvne*
kontroluje build výstup (žiadny `@layer`, ES2015, self-hosted assety)
a kričí, keď sa kontrakt poruší. Lekalo robí to isté so svojím výstupom —
ale ako základnú vlastnosť nástroja, nie ako kontrolu po builde. Ak sa dva
behy nástroja nezhodnú, nemôžeš cacheovať, nemôžeš diffovať, nemôžeš mu
veriť v pipeline. Ak sa vždy zhodnú, výstup sa stáva hodnotou, ktorú môžeš
porovnávať, hashovať a odvodzovať z nej závery — čo je presne to, čo
umožňuje AI agentovi brať `lekalo graph export` ako ground truth.

## MSRV kontrakt

Ešte jedna zámerná voľba: Lekalo si v `Cargo.toml` piny **MSRV** (minimum
supported Rust version) na `1.80.0`. Toolchain môže ísť ďalej; projekt
potichu nebude vyžadovať novší. To je rovnaký inštinkt ako Chrome 70 baseline
tohto kitu alebo jeho PHPStan level 9 gate: deklaruješ podlahu, testuješ
proti nej a nikdy neklesneš pod ňu bez zámerného rozhodnutia.

Pre PHP vývojára je najbližší príbuzný `require` v `composer.json` alebo CI
matrix, ktorý pinuje `php-version: 8.5`. Rust len robí podlahu súčasťou
samotného manifestu.

## Čo ti to dáva

Daj to dokopy: tenké CLI na testovanom core, clap parsovanie, ktoré
neprekvapí, uzavretý exit protokol, byte-identical kanonický výstup a
pinovaný MSRV. Každý kúsok je malý; spolu produkujú nástroj, ktorý môže
stroj ovládať naslepo — a to je celý point série. Lekalo je postavené na to,
aby ho konzumovali AI agenti a pipeline-y, ktoré potrebujú *garancie*,
a každá z tých garancií je rozhodnutie na úrovni kontraktu, nie nádej.

Ďalší diel sa pozrie na to, ako sa tie garancie držia na uzde: testy, clippy,
MSRV overovanie a contract checkery — kvalita v Ruste v praxi.

[ďalej: kvalita v Ruste](/blog/rust-testovanie-pre-deti)

## Zdroje

- [Lekalo — CLI kontrakt](https://github.com/ichinya/lekalo/blob/main/docs/cli.md) — commandy, exit kódy, JSON wire
- [The Cargo Book](https://doc.rust-lang.org/cargo/) — workspaces a reprodukovateľné buildy
- [clap](https://github.com/clap-rs/clap) — Rust CLI argument parser
