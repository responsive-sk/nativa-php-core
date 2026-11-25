---
title: "Rust a Lekalo pre deti ... diel 15: trace manifest — reťaz, ktorá spája špecifikáciu s testami"
series: "Rust & Lekalo"
series-part: "15"
date: "2026-09-26"
version: "1.0.1"
published: true
tags: ["rust", "lekalo", "trace-manifest", "ci", "openspec"]
excerpt: "Dokumentácia hovorí, že testy niečo strážia. Trace manifest to ukazuje ako reťaz: requirement → symbol → súbor → scenár → test → brána. Každý uzol musí existovať, každá relácia nesie hash — a guard test kontroluje reťaz na oboch koncoch."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "the-trace-manifest-a-chain-with-hashes-on-both-ends"
---

> [Diel 10.2](/blog/lekalo-upgrade-pre-deti) prešiel upgrade cez tri brány,
> [10.3](/blog/lekalo-most-v-modele-pre-deti) stavil most medzi modulmi.
> [Diel 14](/blog/posledny-pin-pre-deti) uzavrel príbeh obálok.
> Dnes 15 — trace manifest: dokument, v ktorom má dokumentácia o testoch
> dôkaz, nielen slová.

Si čitateľ `docs/lekalo-model.md`. V tabuľke trace manifestov stojí
veta: blog reťaz spája FTS, feeds, covers, comments a publish switch
s testami `BlogSearchTest`, `BlogFeedTest`, `BulkActionsTest`.

Znie to dobre. Len jedna vec tam chýba ... **dôkaz**. Nič ti nepovie,
či reťaz, o ktorej veta hovorí, ešte stojí. Kto to dnes kontroluje —
ty, pri každom vydaní, z hlavy?

Práve to rieši **trace manifest**. Nepridá žiadny runtime kód. Je to
jediný JSON dokument, ktorý reťaz „requirement → symbol → súbor →
scenár → test → brána“ zapíše ako dáta. A každá relácia v reťazi nesie
hash, ktorý sa dá znova prepočítať.

## Reťaz a jej články

Manifest sa skladá z uzlov a relácií. Uzol je jedna vec z reťaze.
Druhov je šesť a zoznam je **uzavretý** — nič medzi tým neexistuje:

| Uzol | Čo je | Ukázka z tohto repa |
|---|---|---|
| `requirement` | čo má byť | `publish-draft-switch-on-admin-articles-and-pages-tables` |
| `symbol` | deklarácia v modeli | `blog.publish_article` |
| `artifact` | súbor, ktorý to nesie | `articlepublishtoggleaction-php` |
| `scenario` | tok deklarovaný v modeli | `blog.admin_editing_flow` |
| `native_test` | PHPUnit trieda | `nativa.BulkActionsTest` |
| `gate` | brána v CI | `nativa.phpunit` |

Relácie spájajú uzly do reťaze. Aj ich druhy sú uzavreté a každý má
povolený smer:

| Relácia | Smer | Význam |
|---|---|---|
| `implements` | symbol → requirement | symbol plní požiadavku |
| `binds` | symbol → artifact | symbol žije v tomto súbore |
| `covers` | scenár → symbol alebo requirement | tok prechádza cez symbol |
| `verifies` | test → scenár alebo symbol | test dokazuje tok |
| `evidences` | gate → test | brána spúšťa test |

Nemôžeš vyhlásiť, že test overuje požiadavku priamo. Cesta vedie cez
scenár alebo symbol — tak reťaz prinúti povedať, **čím** je požiadavka
pokrytá, nielen že je.

## Hash na oboch koncoch

Toto je jadro celého nápadu. Každá relácia nesie `relationId` — SHA-256
nad tuplou `[wire, druh, z, do, výskyt]`:

```json
{
  "relationId": "sha256:1bff85d05efabbcfa3a65f3a1c34e3ddb7389dc78fe38a5fa8efa17b5dc243ce",
  "relationKind": "verifies",
  "fromNode": "test:bulk-actions-test",
  "toNode": "symbol:blog.publish_bulk",
  "occurrence": "verifies.blog.publish-bulk"
}
```

Tá relácia je skutočná — leží v `docs/lekalo/trace/blog.trace.json`,
v reťazi, ktorú sme rozšírili pre prepínač publikovania.

Pravidlo je rovnaké ako pri obálkach: obsah určuje identitu. Premenuješ
uzol — musíš prepočítať hash. Presmeruješ reláciu — hash sa zmení.
Reťaz sa nedá „len tak“ premenovať v jednom mieste a nechať druhé
stáť.

Nejde o kryptografiu. Ide o **referenčnú integritu** ako v databáze:
cudzí kľúč musí existovať na druhej strane a hash musí sedieť na oboch
koncoch relácie.

## Dve vrstvy validity

Manifest prechádza dvoma kontrolami a každá hľadá inú chybu.

