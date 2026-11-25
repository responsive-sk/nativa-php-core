---
title: "PHP typy pre deti ... diel 6: prečo 60 riadkov? (chunky rule ... jedna metóda, jedna vec)"
date: "2026-08-09"
version: "1.0.1"
tags: ["php", "clean-code", "architecture", "refactoring"]
excerpt: "Jedna metóda = jedna vec, pomenovaná. Záverečný diel série o tom, prečo starter kit zakazuje metódy dlhšie ako 60 riadkov ... a čo sa stane, keď to porušíš (reálny príbeh z codebase)."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "chunky-rule"
published: true
---

> Toto je diel 6 série **PHP typy pre deti**. Predošlé diely: [diel 1 ... čo sa skrýva za premennou](/blog/php-typy-pre-deti), [diel 2 ... scalar casts](/blog/scalar-casty-pre-deti), [diel 3 ... prísne typuj pred iteráciou](/blog/typuj-pred-iteraciou), [diel 4 ... array vs list, DTO a mixed](/blog/array-list-dto-pre-deti) a [diel 5 ... union types, nullsafe a enumy](/blog/union-nullsafe-enumy-pre-deti). Dnes to celé uzavrieme pravidlom, ktoré v starter kite **vynucuje** architektúra test.

## Pravidlo, ktoré ťa nenechá písať dlhé metódy

V starter kite existuje architektúra test (`tests/Architecture/CorePatternTest.php`), ktorý prehľadáva **celý `src/`** a hľadá metódy dlhšie ako **60 riadkov**:

```php
private const MAX_METHOD_LINES = 60;

// ...
if (null !== $closeLine && $closeLine - $openLine > self::MAX_METHOD_LINES) {
    $violations[] = sprintf('%s::%s (%d lines)', …);
}
```

Keď test nájde porušenie, spadne s hláškou:

```
Methods longer than 60 lines are forbidden (chunky: one screen per function):
src/Modules/Core/Interfaces/HTTP/Actions/BlogArticleAction.php::handle (65 lines)
```

Nie je to „odporúčanie“ ani code review poznámka ... je to **červený test, ktorý musíš opraviť, aby build prešiel**. A to je presne pointa: pravidlá, ktoré nie sú vynútené, sa nedodržiavajú.

## Prečo „jedna obrazovka“

Názov hovorí všetko ... **chunky** (z anglického „chunk“ = kus): metóda má byť taká veľká, aby sa **celá zmestila na jednu obrazovku**. Prečo práve toto?

Keď metóda prerastie obrazovku, prestaneš ju „vidieť celú“. Čítanie 80 riadkov vyžaduje:

- scrollovanie hore-dole
- držanie kontextu v hlave („čo bolo na riadku 10, keď som na riadku 70?“)
- hľadanie, kde sa čo mení

A presne to je pre deti ... čisté hlavy ... najdôležitejšie: **krátka metóda s dobrým menom sa dá pochopiť bez toho, aby si ju musel celú prečítať.** Prečítaš názov a už vieš, čo robí.

## Reálny príbeh z codebase ... ako to vyzerá, keď to porušíš

Tento článok má **živý príklad priamo z tohto projektu**. Pri refaktorovaní `BlogArticleAction` som pridal normalizáciu tagov na hranici (diel 3!) priamo do metódy `handle()`. A čo sa stalo? Test spadol:

```
BlogArticleAction.php::handle (65 lines)
```

Presne 5 riadkov cez limit. Oprava bola jednoduchá a je to **ten istý vzor, ktorý učí celá séria**: vyrieš problém na hranici a rozdeľ na pomenované helpery.

Predtým ... všetko natlačené v `handle()` (skrátené):

```php
public function handle(Request $request): Response
{
    // … slug validácia, čítanie súboru, front matter …

    $metaBits = array_values(array_filter([ … ]));   // 5 riadkov logiky
    $tags = is_array($meta['tags'] ?? null)          // normalizácia tagov
        ? array_values(array_filter(array_map(…)))   // …
        : [];
    $tagChips = '';
    if ([] !== $tags) { … }                          // render tagov
    // … render článku …
}
```

Potom ... `handle()` len **rozpráva príbeh**, detaily žijú v pomenovaných metódach:

```php
public function handle(Request $request): Response
{
    // … slug validácia, čítanie súboru, front matter …

    $title = is_string($meta['title'] ?? null) ? $meta['title'] : ucfirst($slug);
    $excerpt = is_string($meta['excerpt'] ?? null) ? $meta['excerpt'] : '';

    $metaBits = $this->metaBits($meta);   // čo je v meta linke?
    $tagChips = $this->tagChips($meta);   // aké tagy vyrenderovať?

    // … render článku s $metaBits a $tagChips …
}

/** Date/category/author meta line, non-empty values only. */
private function metaBits(array $meta): array { … }

/** Render tag chips footer ... tags normalized at the boundary. */
private function tagChips(array $meta): string { … }
```

