---
title: "Rust a Lekalo pre deti ... diel 17: tri verzie a ani jedna zmena"
series: "Rust & Lekalo"
series-part: "17"
date: "2026-10-05"
version: "1.0.0"
tags: ["rust", "lekalo", "upgrade", "ci", "semanticky-model", "lock"]
excerpt: "Nástroj vyrástol o tri malé verzie a model nepohul ani o jeden riadok. Článok o tom, ako zistiť ešte pred upgradeom, ktoré verzie sa hýbu a ktoré stoja, a prečo verdict, ktorý vyzerá ako tvoja chyba, nemusí byť tvoj."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-three-versions"
published: true
---

V [dieli 10.2](/blog/lekalo-upgrade-pre-deti) stál model na 142 definíciách
a nástroj prešiel z 0.1.31 na 0.3.2. Odvtedy pribudlo 104 definícií a
všetky sú naše — pribudli s modmi, ktoré sme do modelu vpustili my. Dnes
nástroj prešiel z 0.3.2 na 0.6.4 a z tých 246 definícií sa nepohlo ani
jedno.

Nie náhodou. Vďaka jednému digestu, ktorý zostal v locku rovnaký.

## Hmla: štyri čísla, tri verzie, jedna nejasnosť

Projekt má teraz štyri rôzne čísla verzii a ani jedno z nich nie je to
druhé:

| Číslo | Čo je to |
|---|---|
| `v0.6.4` | git tag, z ktorého sa inštaluje |
| `lekalo 0.6.3` | čo vypíše `--version` |
| `0.3.2` | verzia target protokolu aj verzia wire schémy locku |
| `0.2.16` | verzia modelu a IR |

Pred mesiacom to bolo jedno číslo. Dnes je z toho štyri a čitateľ
dokumentácie má uhádnuť, ktoré z nich patrí do CI, ktoré do locku a
ktoré ani do čoho.

Najhoršie je, že dve z nich sú rovnaké číslo s dvoma rôznymi významami.
`0.3.2` je dnes zároveň verzia protokolu aj wire schéma locku, ktorú
práve zapísala nová verzia nástroja. Jeden reťazec, dva svety.

## Slnko: jediný príkaz, ktorý odpovedá skôr, než sa zrúti

Najlacnejšia vec, ktorú som dnes mohol urobiť, je nezačať. Preveriť
register kontraktov, ktorý je zabudovaný v binárke, a čítať, ktoré
verzie sa vôbec hýbu:

```sh
lekalo compatibility
// compatibility: model current 0.2.16 (0.2.16..0.2.16),
// ir current 0.2.16, protocol current 0.3.2
```

Všetko tri sú `current` na tej istej verzii, ktorá už v našom projekte
bola. Cez rozsah 0.4, 0.5 a 0.6 sa model, IR ani protokol neposunuli
o jeden prírastok. Takže plán mohol byť jedným vetom: zmeniť pin,
pregenerovať lock, nič ďalšie.

A tak to aj vyšlo. Rozdiel medzi dnešným upgradeom a dielom 10.2 je,
že vtedy sa vámali kontrakty aj wire formáty; dnes sa nepohlo nič
okrem jednej vecí:

```sh
git diff lekalo.lock
- "schema_version":"lekalo/lock/v0.2.16"
+ "schema_version":"lekalo/lock/v0.3.2"
- "core":{"version":"0.3.2"}
+ "core":{"version":"0.6.3"}
- "request_digest":"sha256:63db07fa…"
+ "request_digest":"sha256:8f194d07…"
```

Tri polia. A na stole stojí ešte jedno, ktoré sa vdiffoch **nenachádza**:
digest modelu. Pred upgradom a po ňom je ten istý bajt, `sha256:0bf1d827…`,
lebo kanonická forma našich 246 definícií sa nemenila.

To je silnejší dôkaz než tabuľka. Tabuľka hovorí, čo sa zmenilo.
Digest hovorí, čo sa **nemenilo**, a to overuje aj keby sa zmenilo niečo,
čo som v diffe zabudol prečítať.

Žiadny YAML, žiadny trace manifest, žiadna jediná definícia modelu. Keď
`bin/trace/build.py --check` hlási sedem čistých manifestov, viem, že
generované veci stoja, lebo sa generátor neopýtal iného sveta.

## Noc: tri verzie žijú v trojoch rýchlostiach

Nástroj verzuje rýchlo, pretože jeho vlastnosti rastú s každým
miliónom riadkov kódu, ktorý musí porozumieť cudzíemu projektu. Model
verzuje pomaly, pretože popisuje **našu aplikáciu**, a aplikácia sa
nemení len preto, že sa zmenil nástroj, ktorý ju číta.

Tretia je wire schéma locku. Tá verzuje vtedy, keď sa zmení tvar
záznamu, ktorý nástroj píše na disk. A to je najzaujímavejšia z
troch, pretože je **jediná, ktorá sa zaujíma o tvoj počítač**. Model
je porovnateľný kdekoľvek, kontrakt sa dá poslať kolegovi. Lock je
súbor vo version kontrole, a číta ho iba jeden program na svete.

