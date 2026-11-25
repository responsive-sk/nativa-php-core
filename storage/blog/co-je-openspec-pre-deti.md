---
title: "OpenSpec pre deti ... diel 1: čo je OpenSpec a prečo sa zmeny nepíšu priamo do spec"
date: "2026-08-09"
version: "1.0.2"
tags: ["openspec", "php", "workflow"]
excerpt: "Nový seriál. Čo je OpenSpec, čo je change a prečo v tomto projekte nikto nesiahne priamo do spec ... s živým príkladom, ktorý beží súbežne s týmto článkom."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "OpenSpec"
translation: "what-is-openspec"
published: true
---

Zmeny v projekte sa nepíšu priamo do spec. Majú svoju cestu — od nápadu cez proposal, design, tasks, až po archív. Táto cesta sa volá OpenSpec.

> Toto je diel 1 novej série **OpenSpec pre deti**. V sérii si ukážeme, ako sa
> v tomto projekte riadia zmeny: čo je OpenSpec, z čoho sa skladá jeden change
> a ako vyzerá jeho cesta od nápadu až po archív. Dnes začneme od úplného
> začiatku ... **prečo sa zmeny nepíšu priamo do spec**.

## Návod na auto vs. návod na projekt

Predstav si, že staviaš auto podľa návodu. Návod má 200 strán. Jedného dňa si
povieš: „chcem iné sedačky“ ... a **rovno do návodu** prečiarkneš sedačky a
nakreslíš nové. Funguje to? Chvíľu. Ale o mesiac si už nikto nepamätá, prečo
sú sedačky iné, ktoré časti auta sa kvôli tomu zmenili a či sa to vôbec
testovalo. Návod je síce aktuálny, ale **história rozhodnutí je preč**.

V tomto projekte máme návod ... je to **spec** (`openspec/specs/starter-kit/spec.md`)
a je to zdroj pravdy: 39 požiadaviek, každá s GIVEN/WHEN/THEN scenármi. A platí
tu jedno prísne pravidlo: **do spec sa nepíše priamo**. Každá zmena
správania prejde cez **change**.

## Čo je change

Change je malý, ohraničený balíček, ktorý popisuje JEDNU zmenu:

```text
openspec/changes/2026-08-09-blog-author-linking/
├── .openspec.yaml        # meta: kedy vznikol, čo je cieľ
├── proposal.md           # prečo to robíme (aj čo NEROBÍME)
├── design.md             # ako to technicky urobíme + limity
├── tasks.md              # úlohy: implementácia, testy, kvalita
└── specs/starter-kit/    # nové požiadavky pre canonical spec
    └── spec.md
```

Keď je change hotový a overený, presunie sa do `openspec/changes/archive/` a
jeho požiadavky sa zlúčia do canonical spec. **Archív je história rozhodnutí** ...
presne to, čo pri návode na auto chýbalo. V tomto repo je už sedem archivovaných
zmien, od prvého `minimal-starter-kit` cez locale modul až po mobilné menu.

## Prečo to nie je byrokracia

Na prvý pohľad to vyzerá ako papiere navyše. Ale každý súbor rieši jednu vec:

| Súbor | Odpovedá na otázku |
|---|---|
| `proposal.md` | Prečo to robíme? Čo zámerne NErobíme? |
| `design.md` | Ako presne? Ktoré pravidlá projektu musíme rešpektovať? |
| `specs/…/spec.md` | Ako to overíme, že to funguje? (GIVEN/WHEN/THEN) |
| `tasks.md` | Čo konkrétne treba spraviť a kedy je to hotové? |
| `archive/` | Prečo sme sa takto rozhodli (o pol roka)? |

Malé zmeny = malý change. V archíve je aj `2026-08-07-fix-pentest-f7-f8` ...
oprava dvoch bezpečnostných nálezov, ktorá šla cez presne ten istý workflow.
Nič sa neriadi veľkosťou zmeny, ale tým, či mení správanie.

## GIVEN / WHEN / THEN ... reč požiadaviek

Požiadavky v spec sa nepíšu ako „treba prepojiť autora s userom“. Píšu sa ako
**overiteľné scenáre**:

```text
GIVEN článok s author_email: eva@example.test
WHEN sa vyrenderuje /blog
THEN sa zobrazí meno Evy Autorky z users databázy
```

GIVEN = čo je pripravené. WHEN = čo sa stane. THEN = čo musí byť pravda.
Takáto požiadavka sa dá **strojovo overiť** ... test ju buď splní, alebo nie.
Žiadne „malo by to fungovať“.

## Živý príklad: tento článok píšeme súbežne so change

A to je pointa celej série. Kým píšem tieto riadky, beží v repo change
`2026-08-09-blog-author-linking` ... prepojenie autora článku so skutočným users
účtom. V [dieli 2](/blog/anatomia-change-pre-deti) si rozoberieme, čo presne
je v každom súbore takéhoto change, a v [dieli 3](/blog/zivotny-cyklus-change-pre-deti)
prejdeme celú cestu od nápadu po archív ... na tomto živom príklade.

A najkrajšia meta-vrstva? **Tento článok o tom, ako sa robí change správne,
vzniká presne tým spôsobom, o ktorom rozpráva.** Čisté návody, čisté
rozhodnutia, čistý archív. 

→ [Spec ako zdroj pravdy](/blog/spec-ako-zdroj-pravdy-pre-deti) — prečo spec žije
→ [Životný cyklus change](/blog/zivotny-cyklus-change-pre-deti) — od nápadu po archív
→ [Anatómia change](/blog/anatomia-change-pre-deti) — čo sa deje vnútri

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — bezpečnostné best practices
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
- [W3C — Web Standards](https://www.w3.org/standards/) — web štandardy
