---
title: "PHP typy pre deti ... diel 5: union types, nullsafe a enumy (keď jedna hodnota nie je len jedna)"
date: "2026-08-09"
version: "1.0.0"
tags: ["php", "types", "enums", "clean-code", "phpstan"]
excerpt: "Čo ak hodnota môže byť viac vecí naraz? Diel 5 série: union types (Locale|string), nullsafe ?-> a enumy ... na reálnych príkladoch zo starter kitu a prečo tam zatiaľ žiadne enumy nie sú."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "union-nullsafe-enums"
published: true
---

> Toto je diel 5 série **PHP typy pre deti**. Predošlé diely: [diel 1 ... čo sa skrýva za premennou](/blog/php-typy-pre-deti), [diel 2 ... scalar casts](/blog/scalar-casty-pre-deti), [diel 3 ... prísne typuj pred iteráciou](/blog/typuj-pred-iteraciou) a [diel 4 ... array vs list, DTO a mixed](/blog/array-list-dto-pre-deti). Dnes si povieme, čo sa stane, keď jedna hodnota **môže byť viac vecí naraz** ... a ako z toho urobiť poriadok. V [dieli 6](/blog/chunky-rule-pre-deti) sériu uzavrieme chunky rule.

Premenná môže byť string alebo null. Alebo int. Alebo vlastný typ. PHP 8.0+ vie, že jedna hodnota nie je len jedna vec, a dá vám nástroje, ako to vyjadriť.

## Minule sme si povedali…

V [dieli 4](/blog/array-list-dto-pre-deti) sme zistili, že `mixed` = „neviem, čo to je“ ... a level 9 ťa núti to zúžiť. Ale čo ak **presne vieš**, že to môže byť jedna z dvoch (alebo troch) konkrétnych vecí? To je **union type** ... a je to oveľa lepšia správa ako `mixed`.

## Union types ... „buď, alebo, ale presne viem ktoré“

Union type hovorí: **táto hodnota je ALEBO typ A, ALEBO typ B** ... a nič iné. V PHP sa píše s `|`:

```php
function setLocale(Locale | string $locale): void { … }
// $locale je BUĎ Locale objekt, ALEBO string ... a nič iné
```

V starter kite je to naživo v `LocaleService::set()`:

```php
public function set(Locale | string $locale): void
{
    $this->currentLocale = is_string($locale) ? Locale::fromString($locale) : $locale;
}
```

Všimni si dve veci:

1. **Zmluva je presná** ... `Locale | string` je oveľa silnejšie ako `mixed`. PHPStan vie, že to nie je `int`, `array` ani `null`.
2. **Narrowing je jednoznačný** ... stačí jeden `is_string()` a PHPStan už vie, že v `else` vetve je `Locale`. Žiadne ďalšie checky.

Ďalší reálny príklad ... migrátory prijímajú `CliOutput | OutputInterface` (vlastný výstup alebo Symfony):

```php
public function run(CliOutput | OutputInterface $output): void { … }
```

To je pekné: **dva rôzne typy, ktoré majú spoločnú metódu `writeln()`** ... union type to vyjadrí bez zbytočnej abstrakcie (žiadne rozhranie kvôli dvom triedam!).

## Union types a level 9 ... pravidlá hry

| Union | Čo znamená | Pozor na |
|---|---|---|
| `Locale \| string` | Locale objekt alebo string | narrowing cez `is_string()` / `instanceof` |
| `int \| float` | „číslo“ (celé alebo desatinné) | `is_numeric()` nestačí na oba |
| `string \| null` | „možno string“ (= `?string`) | `??`, `is_string()` |
| `self \| null` | „možno objekt“ | `instanceof`, `?->` |

Ako sa s unionom pracuje? **Narrowing** ... zúženie na konkrétny člen:

```php
// Zlé: hádaj, čo to je, cez mixed
function log(mixed $value): void {
    echo (string) $value;  // môže byť hocičo
}

// Dobré: povedz presne, čo prijímaš
function log(string | int | float $value): void {
    echo (string) $value;  // level 9: viem, že je to scalar
}
```

A čo `int | float`? PHP ich vie porovnávať priamo ... ale pozor, **`(int)` cast na float** sekne desatinnú časť (diel 2!). Niekedy je lepšie povedať rovno, čo chceš:

```php
// Všetko sú „čísla“ ... PHPStan nechá porovnávať
function bigger(int | float $a, int | float $b): int | float
{
    return $a > $b ? $a : $b;
}
```

## Nullsafe `?->` ... navigácia v „možno nič“

`string | null` je také časté, že má vlastnú skratku: `?string`. A keď máš reťazec objektov, kde každý môže byť `null`, čaká ťa peklo `if` checkov:

```php
// Bez nullsafe ... pyramída null checkov
$city = null;
if (null !== $user) {
    if (null !== $user->address()) {
        $city = $user->address()->city();
    }
}

// S nullsafe ... preruší sa pri prvom null
$city = $user?->address()?->city();
```

`?->` znamená: **„ak je ľavá strana null, celé to je null ... inak pokračuj“**. Žiadna chyba, žiadny `if`.

