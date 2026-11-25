---
title: "PHP typy pre deti ... diel 4: array vs list, DTO a mixed (ako to vidí PHPStan level 9)"
date: "2026-08-09"
version: "1.0.2"
tags: ["php", "types", "phpstan", "clean-code", "dto"]
excerpt: "Všetky polia nie sú rovnaké. Diel 4 série: prečo PHPStan rozlišuje array a list, čo je mixed a prečo ho level 9 nenávidí, a kedy shape array nahradiť DTO ... na reálnych príkladoch zo starter kitu."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "array-list-dto"
published: true
---

> Toto je diel 4 série **PHP typy pre deti**. Predošlé diely: [diel 1 ... čo sa skrýva za premennou](/blog/php-typy-pre-deti), [diel 2 ... scalar casts](/blog/scalar-casty-pre-deti) a [diel 3 ... prísne typuj pred iteráciou](/blog/typuj-pred-iteraciou). Dnes sa pozrieme na to, čo sa deje, keď dáta nie sú scalar ... polia, `mixed` a DTO. V [dieli 5](/blog/union-nullsafe-enumy-pre-deti) potom na union typy, nullsafe a enumy ... a v [dieli 6](/blog/chunky-rule-pre-deti) na to, prečo 60 riadkov.

## Minule sme si povedali…

V [dieli 3](/blog/typuj-pred-iteraciou) sme normalizovali front matter **na hranici** a ďalej pracovali s typovanými riadkami. Ale všimni si jeden detail ... typovaný riadok bol stále **pole** (`array{slug: string, tags: list<string>, …}`).

Dnes si povieme, prečo „pole“ nie je „pole“, čo s tým robí PHPStan level 9 a kedy pole nahradiť triedou.

## Všetky polia nie sú rovnaké

PHP má jeden typ `array` ... ale logicky existujú **dva druhy**:

```php
// MAPA (array) ... kľúče si poviem ja: stringy, čísla, čokoľvek
$settings = ['theme' => 'dark', 'locale' => 'sk', 7 => 'seven'];

// LIST (list) ... kľúče sú 0, 1, 2, … bez dier, v poradí
$tags = ['php', 'types', 'dto'];
```

- **`array`** = všeobecná mapa: kľúče môžu byť hocičo, poradie je moja vec.
- **`list`** = špeciálny prípad `array`, kde kľúče sú `0..n-1` presne v poradí.

Je to **užšia** podmnožina ... každý `list` je `array`, ale nie každé `array` je `list`. Prečo na tom záleží? Lebo pri `list` **garantuješ viac** ... a PHPStan level 9 to vie využiť.

## PHPStan level 9: list je bezpečnejší

Predstav si, že chceš prvý tag článku:

```php
/** @var array<string, list<string>> $article */
$first = $article['tags'][0]; // PHPStan level 9: hmm, môže tu byť diera?
```

PHPStan vie, že `$article['tags']` je `list<string>` ... a **`list` garantuje, že index `0` existuje**. Žiadna diera, žiadny warning.

Ale keď tagy prídu ako `array` s ľubovoľnými kľúčmi, PHPStan nad tým krúti hlavou:

```php
/** @var array<string, array<int, string>> $article */
$first = $article['tags'][0]; // PHPStan: array<int, string> nemusí mať kľúč 0!
```

Rozdiel medzi `array<int, string>` a `list<string>` je presne tento: **`list` je „pole bez dier“**, takže indexovanie `[0]`, `count()`, `foreach` v poradí ... všetko bezpečné. `array` je „možno mapa“ ... na `[0]` sa nedá spoľahnúť.

## Ako vzniká list (a ako sa oň prichádza)

Najčastejšia pasca: `array_filter` a `array_map` **zachovávajú kľúče**. Takto sa z listu stane array s dierami:

```php
$tags = ['php', '', 'dto'];

// array_filter vyhodí prázdny tag, ALE kľúče ostanú: [0 => 'php', 2 => 'dto']
$filtered = array_filter($tags);

var_dump($filtered[1]); // Undefined array key 1! ... a PHPStan to vie
```