Prvá je **wire gate** — `lekalo trace validate` v CI. Kontroluje
gramatiku: uzavreté druhy uzlov, povolené smery relácií, kanonické
hashe, provenance. Je to kontrola tvaru.

Druhá je **guard test** — `TraceManifestTest`. Ten istý vzor, ktorý
poznáš z obálok: „gate cannot read, test does“. Číta manifesty priamo
v PHPUnit a kontroluje, či reťaz ešte ukazuje na realitu:

| Kontrola | Čo sa stane, keď to pokazíš |
|---|---|
| symbol resolve | symbol, ktorý model nepozná → fail |
| cesty existujú | artifact ukazuje na neexistujúci súbor → fail |
| testy sú reálne | `native_test` bez PHPUnit triedy → fail |
| anchors sú verbatim | požiadavka nesúhlasí so specom → fail |
| hash relácie | prepočítaný hash nesedí → fail |
| completeness je `partial` | `full` by bola klam → fail |

Wire gate povie „reťaz je gramaticky v poriadku“. Guard povie „reťaz
je ešte pravdivá“. Potrebuješ obe — jedna bez druhej overuje iba polovicu.

## Publish chain z repa

Vezmi si reťaz, ktorá vznikla pre prepínač publikovania v admin
tabuľkách — nie abstraktný príklad, konkrétny commit z tohto týždňa.

Requirement `publish-draft-switch-on-admin-articles-and-pages-tables`
→ symboly `blog.publish_article` a `blog.publish_bulk` s oboma
efektmi → akcie `ArticlePublishToggleAction` a `BulkPublishAction`
→ scenár `blog.admin_editing_flow` → `nativa.BulkActionsTest`
→ brána `nativa.phpunit`.

Sedemnásť nových relácií, každá s vlastným hashom. Manifest narástol
na 47 uzlov, 66 relácií a 7 gapov. Pritom sa v PHP nestalo nič —
runtime je a zostáva ručne písaný. Zmenila sa len mapa, nie terén,
presne ako v [dieli 11](/blog/mapa-a-teren-pre-deti).

A teraz si predstav, že niekto v ďalšom týždni premenuje
`blog.publish_bulk`. Guard test padne v CI v tej sekunde — symbol
v manifeste už model nepozná. Bez manifestu by si na ten rozpad prišiel
až pri čítaní dokumentácie, ktorej veru nikto neverí na slovo.

## Kedy manifest nie je dôkazom

Úprimná sekcia — kedy to **neplatí**.

Prvá úprimnosť: manifest nie je pokrytie. Hovorí „toto je reťaz“,
nehovorí „toto je úplná reťaz“. Preto nesie **gapy** — typizované diery,
ktorými sám priznáva, čo nevie. Náš nesie `missing-gate` (receipty od
natívnych brán blokuje upstream #89), `missing-native-test` a
`unsupported-provider`. Kompletnosť `full` by tu bola klam — preto ju
test priamo zakazuje.

Druhá úprimnosť: manifest sa neaktualizuje sám. Je ručne písaný
dokument, ktorý validátor len kontroluje. Keď premenuješ symbol,
manifest sa nerozpadne sám — padne validátor a guard, a reťaz musíš
dotiahnuť ručne. To nie je chyba, to je zmluva.

Tretia úprimnosť: hash chráni identitu relácie, nie jej pravdu.
Môžeš deklarovať `verifies` od testu, ktorý daný tok vôbec netestuje —
a obe brány to prepustia, lebo gramaticky je to v poriadku. Guard
overuje, že uzly existujú; že relácia je **pravdivá**, to musí vedieť
ten, kto ju píše. Ani tu nestráži stroj úsudok — stráži súdržnosť.

## Čo bude ďalej

V modeli pribudli moduly `audit` a `pages` — ich reťaze čakajú na
vlastné manifesty v ďalšom dieli. Blokový slovník medzitým čaká na kryštalizáciu
v dokumentácii, presne ako sľubuje diel 10.2.

Pravidlo, ktoré si z dnes odnes: keď píšeš, že test niečo stráži,
zapíš to ako reťaz s hashmi. Veta sa dá potichu prepísať. Reťaz
s hashmi pri každej zmene hlási — niečo sa posunulo, pozri sa na to.

## Zdroje

- [Lekalo](https://github.com/ichinya/lekalo) — repozitár projektu (`lekalo trace validate|export|query`)
- [docs/lekalo-model.md](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md) — manifesty, konvencie a digesty oboch vrstiev
- `tests/Integration/TraceManifestTest.php` — guard, ktorý kontroluje reťaz na oboch koncoch
- [Diel 10.3 — most medzi modulmi](/blog/lekalo-most-v-modele-pre-deti) — scenár ako most a radius 2→3
- [Diel 14 — posledný pin](/blog/posledny-pin-pre-deti) — odkiaľ pravidlo „obsah určuje identitu“ rastie
