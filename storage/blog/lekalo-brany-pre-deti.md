---
title: "Rust a Lekalo pre deti ... diel 18: brána, ktorú nikto nespustil"
series: "Rust & Lekalo"
series-part: "18"
date: "2026-10-10"
version: "1.0.0"
tags: ["rust", "lekalo", "ci", "brany", "model", "makefile"]
excerpt: "V repozitári ležala brána, ktorá vedela odmietnuť. Mala telo, mala exit 1, mala vlastnú poznámku a nespúšťal ju nikto, lebo vyzerala ako report. Tri podmienky, ktoré bránu robia bránou, a štyri miesta, kde sa dá stratiť."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-gates"
published: true
---

> Dnes je to diel 18 série **Rust a Lekalo**. [Diel 17](/blog/lekalo-tri-verzie-pre-deti)
> je o upgráde, pri ktorom sa nepohlo nič. [Článok o nových príkazoch](/blog/lekalo-nove-prikazy)
> hovorí, čo plochy 0.6.5 odpovedali na náš model. Reč bude o bránach ...
> a o jednej, ktorú nikto nespustil.

V repozitári ležala brána.

Mala telo. Mala `exit 1`. Mala poznámku, ktorá vysvetľovala, prečo tam je.

Volal ju niekto? Nie.

`make pre-commit` ju nemenoval. CI job ju nespúšťal. Ani jeden dokument, skript alebo test sa na ňu neodvolával.

Jej meno je `make workspace-audit`.

## Hmla: brána ako štyri riadky

Jej telo vyzerá takto:

```make
workspace-audit:
	@$(LEKALO) init --adopt --dry-run --json \
		| jq -e '.detection | (workspaceRoots == ["Frontend/packages"] and monorepoTools == [] and ...)' > /dev/null \
		&& echo "workspace-audit: OK" \
		|| { echo "workspace-audit: FAILED" >&2; exit 1; }
```

`jq -e` je celá pointa.

Bez `-e` vráti `jq` nulu aj vtedy, keď predikát neplatí ... vypíše `false` a skončí veselo.

S `-e` je z výsledku rozhodnutie.

Zvyšok sú `&&` a `||`, teda čo sa stane, keď predikát prejde, a čo keď nie.

Odtiaľ plynie otázka, ktorá odlíši bránu od reportu: **vie odmietnuť?**

Táto vie. A nikto sa jej na to neopýtal.

## Kde sa schovala

Stála v `Makefile` medzi `lekalo-doctor` a `impact-report`.

Oba susedia majú v popise „informational, not a gate".

Reporty sa zámerne nezapájajú nikam ... donesú vetu do CI summary a idú ďalej. Bránu v takej štvrti prehliadne každý, kto číta zoznam cieľov očami.

Celý mechanizmus straty je v tom, že sa nič nestalo.

Nezhodila sa. Nespadla. Nezlyhala.

Len ju nezavolal nikto.

## Slnko: tri podmienky

Bránu robí bránou trojica vecí naraz:

| Podmienka | `workspace-audit` dnes |
|---|---|
| Vie odmietnuť | áno ... `jq -e` a `exit 1` |
| Niekto ju spustí | áno ... `make pre-commit` a CI job `lekalo` |
| Neprejde naprázdno | áno ... predikát meria tri konkrétne polia |

Prvú podmienku som zmeral tak, že som predikátu predložil osem podôb odpovede.

Reálny výstup CLI prešiel. Všetko ostatné padlo:

| Payload | Výsledok |
|---|---|
| reálny výstup `lekalo init --adopt --dry-run --json` | prijaté, exit 0 |
| root inde v strome | odmietnuté |
| root presunutý mimo `Frontend/packages` | odmietnuté |
| `monorepoTools` s nástrojom | odmietnuté |
| `packageManagers` bez pnpm | odmietnuté |
| `{}` | odmietnuté |
| odpoveď bez `detection` | odmietnuté |

Posledné dva riadky nie sú formalita.

Predikát, ktorý prejde naprázdno, je zelená tabuľka ... a zelená tabuľka nad prázdnym vstupom je tá istá lož ako nula z `ai-lint`.

Tretiu podmienku drží `tests/Architecture/MakeTargetsWiredTest.php`.

Ten test nerobí nič múdrejšie než to, že sa na každý cieľ `Makefile` opýta, či je zapojený do `pre-commit` alebo do CI, a keď nie, či má v zozname `NOT_WIRED` zapísaný dôvod.

Zoznam výnimiek je kniha rozhodnutí, nie zoznam prehliadnutí: kľúč, ktorý ukazuje na cieľ, čo neexistuje, test zhodí. Zapojený cieľ v zozname výnimiek tiež.