Riešenie? **`array_values()`** ... preindexuje na `0..n-1` a vráti list:

```php
$tags = array_values(array_filter($tags)); // list<string>: [0 => 'php', 1 => 'dto']
```

Presne toto robí `BlogIndexAction` pri tagoch z front matter ... `array_values(array_filter(array_map(…)))`, aby mal garantovaný `list<string>`:

```php
'tags' => is_array($meta['tags'] ?? null)
    ? array_values(array_filter(array_map(
        static fn (mixed $tag): string => is_scalar($tag) ? (string) $tag : '',
        $meta['tags'],
    ), static fn (string $tag): bool => '' !== $tag))
    : [],
```

Reťazec `array_map → array_filter → array_values` je vzor, ktorý v starter kite uvidíš často: **transformuj → vyhoď prázdne → preindexuj na list**.

## `mixed` ... typ, ktorý nevieš

A teraz k `mixed`. V [dieli 2](/blog/scalar-casty-pre-deti) sme spomínali `array_map(static fn (mixed $p): float => …)`. Čo to `mixed` je?

`mixed` = **„neviem, čo to je“**. Je to najširší typ v PHP ... prijíma čokoľvek. Objaví sa všade tam, kde dáta vstupujú zvonka:

```php
$meta = $renderer->extractFrontMatter($source)['meta'];
// $meta['title'] je mixed ... YAML môže obsahovať čokoľvek
```

A tu je kľúč: **PHPStan level 9 považuje prácu s `mixed` za chybu, pokiaľ hodnotu nenarrowuješ** (nezúžiš). Toto je „level 9“ v praxi:

```php
$title = $meta['title']; // mixed
echo strtoupper($title); // PHPStan level 9: cannot call strtoupper() on mixed

$title = is_string($meta['title']) // narrowing ... viem, že je to string
    ? $meta['title']
    : 'Untitled';
echo strtoupper($title); // v poriadku
```

Level 9 ťa **núti** spraviť to, čo sme si povedali v dieli 3: `is_*` check **raz na hranici**, nie v cykle. `mixed` nie je nepriateľ ... je to poctivé priznanie „neviem“, ktoré ťa donúti pýtať sa skôr, než niečo s hodnotou spravíš.

## Keď shape array prestane stačiť ... DTO

Shape array (`array{slug: string, tags: list<string>}`) je fajn na malé dáta. Ale keď riadok rastie, objaví sa niekoľko problémov:

1. **Kľúče sú len stringy** ... preklep v `'titel'` nikto nechytí
2. **Typy žijú len v docbloku** ... refaktoring ich nevidí
3. **Žiadne metódy** ... logika (validácia, odvodené hodnoty) nemá kam ísť

Riešenie: **DTO** (Data Transfer Object) ... malá trieda, ktorá drží dáta a ich typy pohromade. V starter kite je na vzor `MenuItemDTO`:

```php
final readonly class MenuItemDTO
{
    /**
     * @param list<MenuItemDTO> $children
     */
    public function __construct(
        private string $label,
        private string $href,
        private bool $active = false,
        private array $children = [],
        private bool $external = false,
    ) {
        $this->validate();
    }

    public function label(): string
    {
        return $this->label;
    }

    public function href(): string
    {
        return $this->href;
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function external(): bool
    {
        return $this->external;
    }

    /** @return list<MenuItemDTO> */
    public function children(): array
    {
        return $this->children;
    }

    public function hasChildren(): bool
    {
        return [] !== $this->children;
    }
}
```

Všimni si, čo DTO pridáva oproti shape array:

- **Typy sú reálne** ... `string $label`, `bool $active`, a hlavne `list<MenuItemDTO> $children` (list DTO, nie hocijaké pole!)
- **`readonly`** ... dáta sa po vytvorení nedajú zmeniť, čo je presne to, čo od „prenášaného objektu“ chceš
- **Validácia v konštruktore** ... `validate()` vyhodí výnimku hneď pri vzniku, nie o päť použití neskôr
- **Metódy** ... `hasChildren()` je čitateľnejšie ako `[] !== $item['children']`

