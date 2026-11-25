---
title: "Rust a Lekalo pre deti ... diel 13: rebrík zlyhaní"
series: "Rust & Lekalo"
series-part: "13"
date: "2026-09-17"
version: "1.0.1"
tags: ["rust", "lekalo", "fallback", "degradation", "architektura"]
excerpt: "Pin na obálku, ktorý CSP blokuje, je zlyhanie, ktoré sa nikdy neozve. Rebrík fallbackov nie je hádka o poradí — je to poriadok, ktorý musí mať meno. A meno tichému zlyhaniu dáva kontrakt."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "fallback-ladder"
published: true
---

V [dieli 12](/blog/obalky-ako-kontrakt-pre-deti) sme presunuli identity
do vecí samých. Dnes sa nám prihlásila vec, ktorá mlčala roky: článok
s pinom na obrázok z imgur. Prehliadač ho odmietol. Nikto sa o tom
nedozvedel.

## Čo sa stalo

Šablóna článku pozná riadok `image:` — pin na konkrétnu obálku. Jeden
starší článok držal `i.imgur.com/placeholder.png`. Doslovný placeholder.
Prehliadač ho nikdy nezobrazí: CSP povoľuje obrázky len z vlastného
servera a z cloudinary. Imgur padá na dverách.

A viac: zlyhanie bolo tiché. Žiadna chyba v logu, žiadne upozornenie.
Stránka vyzerá zdravo — kým sa človek nepozrie presne na miesto, kde má
byť obrázok. Najdrahšie zlyhania sú tie, ktoré sa neozvú.

## Rebrík, ktorý tam má byť

Pin nie je jediná možnosť, ako článok dostane obálku. `resolveCover()`
ide po rebríku: najprv explicitný pin, potom lokálne SVG s `?v=` hashom,
na konci cloudinary pool ako záchranná sieť. Rebrík zlyhaní — poradie,
ktoré hovorí, kto naskočí, keď predchádzajúci krok padne:

```php
// 1. pin z front matter — ak existuje a nie je prázdny
// 2. lokálne SVG + ?v= hash — vždy čerstvá URL po regenerácii
// 3. cloudinary pool — záchranná sieť, keď nič iné nie je
```

Každý systém, ktorý niečo skúša a pri prvom pokuse nepočítajúci s padom,
rebrík má — napísaný, alebo len skrytý v kóde. Otázka nie je, či rebrík
existuje. Otázka je, či vieš povedať, kto naskočí a prečo. My sme to
vedeli. Vedeli sme aj to, čo rebrík nevie: **ako zlyhá pin, ktorý
prehliadač odmietne.** Zlyhá ticho — a ticho je v rebríku diera.

## Kontrakt dáva zlyhaniu meno

Riešenie nie je meniť CSP ani rebrík. Riešenie je dať tichému zlyhaniu
hlas — kontrakt, ktorý padnutý pin zachytí skôr, než vyjde z budovy.
`CoverPinPolicyTest` prebehne nad každým článkom a kontroluje dve
pravidlá:

```php
// 1. pin musí vedieť zobraziť prehliadač (CSP allowlist)
$allowed = '/' === $host
    || in_array($host, self::CSP_ALLOWED_HTTPS_HOSTS, true);

// 2. pin nesmie ukazovať na výstup generátora
//    — ten si verziovanú URL dáva rebrík cez ?v=
self::assertFalse(
    str_starts_with($pin, '/storage/blog-images/'),
    'pinning your own SVG bypasses cache-busting',
);
```

Prvé pravidlo: pin z imgur padne už pri teste, nie v prehliadači
čitateľa. Druhé: nikto si nepinuje vlastné SVG — obídial by tým `?v=`
hash a regenerácia by zase rok servovala staré bajty, ten prípad z
[dielu 12](/blog/obalky-ako-kontrakt-pre-deti). Zlyhanie, ktoré má meno,
sa dá opraviť. Zlyhanie bez mena sa dá len nájsť — ak sa hľadá.

## Prečo nie je CSP dosť

Prečo kontrakt, keď CSP aj tak rozhodne? Lebo CSP je posledný článok
rebríka — zastaví zlé URL, ale nič nepovie. Povie to až prehliadač
čitateľa, a to len tým, že obrázok nezobrazí. Rust má rovnaký inštinkt:
`Result<T, E>` nenechá zlyhanie prejsť bez mena — jazyk vyžaduje, aby si
chybe dal typ. Náš tichý obrázok bol pad bez typu. CSP je správna
zásada, nie hlásenie. Hlásenie musí prísť skôr — tam, kde píšeš, nie
tam, kde čítaš.

Rebrík je poriadok, nie hádka. Naskočí ten, kto má v poriadku miesto —
a zlyhanie, ktoré sa ozve, je súčasť poriadku.

## Cloudinary v rebríku

Cloudinary zostáva vynikajúci — len nie ako identita článku. Pool drží
náladu a zvládne úplne unikátny obrázok pre článok, ktorý si ho
zaslúži. A presne preto nie je miesto pre deterministickú obálku, ktorá
musí po regenerácii zostať rovnaká. Pool rozhoduje o náhode, rebrík
o poriadku — a identita je poriadok.

---

## Ľudská poznámka

Rebrík nerobí systém nezničiteľný. Zaisťuje len jedno: keď prvý padne, 
naskočí ďalší podľa poriadku a zlyhanie sa ozve menom, nie tichom.
Ďalší, kto po tebe príde, uvidí rebrík a pochopí poriadok. A čitateľ
uvidí obrázok — lebo zlyhanie, ktoré sa neozvalo, tentokrát žiadne
nebolo.

---

→ [Diel 12 — obálky ako kontrakt](/blog/obalky-ako-kontrakt-pre-deti) —
identita veci vo veci, regenerácia mení URL
→ [Diel 11 — mapa hovorí 393, terén 962](/blog/mapa-a-teren-pre-deti) —
čomu agent verí, keď číta mapu
→ [MDN: Content-Security-Policy img-src](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Security-Policy/img-src)
— prehliadač ako posledný článok rebríka

## Zdroje

- `tests/Unit/CoverPinPolicyTest.php` — kontrakt: CSP renderability +
  zákaz pinovania vlastného SVG
- `src/Modules/Blog/Application/Services/BlogArticlesService.php` —
  rebrík `resolveCover()`: pin → lokálne SVG s `?v=` → cloudinary pool
- `src/Modules/Core/Interfaces/HTTP/Middleware/SecurityHeadersMiddleware.php`
  — CSP `img-src` allowlist
- [Diel 12 — obálky ako kontrakt](/blog/obalky-ako-kontrakt-pre-deti)
- [MDN: Content-Security-Policy img-src](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Security-Policy/img-src)
