---
title: "Rust a Lekalo pre deti ... diel 10.3: most medzi modulmi a radius, ktorý sa dal zmerať"
series: "Rust & Lekalo"
series-part: "10.3"
date: "2026-09-20"
version: "1.0.1"
published: true
tags: ["rust", "lekalo", "semanticky-model", "design", "ci"]
excerpt: "Dva moduly, ktoré sa navzájom nepoznajú, a jedna zmena témy, ktorá by mala zaujímať aj audit kontrastov. Scenár ako most, jednosmerný import pretože cyklus je zakázaný, a radius, ktorý narástol z 2 na 3 — merané, nie deklarované."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-bridge-in-the-model"
---

> [Diel 9](/blog/lekalo-invarianty-pre-deti) pridal invarianty a [vetva 9.2](/blog/lekalo-bindings-pre-deti) trasy. [Diel 10](/blog/agent-state-pre-deti) bol o tom, ako si agent pamätá, [10.1](/blog/lekalo-dizajn-v-modeli-pre-deti) o piatom module modelu a [10.2](/blog/lekalo-upgrade-pre-deti) o upgrade cez tri brány. Dnes 10.3 — most medzi modulmi a radius, ktorý sa dal zmerať.

V [dieli 10.2](/blog/lekalo-upgrade-pre-deti) stál model na 142
filtroch a nástroj práve prešiel cez tri brány. Dnes pribudla ďalšia —
model má 143 definícií a desať scenárov. Nie preto, že rastie pre rast.
Pribudol **most**: scenár v module `design`, ktorý popisuje vec, o ktorej
sa doteraz model nemal ako vyjadriť ... že zmena témy je záležitosť aj
auditu kontrastov.

## Dva moduly, jedna otázka

Model má dnes päť modulov. Pre témy a dizajn platí rozdelenie, ktoré
sa správa prísne:

| Modul | Modeluje | Stav | Paritný test |
|---|---|---|---|
| `themes` | ktorá téma sa vykreslí | áno — `themes.active_state` v `config.db` | `ThemesModelParityTest` |
| `design` | čo musí token spĺňať | nie — čistý čítací kontrakt | `DesignModelParityTest` |

Oba svety sa prekrývajú na štyroch verejných témach. A práve tam vznikla
otázka, na ktorú model nemal slovo: keď admin v `/mark/settings` zmení
tému, stránky sa začnú vykresľovať cez inú rodinu tokenov. Kto v modeli
hovorí, že tá nová rodina je pod WCAG AA kontraktom?

Nikto. Odpoveď bola v teste a v hlave. V modeli nie.

## Scenár ako most

Riešenie je definícia druhu `scenario` v `lekalo/modules/design/scenarios.yaml`:

```yaml
// lekalo/modules/design/scenarios.yaml — most (skrátené)
id: design.theme_change_reaudit_flow
kind: scenario
covers:
  - design.resolve_tokens        // kontrakt auditu
  - themes.set_site_theme        // príkaz z cudzieho modulu
  - themes.effect_site_theme_set
  - themes.site_theme_set
```

Scenár pokrýva (`covers`) kontrakt z vlastného modulu a tri symboly z modulu
`themes` — príkaz, efekt aj event jednej perzistovanej zmeny témy.
Nič sa nevykonáva, nič sa negeneruje. Lekalo je deklarácia: scenár hovorí
**čo spolu súvisí**, nie ako.

Prvá odpoveď nástroja padla presne tam, kde má: `LEK-LOAD-015`,
`reference-without-import`. Referencia do cudzieho modulu bez importu
nie je skratka, je to chyba. Import sa pridá do `module.yaml`:

```yaml
// lekalo/modules/design/module.yaml
"imports": [
  "themes"
]
```

## Prečo jeden smer

Najprirodzenejšia predstava — scenár v oboch moduloch, obojsmerný most —
naráža na pravidlo loadera: **import cyklus je zakázaný**
(`loader.import-cycle`). `design` nemôže importovať `themes`, ak `themes`
importuje `design`.

Niet čo riešiť, lebo závislosť reálne ide len jedným smerom. Audit
kontraktuje povrchy, ktoré zmena témy vykresľuje — nie naopak. Zmena témy
nepotrebuje vedieť nič o audite; audit potrebuje vedieť o zmene témy.
Most je jednosmerný a tichá rovnováha vyzerá takto: most v modeli je len
jeden, stráži ho test na druhej strane a nič nešprintuje za oboch.

## Radius, ktorý sa dal zmerať

