---
title: "Lekalo 0.6.5 pre deti ... čo príkazy povedia o projekte"
date: "2026-10-10"
version: "1.0.0"
tags: ["lekalo", "rust", "architektura", "model", "ci"]
excerpt: "Lekalo 0.6.5 pridalo sedem plôch. Každú sme skúsili spustiť proti nášmu modelu: tri bežia, dve nástroj odmietne, jedna vráti nulu, ktorej sa nemá veriť, a jedna potrebuje vstup, ktorý nevedieme. A dve pasce, ktoré sme pri ceste zaplatili."
author: "nativa.dev@example.test"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-new-commands"
published: true
---

Nový nástroj je tvrdenie.

Tvrdenie, ktoré nikto nespustil proti vlastnému projektu, je `mixed` ... vyzerá ako všetko a nestojí za nič.

Lekalo 0.6.5 pridalo sedem plôch: `trace assess`, `coupling`, `ai-lint`, `waivers`, `architecture-profile`, `metrics` a `requirements`.

Každú z nich sme skúsili spustiť proti modelu tohto repozitára.

Nehovoríme teda „podporujeme to". Hovoríme, čo vrátili.

## Začni tým, ktorý `lekalo` spúšťaš

Prvá vec, ktorú sme zaplatili, nebola v modeli.

`make` si binárku vyberá takto:

```sh
# Makefile
LEKALO ?= $(shell command -v lekalo 2>/dev/null || echo "$$HOME/.cargo/bin/lekalo")
```

`~/.cargo/bin` u nás nebolo na `PATH`, takže `command -v` nenašlo nič a `make` použilo druhú polovicu.

Tam sedela verzia `0.6.3`.

V nej nové príkazy neexistujú a `lock --check` vracia 1.

| Binárka | `--version` | `coupling`, `ai-lint`, `architecture-profile` | `lock --check` |
|---|---|---|---|
| `~/.cargo/bin/lekalo` vtedy | `0.6.3` | chýbajú | exit 1 |
| `~/.cargo/bin/lekalo` dnes | `0.6.5` | sú | exit 0 |

Rozdiel je celý v tom, čo vidíš.

„Nové plochy sú rozbité" a „beží starý CLI" vyzerajú zvonku rovnako.

Kto uverí červenému gatu bez `lekalo --version`, hľadá chybu v modeli.

Oprava je jedna stávka: archív release s overeným `sha256`, do `~/.cargo/bin/lekalo`.

`cargo uninstall lekalo-cli` bežal predtým, lebo pôvodný súbor spravoval cargo vo verzii `v0.3.2` ... a nechať záznam tvrdiť jednu verziu a binárku druhú je tá istá nezhoda medzi zápisom a vecou, ktorú zapisuje, ktorú sme stretli ešte dvakrát.

## Čo naozaj beží

Každá plocha a čo vrátila:

| Plocha | Príkaz | Výsledok proti nášmu modelu |
|---|---|---|
| `coupling` | `lekalo coupling --module users` | beží, exit 0, advisory |
| `architecture-profile` | `lekalo architecture-profile assess --module users` | beží, exit 0, advisory |
| `verify` | `make lekalo-verify` | beží, exit 0, verdikt `degraded` |
| `trace assess` | `lekalo trace assess` | blokované, `LEK-TRACE-008` |
| `coupling --all` | `lekalo coupling --all` | blokované, `LEK-COUPLING-001` |
| `waivers list` | `lekalo waivers list` | odmietnuté, `LEK-AILINT-004` |
| `ai-lint` | `lekalo ai-lint --module users` | beží a **nepreukazuje nič** |
| `metrics` | `lekalo metrics` | potrebuje `.lekalo/history/` |

Tri riadky z tej tabuľky si zaslúžia vlastnú sekciu.

## Coupling ... čo povie o jednom module

`lekalo coupling --module users` vráti za dve sekundy obraz závislostí modulu:

| Modul | Subjekty | Verejné kontrakty | Podporné | Interné | Cykly | Nálezy |
|---|---:|---:|---:|---:|---:|---:|
| `users` | 117 | 94 | 30 | 0 | 0 | 97 |
| `blog` | 106 | 71 | 18 | 0 | 0 | 78 |

Report nesie dva kódy a oba sú skutočné upozornenia, nie pečiatka:

- `coupling.evidence-incomplete` (`LEK-COUPLING-012`) ... report je úprimný o dôkazoch, ktoré nemá
- `coupling.shared-mutable-state` (`LEK-COUPLING-006`) ... viac deklarovaných operácií mení ten istý zdroj

Druhý kód je to, prečo sa to vôbec oplatí spúšťať.

Nie je to zoznam chýb, je to miesto, kde sa dvaja vlastníci bijú o jednu vec.

`fan-in` a `fan-out` na úrovni jedného subjektu povedia, čo sa pohne, keď sa pohne on.

## Architecture profile ... jedno pravidlo s dôkazom

`lekalo architecture-profile assess --module users` vráti 15 pravidiel z katalógu `architecture-core/1`.

Stav je takýto:

| Modul | Posúdenie | Pravidlá s dôkazom | Pravidlá bez dôkazu | Povinné s dôkazom |
|---|---|---:|---:|---:|
| `users` | advisory | 1 | 14 | 1 |
| `blog` | advisory | 1 | 14 | 1 |

To jedno pravidlo je `architecture.stable-semantic-ids` ... 56 deklarovaných identít pre `users` a 57 pre `blog`.

Štrnásť pravidiel bez dôkazu znie hrozivo a nie je to nález.

