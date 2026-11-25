---
title: "PHP typy pre deti ... diel 1: čo sa vlastne skrýva za premennou"
date: "2026-08-09"
version: "1.0.2"
tags: ["php", "types", "clean-code"]
excerpt: "PHP je laxný jazyk ... premenná nemá typ, typ má hodnota. Prvý diel série o typoch: scalar typy, dynamic typing a pasce type jugglingu, ktoré ticho žerú CPU aj nervy."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "php-types-part-1"
published: true
---

Premenná v PHP nemá typ. Typ má hodnota. A to mení všetko.

## Pre koho je táto séria

Ak si niekedy napísal `$x = "5";` a potom `$x + 1` a **nevedel si presne**, čo sa stane ... tento seriál je pre teba. Voláme ho „pre deti“ ... pre čisté hlavy, neposkvrnené starými vzormi a patternami. Žiadne predsudky, žiadne „to sa tak nerobí“, len čerstvý pohľad na to, prečo sa PHP správa tak, ako sa správa.

Seriál má šesť dielov:

1. **Tento diel** ... čo sú scalar typy a prečo PHP tak ľahko „kľučkuje“ medzi nimi
2. [Diel 2 ... scalar casts](/blog/scalar-casty-pre-deti) ... ako povedať PHP presne, čo chceš, a `strict_types`
3. [Diel 3 ... prísne typuj a castuj pred iteráciou](/blog/typuj-pred-iteraciou) ... ako to všetko šetrí CPU v cykloch
4. [Diel 4 ... array vs list, DTO a mixed](/blog/array-list-dto-pre-deti) ... čo sa deje, keď dáta nie sú scalar
5. [Diel 5 ... union types, nullsafe a enumy](/blog/union-nullsafe-enumy-pre-deti) ... keď jedna hodnota môže byť viac vecí
6. [Diel 6 ... prečo 60 riadkov? (chunky rule)](/blog/chunky-rule-pre-deti) ... jedna metóda, jedna vec

## Premenná nemá typ. Hodnota má typ.

Toto je **najdôležitejšia veta celého seriálu**. V PHP:

```php
$value = "5";    // string
$value = 5;      // teraz je to int ... tá istá premenná!
$value = 5.0;    // teraz float
$value = true;   // a teraz bool
```

Premenná je len **nádoba**. Typ má **hodnota**, ktorá v nej práve sedí. V jazykoch ako C alebo Java si premennú „natypuješ“ raz a hotovo ... v PHP sa typ mení podľa toho, čo do nej priradíš. Tomu sa hovorí **dynamic typing** a je to hlavný zdroj všetkých „čarovných“ bugov.

## Štyri scalar typy

Scalar (škálarny) typ = jedna jednoduchá hodnota. PHP ich má štyri:

| Typ | Príklady | Po slovensky |
|---|---|---|
| `int` | `42`, `-7`, `0` | celé číslo |
| `float` | `3.14`, `-0.5`, `2.0` | desatinné číslo |
| `string` | `"ahoj"`, `''`, `"5"` | reťazec znakov |
| `bool` | `true`, `false` | pravda/nepravda |

K tomu ešte `null` (žiadna hodnota) a zložené typy (`array`, `object`) ... tie necháme na inokedy. Dôležité: **`"5"` nie je `5`**. Jeden je reťazec, druhý číslo. Vyzerajú rovnako, správajú sa inak.

## Type juggling ... keď PHP „háda“

Pretože je PHP laxné, keď mu dáš zmiešané typy, **sám sa rozhodne**, ako ich skombinovať. Tomu sa hovorí **type juggling** (žonglovanie s typmi):

```php
echo "5" + 3;       // 8  ... reťazec "5" sa zmenil na číslo 5
echo "5" . 3;       // "53" ... číslo 3 sa zmenilo na reťazec "3"
echo "abc" + 3;     // 3  + varovanie ... "abc" sa nedá prečítať ako číslo, tak 0
echo "5abc" + 3;    // 8  ... PHP prečíta 5, zvyšok zahodí (od PHP 8 už varovanie)
echo 1 == "1";      // true  ... voľné porovnanie porovnáva hodnoty
echo 1 === "1";     // false ... striktné porovnanie kontroluje AJ typ
```

Všimni si posledné dva riadky: `==` (voľné) a `===` (striktné). Voľné porovnanie je **džungľa**:

```php
var_dump(0 == "a");      // false v PHP 8 (v PHP 7 to bolo true!)
var_dump(0 == "");       // false v PHP 8
var_dump("0" == "");     // false
var_dump("1" == "01");   // true ... obe sa prečítajú ako čísla
var_dump("abc" == 0);    // false v PHP 8
```

PHP 8 to výrazne opravilo, ale **pravidlo zostáva**: pri porovnávaní používaj `===` a `!==`, pokiaľ naozaj nevieš, čo robíš.

## Prečo ťa to stojí CPU

Každý type juggling je práca navyše, ktorú musí PHP spraviť **pri každom prístupe**. A teraz to najlepšie ... keď máš cyklus:

```php
// Zlé: každá iterácia znova zisťuje, čo $row['count'] vlastne je
foreach ($rows as $row) {
    if (is_string($row['count']) && is_numeric($row['count'])) {
        $total += (int) $row['count'];
    } elseif (is_int($row['count'])) {
        $total += $row['count'];
    }
}
```

PHP pri každom prechode cyklom **znova skúma typ** ... pre každý riadok, pre každý stĺpec. Pri 10 000 riadkoch je to 10 000 krát to isté rozhodovanie. Tomu sa vieme vyhnúť ... a presne o tom je diel 2 a 3.

1. **Premenná nemá typ** ... typ má hodnota; PHP je dynamic typed
2. Existujú 4 scalar typy: `int`, `float`, `string`, `bool`
3. **`"5"` nie je `5`** ... rovnaký vzhľad, iné správanie
4. PHP **žongluje s typmi** automaticky (type juggling) ... niekedy užitočné, často zrada
5. Pri porovnávaní používaj **`===` / `!==`**, nie `==` / `!=`

V [dieli 2](/blog/scalar-casty-pre-deti) si ukážeme, ako povedať PHP presne, čo chceš ... explicitné casty a `declare(strict_types = 1)`. V [dieli 3](/blog/typuj-pred-iteraciou) to celé spojíme do pravidla, ktoré šetrí procesorové cykly. V [dieli 4](/blog/array-list-dto-pre-deti) sa pozrieme na polia, `mixed` a DTO. V [dieli 5](/blog/union-nullsafe-enumy-pre-deti) na union typy, nullsafe a enumy ... a v [dieli 6](/blog/chunky-rule-pre-deti) na to, prečo sa metódy nesmú pretiahnuť cez 60 riadkov.

→ [Scalar casty](/blog/scalar-casty-pre-deti): explicitné typové konverzie
→ [Typuj pred iteráciou](/blog/typuj-pred-iteraciou): typovanie na hranici
→ [Array vs list vs DTO](/blog/array-list-dto-pre-deti): polia a mixed

## Zdroje

- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
- [Web.dev, Performance](https://web.dev/performance): Core Web Vitals
- [Lighthouse Documentation](https://developer.chrome.com/docs/lighthouse/): Lighthouse nástroj