## DTO vs shape array ... kedy čo

| | Shape array | DTO |
|---|---|---|
| Zápis | `array{slug: string}` | trieda s typovanými property |
| Typy | len v docbloku | reálne, kontrolované PHP aj PHPStanom |
| Preklep v kľúči | tichý bug | fatal pri kompilácii/analýze |
| Validácia | nikde | v konštruktore |
| Logika | žiadna | metódy (`hasChildren()`) |
| Kedy použiť | malé, lokálne dáta (1–4 polia) | dáta, ktoré cestujú medzi vrstvami |

Zlaté pravidlo starter kitu: **shape array pre lokálnu normalizáciu na hranici, DTO pre dáta, ktoré prechádzajú cez hranice modulov** (repozitár → služba → akcia). `MenuItemDTO` a `TranslationDTO` sú presne to.

## Čo teda level 9 chce

PHPStan level 9 je najprísnejšia úroveň ... a starter kit na nej beží s **nulou chýb**. V skratke:

1. **Žiadne operácie s `mixed`** ... každú hodnotu treba najprv narrowovať (`is_string`, `is_array`, …)
2. **Žiadne indexovanie `array` bez záruky** ... `list` je bezpečnejšie ako `array<int, T>`
3. **Žiadne neoverené prístupy** ... `$row['key']` na `mixed` je chyba; vyrieš to na hranici
4. **Casty, nie sily** ... presne z [dielu 2](/blog/scalar-casty-pre-deti)

Ako to celé vyzerá v praxi? `BlogIndexAction` má na konci `articles()` docblock, ktorý je **zmluvou** medzi normalizáciou a renderom:

```php
/**
 * @return list<array{
 *   slug: string, title: string, date: string, excerpt: string,
 *   category: string, author: string, tags: list<string>, image: string
 * }>
 */
```

`list<…>` navonok (články sú zoradené, bez dier) + `list<string>` pre tagy + samé `string` hodnoty = render môže byť čistý, bez jediného `is_*` v cykle.

## Zhrnutie dielu 4

1. **`array` vs `list`** ... `list` je pole bez dier (`0..n-1`); PHPStan level 9 to vie a `[0]` na liste je bezpečné
2. **`array_filter`/`array_map` zachovávajú kľúče** ... preindexuj cez `array_values()`, aby si mal `list`
3. **`mixed` = „neviem“** ... level 9 ťa núti narrowovať (`is_*`) na hranici, nie v cykle
4. **DTO** ... keď dáta cestujú medzi vrstvami, daj im triedu (`final readonly` + typované property + validácia); shape array nechaj pre lokálne dáta
5. **Zmluva v `@return`** ... `list<array{…}>` je čitateľná dohoda medzi normalizáciou a konzumentom

Týmto sme prešli od „premenná nemá typ“ ([diel 1](/blog/php-typy-pre-deti)) cez casts a `strict_types` ([diel 2](/blog/scalar-casty-pre-deti)) a typovanie pred iteráciou ([diel 3](/blog/typuj-pred-iteraciou)) až po polia, `mixed` a DTO. Séria pokračuje v [dieli 5](/blog/union-nullsafe-enumy-pre-deti) ... union typy, nullsafe a enumy ... a v [dieli 6](/blog/chunky-rule-pre-deti) ju uzatvára chunky rule.

Čisté hlavy, čisté dáta, čisté cykly.

## Zdroje

- [PHP Manual — Arrays](https://www.php.net/manual/en/language.types.array.php) — `array` typ a sémantika kľúčov
- [PHPStan — Writing PHPDoc Types](https://phpstan.org/writing-phpdoc-types) — `list<T>` a shape arrays v PHPDoc
- [PHP Manual — Enums](https://www.php.net/manual/en/language.enumerations.php) — príprava na diel 5
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