Je to chýbajúci adaptér.

Pravidlo, ktoré nemá čím merať, sa nerozhodne proti projektu, len sa nevyhodnotí.

Presne preto je tá plocha advisory a nie gate.

## Nula, ktorej sa nemá veriť

`lekalo ai-lint --module users` skončí s exit 0 a napíše `0 findings`.

To číslo je zradné.

V tom istom výstupe je `LEK-AILINT-003 ai-lint.coverage-incomplete` ... teda „nazbieraj najprv dôkazy, až potom tvrď čistý výsledok".

Nula nie je čistý štít. Je to prázdna tabuľka.

Kto by ju gatoval, ten by si zaviedol bránu, ktorá je zelená preto, že nič nemeria.

## Kedy to neplatí

Táto sekcia patrí do každého článku, a tak je tu aj dnes.

`coupling --all` na celý projekt **nefunguje**: chce vlastný coupling profil a bez neho vráti `LEK-COUPLING-001`.

A je v tom jedna nepríjemnosť navyše: s `--json` vráti `status: invalid` **a pritom exit 0**.

Kto gatuje podľa exit kódu, prehliadne to.

`trace assess` **nefunguje** a nebude, kým neexistuje neutrálny dokument s evidenciou validácie. Ani `verify` report sa mu nepáči, čo je presnejší tvar tej istej chýbajúcej veci.

`waivers list` **nemá čo vypísať** ... sada waiverov sa validuje proti ai-lint rodine, takže prázdna sada je `LEK-AILINT-004`, nie prázdny zoznam.

`metrics` potrebuje `.lekalo/history/`, ktorý u nás nikto nevedie. Nie je to chyba nástroja, je to chýbajúci vstup.

A posledné, najdôležitejšie: **ani jedna z týchto plôch dnes nič nezakazuje.**

Sú to reporty. Gate sú `make lekalo-check` a `make lekalo-trace`.

## Dve pasce, ktoré sme zaplatili

### Makefile je digestovaný zdroj každého trace manifestu

Každý z ôsmich manifestov v `docs/lekalo/trace/` má `evidenceDigest` ... `sha256` reálneho súboru za uzlom.

Pre gaty `make-<target>` je tým súborom **`Makefile`**.

Pridali sme do neho jeden target a všetkých osem digestov sa prepísalo naraz.

Lokálne bolo `build.py --check` čisté len dovtedy, kým sme ten commit nespravili.

CI to chytilo správne a `Lekalo` job sčervenal.

Digest súboru `Makefile` pred: `a212e116…`, po: `185aabbb…`.

Dnes je v `Makefile` poznámka, ktorá to hovorí nahlas, aby to druhý človek neplatil znova.

### Zapísané číslo starne v momente, keď sa pohne jeho zdroj

V `docs/lekalo-model.md` bola tabuľka „Canonical digests" so siedmimi `manifestDigest` hodnotami.

Po tom commite boli **všetky nesprávne** ... presne z toho istého dôvodu.

Tabuľka vyzerala autoritatívne a bola to fotka.

Dnes nesie revíziu, v ktorej vznikla, a príkaz, ktorým sa znova odvodí.

## Ako to máme zapojené

Dve plochy, ktoré bežia čisté, sme zapojili takto:

```sh
make lekalo-reports                                  # users + blog, ako markdown
make lekalo-reports LEKALO_MODULES=users,blog,forms  # širší výber
```

`bin/ops/lekalo-reports.php` spustí obe komendy pre každý modul, prečíta JSON a vykreslí jednu tabuľku na plochu.

Kódy a ich významy idú **pod tabuľku**, nie do koša ... zelený riadok, ktorý zamlčí `coupling.shared-mutable-state`, učí čitateľa, že zelený riadok znamená čistý report.

CI `lekalo` job pripája ten markdown do `$GITHUB_STEP_SUMMARY`.

Dva moduly sú základ a nie jeden: jeden modul nepovie nič o tom, či tvar drží tam, kde je model iný.

A jedna vec je v tom kroku zámerne: `LEKALO` sa predáva ako cesta, nie cez `command -v`.

Prázdne `command -v` by prepísalo default z Makefile-u ničím a zhodilo by každý riadok.

## Prakticky

1. Predtým než uveríš červenému gatu, spusti `lekalo --version`. Pinnutá verzia je `0.6.5` a všetko ostatné je iná otázka.

2. Keď chceš vedieť, čo sa pohne pri zmene modulu, spusti `lekalo coupling --module <modul>`. Je to advisory a je to rýchle.

3. Keď do `Makefile` pridáš target, spusti `make trace-build`. Osem manifestov digestuje ten súbor.

4. Keď zapisuješ číslo, ktoré vzniklo meraním, napíš k nemu aj revíziu. Bez nej je to fotka a fotky starnú.

## Zdroje

- [lekalo (GitHub)](https://github.com/ichinya/lekalo) ... semantický model, CLI a trace manifesty
- [Lekalo v0.6.5 ... release](https://github.com/ichinya/lekalo/releases/tag/v0.6.5) ... checksumované archívy a nové plochy
- [GitHub Actions ... workflow syntax](https://docs.github.com/en/actions/reference/workflow-syntax-for-github-actions) ... odkiaľ sa report dostane do job summary
- [Semantic Versioning](https://semver.org/) ... prečo je `0.6.5` a `0.6.4` medzi sebou kompatibilné
- [Nativa Minimal Documentation](/docs/getting-started) ... ako si to celé spustíš lokálne