Najzaujímavejšia otázka: mení most vôbec niečo, alebo je to dekorácia?
Odpoveď sa nehádala ... zmerala sa. Porovnal som `lekalo impact
themes.set_site_theme` na commite s mostom a na jeho rodičovi — dočasný
git worktree, dve JSON obálky, diff programovo.

| Sekcia impactu | Pred mostom | S mostom |
|---|---|---|
| direct | 2 | **3** |
| transitive | 0 | 0 |
| scenáre | 1 | **2** |
| vysvetlenia ciest | 2 | 3 |
| risks / gates | 6 / 9 | 6 / 9 |

Nová hrana hovorí presne, čo má: `operation:themes.set_site_theme →
scenario:design.theme_change_reaudit_flow`, vzťah `references`,
vzdialenosť 1, dôvera `canonical`. Ktokoľvek mení príkaz na prepínanie
témy, uvidí v impakte aj auditný kontrakt. Risks a gates ostali — most
pridal **viditeľnosť**, nie nové riziká.

Aby most nemohol sprať, parity test pripína presný zoznam `covers`:
`DesignModelParityTest::testBridgeScenarioPinsTheThemesSymbolSet` padne,
keď scenár zmizne alebo zmení symboly.

## Report, nie brána

Radius sa dá čítať aj automaticky. `lekalo impact --changed --base
origin/main --worktree` projektuje zmenu pracovného stromu proti base —
presne scenár pull requestu. Vyskúšané naživo a rozhodnutie znie:
**report, nie gate**.

Dôvody sú merané, nie cítené:

- default profil len varuje (`impact.evidence-unknown`) — nikdy nepadá;
  gate by bol no-op
- `--profile strict` je dnes zamietnutý (`LEK-IMPACT-010`) — evidence
  zdroje nie sú pripojené; gate by bol vždy červený
- prázdny changed set je sám o sebe chyba (`LEK-IMPACT-002`) — krok musí
  najprv skontrolovať, či sa vôbec niečo menilo

Takže CI na PR, ktoré menia `lekalo/`, radius **vypíše**, lokálne to isté
robí `make impact-report BASE=<ref>`. Keď upstream pripojí evidence
zdroje, otázka tvrdej brány sa vráti na stôl. Dnes by brána buď nič
nezachytila, alebo by bol každý beh červený — a to nie je disciplína, to
je divadlo.

## Kedy most nedávať

Úprimne: ak tvoje moduly reálne nesúvisia, most je hluk. Tento má zmysel
preto, lebo povrchy sú **tie isté štyri témy** — audit audituje presne
tie identity, ktoré prepínač prepína. Import ide len jedným smerom, nie
pár zrkadlených entít; ak by si chcel modelovať obojsmernosť, narazíš
na cyklus a budeš musieť nájsť iný tvar.

Druhá úprimnosť: most **negeneruje žiadnu prácu**. Nepridá test, nepridá
gate, nezmení runtime. Zmení len jednu vec — kto číta impact, uvidí
súvislosť, ktorá doteraz žila v hlave. Ak ti to príde málo, spomeň si na
mapu z [dielu 11](/blog/mapa-a-teren-pre-deti): terén sa nezmenil, zmenila
sa mapa. Niekedy je to presne ten druh zmeny, ktorý stojí za commit.

## Čo bude ďalej

Blokový slovník — grid, section, form, nav — čaká na kryštalizáciu v
dokumentácii, presne ako sľubuje diel 10.2. Most medzi `themes` a
`design` medzitým ukázal tvar, ktorý sa dá zopakovať: keď dva moduly
zdieľajú povrch, jeden scenár a jeden import vyjadria súvislosť lacnejšie
ako akýkoľvek komentár.

## Zdroje

- [Lekalo](https://github.com/ichinya/lekalo) — repozitár projektu (loader doc: visibility, `loader.import-cycle`, `LEK-LOAD-015`)
- [docs/lekalo-model.md](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md) — nameraný radius 2→3 a rozhodnutie o CI reporte
- [loader.md v upstreamu](https://github.com/ichinya/lekalo/blob/v0.3.2/docs/loader.md) — importy ako vlastnosť modulu, priama viditeľnosť, cykly
- [Diel 10.1 — dizajn vstúpil do modelu](/blog/lekalo-dizajn-v-modeli-pre-deti) — token identity, roly a prvá lekcia z gramatiky
- [Diel 10.2 — upgrade cez tri brány](/blog/lekalo-upgrade-pre-deti) — wire formáty, registrácia a digest
