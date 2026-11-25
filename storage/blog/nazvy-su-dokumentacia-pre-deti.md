---
title: "Clean code pre deti ... diel 2: názvy sú dokumentácia (kód, ktorý sa číta bez čítania)"
date: "2026-08-11"
version: "1.0.2"
tags: ["clean-code", "php", "architecture", "naming"]
excerpt: "Najlacnejšia dokumentácia, akú máš, je dobré meno. Diel 2: prečo `tagChips($meta)` hovorí viac než komentár, čo je problematické meno a ako spoznať, že meno klame ... na reálnych príkladoch z tohto repo."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "names-are-documentation"
published: true
---

Čítali ste niekedy kód a zastavili ste sa na riadku, ktorému ste nerozumeli? Nie preto, že by bol zložitý. Ale preto, že sa volal `$t = $this->getDataBits($m)` a vy ste nevedeli, čo je `t`, čo sú `bits` ani čo je `m`. Tri písmená, tri otázky, nula odpovedí.

## Zlé meno je diera v príbehu

V [dieli 1](/blog/co-je-cisty-kod-pre-deti) sme si povedali, že kód rozpráva príbeh. `handle()` v `BlogArticleAction` odpovedá na otázku „čo sa stane, keď niekto navštívi /blog/{slug}?“ a detaily žijú v pomenovaných helperoch. Ale čo sa stane, keď sa helper volá zle?

```php
$t = $this->getDataBits($m); // čo je t? čo sú bits? čo je m?
$tagChips = $this->tagChips($meta); // jasné bez rozmýšľania
```

Prvý riadok je **diera v príbehu**. Čitateľ sa zastaví, musí otvoriť telo metódy, dešifrovať, čo sa deje, a potom sa vrátiť. Druhý riadok plynie. Rozdiel je jeden ... **kto zaplatí čas navyše**. Pri prvom ho platí čitateľ, pri druhom nikto.

Dobré meno je dokumentácia, ktorá ťa nič nestála. Nepíšeš ju do samostatného dokumentu, neudržiavaš ju ... ona je priamo v kóde a nedá sa s kódom rozísť.

## Meno odpovedá na „čo?", nie na „ako?"

Pozrite sa na názvy helperov v `BlogArticleAction`:

| Volanie | Čo odpovedá |
|---|---|
| `$this->metaLine($meta, $authorHtml)` | „čo je v meta linke?" |
| `$this->tagChips($meta)` | „aké tagy vyrenderovať?" |
| `$this->coverImage($slug, $meta)` | „aký cover image použiť?" |
| `$this->authorHtml($meta, $resolver, $avatar)` | „ako vyzerá autorov riadok?" |

Všimnite si, že žiadny z názvov nehovorí, **ako** sa vec robí. Nevolajú sa `concatMetaWithSeparator()`, `loopTagsAndEscape()` ani `getImgFromPool()`. Hovoria, **čo** je výsledok.

Lebo keď čítate `handle()` ... príbeh z dielu 1 ... chcete vedieť, čo sa deje, nie ako.

Konvencia je jednoduchá:

- **Metódy, ktoré niečo robia, sú slovesá**: `renderCards()`, `discover()`, `resolveCover()`.
- **Metódy, ktoré vracajú pravdu/nepravdu, sú otázky**: `isActive()`, `isAllowed()`, `hasPermission()`.

`$user->isActive()` čítate ako vetu: „je používateľ aktívny?" ... a odpoveď je áno/nie. Keby sa metóda volala `active()`, museli by ste hádať, či vracia bool, objekt alebo string. Otázkový tvar `is…`/`has…` tú nejednoznačnosť zničí.

## Názov z domény, nie z technológie

Jemnejšie, ale dôležité. Pozrite sa na premenné v `BlogArticlesService`:

```php
$metaBits = array_values(array_filter([ ... ]));
```

`$metaBits` je **pojem z domény** (kúsky meta informácie o článku). Keby sa to volalo `$array1`, `$parts` alebo `$data`, hovorilo by to o tom, aký je typ dát, nie o tom, **čo dáta sú**.

| Zlé meno | Prečo zlyháva | Dobré meno |
|---|---|---|
| `$array1` | hovorí o type, nie o význame | `$metaBits` |
| `$data` | môže byť čokoľvek ... nič nehovorí | `$article` |
| `$str` | skratka typu, nič o obsahu | `$excerpt` |
| `$m` | jedno písmeno = nič | `$meta` |

Pravidlo: **premenná sa volá podľa toho, čo reprezentuje v doméne, nie podľa toho, aký má typ.** `$metaBits` ti povie „čo to je“, `$array1` ti povie len „je to pole“ ... a to už vidíš aj z kódu.

## Keď meno klame, komentár to nezachráni

Tu je pravidlo, ktoré znie presne naopak, než by ste čakali:

> **Keď meno vyžaduje komentár, meno zlyháva. Oprav meno, nie komentár.**

Lebo komentár je **druhá verzia pravdy**, ktorá sa môže rozísť s kódom. Názov sa s kódom rozísť nemôže ... je priamo v ňom. Komentár môže zastarať a klamať; názov buď platí, alebo neplatí.

Pozrite si `LocalStorageProvider`:

```php
public function isAllowed(string $relativePath): bool
{ if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) { return false; } // ...
}
```

`isAllowed()` ... žiadny komentár netreba. Meno samo hovorí: „je táto cesta povolená?"

A ak by sa metóda volala `check()`? Museli by ste písať komentár „vráti, či je cesta povolená" ... a komentár by bol priznanie, že meno zlyhalo.

Komentáre nie sú zakázané. V tomto repo nájdete komentáre, ktoré vysvetľujú **prečo** (rozhodnutie, pasca, invariant) ... nie **čo** (lebo meno hovorí samo):

```php
// 'admin' role bypasses permission checks (super-user).
if (in_array('admin', $this->roles, true)) {
```

Komentár vysvetľuje, **prečo** admin obchádza kontroly ... to z názvu nevyčítate.

## Meno, ktoré prežije vašu neprítomnosť

Test, ktorý funguje vždy: **prečítaj volanie nahlas.**

„`$this->tagChips($meta)` ... jasné."
„`$this->getDataBits($m)` ... čože?“ Ak vás to pri čítaní zastaví, meno zlyhalo ... bez ohľadu na to, ako správne je napísané telo. Dobré meno nie je o tom, aby kód vyzeral pekne. Je o tom, aby **prežil vašu neprítomnosť**. Keď odídete z projektu, keď sa pridá nový člen, keď sa o pol roka vrátite k vlastnému kódu. Meno je rešpekt k človeku, ktorý ešte neprišiel. → [Jedna vec (SRP)](/blog/jedna-vec-srp-pre-deti) — metóda aj trieda robia len jednu vec
→ [DRY vs abstrakcia](/blog/dry-vs-abstrakcia-pre-deti) — jedno miesto pravdy, žiadne rozhrania do zásoby
→ [Smoke testy](/blog/smoke-test-pre-deti) — pravidlá vynútené testom = výstražné dymové signály

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
- [Web.dev — Performance](https://web.dev/performance) — Core Web Vitals
- [Lighthouse Documentation](https://developer.chrome.com/docs/lighthouse/) — Lighthouse nástroj
