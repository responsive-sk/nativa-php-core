---
title: "Chunky rule v praxi ... warning zóna, ktorá nedovolí rásť potichu"
date: "2026-09-28"
version: "1.0.0"
tags: ["php", "architecture", "clean-code", "testing", "ai-agents"]
excerpt: "Pravidlo 60 riadkov sme mali aj test ... a aj tak stálo sedem metód priamo na hranici. Ako warning zóna s allowlistom zmenila tiché lezenie k capu na vedomé, zdokumentované rozhodnutia."
author: "evan.mcdan@example.test"
author_email: "evan.mcdan@example.test"
category: "Architecture"
translation: "chunky-warning-zone"
published: true
---

Máš pravidlo. Máš naň test. Test je zelený v každom CI behu.

Otázka znie ... kolko metód v codebase stojí **presne na limite** toho
pravidla?

V tomto kite znie odpoveď sedem. Sedem metód malo telo dlhé 56 až 60
riadkov ... a najväčšia z nich presne 60. Cap bol 60. Headroom nula.
Akákoľvek úprava takej metódy ... pridanie jednej podmienky, jedného
parametra ... padla by do testu.

## Čo test hovoril ... a čo nehovoril

Chunky pravidlo stráži `CorePatternTest::testNoOversizedMethods`
(`tests/Architecture/CorePatternTest.php`). Tokenizuje PHP, meria telo
každej funkcie od otváracej po uzatváraciu zátvorku ... a failuje všetko
nad 60 riadkov.

Test povie **áno/nie**. Neovie **blízko**.

Metóda s 59 riadkami prejde. Zajtra má 61. Padne ... a vtedy už niekto
musí rýchlo rozumieť, prečo je tam 61 riadkov a čo s tým. Skok z hotového
refactoru na pád CI je zlé miesto pre také rozhodnutie.

Druhá polovica pravdy ... test s fail thresholdom nevie o ** Trende **.
Lezenie k capu je pre neho neviditeľné, kým sa nepreleze.

## Zúženie ... názorná ukázka

Najväčšia metóda stojaca na cape bola
`AdminNavService::hierarchicalItems` ... orchestrácia hierarchickej
admin navigácie. Celé telo (60 riadkov ... presne na hranici):

```php
public function hierarchicalItems(string $activeSection = ''): array
{
    $flat = $this->activeItems();

    // Index by id for quick lookup.
    $byId = [];
    $byLabel = [];
    foreach ($flat as $item) {
        $byId[$item['id']] = $item;
        $byId[$item['id']]['children'] = [];
        $byId[$item['id']]['is_expanded'] = false;
        $byLabel[strtolower($item['label'])] = $item['id'];
    }

    // Resolve activeSection: actions pass lowercase labels (e.g. 'tags'),
    // but $byId is indexed by hex DB IDs. Resolve via label fallback.
    $activeId = '';
    if ('' !== $activeSection) {
        // ... šesť logických blokov po sebe
    }
}
```

Šesť blokov v rade ... indexovať, nájsť aktívny, rozhodnúť expandované
skupiny, pripojiť deti, posbierať top-level, označiť `is_expanded`. Každý
blok má meno. Pravidlo núti meno zapísať aj do kódu:

```php
public function hierarchicalItems(string $activeSection = ''): array
{
    $index = $this->indexItems($this->activeItems());

    $activeId = $this->resolveActiveId($index, $activeSection);
    $expandedGroups = $this->expandedGroups($index, $activeId);
    $tree = $this->buildTree($index['byId']);

    foreach ($tree as &$group) {
        $group['is_expanded'] = isset($expandedGroups[$group['id']]);
    }
    unset($group);

    return $tree;
}
```

Výsledok ... orchestrátor so 14 riadkami a štyri helpery
(`indexItems`, `resolveActiveId`, `expandedGroups`, `buildTree`), každý
hlboko pod capom. Rovnaký vzor sme prehnali cez `Kernel::dispatch`
(59 → 6 ... `dispatchMatched`, `runAction`, `normalizeResult`,
`renderThrowable`) a `SettingsDatabaseMigrator::seedDefaultMenu`
(58 → 16 ... dátový literál išiel do konštanty `DEFAULT_MENU_ITEMS`).
Commity `cf0acd0` a `c85f434`.

## Warning zóna ... mechanika

Nový test `testNoNearCapMethods` berie každé telo s headroomom do
10 riadkov (51–60) a pýta sa na záznam v `NEAR_CAP_ALLOWLIST`:

```php
private const NEAR_CAP_HEADROOM = 10;

private const NEAR_CAP_ALLOWLIST = [
    'src/Modules/Pages/Interfaces/HTTP/Actions/PagesIndexAction.php::handle'
        => 'index action orchestration',
    'src/Modules/Core/Application/Services/MarkdownRenderer.php::extractFrontMatter'
        => 'frontmatter parser loop',
    // ...
];
```

Záznam nie je ospravedlnenie. Je to **dokumentované rozhodnutie** ... cesta
`path.php::method` a krátke dôvod, prečo kód leží práve tam. Padne
nasledovne ... nová 52-riadková metóda bez záznamu vypíše:

