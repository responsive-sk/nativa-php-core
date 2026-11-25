---
title: "Zákony majú jeden týždeň ... archeológia kontraktov"
date: "2026-09-17"
version: "1.0.2"
tags: ["git", "archaeology", "testing", "architektura", "workflow"]
excerpt: "Pickaxe (`git log -S`) nad tests/ a bin/ vykopal korene všetkých kontraktov tohto repa — a našiel vzor: prax bežala od augusta, zákony prišli v jednom týždni v septembri. Kontrakt bez praxe je vzduch, prax bez kontraktu je ticho. Plus dve falošné stopy, ktoré ukázali, že aj grep potrebuje typ."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "laws-have-one-week"
published: true
---

Vykopal som históriu vlastného repozitára. Nie celú — len korene
zákonov, ktoré dnes strážia CI.

Nástroj: jednorazový skript s pickaxe (`git log -S`), ktorý hľadá
commity meniace počet výskytov reťazca. Otázka znela: *kedy
vstúpil do histórie každý kontrakt, ktorý ma dnes niečo zakazuje?*

Odpoveď nesúvisela s tým, čo som čakal.

## Dva dni, ktoré stoja za pozretie

Korene kódových kontraktov v `tests/` a `bin/`:

| kontrakt | koreň | príbeh |
|---|---|---|
| `trait FindsIndexPage` | 09-07 | paginácia indexu, Chrome 70 fallbacky |
| `STATE_DIR` + `Never mtimes` | 09-16 | revision report — agent-state sa rodí |
| `const GATES` | 09-16 | gate runner so záznamom zlyhaní |
| `CoverDeterminismTest` | 09-16 | CoverGenerator v2 |
| `CoverPinPolicyTest` | 09-17 | diel 13, fallback ladder |
| `BlogVoiceTest` | 09-17 | hlasová zmluva v CI |

Pozri sa na tie dátumy. Traja zákonodarcovia za tri dni.

09-16 je deň, keď sa narodil agent-state — tri kontrakty v jednom
dni. 09-17 je deň hlasovej zmluvy — ďalšie tri.

A najstarší kontrakt celej vrstvy, paginačný trait? Deväť dní starší.

## Prax bežala. Zákony prišli neskôr

Blog korpus existuje od augusta. Články sa písali, prekladali,
publikovali ... celý mesiac bez jediného z týchto zákonov.

A nič sa nerozpadlo.

Pretože kontrakt bez praxe je vzduch. Nemal by som, čím zakázať emoji,
keby tabuľky neobsahovali prvé emoji. Nemal by som, čím strážiť
unikátnosť `series-part`, keby kolízia 8/9 nenastala. Krehký
page-one predpoklad by zostal zdravou domienkou, keby korpus rástol
pomaly.

Prax ich najprv živila. Potom ich spísala.

Toto nie je obžaloba pravidiel ... je to ich genealógia. Každý dobrý
test v tomto repe má v rodnom liste škodlivú udalosť. Zmluva, ktorej
nevládneš povedať, čo má zabrániť, nechráni nič.

## Ale pozor — prax bez zákona mlčí

Druhá polovica vzoru je ostrejšia.

Všetko to bežalo aj bez zákonov. Paginácia fungovala, obálky sa
generovali, brány bežali. Nikto nezlyhal. Len nikto nemohol povedať,
*prečo* fungovali.

Bez `FindsIndexPage` by dnes každý nový index test opakoval rovnakú
chybu — „karta je na stránke 1“, písané s odhodlaním a naivitou.

Bez záznamov brán by sme všetky zlyhania opakovali naslepo.

Bez pickaxe ukotvenia by história hovorila šum. Prax bez zákona
funguje — pokým sa nezmení svet okolo nej. Potom mlčí presne v deň,
keď potrebuješ, aby kričala.

Zákon je spomienka na škodlivú udalosť ... napísaná tak, aby sa nedala
zabudnúť.

## Dve falošné stopy

Samotný výkop stál dve opravy — a obe sú lekcia o type.

Prvý vzor bol `class FindsIndexPage`. Krompáč mlčal. Trait sa
deklaruje `trait`, nie `class` — hľadal som zlé slovo na správnom
mieste.

Druhý vzor bol `never mtimes`. Opäť ticho. V docblocke stojí `Never`
s veľkým písmenom — začiatok vety.

Dva nulové výsledky, dva dôvody ... a obe rovnakého typu: vzor bez
ukotvenia. Rovnaká pasca, ktorú `^version:` spravil archeológii
front matter — voľný reťazec číta šum a ukotvený číta pravdu. Aj grep
potrebuje deklarovaný typ.

## Kedy to neplatí (a povinná úprimnosť)

Buďme úprimní: jeden týždeň nie je vzor. Je to zhluk.

Tri kontrakty v jednom dni neznamenajú, že zákony majú prichádzať
dávkovo. Znamenajú, že ich spisovanie má za sebou mesiac praxe —
a že som ho spísal vtedy, keď som mal ruky voľné. Ďalší kontrakt
príde, keď sa vyskytne ďalšia škoda. Možno o mesiac. Možno zajtra.

Druhá úprimnosť: táto archeológia je nástroj pre človeka, nie pre
CI. Beží jednorazovo, z cache, za 0,3 sekundy a 12 git volaní. Nikto
ju nepustí na každom pushi — to by bolo minovanie bez rudy.

## Prakticky

1. **Otvor si rodný list svojich testov** ... `git log -S
   'class NazovTestu' -- tests/` — ak kontrakt nevie povedať, pred
   čím chráni, možno nič nechráni.
2. **Ukotvi vzor skôr, než kopeš** ... `^`, celé slovo, presný
   reťazec. Nulový výsledok krompáča je informácia, nie prázdnota.
3. **Čakaj, až prax prinesie škodu** ... najistejší test je reakcia na
   reálne zlyhanie. Predvídavé zákony sú predpovede — a predpovede
   bývajú vzduch.

Zákony potom nerastú z neba ... rastú z git histórie.

## Zdroje

- [Git — pickaxe, -S](https://git-scm.com/docs/git-log) ... hľadanie zmien počtu výskytov v histórii
- [Henrik Kniberg — feature archaeology](https://martinfowler.com/articles/feature-creep.html) ... o rozdieli medzi praktikou a kontraktom
- [Nativa Minimal Documentation](/docs/getting-started) ... getting started guide tohto kitu
- [docs/blog-voice.md](/docs/blog-voice) ... príručka hlasu, ktorej mechanickú polovicu stráži test
