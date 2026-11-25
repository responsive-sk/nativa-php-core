---
title: "Rust a Lekalo pre deti ... diel 10.1: dizajn vstúpil do modelu"
series: "Rust & Lekalo"
series-part: "10.1"
date: "2026-09-19"
version: "1.0.1"
tags: ["rust", "lekalo", "semanticky-model", "dizajn", "wcag"]
excerpt: "Piaty modul v semantickom modeli: token identity namiesto farieb, sedem rolí, ktoré stráži WCAG audit, a dve lekcie z gramatiky. Snake_case enumy, prázdny applies_to, ktorý closed schema odmieta, a znova overený recept na pripnutý digest."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-design-enters-the-model"
published: true
---

V [dieli 9.2](/blog/lekalo-bindings-pre-deti) témy dostali routy a model
stál na 135 definíciách. Dnes pribúda piaty modul: **design**. Model tak
rastie na **142 definícií** a po prvýkrát nepopisuje správanie, ale
niečo, čo si bežný človek predstaví pod slovom dizajn: farby, povrchy
a kontrasty.

## Čo je nového v modeli

Sedem definícií v `lekalo/modules/design/`. Jadrom nie je farba, ale
**token identita**: dvojica povrchu a módu plus meno CSS premennej.

| Definícia | Druh | Čo hovorí |
|---|---|---|
| `design.role` | enum | 7 rolí, ktoré reálne kontraktuje CSS audit |
| `design.mode` | enum | `dark \| light`: dva audited bloky |
| `design.variant` | enum | 6 povrchov: 4 témy + admin + auth |
| `design.token_name` | scalar | meno CSS premennej, napr. `--st-accent-strong` |
| `design.identity` | entita | identita `[variant, mode, token]` + rola |
| `design.resolve_tokens` | query | číta identity, pripína WCAG AA kontrakt |
| `design.contrast_flow` | scenario | prepne sa mód → kontrast vydrží v oboch |

K tomu dve politiky v `authorization.yaml` (celkovo ich má projekt 13):
čítanie je `public`, admin má politiku na tom istom read povrchu.

## Rola, nie hodnota

Najdôležitejšie rozhodnutie celého dielu: model deklaruje **ktoré
identity existujú a pod akou rolou**, nie akú farbu majú. Farby sú
bajty v CSS a tie ostávajú v CSS.

`design.role` nie je vymyslený zoznam. Je to presne sedem prípon, ktoré
`BuildCssAuditTest` stráži reálnymi WCAG výpočtami: `accent_strong`,
`accent_soft`, `accent_hover`, `primary_ink`, `error_ink`, `surface`,
`tag_bg`. Dekoratívna amberová `--mag-accent` (cca 3:1, len ikony a
okraje) rolou zámerne nie je. Jej kontrakt je zákaz textu, nie minimum.

A prečo `accent_strong` a nie `accent-strong`? Presne tak. Prvá lekcia
z gramatiky.

## Dve lekcie z gramatiky

**Lekcia 1: enum hodnoty sú snake_case.** Prvý beh `lekalo validate`
spadol na `LEK-IR-006` pri každom `accent-strong`. Pomohla analógia:
blog má `members_only`, nie `members-only`. Gramatika hodnôt chce
podčiarkovníky a model ju neobíde.

**Lekcia 2: closed schema odmieta prázdny `applies_to`.** Chceli som
úprimnú admin politiku bez príkazu, tak som napísal `applies_to: []`.
`LEK-AUTH-001`, bez vysvetlenia. Bisekciou: blok bez admin politiky
prejde, prázdny zoznam nie, plný zoznam áno. Schéma nechce prázdny
záväzok.

Riešenie je poctivejšie ako placeholder: `design.policy.admin_editing`
dostal ten istý read povrch `design.resolve_tokens`. Admin si povrch
aj tak len číta. Žiadny fiktívny príkaz sme nevymysleli a write strana
ostáva nemodelovaná, lebo v runtime neexistuje.

## Pripnutý digest, tentoraz na dvoch miestach

Po pridaní modulu padol `validate --strict` na `LEK-AUTH-010`
(`state: stale-model`). Rovnaká lekcia ako v [dieli
9.1](/blog/lekalo-temy-v-modele-pre-deti), tentoraz s dôrazom:

1. recept overiť **pred** použitím → na committed modeli vyšiel presne
   pripnutý `23bdb373…`,
2. nový pin `6afbf0eb…` → `authorization.yaml`,
3. ten istý kompaktný IR digest ide aj do `irRef.digest` v attachmente
   `docs/lekalo/invariant-transition.nativa.json` (jeho stará hodnota
   sedela na rovnaký recept, takže recept je dvojito overený),
4. `modelRef.digest` v attachmente je kontrakt z locku a ostáva.

A `lekalo.lock`? Znova **byte-identický**. Zamraze resolution identity,
nie obsah modelu.

## Gate nemôže čítať CSS, test áno

Vzor z dielu 9.1 dostal druhé nasadenie:
`tests/Integration/DesignModelParityTest.php`, 5 testov.

- `design.variant` musí pokryť každý povrch, ktorého sa audit dotýka
  (parsuje sa zdroj auditu: `--st-`, `--mag-`, `--min-`, `--m-` z
  bundlu plus `adminCss()` a `authCss()` inline bloky),
- `design.role` musí byť set-rovný s auditovaným zoznamom rolí,
- `design.mode` musí byť presne `dark|light`,
- tvar kontraktu: polia identity, `reads` a `returns` query.

Nová audited rola bez zápisu do modelu → CI spadne. Model bez CSS →
tiež. Lekalo validuje model izolovane a CSS nikdy nevidí.

## Živý výstup

```
validated default profile v1.0.0: 23 rules, 0 errors, 0 warnings, 0 info
validated strict profile v1.0.0: 23 rules, 0 errors, 0 warnings, 0 info
lock checked sha256:bc4dbad9… resolver 1.0.0
```

142 definícií (31 scalar, 18 command, 18 effect, 17 endpoint, 12 query,
10 entity, 9 policy, 9 scenario, 7 enum, 6 event, 3 target-binding,
2 value-object) v piatich moduloch: users, blog, comments, themes,
**design**. Paritný test: 5 testov, 55 asercií, zelený.

## Prebudte sa

- Model dnes popisuje aj dizajnovú vrstvu. Slovník má 5 modulov.
- Jednotka modelovania je identita a rola, nie farba. CSS ostáva
  jediným zdrojom pravdy pre bajty.
- Gramatika má črevá: snake_case hodnoty a žiadne prázdne
  `applies_to`. Obidve chyby vedia vyriešiť bisekcia a analógia.
- Recept na digest overujte vždy proti známemu dobrému pinu.

Ďalší diel: bloky. Grid, section, form, nav, footer. Najprv ako bloková
matica v dokumentácii a architektonickom teste, do modelu prídu, až keď sa
slovník skristalizuje.

## Zdroje

- [Lekalo](https://github.com/ichinya/lekalo): repozitár projektu (product 0.1.31, autorizácia #25)
- [docs/lekalo-model.md](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md): dokumentácia modelu, vrátane design sekcie a receptu na refresh `model_ref.ir_digest`
- `lekalo/modules/design/*.yaml`: sémantický model Design modulu (7 definícií)
- `tests/Integration/DesignModelParityTest.php`: paritný strážca (5 testov)
- [Diel 9.1, témy v modeli](/blog/lekalo-temy-v-modele-pre-deti): prvýkrát recept na pripnutý digest
- [Diel 9.2, témy dostali routy](/blog/lekalo-bindings-pre-deti): endpoint bindings pre témy
