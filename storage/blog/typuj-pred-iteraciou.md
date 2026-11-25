---
title: "PHP typy pre deti ... diel 3: prísne typuj a castuj pred iteráciou"
date: "2026-08-09"
version: "1.2.2"
tags: ["php", "types", "clean-code", "patterns"]
excerpt: "Normalizuj dáta na hranici ... nie vo vnútri cyklu. Záverečný diel série: prečo sa oplatí castovať kolekciu raz, aby si nemusel od-počúvať správanie každého child nodu."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "type-before-iterating"
published: true
---

> Toto je diel 3 série **PHP typy pre deti**. Predošlé diely: [diel 1 ... čo sa skrýva za premennou](/blog/php-typy-pre-deti) a [diel 2 ... scalar casts](/blog/scalar-casty-pre-deti). Dnes to všetko spojíme do jedného pravidla, ktoré šetrí CPU v cykloch. V [dieli 4](/blog/array-list-dto-pre-deti) sa potom pozrieme na polia, `mixed` a DTO, v [dieli 5](/blog/union-nullsafe-enumy-pre-deti) na union typy, nullsafe a enumy ... a v [dieli 6](/blog/chunky-rule-pre-deti) na chunky rule.

## Lekcia, ktorá sa stala pravidlom

Každý projekt si časom vytiahne opakujúce sa vzorce do pravidiel. Jedna z lekcií, ktorá sa v minimal starter kite stala oficiálnym pravidlom (`docs/rules` ... commit `c519199`), znie:

> **Prísne typuj a castuj dáta PRED iteráciou** ... normalizuj kolekcie (typované polia, DTO, scalar casts) na hranici, aby cyklus nikdy nemusel znovu zisťovať typ alebo správanie každého child nodu.

V praxi: `is_*()` / `instanceof` / null-check **raz pri budovaní štruktúry**, nie **pre každý prvok v `foreach`**.

## Prečo to vzniklo

Pri portovaní blog indexu (`/blog`) prišiel zo súborov s front matter **nezaručený tvar dát**. YAML v `storage/blog/*.md` môže mať `title`, ale nemusí; `tags` môžu byť pole, ale pokojne aj reťazec alebo nič. Dve stratégie:

- **Zlá:** v cykle renderovania kariet kontrolovať pri každom článku `isset($meta['title'])`, `is_array($meta['tags'])`, `is_scalar($tag)` ... cyklus sa zapchá vetvami a každý child node sa „od-počúva“ dokola.
- **Dobrá:** normalizovať všetko **raz** v metóde `articles()`, kde front matter vstupuje do systému, a ďalej pracovať už len s typovanými riadkami.

## Príklad z codebase

`BlogIndexAction::articles()` najprv vybuduje **typovaný zoznam** ... každý riadok má presne známe typy (`string` / `list<string>`):

```php
$meta = $renderer->extractFrontMatter($source)['meta'];

$articles[] = [
    'slug' => $slug,
    'title' => is_string($meta['title'] ?? null) ? $meta['title'] : ucfirst($slug),
    'date' => is_string($meta['date'] ?? null) ? $meta['date'] : '',
    'excerpt' => is_string($meta['excerpt'] ?? null) ? $meta['excerpt'] : '',
    'category' => is_string($meta['category'] ?? null) ? $meta['category'] : '',
    'author' => is_string($meta['author'] ?? null) ? $meta['author'] : '',
    'tags' => is_array($meta['tags'] ?? null) ? array_values(array_filter(array_map(
        static fn (mixed $tag): string => is_scalar($tag) ? (string) $tag : '',
        $meta['tags'],
    ), static fn (string $tag): bool => '' !== $tag)) : [],
];
```

Všimnite si vzor: **`?? null` + jeden `is_*` + fallback** pre každé pole, a `array_map` s `(string)` castom pre tagy ... všetko **na hranici**. Výstup je deklarovaný docblockom:

```php
/**
 * @return list<array{
 *   slug: string, title: string, date: string, excerpt: string,
 *   category: string, author: string, tags: list<string>
 * }>
 */
```

Potom `renderCards()` → `renderCard()` iterujú **bez jediného typu checku** ... hodnoty sú už `string`, takže ich stačí escapovať a vypísať:

```php
private function renderCard(array $article): string
{
    $href = '/blog/' . $article['slug'];

    $metaBits = array_values(array_filter([
        $article['date'],
        $article['category'],
        $article['author'],
    ], static fn (string $bit): bool => '' !== $bit));

    // ... escape a render, žiadne is_* v tele cyklu
}
```

## Čo to dáva

| | Bez normalizácie | S normalizáciou na hranici |
|---|---|---|
| Typ checky | `N × M` (pre každý node × každú vlastnosť) | `1×` pri budovaní štruktúry |
| Čitateľnosť cyklu | Vetry, fallbacky, hlboké `isset` | Čistý render, len escapovanie |
| PHPStan level 9 | Nekonečné `mixed` problémy | Jasné shape v `@return` |
| Chunky 60-line rule | Cyklus rastie nad limit | Metódy ostávajú krátke |

Presne toto je duch C-patternu starter kitu ... explicitné a transparentné. Typové rozhodnutia sa robia **na jednom mieste**, nie roztrúsené po celom codebase.

## Ako sa lekcia šíri ďalej

Nová konvencia nežije len v hlavách ... má dve vrstvy:

1. **Projektové pravidlo** ... `.ai-factory/rules/base.md` (sekcia PHP), načítané automaticky cez `/aif-implement`
2. **Skill-context** ... `.ai-factory/skill-context/aif-implement/SKILL.md` (commit `5df160b`), s odkazom na zdroj a príkladom z codebase, aby ju ďalšie AI session poznali aj s odôvodnením

Takto sa každý opakovaný vzorec zmení z tichej skúsenosti na **vynútenú konvenciu** ... a ďalšia iterácia nezačne od nuly.

## Prakticky

Keď nabudúce píšeš `foreach` nad dátami, ktoré neprišli z tvojho vlastného typovaného kódu (YAML, JSON, DB riadky, DOM nodes):

1. **Castuj raz na hranici** ... pri parsovaní, nie vo vnútri cyklu
2. **Deklaruj tvar** ... `@return` s shape alebo DTO, aby PHPStan vedel, čo príde
3. **Iteruj čisto** ... v tele cyklu už len použi hodnoty

Cyklus potom odpovedá na otázku „čo s tým spraviť“ ... nie „čo to je“.


→ [PHP typy ... diel 1](/blog/php-typy-pre-deti): čo sa skrýva za premennou
→ [Scalar casts](/blog/scalar-casty-pre-deti): povedz PHP presne, čo chceš
→ [Chunky rule](/blog/chunky-rule-pre-deti): prečo 60 riadkov?

## Zdroje

- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): bezpečnostné best practices
- [W3C, Web Standards](https://www.w3.org/standards/): web štandardy
