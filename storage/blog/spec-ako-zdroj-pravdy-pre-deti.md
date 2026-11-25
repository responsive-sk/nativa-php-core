---
title: "OpenSpec pre deti ... diel 4: spec ako zdroj pravdy ... štyri vrstvy, jeden príbeh"
date: "2026-08-09"
version: "1.0.2"
tags: ["openspec", "php", "workflow", "qa"]
excerpt: "Spec nie je dokument, je to zdroj pravdy. Ukážeme si, ako sa štyri vrstvy ... spec, kód, testy a QA ... navzájom kontrolujú, na live príklade author-profile change."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "OpenSpec"
translation: "spec-as-source-of-truth"
published: true
---

> Toto je diel 4 série **OpenSpec pre deti**. V [dieli 1](/blog/co-je-openspec-pre-deti)
> sme zistili, čo je change. V [dieli 2](/blog/anatomia-change-pre-deti) sme
> rozobrali jeho päť súborov a v [dieli 3](/blog/zivotny-cyklus-change-pre-deti)
> celý životný cyklus. Dnes sa pozrieme na to najdôležitejšie: ako sa
> **spec, kód, testy a QA navzájom kontrolujú** ... a prečo je spec zdrojom
> pravdy, nie len ďalším dokumentom.

Štyri tímy: spec, kód, testy, QA. Každý hovorí svoju verziu pravdy. Ale kto má pravdu? A čo sa stane, keď sa ich verzie rozídu?

## Čo znamená „zdroj pravdy“

Predstav si hru v štyroch hráčoch. Každý hovorí niečo o rovnakej veci ... ale
iným jazykom:

| Hráč | Jazyk | Hovorí |
|---|---|---|
| **Spec** | GIVEN/WHEN/THEN | Čo musí platiť (požiadavky) |
| **Kód** | PHP | Ako to naozaj funguje |
| **Testy** | PHPUnit | Čo sa overuje automaticky |
| **QA** | JSON + verify.md | Že na seba všetci traja nadväzujú |

Zdroj pravdy = ten, kto má v spore posledné slovo. V OpenSpec je to **spec**:
kód sa píše podľa neho, testy ho overujú a QA kontroluje, či kód aj testy
naozaj hovoria o tom istom spec, nie o vlastnej verzii príbehu.

## Štyri vrstvy na živej ukážke

Vezmime si change, ktorý celý čas sledujeme ... `2026-08-09-author-profile`
(autorská stránka + avatar). Jeho spec delta obsahuje štyri požiadavky.
Poďme si jednu z nich prejsť cez všetky štyri vrstvy.

**1. Spec ... čo musí platiť:**

```text
### Requirement: Self-hosted uploads serving

The starter kit MUST serve stored uploads at GET /storage/uploads/{path*}
read-only with hardened guards: dotfile basenames, .. segments, null bytes,
paths escaping storage/uploads/ and files whose server-detected MIME is not
in the upload whitelist MUST all return HTTP 404. Served files MUST use a
server-detected Content-Type and MUST never be executed.
```

Všimni si, že to nie je „treba servírovať uploady“. Je to **zoznam svetov,
ktoré musia skončiť 404** a pár vecí, ktoré musia platiť. Každá veta sa dá
zmeniť na test.

**2. Kód ... ako to naozaj funguje:**

```php
// ServeUploadedFileAction ... GET /storage/uploads/{path*}
if (!$this->isSafePath($relative)) {           // dotfile, .., null byte
    return Response::html('Not Found', 404);
}
$real = realpath($full);                        // symlink-safe containment
if (false === $real || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
    return Response::html('Not Found', 404);
}
if (!$storage->isAllowed($relative)) {          // MIME whitelist
    return Response::html('Not Found', 404);
}
```

Tri guardy, tri 404 ... presne tie svety, ktoré spec vymenoval. Kód je tak
napísaný, aby **dokazoval** požiadavku, nie aby sa jej len „podobal“.

**3. Testy ... čo sa overuje automaticky:**

```php
// ServeUploadedFileTest
foreach (['../.env', 'a/../../b.png', '..%2f..%2f.env'] as $path) {
    self::assertSame(404, $this->handle($path)->getStatusCode(), $path);
}
self::assertSame(404, $this->handle('.env')->getStatusCode());
self::assertSame(404, $this->handle('2026/08/09/probe.php')->getStatusCode());
```

Každý svet zo spec má svoj test. A nielen ten priamy ... e2e testy poháňajú
request cez celý Kernel, takže sa overuje aj dekódovanie URL (`%2e%2e%2f` →
`../`), ktoré priame volanie akcie nevidí.

**4. QA ... že na seba nadväzujú:**

A tu prichádza vrstva, ktorú väčšina projektov nemá. Keď sa change dokončí,
QA (skill `aif-verify`) vytvorí **coverage.json** ... maticu, ktorá každú
požiadavku zo spec prepojí s konkrétnymi súbormi implementácie a konkrétnymi
testami:

```json
{
  "requirements": [
    {
      "id": "starter-kit.self-hosted-uploads-serving",
      "status": "covered",
      "implementation_evidence": [
        "src/Modules/Core/Interfaces/HTTP/Actions/ServeUploadedFileAction.php"
      ],
      "test_evidence": [
        "tests/Integration/ServeUploadedFileTest.php"
      ],
      "rules_gate": "pass"
    }
  ],
  "summary": { "covered": 4, "partial": 0, "missing": 0 }
}
```