Pri prvom behu našiel dve ďalšie veci, obe skutočné.

### Cieľ, ktorý nie je phony, sa ticho preskočí

`.PHONY` vyzerá ako dekorácia do Makefile.

Je to zámok: cieľ bez neho sa preskočí a vráti nulu vo chvíli, keď v strome existuje súbor s jeho menom.

V zozname chýbali `hooks-install`, `acceptance` a `impact-report`.

Dnes je to test, nie poznámka.

### Cieľ, ktorý žije len v komentári

CI krok spúšťal `lekalo verify …` priamo.

Komentár nad ním menoval `make lekalo-verify`.

Guard, ktorý hľadá volanie, musí čítať vykonateľné riadky, lebo komentár je próza.

Toto je tá istá trieda chyby, akou je celý článok: volanie, ktoré neexistuje, a zmienka, ktorá vyzerá ako volanie.

## Rust: jeden súbor namiesto runtime

Nástroj za tou bránou je jeden binár.

Žiadny runtime, žiadne závislosti, žiadny `node_modules`. To je celý dôvod, prečo si PHP projekt môže pustiť do CI nástroj, ktorý píše niekto iný: stiahne sa archív, overí sa `sha256` a rozbalí sa jediný spustiteľný súbor.

```sh
# .github/workflows/ci.yml
sha256sum -c -                                 # pinovaný hash archívu
install -m 0755 "…/lekalo" "$HOME/.local/bin/lekalo"
echo "$HOME/.local/bin" >> "$GITHUB_PATH"
```

Súbor má na mojom počítači 34 MB a je v ňom celý nástroj.

Tá istá vlastnosť má druhú stranu, a tú som dnes zaplatil.

Rust binárky sa inštalujú do `~/.cargo/bin`, a to nemusí byť na `PATH`. `make` si preto nástroj vyberá na dvakrát:

```sh
# Makefile
LEKALO ?= $(shell command -v lekalo 2>/dev/null || echo "$$HOME/.cargo/bin/lekalo")
```

`command -v lekalo` u mňa nevrátilo nič. `$HOME/.cargo/bin/lekalo --version` píše `lekalo 0.6.5`.

Z toho plynie pravidlo, ktoré patrí k bránam: keď brána zlyhá, prvá otázka nie je „čo je v modeli", ale „ktorý binár to spustil".

`lekalo --version` je lacnejšie než hodina hľadania chyby v modeli.

A úprimne: Rust nerobí bránu správnou. Binár odpovie presne na to, na čo sa ho predikát opýta. Keby bol predikát zlý, zhodí sa rovnako spoľahlivo, len rýchlejšie.

## Noc: prečo to nikto nezapojil

Dôvod nie je lenivosť. Je to čítanie.

Keď sa plocha volá `verify`, `doctor` alebo `audit`, človek čaká report. Report nemá čo skaziť, tak sa nezapája.

Zapojí sa až vtedy, keď niekto prečíta jej telo.

## Tri veci, ktoré vyzerajú ako brána

Tento týždeň som premeral tri plochy, ktoré sa na bránu hrajú:

| Plocha | Čo povie | Čo naozaj vráti |
|---|---|---|
| `verify --ci-policy strict` | report `blocked (exit 4)` | **exit 0**, envelope `verdict: ready` |
| `ai-lint --module users` | `0 findings` | exit 0 a `LEK-AILINT-003 coverage-incomplete` |
| `architecture-profile assess` | 15 pravidiel | advisory, jedno pravidlo s dôkazom |

Prvý riadok je najzaujímavejší, lebo je vnútri nezhoda.

Hlavička reportu nesie `blocked (exit 4)`. Proces vráti nulu. Všetky tri politiky som spustil a všetky tri vrátili nulu:

| Politika | Hlavička reportu | Exit procesu |
|---|---|---|
| `default` | `degraded (exit 0)` | 0 |
| `strict` | `blocked (exit 4)` | 0 |
| `lenient` | `degraded (exit 0)` | 0 |

Kto gatuje podľa exit kódu, tomu `strict` nezakáže nič.

Kto gatuje podľa hlavičky reportu, musí report najprv prečítať. Dve rozdielne disciplíny a jedna z nich sa nedá zbaliť do `&&`.

Do komentára som preto napísal, čo som nameral.

## Dve pasce, ktoré brána zaplatila

### Report sa nedá prepísať

`make lekalo-verify` spúšťa `lekalo verify --report-file storage/cache/lekalo-verify.md`.

Prvý beh prešiel.

Druhý zlyhal s `LEK-CI-001 ci.report-write-failed`.

`--report-file` zapisuje len na miesto, ktoré ešte neexistuje, a existujúci súbor odmietne s exit 4. Cieľ teda prejde raz a potom už nikdy ... a to je presne to, čo sa stalo vo chvíli, keď naň CI krok začal ukazovať.

