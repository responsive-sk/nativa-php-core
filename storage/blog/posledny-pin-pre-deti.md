---
title: "Rust a Lekalo pre deti ... diel 14: posledný pin"
series: "Rust & Lekalo"
series-part: "14"
date: "2026-09-20"
version: "1.0.2"
published: true
tags: ["rust", "lekalo", "determinizmus", "kontrakty", "blog"]
excerpt: "Sto dvadsaťpät pool pinov odišlo a test si ich zákaz zapamätal. Jeden pin ostal — legálny upload mimo poolu. Príbeh o poslednej výnimke, ktorá sa vzdala sama, a o teste, ktorý si ju už nikdy nenechá."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "the-last-pin"
---

> [Diel 12](/blog/obalky-ako-kontrakt-pre-deti) urobil z obálky kontrakt
> a [diel 13](/blog/rebrick-zlyhania-pre-deti) postavil rebrík zlyhaní.
> Dnes diel 14 — o pine, ktorý rebrík obchádzal legálne, a o migrácii,
> ktorá mu vzala výhovorku.

Máš pred sebou starý článok a riadok `image:` vo front matter. Obrázok
sa zobrazuje, karta vyzerá ako každá iná, nič nie je zlé.

A presne to je chyták. Zlé veci si všimneš sám — legálne výnimky ti
podá kolega s úsmevom a rád si ich necháš.

## Sto dvadsaťpät odišlo, jeden ostal

Éra poolu je v tomto korpuse uzavretá. Štyri zamrznuté URL, rozdelenie
63/30/22/10, výstup starého resolvera natvrdo v kóde — o tom boli
predchádzajúce dva diely. Piny odišli postupne, cez obsahové pasáže,
a test si ich zákaz zapamätal.

Dnes ten istý prieskum vráti jeden súbor:

```bash
grep -l '^image:' storage/blog/*/*.md
storage/blog/sk/hromadne-mazanie-dry-pasca.md
```

Jeden riadok v celom korpuse. A nie je to pool URL:

```yaml
// storage/blog/sk/hromadne-mazanie-dry-pasca.md — front matter
image: "https://res.cloudinary.com/epithemic/image/upload/nativa-media/ne3ulmsddkg1dwb6cj8s"
```

Pool žije v priečinku `blog/` a nesie transformačné parametre —
`c_fill,w_720,h_360,f_webp,q_auto`. Tento pin smeruje do `nativa-media/`
a nemá nič. Je to reálny upload — screenshot administračnej tabuľky,
ktorý si autor vybral ručne.

Staré pravidlo také niečo povoľovalo: „jediný povolený cloudinary pin je
reálny upload mimo priečinka poolu“. Tento pin bol presne to. Legálny.

## Čo pin obchádza

Rebrík `resolveCover()` má tri stupne. Dva sa správajú ako kontrakt,
jeden ako výnimka:

| Stupeň rebríka | Čo vráti | `?v=` cache-buster |
|---|---|---|
| 1. `image:` pin | presná URL z front matter | nie |
| 2. lokálne SVG | `/storage/blog-images/{slug}.svg` + hash | áno |
| 3. cloudinary pool | 1 z 4 zamrznutých URL | nie |

Hash v druhom stupni je prvých osem hex znakov md5 zo súboru. Zmeníš
SVG — zmení sa URL — prehliadač nepodáva rok staré bajty z immutable
cache. To je celý mechanizmus.

Pin tento mechanizmus obchádza. Nie preto, že by bol zlý — preto, že
regenerácia mení súbory, nie riadky vo front matter. URL pina je
nemenná ako text článku. A text článku nie je to, čo sa generuje.

## SVG už existuje

Tu je vec, ktorá celú migráciu robiť skoro zábavnou: obálka pre tento
článok už dávno leží na disku. Vznikla pri hromadnej regenerácii —
rovnaká, ako má každý iný článok.

Spustil som generátor ešte pred zmazaním pinu a porovnal bajty:

```bash
php bin/cms generate-images --slug hromadne-mazanie-dry-pasca
git status --short storage/blog-images/hromadne-mazanie-dry-pasca.svg
# prázdny výstup — byte-identická regenerácia
```

Determinizmus nie je teória. Rovnaký článok, rovnaké SVG, nula rozdielov.

Pin teda nezastieral chýbajúcu obálku. Zastieral hotovú — a `?v=` hash,
ktorý by ju udržal čerstvú v prehliadačoch.

