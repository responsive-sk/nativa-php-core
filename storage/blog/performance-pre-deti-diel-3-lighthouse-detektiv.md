---
title: "Performance pre deti ... diel 3: čítaj Lighthouse report ako detektív"
date: "2026-08-13"
version: "1.0.2"
tags: ["performance", "lighthouse", "lcp", "mobile", "desktop"]
excerpt: "Diel 3: Lighthouse nie je orákulum ... je to detektív, ktorému občas ujde kontext. Ukážeme si, ktoré audity ovplyvňujú skóre a ktoré sú len odporúčania, prečo nám mobile audit tvrdil 63 KiB úsporu, ktorá neexistovala, a ako čítať report tak, aby si neopravoval veci, ktoré nie sú rozbité."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "reading-lighthouse-report"
published: true
---

> Toto je diel 3 série **Performance pre deti**. V [dieli 1](/blog/performance-pre-deti)
> sme opravili LCP na indexe, v [dieli 2](/blog/performance-pre-deti-diel-2-cover-lcp)
> na článkovej stránke. Obidvakrát nám cestu ukázal Lighthouse. Dnes si
> ukážeme, ako ho čítať **kriticky** ... lebo Lighthouse nie je orákulum,
> je to detektív, ktorému občas ujde kontext. A ak mu neuveríš slepo,
> neopravíš veci, ktoré nie sú rozbité.

## Skóre, ktoré ťa oklame

Lighthouse report otvoríš a prvé, čo vidíš, je veľké číslo ... Performance
97, 100, 82… Vyzerá to ako známka z testu. Ale nie je. Skóre je vážený
priemer niekoľkých **skórových auditov** (LCP, CLS, TBT, FCP, SI),
a každý z nich sa meria v umelom, spomalenom prostredí. To znamená dve veci:

1. **Skóre sa mení run od runu** ... aj bez zmeny kódu. Spomalená sieť, výkon stroja, teplota… Lighthouse je laboratórne meranie, nie realita používateľa. Rozdiel 1–2 body medzi dvoma runmi je šum, nie regresia.
2. **Skóre meria jednu vec: laboratórny priemer.** Skutočný používateľ na rýchlom mobile s dobrou sieťou zažije úplne iné čísla. Preto pravidlo číslo jedna: **porovnávaj rovnaký preset s rovnakým
presetom.** Desktop a mobile run majú iné emulované podmienky (mobile má
spomalenú sieť 4× a DPR 2,625), takže „desktop 100, mobile 99“ nie je
žiadny rozpor ... je to iný svet.

## Skórový audit vs. insight

Tu je miesto, kde väčšina ľudí robí chybu. Lighthouse 13 má dva druhy
auditov:

- **Skórové audity** ... LCP, CLS, TBT, FCP, Speed Index. Majú váhu v Performance skóre. Keď jeden zlyhá, skóre klesne.
- **Insight audity** („LCP request discovery“, „Improve image delivery“) ... diagnostika. **Neovplyvňujú skóre.** Sú to odporúčania detektíva: „tu by sa dalo niečo zlepšiť“. To je presne to, čo sme videli v [dieli 1](/blog/performance-pre-deti).
Insight „LCP request discovery“ ukázal `loading="lazy"` na prvej karte.
Opravili sme to ... a hoci insight sám skóre nemení, **oprava skutočného
problému** (lazy LCP obrázok) zlepšila aj skórový LCP. Insighty často
vedú k veciam, ktoré skóre ovplyvnia ... ale nie vždy. A to je ten trik:
treba vedieť rozlíšiť.

## Prípad: úspora, ktorá neexistovala

V [dieli 1](/blog/performance-pre-deti) sme narazili na príklad, ktorý ukazuje.
Po oprave srcset na kartách sme spustili Lighthouse na mobile a
`image-delivery-insight` hlásil:

```
Est savings of 63 KiB
This image file is larger than it needs to be (720x360) for its
displayed dimensions (362x181). Use responsive images.
```

Znelo to ako jasná výčitka: „stále sťahuješ príliš veľké obrázky!“ Ale
keď sme sa pozreli na detaily, našli sme háčik:

- Lighthouse mobile emuluje **DPR 2,625** (retina).
- Karta sa zobrazuje na 362 CSS px, ale retina potrebuje ~950 fyzických pixelov.
- Z našich srcset kandidátov (360/634/720w) browser správne vybral **720w** ... najbližší k pokrytiu potreby.
- Lighthouse ale počítal úsporu, akoby stačila 360-px verzia. Inými slovami: **audit tvrdil, že sťahujeme príliš veľa, ale browser
sťahoval presne to, čo mal.** Keby sme auditu slepo uverili a zmenšili
obrázky, retina mobile by dostal rozmazané cover-y. A keby sme pridali
ešte väčší variant (napr. 1080w), mobile by začal sťahovať **viac**,
nie menej ... LCP by sa zhoršil, aby sa „úspora“ páčila auditu.