Toto je **dôkaz**, nie sľub. „Pokryté“ neznamená „myslíme si, že to
funguje“ ... znamená: tu je súbor, ktorý to implementuje, a tu je test, ktorý
to overuje.

## Prečo štyri vrstvy a nie dve

Keby stačil kód a testy, všetko by bolo jednoduchšie. Ale každá vrstva chytá
**iný druh klamstva**:

| Vrstva chytá | Príklad z reálneho života |
|---|---|
| Testy chytia **regresiu** | Kód prestal robiť to, čo robil včera |
| Kód chytí **nefunkčný sen** | „Veď uploady už fungujú“ ... nič ich neservírovalo |
| Spec chytí **zlé zadanie** | „servírovať uploady“ bez zoznamu 404-svetov |
| QA chytí **medzeru medzi vrstvami** | Požiadavka, ktorá nemá žiadny test ... „pokryté“ bez dôkazu |

Najzaujímavejší je posledný riadok. Na author-profile change sme mali 194
testov ... a predsa review našiel tri skutočné problémy (identity-keyed grid,
nonce substitúcia na binárnych odpovediach, preview fallback). Testy overujú,
že kód robí to, čo má. **QA a review overujú, že to, čo má robiť, je
správne.** Preto sa v dieli 3 celá fáza 5 volá „kód sa píše viackrát, než je dokonalý“.

## Aif-verify ... záverečný kontrolór

Keď je change hotový, `aif-verify` nielen spustí gates ... on ich **dosvedčí**
do `verify.md`. Pozri sa na ten dokument z predchádzajúceho change
(`2026-08-08-mobile-nav-menu`):

```text
## 3. Quality gates
| Gate | Result |
| PHPUnit (unit + integration + css + architecture) | 160 tests |
| PHPStan level 9 (full project) | [OK] No errors |
| PHP-CS-Fixer dry-run | 0 files |
| Codeception acceptance | 13 tests |

## 4. Coverage
All 3 spec-delta requirements covered by tests + live verification

## Assessment
Verdict: PASS ... all quality gates green, OpenSpec validates with zero
issues, all 11 tasks complete, tasks ↔ spec requirements consistent
```

Všimni si poslednú vetu: **tasks ↔ spec requirements consistent**. To je
celý zmysel. QA nekontroluje len „prešli testy“ ... kontroluje, že každá
požiadavka má úlohu, každá úloha má test a každý test sa vzťahuje na
požiadavku. Štyri vrstvy tvoria **uzavretý kruh**, v ktorom sa nedá stratiť.

## Čo sa stane, keď jedna vrstva klame

Skúsme si predstaviť, že by sme štvrtú vrstvu nemali. Človek by povedal
„change je hotový, testy prešli“. A testy prešli, lebo testovali len polovicu
požiadavky ... tú, ktorá sa ľahko testuje. Zvyšok je „pokrytý“ v tom zmysle,
že o ňom nikto nehovorí.

S coverage.json to nejde. Každá požiadavka musí ukázať **konkrétny súbor
implementácie a konkrétny test** ... alebo je `missing` a change sa
nearchivuje. Žiadne „veď to asi funguje“. Buď máš dôkaz, alebo nemáš
change.

## Live status: kde je author-profile práve teraz?

A opäť meta-vrstva. Tento článok píšem v momente, keď author-profile change
má hotové všetky štyri vrstvy:

- **Spec** ... 4 požiadavky v `specs/starter-kit/spec.md`
- **Kód** ... BlogArticlesService, AuthorProfileAction, ServeUploadedFileAction…
- **Testy** ... 194 testov, vrátane e2e traversal testov cez Kernel
- **QA** ... ešte nie: coverage.json a verify.md pre tento change čakajú na
  záverečný `aif-verify` pred archiváciou

To znamená, že keď si to čítaš, change je buď už celý v archíve ... alebo má
štyri vrstvy pripravené a čaká na poslednú. Oboje je správne. Zdroj pravdy
nie je o tom, že je všetko hotové ... je o tom, že **vždy vieme, čo je dokázané
a čo ešte nie**.

## Prečo to celé robíme

Na začiatku série sme si povedali, že OpenSpec je návod na auto s históriou
rozhodnutí. Dnes vidíme ten druhý polčas: je to aj **systém dôkazov**. Spec
hovorí, čo má platiť. Kód to dokazuje. Testy to strážia. QA kontroluje, že
nikto z nich neklame. A keď sa o pol roka niekto spýta „prečo uploady
servíruje práve tento route a prečo 404 a nie 403?“ ... odpoveď je celá v
archíve.

V [dieli 5](/blog/openspec-a-ai-factory-pre-deti) si ukážeme posledný diel
skladačky: ako celý tento workflow riadia AI Factory skills ... `aif-plan`,
`aif-implement`, `aif-verify`, `aif-done` ... a ako vyzerá spolupráca človeka
a AI v tomto systéme. Dočítame sa!


→ [OpenSpec pre deti ... diel 1](/blog/co-je-openspec-pre-deti) — čo je OpenSpec
→ [Anatómia change](/blog/anatomia-change-pre-deti) — päť súborov, päť otázok
→ [Flow a ADR](/blog/flow-a-adr-starter-kitu) — mindset za starter kitom

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — bezpečnostné best practices
- [W3C — Web Standards](https://www.w3.org/standards/) — web štandardy
