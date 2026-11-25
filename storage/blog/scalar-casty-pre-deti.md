---
title: "PHP typy pre deti ... diel 2: povedz PHP presne, čo chceš (scalar casts)"
date: "2026-08-09"
version: "1.0.2"
tags: ["php", "types", "casts", "clean-code"]
excerpt: "Nechaj PHP hádať, a bude hádať zle. Diel 2 série: explicitné casty (int), (string), (float), (bool), declare(strict_types=1) a vzor cast-at-boundary, ktorý šetrí CPU v cykloch."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "scalar-casts"
published: true
---

PHP vie hádať. Dá mu číslo a text a ono sa pokúsi uhádnuť, čo chceš. Dnes sa naučíme povedať mu to na rovinu.

## Minule sme si povedali…

V [dieli 1](/blog/php-typy-pre-deti) sme zistili, že **premenná nemá typ ... typ má hodnota**, a PHP s typmi samo žongluje (type juggling). Dnes sa naučíme **povedať PHP presne, čo chceme**, aby nemuselo hádať. Hádať = strácať CPU aj nervy.

## Explicitné casty ... hod do formy

Casting je **zámerná zmena typu** ... „túto hodnotu chcem ako string“. Syntax je `(typ) $hodnota`:

| Cast | Príklad | Výsledok |
|---|---|---|
| `(int)` | `(int) "42"` | `42` |
| `(int)` | `(int) "42.9"` | `42` ... nezaokrúhľuje, sekne desatinnú časť |
| `(int)` | `(int) "abc"` | `0` ... pozor, ticho! |
| `(int)` | `(int) "5abc"` | `5` ... prečíta začiatok, zvyšok zahodí |
| `(float)` | `(float) "3.14"` | `3.14` |
| `(string)` | `(string) 42` | `"42"` |
| `(string)` | `(string) true` | `"1"` ... bool true sa stane „1“ |
| `(bool)` | `(bool) "false"` | `true` ... **pozor!** Neprázdny string je vždy true |
| `(bool)` | `(bool) 0` | `false` |
| `(bool)` | `(bool) "0"` | `false` ... toto je špeciálny prípad |

Dve najväčšie pasce v tabuľke:

```php
$n = (int) "abc";        // 0 ... žiadna chyba, len tiché 0
$b = (bool) "false";     // true ... string "false" JE neprázdny, takže true!
```

String `"false"` je podľa PHP **pravda**, pretože je neprázdny. Jediné stringy, ktoré sú `false`, sú `""` a `"0"`. Keď parsuješ formulár alebo JSON a čakáš bool, **kontroluj obsah stringu, nie jeho „boolovskosť“**:

```php
// Zle: "false" sa zmení na true
$active = (bool) ($data['active'] ?? false);

// Dobre: porovnaj reťazec sám
$active = ($data['active'] ?? '') === 'true' || $data['active'] === true;
```

## Namiesto castu ... funkcie

Niekedy je čitateľnejšie použiť funkciu, ktorá má **presnejšie pravidlá**:

```php
intval("42.9");     // 42
floatval("3.14");   // 3.14
strval(42);         // "42"
boolval(1);         // true
is_numeric("42");   // true ... kontroluje, či sa to DÁ prečítať ako číslo
```

Rozdiel medzi `(int)` a `intval()` je v detaile: `intval()` rešpektuje aj zápis ako `intval("0x1A", 16)`. Pre bežnú prácu sú ekvivalentné ... vyber si jeden štýl a drž sa ho.

## `declare(strict_types = 1)` ... nech PHP neuhýba

V starter kite má **každý** súbor na prvom riadku:

```php
<?php

declare(strict_types = 1);

function add(int $a, int $b): int
{
    return $a + $b;
}

add(1, 2);     // OK
add("1", 2);   // TypeError! ... string sa už ticho nezmení na int
```

Bez `strict_types` by `add("1", 2)` ticho prehodilo `"1"` na `1` a pokračovalo. S `strict_types` PHP **vyhodí chybu** ... a to je dobré! Chyba ťa donúti opraviť to na mieste, kde to vzniká, nie o desať volaní ďalej.

> **Dôležité:** `strict_types` platí **per súbor** ... nie globálne. Rozhoduje súbor, z ktorého sa funkcia **volá** (teda kde je volanie napísané), nie kde je definovaná. Preto sa dáva na začiatok každého súboru.

## Vzor cast-at-boundary ... castuj na hranici, nie v cykle

A teraz tá časť, ktorá šetrí CPU. **Hranica** = miesto, kde dáta vstupujú do tvojho kódu: formulár, JSON, YAML front matter, riadok z databázy, odpoveď z API. Na hranici sú dáta „surové“ ... môžu byť čokoľvek. V cykle už majú byť **presne natypované**.

```php
// Zlé ... cyklus háda pri každom prvku (opakuje prácu N-krát)
$total = 0;
foreach ($rows as $row) {
    if (is_string($row['price'])) {
        $total += (float) $row['price'];
    } elseif (is_float($row['price'])) {
        $total += $row['price'];
    } elseif (is_int($row['price'])) {
        $total += (float) $row['price'];
    }
}

// Dobre ... vyrieš typ RAZ na hranici, cyklus už len počíta
$prices = array_map(static fn (mixed $p): float => (float) $p, $rows);
$total = array_sum($prices);
```

V druhom prípade sa typové rozhodnutie spraví **raz na riadok** (v `array_map`), a cyklus (`array_sum`) už len sčíta čísla. Pri 10 000 riadkoch je rozdiel v počte typových kontrol markantný ... a kód je zároveň kratší a čitateľnejší.

1. **`(int)`, `(float)`, `(string)`, `(bool)`** ... zámerná zmena typu; pozor na `(int) "abc"` → `0` a `(bool) "false"` → `true`
2. **`declare(strict_types = 1)`** ... každý súbor, žiadne tiché prehadzovanie typov pri volaniach funkcií
3. **Cast-at-boundary** ... vyrieš typ na hranici (vstup), nie v cykle; cyklus potom len pracuje
4. Pri bool z formulára/JSON **porovnávaj reťazec**, necastuj naslepo

V [dieli 3](/blog/typuj-pred-iteraciou) to celé spojíme do jedného pravidla s reálnym príkladom z `BlogIndexAction` ... a ukážeme si, ako typované dáta držia cykly krátke a metódy v limite 60 riadkov. V [dieli 4](/blog/array-list-dto-pre-deti) si povieme, čo sa deje, keď dáta nie sú scalar ... `array` vs `list`, `mixed` a DTO. V [dieli 5](/blog/union-nullsafe-enumy-pre-deti) sa pozrieme na union typy, nullsafe a enumy ... a v [dieli 6](/blog/chunky-rule-pre-deti) na to, prečo 60 riadkov.

## Zdroje

- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): bezpečnostné best practices
- [W3C, Web Standards](https://www.w3.org/standards/): web štandardy