## Ako detektív overí podozrenie

Keď Lighthouse niečo tvrdí, neprijímaj to ako rozsudok. Over si to:

1. **Čo presne audit hovorí?** Otvor detaily ... ktorý obrázok, aká veľkosť, aká „úspora“.
2. **Aká je realita?** V DevTools (Network tab) sa pozri, ktorý variant browser **naozaj** stiahol. Ak stiahol 360w na desktope, srcset funguje. Ak 720w na mobile DPR 2,625, tiež funguje ... audit len nepozná DPR.
3. **Opravuješ skutočný problém?** Oprava má dávať zmysel bez ohľadu na audit. „LCP obrázok je lazy“ je skutočný problém. „Stiahni menší obrázok, aj keď ho retina potrebuje väčší“ nie je. Presne takto sme postupovali pri `image-delivery-insight` na mobile:
overili sme sieťové requesty, zistili sme, že browser si vybral správny
variant, a **nechali sme to tak**. Desktop run (kde DPR je 1) medzitým
ukázal, že srcset funguje presne: browser stiahol 360w namiesto 720w,
a `image-delivery-insight` prešiel.

## Nástroje detektíva

- **Network tab** ... ktoré requesty naozaj odišli a v akej veľkosti.
- **Lighthouse JSON výstup** (`--output=json`) ... `network-requests` audit ukáže presné URL a veľkosti; `lcp-breakdown-insight` ukáže LCP element a fázové časy (TTFB, load delay, load duration, render delay).
- **Rovnaký URL, rôzne presety** ... desktop vs mobile porovnanie odhalí, či je problém univerzálny alebo viewport-špecifický.
- **Pred/po meranie** ... bez „pred“ čísel nemáš dôkaz, že si niečo zlepšil. (V [dieli 2](/blog/performance-pre-deti-diel-2-cover-lcp) sme si ukázali tabuľku Pred/Po presne preto.)

## Kedy sa auditu oplatí veriť

Pravidlo nie je „nikdy never“ ... je to „**ver, ale over**“. Existujú
audity, ktorým môžeš veriť prakticky vždy:

- **`lcp-discovery-insight`** ... LCP zdroj má byť eager + fetchpriority. Toto je jednoznačné pravidlo, bez výnimiek (a v tomto repo ho strážia testy ... [diel 1](/blog/performance-pre-deti), [diel 2](/blog/performance-pre-deti-diel-2-cover-lcp)).
- **`image-size-responsive`** ... obrázok väčší než zobrazenie na **desktope** (DPR 1) je reálna strata.
- **`total-byte-weight`**, **render-blocking resources** ... univerzálne pravdy.

A existujú audity, ktoré treba brať s rezervou:

- **`image-delivery-insight` na mobile** ... DPR výpočet ignoruje hustotu pixelov (naša 63 KiB „úspora“).
- **Akýkoľvek „Est savings“** ... je to odhad zjednodušeného modelu, nie faktúra.

## Pravidlo bez testu sa nedodržiava

Ako v celej sérii: to, čo sme sa naučili, sme podpísali testom. V
`BlogIndexTest`, `BlogCoverTest`, `BlogSearchTest` a `AuthorProfileTest`
žijú contract testy, ktoré strážia, že LCP zdroj má eager +
fetchpriority a Cloudinary cover-y nesú srcset. Lighthouse môže klamat
o úsporách ... testy nie.

## Čo si z toho odniesť

1. **Skóre nie je známka** ... je to laboratórny priemer, ktorý sa mení run od runu. Porovnávaj rovnaký preset s rovnakým presetom.
2. **Insight ≠ skóre** ... „LCP request discovery“ skóre nemení, ale často vedie k oprave, ktorá ho zmení.
3. **„Est savings“ je odhad, nie faktúra** ... over v Network tab, čo browser naozaj stiahol.
4. **Mobile audit nepozná DPR** ... 720w na retina mobile nie je prebytok, je to potreba.
5. **Ver, ale over** ... oprava musí dávať zmysel aj bez auditu. A keď dáva, podpíš ju testom. V ďalšom dieli si ukážeme, ako vyzerá LCP na stránkach bez obrázkov ...
a čo sa stane, keď LCP element je text.

→ [Performance diel 1](/blog/performance-pre-deti) — LCP, srcset a prečo sa obrázky nesťahujú zbytočne veľké
→ [Performance diel 2](/blog/performance-pre-deti-diel-2-cover-lcp) — LCP na článkovej stránke
→ [Performance diel 4](/blog/performance-pre-deti-diel-4-textovy-lcp) — keď LCP nie je obrázok

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
- [Web.dev — Performance](https://web.dev/performance) — Core Web Vitals
- [Lighthouse Documentation](https://developer.chrome.com/docs/lighthouse/) — Lighthouse nástroj
