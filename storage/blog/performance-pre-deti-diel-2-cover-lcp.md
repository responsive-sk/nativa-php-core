---
title: "Performance pre deti ... diel 2: LCP na článkovej stránke a textové LCP elementy"
date: "2026-08-13"
version: "1.0.1"
tags: ["performance", "lcp", "fetchpriority", "lighthouse", "php"]
excerpt: "Diel 2: na indexe sme opravili LCP karty, ale článková stránka mala svoj vlastný problém ... cover obrázok bez fetchpriority=high. Ukážeme si, ako sa LCP líši od stránky k stránke, čo je textový LCP element a prečo jeden vzor nestačí."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "article-cover-lcp"
published: true
---

> Toto je diel 2 série **Performance pre deti**. V [dieli 1](/blog/performance-pre-deti)
> sme si ukázali, čo je LCP, prečo LCP obrázok nesmie byť lazy a ako `srcset`
> + `sizes` ušetria polovicu bajtov na blogových kartách. Dnes sa pozrieme na
> **článkovú stránku** ... lebo LCP nie je raz a navždy, mení sa od stránky k
> stránke. A ukážeme si aj to, čo robiť, keď LCP vôbec nie je obrázok.

V [dieli 1](/blog/performance-pre-deti) sme opravili LCP obrázky na indexe. Teraz sa pozrieme na článkovú stránku, a tam je každá stránka iná.

## Každá stránka má vlastný LCP


LCP nie je vlastnosť webu ... je to vlastnosť **jednej konkrétnej stránky**.
To, čo je LCP na indexe (prvá karta), nemusí byť LCP na článkovej stránke.
Tam je najväčším kusom obsahu **cover obrázok** ... 720 × 360 px hore nad
titulkom. A keď sme naň spustili Lighthouse, našli sme ďalší zádrhel.

## Cover mal eager, ale nie fetchpriority

Cover na článkovej stránke mal `loading="eager"` ... takže sa nesťahoval
lazy, to bolo v poriadku. Ale chýbalo mu **`fetchpriority="high"`**.
Lighthouse `lcp-discovery-insight` ukázal presne toto:

```
priorityHinted:  fetchpriority=high should be applied  → FALSE
requestDiscoverable: Request is discoverable in initial document → TRUE
eagerlyLoaded:   LCP resources should not use loading=lazy → TRUE
```

Z troch položiek checklistu dve prešli, ale tá najdôležitejšia nie. Prečo
`fetchpriority` záleží, keď už je obrázok eager? Pretože eager hovorí
„stiahni hneď“, ale nič nehovorí o **poradí**. Na článkovej stránke
súťaží cover s CSS, fontami a ďalšími zdrojmi o sieť ... a `fetchpriority`
mu dáva VIP vstup: „tento zdroj je dôležitejší než ostatné, stiahni ho
prednostne“.

Oprava bola jeden riadok v `BlogArticleAction`:

```php
// The cover is the article page's LCP candidate ... eager with
// fetchpriority=high so it loads first (same pattern as the card
// grid and the homepage hero).
return '<img class="st-article__cover" src="' . FrontendLayout::e($image)
    . '" alt="' . FrontendLayout::e($meta['title'] ?? $slug) . '" width="720" height="360"'
    . ' loading="eager" fetchpriority="high" decoding="async">';
```

A výsledok po re-rune:

| Audit | Pred | Po |
|---|---|---|
| `lcp-discovery-insight` | FAIL (priorityHinted) | **PASS** |
| Performance | 100 | **100** |
| LCP | 0,6 s | **0,6 s** |

LCP čas sa nezmenil ... stránka už bola rýchla. Ale audit, ktorý meria, či
sa LCP zdroj načítava správne, prestal hnevať. A to je dôležité:
**Lighthouse meria nielen to, ako rýchlo sa stránka načíta, ale aj to,
či sa zdroje správne priorizujú.**

## Prečo cover nemá srcset

V [dieli 1](/blog/performance-pre-deti) sme dali srcset kartám, lebo sa
zobrazujú na 330-px karte, ale sťahovali sa v 720-px veľkosti. Cover na
článkovej stránke taký problém nemá ... jeho CSS je `width: 100%` a zobrazí
sa v podstate v plnej šírke článku (~768 px na desktope), takže 720-px
verzia je presne tá správna. Žiadne zbytočné bajty, žiadny srcset.

