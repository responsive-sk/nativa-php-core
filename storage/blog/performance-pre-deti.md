---
title: "Performance pre deti ... diel 1: LCP, srcset a prečo sa obrázky nesťahujú zbytočne veľké"
date: "2026-08-13"
version: "1.0.2"
tags: ["performance", "lcp", "srcset", "lighthouse", "php"]
excerpt: "Nová séria o výkone webu. Diel 1 vysvetlí, čo je LCP, prečo loading=lazy na najdôležitejšom obrázku je zrada, čo robí fetchpriority=high a srcset ... na reálnych meraniach z tohto repo (desktop LCP 1,2 s → 0,8 s, obrázky o polovicu menšie)."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lcp-srcset-basics"
published: true
---

> Toto je diel 1 novej série **Performance pre deti**. V sérii si ukážeme,
> čo robí web rýchlym ... a prečo to toto repo **meria a vynucuje testami**,
> nie odporúčaniami. Dnes začneme od úplného základu: **prečo je prvý
> obrázok na stránke taký dôležitý** a ako ho prehliadaču podať tak, aby
> sa nenačítaval zbytočne veľký.

Keď otvoríte blog, prvá vec, ktorú vidíte, je obrázok. Ak sa načíta pomaly, čitateľ odíde. Ak sa načíta rýchlo, zostane. LCP — Largest Contentful Paint — je metrika, ktorá meria, ako rýchlo sa zobrazí prvý veľký obsah.

## Čo je to LCP

Predstav si, že otvoríš stránku. Čo vidíš ako prvé? Titulok, pár slov…
a nejaký obrázok. Ten **najväčší kus obsahu, ktorý sa vykreslí v prvom
zábere**, sa volá LCP ... *largest contentful paint*. Prehliadač ho meria
a Google ho považuje za jeden z hlavných signálov toho, ako rýchlo sa
stránka „cíti“.

Pointa: kým sa LCP nevykreslí, stránka sa ti zdá prázdna. Nie je to
o celkovom načítaní všetkých 20 obrázkov ... je to o **tom jednom**, ktorý
vidíš hneď. Preto si výkonnostné audity (Lighthouse, PageSpeed Insights)
vyberú presne jeden element a hovoria: „tento je LCP, a s ním máš
problém“.

## loading=„lazy“ je pre LCP zrada

Keď má obrázok `loading="lazy"`, prehliadač ho stiahne až vtedy, keď sa
k nemu používateľ priblíži (scrollne). To je skvelé pre obrázky pod
čiarou ponoru ... prečo sťahovať dvadsiaty obrázok, keď ho nikdy neuvidí?
Ale pre **LCP obrázok je to katastrofa**: čakáš na najdôležitejší kus
obsahu a prehliadač ho zámerne odkladá.

V tomto repo sa to stalo na blogu. Karty článkov mali všetky
`loading="lazy"` ... a Lighthouse ukázal presne toto:

```
LCP request discovery ... Optimize LCP by making the LCP image
discoverable from the HTML immediately, and avoiding lazy-loading
```

Oprava bola jednoduchá: LCP kandidát dostal `loading="eager"` (sťahuj
hneď) plus `fetchpriority="high"` (a sťahuj ho prednostne):

```html
<img loading="eager" fetchpriority="high" decoding="async"
     width="720" height="360">
```

## Jeden obrázok? Alebo prvý rad?

Tu prichádza prvý zádrheľ. Na mobile má blog jeden stĺpec ... LCP kandidát
je jednoznačne prvá karta. Ale na desktope má blog **tri stĺpce** a prvé
tri karty sú v jednom rade, všetky rovnako veľké (329 × 165 px). A LCP
vyhrá tá, ktorá sa vykreslí posledná ... čo sa nedá dopredu vedieť.

Dali sme teda `eager` + `fetchpriority="high"` celému prvému radu, nie
len prvej karte. V kóde to vyzerá takto (`BlogArticlesService`):

```php
// The first grid row (3 cards on desktop) holds the LCP candidate ...
// all its images are the same size, so the LCP winner is whichever
// paints last. Eager + fetchpriority=high for the whole row.
$cards .= $this->renderCard($article, $index < self::FIRST_ROW_CARDS);
```

Druhý a tretí obrázok na mobile sú pod čiarou ponoru ... sťahujú sa o
trochu skôr, než by museli. To je cena ~25 KiB, ktorú platíme za to, že
heuristika ostane jednoduchá a na desktope vždy trafí LCP.

## srcset ... neposielaj 720, keď treba 330

Druhý problém bol ešte zákerný. Karta na desktope má šírku ~330 px,
ale obrázok sa sťahoval v plnej veľkosti 720 × 360. Lighthouse na to
ukázal úplne presne:

```
This image file is larger than it needs to be (720x360) for its
displayed dimensions (634x317). Use responsive images to reduce the
image download size.
```