Keď ten program odmietne lock, ktorý sám napísal pred šiestimi
týždňami, nie je to chyba v tvojom modeli. Je to chyba v kalendári.

Slovo `future` v chybe tomu hovorí nahlas:

```sh
lekalo lock
// unsupported-version error [LEK-LOCK-020]
// lock.unsupported-schema-version: The lock wire carries a
// future schema discriminator.
```

Nástroj nevie, že diskriminátor `v0.2.16` je minulý. Vie len, že nie
je v jeho registri. Preňho je to naozaj budúcnosť, ktorú nepozná.

## Objekt, ktorý odkladáme

Samotný v0.6.4 si zaslúži na svoju chvíľu, len ju nemáme.

Vydal Node a TypeScript bindingy pre Hono a Drizzle, lokálnu
históriu behov a pilotáž na brownfield projekte. To je skvelá práca,
pravdivá práca, ktorá bude potrebná niekomu, kto má tie technológie.
My máme PHP, SQLite a smelý základ, v ktorom sa bojíme jediného:
aby niekto nechal `debug_backtrace()` v produkcii.

Takže v0.6.4 odkladáme. S rešpektom. A nabudúce, keď sa náš model
ocitne v projekte, kde behá Hono, ten istý príkaz nám znova odpovie,
či sa niečo zmenilo.

## Kto drží vinu

Populárna otázka pri novom nástroji znie: hlási `doctor` chybu? A
druhé: je to moja chyba?

Odpoveď má jednu trúbu a trvá štyridsať sekúnd. Skôr než obviniš
upgrade, pozri, či verdikt existoval pred ním. Zdroj predošlej
verzie leží na disku:

```sh
grep -rn "targets-without-adapters" \
  storage/cache/lekalo/lekalo-src/crates/lekalo-core/
// crates/lekalo-core/src/doctor/checks.rs:583
```

Riadok existuje aj v 0.3.2. Verdikt nepridal upgrade, verdikt bol
celý čas a my sme ho nikdy nečítali, lebo `doctor` je informačný a
nie brána. Dva blokované kontroly hovoria, že nemáme nainštalovaný
adapter. Nemáme ho schválene: projekt adoptuje model a trace
manifesty, nie adaptery. Neplatí.

Rozdiel medzi `blocked` a `zlyhaním` je presne v tejto jednej vete.

## Čo bude ďalej

Dve roadmappy, obe malé, obe poctivé.

Upstream v0.6.4 rieši to, čo sme my neriešili: drbtil z reálnych
projektov, čo sa dá automaticky a čo nie, a zapísal si to ako nálezy
F1 až F7. Jeden z nich nás zaujíma aj tak — Hono extrakcia sa zhoršuje,
keď v projekte chýba `node_modules`, a nástroj to prizná
poctivými znakmi namiesto tichých preskočení. My sme v tej situácii
píšali test, ktorý to vyhadzuje.

Naše plány sú skromnejšie a zoznam je dlhý. V modeli je osem
modulov: users, blog, comments, audit, pages, themes, design a forms.
Mimo neho stojí media, settings, locales, outbox s UnitOfWork,
security a jadro. Tri z nich majú nárok na model skôr než neskôr,
lebo každý z nich už raz prelomil pravidlo, ktoré sme si mysleli,
že platí navždy.

A potom otázky čitateľa. Keď host položí otázku, na ktorú článok
nevie odpovedať, táto oblasť sa zaplní sama. Model potrebuje ešte
jednu definíciu: subjekt otázky je dvojica, nie slug. Rovnaké poučenie
ako pri jazykoch.

## Téma

Nástroj, ktorý sa zmenil, a dokument, ktorý zostal, sú dve rôzne
vedomosti. Prvá sa píše rýchlo a číta ťažko. Druhá sa píše pomaly a
číta sa ľahko. Tá druhá je tá, na ktorej stoja ľudia.

## Zdroje

- [Lekalo](https://github.com/ichinya/lekalo) — repozitár produktu (release v0.6.4 z 1. októbra 2026, `lekalo compatibility` je register kontraktov)
- [Release v0.6.4](https://github.com/ichinya/lekalo/releases/tag/v0.6.4) — čo pribudlo: Node/TypeScript bindingy, lokálna história behov, brownfield pilotáž, nálezy F1 až F7
- [docs/lekalo-model.md](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md) — náš upgrade record, vrátane pasce, keď `lekalo lock` odmietne lock, ktorý má nahradiť
- [lekalo/modules](https://github.com/evan70/nativa-minimal/tree/master/lekalo/modules) — osem modulov, ktoré model dnes pozná, a zoznam tých, ktoré v ňom ešte nie sú
- [Diel 10.2 — upgrade cez tri brány](/blog/lekalo-upgrade-pre-deti) — predchádzajúci upgrade, pri ktorom sa naozaj niečo zmenilo
- [Diel 16 — komentáre ako posledná reťaz](/blog/lekalo-comments-pre-deti) — kam táto rada smeruje