To je dôležitá lekcia: **srcset nie je povinná výbava každého obrázka.**
Je to riešenie na konkrétny problém ... „obrázok sa zobrazuje menší, než sa
sťahuje“. Keď sa zobrazuje v natívnej veľkosti, srcset len pridáva hluk.
Rovnako SVG karty srcset nemajú ... vektor sa škáluje ľubovoľne.

## Textový LCP element

Zatiaľ sme riešili LCP obrázky. Ale LCP nemusí byť obrázok vôbec. Keď má
stránka nad obrázkom veľký nadpis, LCP môže byť **text** ... `<h1>` alebo
`<p>`. Lighthouse si vyberie najväčší vykreslený prvok, nezáleží na tom,
či je to obrázok alebo text.

Pre textový LCP platia iné pravidlá:

1. **Text sa nedá lazy-loadovať** ... žiadny `loading`, žiadny
   `fetchpriority`. Text je v HTML hneď, takže jeho LCP je priamo úmerné
   tomu, ako rýchlo príde **HTML** (TTFB) a ako rýchlo sa vykreslí CSS.
2. **Žiadny web font v ceste** ... ak je nadpis nastavený fontom, ktorý sa
   sťahuje, prehliadač môže čakať na font (FOIT) a text sa vykreslí
   neskoro. Riešenia: `font-display: swap` alebo systémové fonty.
3. **Rendering-blocking CSS** ... čím menej CSS blokuje vykreslenie, tým
   skôr sa text objaví. Kritický CSS inline je presne na to.

V tomto repo je LCP na článkovej stránke cover obrázok, takže textové
pravidlá tu nie sú kritické ... ale vedieť o nich je polovica úspechu. Keď
raz pridáš stránku bez coveru (napríklad chybovú), LCP sa presunie na
text a zrazu bude záležať na TTFB a fontoch.

## Pravidlo bez testu sa nedodržiava

Ako vždy v tejto sérii ... zmluvu podpisujeme testom. Do `BlogCoverTest`
pribudol contract test, ktorý zlyhá, keby niekto coveru zobral
`fetchpriority`:

```php
// The article page cover is its LCP candidate ... eager + fetchpriority
// =high, discoverable in the initial document (same LCP pattern as
// the card grid and homepage hero).
self::assertStringContainsString('loading="eager" fetchpriority="high" decoding="async"', $html);
```

Teraz máme **tri miesta s tým istým vzorom** ... karty na indexe, hero na
homepage a cover na článkovej stránke ... a každé z nich má test, ktorý
stráži, že LCP zdroj sa načítava správne.

## Čo si z toho odniesť

1. **LCP je per-stránka** ... čo je LCP na indexe, nemusí byť LCP na
   článku. Meraj každú šablónu zvlášť.
2. **`eager` a `fetchpriority` sú dve rôzne veci** ... eager hovorí „kedy“,
   fetchpriority hovorí „v akom poradí“. LCP zdroj potrebuje oboje.
3. **srcset nie je pre každého** ... len pre obrázky, ktoré sa zobrazujú
   menšie, než sa sťahujú.
4. **LCP môže byť text** ... vtedy záleží na TTFB, fontoch a
   rendering-blocking CSS, nie na obrázkových atribútoch.
5. **Jeden vzor, tri miesta, tri testy** ... zdieľaná logika je super, ale
   každé použitie si zaslúži vlastnú zmluvu.

V ďalšom dieli si ukážeme, ako čítať Lighthouse report ako detektív ...
ktoré audity sú „skórové“ a ktoré len odporúčania, a prečo sa niekedy
oplatí ignorovať „Est savings“.


→ [Performance diel 1](/blog/performance-pre-deti): LCP, srcset a prečo sa obrázky nesťahujú zbytočne veľké
→ [Performance diel 3](/blog/performance-pre-deti-diel-3-lighthouse-detektiv): čítaj Lighthouse report ako detektív
→ [Clean code pre deti](/blog/co-je-cisty-kod-pre-deti): ako písať kód, ktorý sa číta sám

## Zdroje

- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
- [Web.dev, Performance](https://web.dev/performance): Core Web Vitals
- [Lighthouse Documentation](https://developer.chrome.com/docs/lighthouse/): Lighthouse nástroj