Všimni si, čo sa stalo:

1. **`handle()` odpovedá na „čo sa stane?“** ... príbeh: načítaj → normalizuj → renderuj
2. **Helpery odpovedajú na „ako?“** ... `tagChips()` vie, ako sa robia tagy
3. **Mená sú dokumentácia zadarmo** ... čítaš `$this->tagChips($meta)`, nemusíš čítať telo

## Prečo to súvisí s typmi (áno, je to séria o typoch!)

Celá séria viedla k tomuto pravidlu. Pozri, ako sa jednotlivé diely spájajú:

| Diel | Lekcia | Ako drží metódy krátke |
|---|---|---|
| [Diel 1](/blog/php-typy-pre-deti) | Premenná nemá typ | Typové checky v dlhých metódach = chaos |
| [Diel 2](/blog/scalar-casty-pre-deti) | Cast-at-boundary | Rozhodni typ raz na hranici → menej vetiev v tele |
| [Diel 3](/blog/typuj-pred-iteraciou) | Typuj pred iteráciou | Čistá iterácia = krátky cyklus |
| [Diel 4](/blog/array-list-dto-pre-deti) | Shape/DTO | Keď metóda rastie, dáta chcú DTO, nie ďalšiu vetvu |
| [Diel 5](/blog/union-nullsafe-enumy-pre-deti) | Union/never | Presné typy → menej defensívnych checkov |

A teraz chunky rule: **keď metóda prerastie 60 riadkov, je to symptóm** ... nie príčina. Symptóm toho, že sa v nej deje viac ako jedna vec. A riešenie je vždy rovnaké:

1. **Normalizuj na hranici** (diel 2+3) ... surové dáta vyrieš skôr
2. **Rozdeľ na pomenované helpery** (tento diel) ... jedna metóda = jedna vec
3. **Ak rastie aj tak ... DTO** (diel 4) ... dáta chcú triedu

## Čo limit NIE JE

Dôležité upozornenie ... 61 riadkov nie je „zločin“. Detektor je **smoke test** (výstražný dymový signál), nie meradlo kvality:

- **Nie je to o počte** ... je to o „zmestí sa to na obrazovku?“
- **Nie je to dogmatické** ... sú legitímne dlhé metódy (napr. veľký `match` alebo SQL builder). C-pattern to rieši elegantne: `match` výrazy a deklaratívne dáta sa dajú vytiahnuť do konštánt/private metód
- **Je to vynútené, aby sa o tom nemuselo diskutovať** ... keď je pravidlo automatické, nemusíš argumentovať v code review

A ešte jedna vec: **`handle()` je dokonalý príklad prečo to funguje**. Keď príde request na `/blog/hello-world`, `handle()` odpovie na „čo sa stane“ v ~40 riadkoch ... a každá časť je pomenovaná. Deti (a všetci ostatní) čítajú flow bez scrollovania.

## Prakticky ... ako si to nacvičiť

Keď nabudúce píšeš metódu a cítiš, že rastie:

1. **Spočítaj si, čo robí** ... ak vieš povedať „toto robí A, B a C“ → sú to tri metódy
2. **Pomenuj tie tri veci** ... `normalize()`, `validate()`, `render()`
3. **Vytiahni detaily do helperov** ... `handle()` rozpráva, helpery robia
4. **Nechaj test, nech ťa stráži** ... `composer test` ti povie, keď to prerastie

A pamätaj: limit nie je tvoj nepriateľ. Je to **detektor, ktorý ťa upozorní skôr, než si sám všimneš**, že metóda prestala byť jednou vecou.

Krátka metóda s dobrým menom je rešpekt — rešpekt k ďalšiemu človeku, ktorý bude čítať kód, rešpekt k tvojmu budúcemu ja, rešpekt k myšlienke, že kód sa číta viac, než píše.

---

→ [Diel 1 — čo je za premennou](/blog/php-typy-pre-deti) — základ
→ [Diel 5 — union typy, nullsafe a enumy](/blog/union-nullsafe-enumy-pre-deti) — presné typy
→ [Chunky rule EN](/blog/chunky-rule) — English version 

## Zdroje

- [W3C — ACID Properties](https://www.w3.org/TR/xml-core/#sec-conformance) — ACID definícia
- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [SQLite Documentation](https://www.sqlite.org/docs.html) — SQLite referenčná príručka
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — bezpečnostné best practices
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