> `PagesIndexAction.php::handle (52 lines, headroom 8) — declare it in
> NEAR_CAP_ALLOWLIST with a reason, or shrink it`

Tri vlastnosti, ktoré z toho vyplývajú:

- **Nemôže rásť potichu** ... každý nový člen zóny musí niekto vedome
  zapísať a odôvodniť.
- **Nelicencuje rast** ... zapísaná metóda, ktorá narastie nad 60, stále
  padne v hard-cap teste. Zóna nie je druhý, vyšší limit.
- **Je shrink-only v duchu** ... zmenšíš metódu, vymažeš riadok. Rovnaká
  politika ako `LEGACY_BASELINE` v `ThemeFileSizeTest` alebo
  `SPECULATIVE_INTERFACE_BASELINE` v tom istom súbore.

Jedno úprimné obmedzenie ... stale záznamy (metóda zmizla alebo sa
zmenšila, ale riadok ostal) test nezachytí. Test hlási nevyplnené členy,
nie nepoužité. Nepoužitý riadok je len šum ... a šum si čistíš pri
nasledujúcom dotyku súboru.

## Prečo nie pravý warning

Prvá myšlienka býva ... nechaj test len warnovať. `phpunit.xml` v tomto
kite má:

```xml
failOnWarning="true"
failOnRisky="true"
```

Pravý PHPUnit warning by padal do CI rovnako ako fail. Zostáva echo ...
a echo sa stráca. V `--no-output` behoch, v CI logoch, v headless agentoch
... nikto neprečíta tri riadky medzi zelenými fajkami. Tiché varovanie je
`mixed` ... vyzerá ako správa, nestojí za nič.

Preto zóna nevaruje, ale **vynúti dokumentáciu**. Fail je správa, ktorú
nemožno ignorovať ... a allowlist je miesto, kde správa zostáva žiť.

## Čo to dalo

Stav po celej sérii (séria štyroch commitov, pushnutá ako `8b302fc..23ff3b6`):

| | Pred | Po |
|---|---|---|
| Najväčšie telo | 60 riadkov (headroom 0) | 54 riadkov (headroom 6) |
| Metódy v zóne 51–60 | 7 neviditeľných | 13 vedených v allowliste |
| Allowlist | — | 13 záznamov s dôvodom |
| Gáty | — | phpstan, test (3239), cs-fix, rector, lekalo ... zelené |

Čísla nezne ... zóna má 13 členov, nie nulu. To je pointa. Nula by
znamenala, že sme kód rozdelili tam, kde to nerobí zmysel, len aby čísla
vyzerali pekne. Parser loop a dátové tabuľky stoja za svoje riadky ... a
teraz to nie je názor, je to zapísané.

Posledná vrstva ... politika je dokumentovaná v
`.ai-factory/ARCHITECTURE.md` (Anti-Patterns), aby ju našiel aj ďalší
AI session, ktorá do repa vlezie bez kontextu.

## Kedy to neplatí

Warning zóna nie je univerzálny nástroj. Tri situácie, kedy neprináša
nič:

- **Malý tím bez architektúrnych testov** ... zóna žije z toho, že test
  beží v CI pri každom commite. Bez toho je allowlist len ďalší zoznam,
  ktorý nikto nečita.
- **Kód, ktorý sa ešte hýbe** ... vo vetve, kde sa metóda cez týždeň
  prerába, je každý druhý beh červený. Zónu pridaj, keď sa kód upokojí.
- **Väčší cap** ... ak tvoje funkcie prirodzene žijú na 120 riadkoch,
  zóna 111–120 nič nechraní. Najprv vyznat cap, potom zóna.

A jedna vec, ktorú to nie je ... nie je to nahrádka za code review.
Test zmeria riadky. Nezmeria, či je kód zrozumiteľný.

## Prakticky

Ak máš v repozitári pravidlo s fail thresholdom:

1. **Zmeraj, koľko telies stojí v odstupe do 10 od capu** ... jednorazový
   skript s `token_get_all`, rovnaká metrika ako v teste.
2. **Rozdel tie na cape** ... každá je budúci pad CI, helpery sú lacnejšie
   než havária.
3. **Pridaj zónu s allowlistom** ... a zapíš do nej to, čo zostalo, aj
   s dôvodom.

Pravidlo potom odpovedá na otázku ... nie „kolko riadkov je moc“, ale
„ktoré dlhé funkcie sme si vedome nechali a prečo“.

## Zdroje

- [PHP Manual — Tokenizér](https://www.php.net/manual/en/book.tokenizer.php) — `token_get_all()` a rodina
- [PHPStan — Rule levels](https://phpstan.org/user-guide/rule-levels) — ako funguje level 9
- [PHPUnit — XML Configuration](https://docs.phpunit.de/en/12.0/configuration.html) — `failOnWarning`, `failOnRisky`
- [The Zen of Python](https://peps.python.org/pep-0020/) — „Explicit is better than implicit“
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