Oprava je jeden riadok: `rm -f` pred zápisom.

Miesto som hľadal aj inde. Čerstvá cesta vo vnútri repozitára, na koreni projektu aj v `$HOME` zapísali bez rečí.

Pravidlo je teda odmietnutie prepisu, nie zákaz písať do projektu.

### Makefile je digestovaný zdroj

Pridanie cieľa do `Makefile` prepíše osem trace manifestov.

Nástroj to nevie obísť: `Makefile` je zdroj dôkazu pre uzly typu `make-<target>`.

Poradie je preto dané: cieľ, `python3 bin/trace/build.py`, a až potom `--check`.

## Kedy to neplatí

**Brána meria tvar, nie výsledok.** `workspace-audit` stráži, že workspace root je `Frontend/packages`, že v strome nie je monorepo nástroj a že pnpm je medzi package managermi. Nepovie, či build prejde. To povie iná brána.

**Kandidát na druhú bránu je zelený z väčšej časti preto, že nemá čím merať.** `lekalo architecture-profile assess --module users --check` vracia exit 0 a posúdenie sa prepne z `advisory` na `enforced-core`. Z pätnástich pravidiel katalógu má dôkaz jedno. Štrnásť nemá čím sa vyhodnotiť, lebo v projekte nie je adaptér ... a pravidlo bez merania sa nerozhodne proti projektu, len sa nevyhodnotí.

Nula zelená je v tomto projekte stará známa: `ai-lint` vracia `0 findings` s tým istým priznaním v tej istej odpovedi.

**Model pozná osem modulov.** `users`, `blog`, `comments`, `audit`, `pages`, `themes`, `design` a `forms`. Mimo neho stoja `media`, `settings`, `locales`, `outbox`, `security` a jadro.

## Čo bude ďalej

Pravidlo adopcie je v `docs/lekalo-model.md` a je krátke: plocha sa stane bránou vtedy, keď proti tomuto modelu prebehla a vrátila nulu.

`assess --check` tú podmienku dnes splnil. `verify --ci-policy strict` nie, lebo jeho exit kód nič nezakazuje.

Zapojenie brány má štyri miesta: `pre-commit`, krok v CI, guard a dokumentáciu.

Vynechať tretie znamená, že ďalšiu bránu stratí niekto iný.

Model má na raste ešte tri moduly ... tie, ktoré už raz prelomili pravidlo, o ktorom sme si mysleli, že platí. A potom otázky čitateľa.

## Objekt, ktorý odkladáme

Adaptér nemáme a ani ho nechceme.

Bez neho ostáva štrnásť pravidiel katalógu nevyhodnotených a `verify` píše `coverage incomplete`.

Je to poctivá práca, poctivo odložená: adoptujeme model a trace manifesty, nie nástroje, ktoré sa pozerajú do cudzieho zdrojáku.

Zelená, ktorá z toho vyjde, je zelená o tvare, nie o pravde.

A nabudúce, keď model pristane v projekte, ktorý adaptér má, povie tá istá plocha viac.

## Téma

Nástroj, ktorý vie zakázať, a rozhodnutie, že sa to bude diať, sú dve rozdielne veci.

Vzdialenosť medzi nimi je jeden riadok v `Makefile` a niekto, kto si prečíta jeho telo.

→ [Diel 17 ... tri verzie a ani jedna zmena](/blog/lekalo-tri-verzie-pre-deti): upgrade, pri ktorom sa nepohlo nič
→ [Diel 15 ... reťaz s hashmi na oboch koncoch](/blog/lekalo-trace-manifest-pre-deti): odkiaľ berie manifest svoj dôkaz
→ [Čo príkazy povedia o projekte](/blog/lekalo-nove-prikazy): sedem plôch 0.6.5 a čo naozaj vrátili

## Zdroje

- [Lekalo](https://github.com/ichinya/lekalo) ... semantický model, CLI a neutrálne trace manifesty
- [Release v0.6.5](https://github.com/ichinya/lekalo/releases/tag/v0.6.5) ... checksumované archívy a sedem nových plôch
- [jq manual](https://jqlang.github.io/jq/manual/) ... čo presne robí `-e` a prečo je bez neho predikát dekorácia
- [actions/runner-images, Ubuntu 24.04](https://github.com/actions/runner-images/blob/main/images/ubuntu/Ubuntu2404-Readme.md) ... odkiaľ je `jq` na runneri, kde ho brána potrebuje
- [docs/lekalo-model.md](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md) ... tabuľka plôch s verdiktom a pravidlo adopcie
- [Nativa Minimal ... getting started](/docs/getting-started) ... ako si to celé spustíš lokálne