Sťahovať 26 KiB, keď stačí 12 KiB, je ako nosiť celý kufrík náradia,
keď potrebuješ jeden skrutkovač. Riešenie sa volá `srcset` ... ponúkneš
prehliadaču **niekoľko veľkostí** a on si vyberie tú, ktorá sedí na jeho
obrazovku (a na hustotu pixelov):

```html
<img src=".../w_720,h_360.webp"
     srcset=".../w_360,h_180.webp 360w,
             .../w_634,h_317.webp 634w,
             .../w_720,h_360.webp 720w"
     sizes="(min-width: 1080px) 330px, (min-width: 640px) 520px, 100vw">
```

- `360w` / `634w` / `720w` ... tri fyzické šírky obrázka, ktoré Cloudinary
  vygeneruje na počkanie (crop `c_fill,w_360,h_180`).
- `sizes` ... poviem prehliadaču, ako široký bude obrázok v každom
  rozložení, aby vedel, ktorý variant vybrať.

## Prečo nestačí len srcset

`srcset` bez `sizes` je polovičaté ... prehliadač by hádal, že obrázok
zaberá 100 % šírky obrazovky, a na mobile by siahol po najväčšom
variante. `sizes` mu dáva kontext: „na veľkých obrazovkách bude mať
karta 330 px“. A `width`/`height` (720 × 360) držia miesto v rozložení
dopredu, takže stránka nepreskakuje, keď sa obrázok načíta.

Pri vektorových SVG obrázkoch je srcset zbytočný ... vektor je
rozlíšením nezávislý, škáluje sa ľubovoľne. Preto ich kód cielene
vynecháva:

```php
// Local SVG covers are vector and resolution-independent ... no variants.
if (!str_contains($image, 'res.cloudinary.com') || !str_contains($image, 'w_720,h_360')) {
    return '';
}
```

## Čo sme namerali

Optimalizáciu sme overili Lighthouse, nie dojmom. Desktop pred a po:

| Metrika | Pred | Po |
|---|---|---|
| Performance | 97 | **100** |
| LCP | 1,2 s | **0,8 s** |
| Prvá karta | 25,6 KiB | **12,3 KiB** |
| image-delivery | FAIL | **PASS** |

Druhá karta sa sťahuje v `w_360` crope namiesto `w_720` ... o polovicu
menší súbor, a na desktope to ani nespoznáš, lebo na 330-px kartu je
360 px presne dosť. (Na mobile s retina displejom prehliadač správne
siahol po `w_720` ... tam je potreba ~950 px.)

## Pravidlo bez testu sa nedodržiava

Ako v celej tejto sérii: **to, čo sa dá zmerať a otestovať, sa
neponecháva na dobré úmysly.** V `tests/Integration/BlogIndexTest.php`
je test, ktorý zlyhá, keby niekto pridal srcset len na prvú kartu
alebo zabudol na `fetchpriority`:

```php
// The eager images are exactly the first row (3 cards).
self::assertSame(3, substr_count($html, 'loading="eager"'));
self::assertStringContainsString('loading="eager" fetchpriority="high" decoding="async"', $html);
```

A `BlogCoverTest` stráži, že SVG karty srcset nenesú. Rovnaký vzor
pokrývajú aj search výsledky a autorské profily ... všetky totiž
renderujú cez ten istý zdieľaný `renderCards()`, takže oprava na
jednom mieste platí všade.

## Čo si z toho odniesť

1. **LCP je jeden element** ... ten najväčší kus obsahu v prvom zábere.
2. **LCP obrázok nikdy nesmie byť lazy** ... `loading="eager"` +
   `fetchpriority="high"`, a ak si na desktope neistý, ktorý z radu
   vyhrá, daj eager celému prvému radu.
3. **Neposielaj 720, keď treba 330** ... `srcset` + `sizes` nechajú
   prehliadač vybrať správnu veľkosť a ušetria polovicu bajtov.
4. **Meraj, netipuj** ... Lighthouse ti povie presne, ktorý obrázok
   a koľko sťahuje zbytočne.
5. **Zmluvu podpíš testom** ... inak sa o pol roka niekto „vylepšením“
   vráti k lazy LCP.

V ďalšom dieli si ukážeme, ako funguje LCP na článkovej stránke
a čo robiť, keď LCP element nie je obrázok, ale text.


→ [Performance diel 2](/blog/performance-pre-deti-diel-2-cover-lcp) — LCP na článkovej stránke
→ [Performance diel 3](/blog/performance-pre-deti-diel-3-lighthouse-detektiv) — čítaj Lighthouse report ako detektív
→ [Clean code pre deti](/blog/co-je-cisty-kod-pre-deti) — ako písať kód, ktorý sa číta sám

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
- [Web.dev — Performance](https://web.dev/performance) — Core Web Vitals
- [Lighthouse Documentation](https://developer.chrome.com/docs/lighthouse/) — Lighthouse nástroj