> **Ale pozor** ... v starter kite `?->` **zámerne nenájdeš**. Prečo? C-pattern pravidlo: **explicitné je lepšie ako magické**. `?->` ticho prehltne null a ty nevieš, kde sa reťazec prerušil. Navyše na level 9 je `$user?->address()?->city()` typu `?string` ... a s ním aj tak musíš narábať. Zlatá stredná cesta: nullsafe **na čítanie hlbokých štruktúr**, explicitné `if` tam, kde je null **významný** (nie len „nenastavené“).

## Enumy ... keď „string“ znamená len pár hodnôt

A teraz najlepšia časť. Union `string | null` rieši „možno nič“. Ale čo keď má string **povolených len 5 hodnôt**? Napríklad farba témy: `'dark'`, `'light'` ... a nič iné. String to nedokáže povedať:

```php
function setTheme(string $theme): void { … }

setTheme('dark');     // OK
setTheme('neon');     // OK?? ... a toto je problém
```

**Enum** (od PHP 8.1) = **uzavretý zoznam hodnôt**. Kompilátor aj PHPStan vedia, čo existuje:

```php
enum Theme
{
    case Dark;
    case Light;

    public function label(): string
    {
        return match ($this) {
            self::Dark  => 'Tmavá',
            self::Light => 'Svetlá',
        };
    }
}

function setTheme(Theme $theme): void { … }

setTheme(Theme::Dark);
setTheme('neon');        // TypeError! ... string sem nepatrí
```

## Prečo v starter kite zatiaľ nie sú enumy

Zaujímavé: codebase má **nula enumov**. Nie preto, že by boli zlé ... ale preto, že vzory, ktoré enumy riešia, tu ešte „nedorástli“:

| Miesto | Dnes | Enum by sa hodil? |
|---|---|---|
| `Locale` VO | `final readonly` trieda s `AVAILABLE` mapou | v podstate už enum-like: `sk_SK`, `en_US` ... ale potrebuje validáciu formátu a `__toString`, čo VO robí lepšie |
| Téma (`dark`/`light`) | string v `data-theme` | presný kandidát na `Theme` enum |
| `MenuService` (`header`/`footer`) | string location | kandidát na `MenuLocation` enum |
| Settings `value_type` | string `'boolean'`/`'integer'`/`'json'`/`'string'` | kandidát na `SettingType` enum |

Prečo to teda nerobíme hneď? **YAGNI** ... rule „nepridávaj abstrakciu, kým nie je potrebná“. Enum má zmysel, keď:

1. Hodnôt je **málo a sú uzavreté** (nie užívateľské dáta)
2. Hodnoty **cestujú** medzi vrstvami (nie lokálny string)
3. Na hodnote **závisí logika** (`match ($this)`)

Keď `Theme` alebo `MenuLocation` začnú presakovať do viac ako dvoch súborov ... moment pre enum. Do vtedy je `'dark'` string úplne v pohode, ak je validovaný **na hranici** (diel 2!).

## Čo znamená `never`

Ešte jedna drobnosť ... `never` return type (PHP 8.1): **funkcia nikdy nevráti hodnotu, lebo vždy vyhodí výnimku alebo ukončí program**:

```php
function abort(string $message): never
{
    throw new \RuntimeException($message);
}

function findUser(int $id): User
{
    $user = $this->repo->find($id);
    if (null === $user) {
        abort("User $id not found");  // never ... PHPStan vie, že ďalej sa nepokračuje
    }

    return $user;  // PHPStan vie, že $user tu NIE JE null
}
```

`never` je darček pre PHPStan: po volaní `abort()` sa kód **nikdy nevykoná**, takže analýza vie, že `$user` je tu už naisto `User`. V starter kite zatiaľ nie je ... ale keď niekedy príde centrálna chybová hliadka, toto je jej typ.

1. **Union types** (`Locale | string`) ... „buď A, alebo B, presne viem ktoré“; silnejšie ako `mixed`, narrowing cez `is_*`/`instanceof`
2. **Nullsafe `?->`** ... preruší sa pri prvom null; fajn na čítanie hlbokých štruktúr, ale v starter kite zámerne explicitné
3. **Enumy** ... uzavretý zoznam hodnôt (`Theme::Dark`); nasadzuj, keď hodnoty cestujú medzi vrstvami a závisí na nich logika ... nie skôr (YAGNI)
4. **`never`** ... „nikdy sa sem nevrátim“; pomáha PHPStanu zúžiť typy po volaní abort funkcií

A tým je séria kompletná ... od „premenná nemá typ“ ([diel 1](/blog/php-typy-pre-deti)) cez casts ([diel 2](/blog/scalar-casty-pre-deti)), typovanie pred iteráciou ([diel 3](/blog/typuj-pred-iteraciou)), polia a DTO ([diel 4](/blog/array-list-dto-pre-deti)) až po union typy, nullsafe a enumy. Séria pokračuje v [dieli 6](/blog/chunky-rule-pre-deti) ... prečo 60 riadkov. Čisté hlavy, čisté dáta ... a čisté typy.


→ [PHP typy ... diel 1](/blog/php-typy-pre-deti): čo sa skrýva za premennou
→ [Scalar casts](/blog/scalar-casty-pre-deti): povedz PHP presne, čo chceš
→ [Chunky rule](/blog/chunky-rule-pre-deti): prečo 60 riadkov?

## Zdroje

- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): bezpečnostné best practices
- [W3C, Web Standards](https://www.w3.org/standards/): web štandardy