## Migrácia — jedna linka

Samotná zmena je skoro neúctivá:

```diff
--- storage/blog/sk/hromadne-mazanie-dry-pasca.md
+++ storage/blog/sk/hromadne-mazanie-dry-pasca.md
 category: "Clean Code"
-image: "https://res.cloudinary.com/epithemic/image/upload/nativa-media/ne3ulmsddkg1dwb6cj8s"
 translation: "htmx-bulk-actions-dry-trap"
```

Jeden odobratý riadok. Od tej chvíle `resolveCover()` vracia druhý
stupeň — `/storage/blog-images/hromadne-mazanie-dry-pasca.svg?v=…` —
a článok má rovnaký druh identity ako sto sedemnásť kolegov.

Anglický dvojča pin nikdy nemal. Jeho obálka prechádzala rebríkom
odjakživa. Jediný holdout v korpuse bol slovenský.

## Pravidlo sa stuhne

Zmazať riadok je polovica. Druhá polovica je zaistiť, že sa nikdy
nevráti — že sa o rok niekto nepovie „legálny upload, to predsa môžem“:

```php
// tests/Unit/CoverPinPolicyTest.php — pred dielom 14
self::assertFalse(
    in_array($pin, self::poolUrls(), true),
    'zamrznutý výstup resolvera nie je výber',
);

// po dieli 14 — aj uploady mimo poolu
self::assertFalse(
    str_contains($pin, '/nativa-media/'),
    'obálky sú self-hosted; cloudinary je pre obsah, nie pre identitu',
);
```

Pred: test poznal štyri zamrznuté URL a pustil všetko ostatné. Po:
každý pin do `nativa-media/` padne. Pool URL padajú naďalej — sú to
iné priečinky, ale tá istá chyba v inom kabáte.

Pravidlo tak hovorí, čo si vždy myslelo, ale nedokázalo povedať:
obálka je identita. Identita sa nevyberá z galérie — generuje sa
z veci samej.

## Kedy pin dať (a kedy nie)

Úprimne: tento pin nebol chyba. Staré pravidlo také niečo výslovne
povoľovalo a screenshot hromadného mazania mal redakčnú hodnotu —
ukazoval presne to UI, o ktorom článok rozpráva. Menili sme pravidlo,
nie opravovali bug. To je rozdiel a treba ho pomenovať.

Druhá úprimnosť: toto nie je vojna proti cloudinary. Článok si svoj
screenshot necháva v tele — obsahové obrázky sú iná zmluva: nahráva
ich mediálny modul, CSP ich hostiteľa pozná a nikto ich negeneruje.
Rebrík sa týka obálky a obálka je kontrakt identity, nie galéria
ukážok.

A tretia: vygenerované SVG je anonymnejšie ako screenshot. Vymenili
sme redakčnú jedinečnosť za jeden systém identity pre celý korpus.
Nie je to jasná výhra — je to trade. Korpus ho urobil tým, že sto
sedemnásť článkov už na deterministickej strane bolo; jeden holdout
nie je rozmanitosť, je to šum.

## Posledný pin

Pin si svoju identitu neprenajal zadarmo — prenajal si ju na rok
a bez cache-bustingu. Dnes ju dostal späť. Rovnakú, ako má každý
iný článok: vygenerovanú z obsahu, verziovanú hashom a čitateľnú
ako dôkaz.

Pravidlo, ktoré má nula výnimiek, sa dá vysvetliť jednou vetou.
Pravidlo s jednou výnimkou potrebuje tento článok.

---

→ [Diel 13 — rebrík zlyhaní](/blog/rebrick-zlyhania-pre-deti) —
čo sa stane, keď zlyhá každý stupeň rebríka
→ [MDN: Cache-Control](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Cache-Control)
— prečo regenerácia potrebuje nové URL

## Zdroje

- `tests/Unit/CoverPinPolicyTest.php` — kontrakt pinov vrátane nového
  invariantu
- `src/Modules/Blog/Application/Services/BlogArticlesService.php` —
  rebrík `resolveCover()`: pin → lokálne SVG s `?v=` → pool
- `docs/blog-covers.md` — politika obálok a cache-busting
- [Diel 12 — obálky ako kontrakt](/blog/obalky-ako-kontrakt-pre-deti)
- [MDN: Cache-Control](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Cache-Control)
