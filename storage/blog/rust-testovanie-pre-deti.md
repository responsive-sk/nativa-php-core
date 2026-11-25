---
title: "Rust a Lekalo pre deti ... diel 4: kvalita v Ruste — testy, clippy, MSRV a zmluvy, ktoré sa dajú skontrolovať"
series: "Rust & Lekalo"
series-part: "4"
date: "2026-09-06"
version: "1.0.1"
tags: ["rust", "lekalo", "testovanie", "kvalita", "architektura"]
excerpt: "Diel 4 série. Diel 3 sľuboval výstup, ktorému môžeš veriť; tento diel ukazuje, ako Lekalo ten sľub drží na uzde — päť cargo príkazov, ktoré gateujú každú zmenu, pinovaný MSRV testovaný na troch operačných systémoch, hermetic fixtures so zlatými bajtmi a Node.js contract checkery."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "rust-testing-quality"
published: true
---

> Toto je diel 4 série **Rust a Lekalo**. V [dieli 3](/blog/rust-cli-pre-deti)
> sme si opísali CLI postavené na garanciách: uzavretý exit protokol,
> byte-identical kanonický výstup, pinovaný MSRV. Ale garancie sú len také
> dobré, aké je ich vynucovanie. Tento diel je o vynucovaní — kvalitatívnom
> stroji, ktorý drží Lekalo na uzde, a o tom, ako paralelne s tým, čo toto
> repo už robí. Ďalšie diely: [diel 5 ... sémantický model](/blog/semanticky-model-pre-deti)
> a [diel 6 ... Lekalo deep dive](/blog/lekalo-pre-deti).

## Päť príkazov, nula zľutovania

Lekalo dev checks je päť príkazov a každý z nich zruší build pri akomkoľvek
porušení:

```bash
cargo fmt --all -- --check                          # formátovanie je gate
cargo check --workspace --all-targets --locked      # kompiluje aj testy/examples
cargo clippy --workspace --all-targets --locked -- -D warnings   # linty ako chyby
cargo build --workspace --locked                    # reprodukovateľný build
cargo test --workspace --locked                     # testovacia sada
```

Pre PHP vývojára sa oplatí rozobrať dva detaily. Po prvé, `--locked`: každý
príkaz trvá na committnutom `Cargo.lock` — žiadny tichý drift závislostí,
nikdy. To je `composer.lock` (alebo frozen lockfile z `pnpm install
--frozen-lockfile`) povýšený na tvrdé CI pravidlo.

Po druhé, `clippy ... -D warnings`: varovania lintera sú **chyby**. Nie
„prosím, zváž opravu“ — build zomrie. To je rovnaký duch ako PHPStan na
level 9 ako gate: názor analyzátora je súčasťou definície hotového, nie
návrh. Tento kit má presne tú úlohu pre `composer cs-fix-dry`, `rector-dry`
a `composer phpstan` — a jeho CI ich spúšťa v paralelných joboch. Rovnaký
inštinkt, rovnaké stávky.

## MSRV sa testuje, nepredpokladá

Podlaha, ktorú deklaruješ v `Cargo.toml`, je sľub. Podlaha, ktorú *testuješ*,
je fakt. Lekalo CI buildí a testuje na **Linuze, Windows aj macOS** s
**presným Rust 1.80.0 toolchainom** — nie „čokoľvek najnovšie“, pinované
minimum. Ak sa niečo rozbije na MSRV alebo na akejkoľvek platforme,
pipeline zlyhá.

Toto repo robí rovnaký tanec so svojím old-browser baseline: Vite build
cieľuje ES2015 a `bin/verify-build.php` overuje dodaný výstup (žiadny
`@layer`, self-hosted assety) — deklarovaná podlaha sa neustále overuje,
nepredpokladá. Zmluva bez kontroly je želanie.

## Hermetic fixtures a zlaté bajty

Lekalo core sľub je deterministický výstup. Ako to otestuješ? **Hermetic
fixtures**: strom `tests/fixtures/`, kde má každý subsystém — loader, IR,
model, graph, effects, context, trace, inspect, validation, lockfile,
structure — vlastný fixture adresár a každý očakávaný výstup existuje ako
**golden canonical bytes**. Test je porovnanie bajtov: spusti nástroj na
fixture, porovnaj so zlatým súborom, pri akejkoľvek odchýlke zlyhaj.

V týchto testoch nie je žiadny mock, žiadna sieť, žiadny čas dňa. Rovnaký
vstup, rovnaké bajty, navždy — čo je presne tá vlastnosť, ktorú nástroj
sľubuje v produkcii. Ak sa zmení formát výstupu, zmenia sa aj zlaté súbory,
zámerne, v rovnakom commite.

## Contract checkery: nezávislý rozhodca

Tu je najnezvyčajnejšia časť — a tá, ktorú sa najviac oplatí ukradnúť. JSON
Schema kontrakty, ktoré riadia Lekalo model, lockfile, diagnostiku, grafy
a ďalšie, validuje **nezávislá** sada checkerov napísaná v Node.js — úplne
iný runtime než Rust core. CI spúšťa sadu na **Node.js 18 aj 24**,
provisionuje **presný Ajv 8.17.1** mimo checkoutu a vystavuje ho cez
`NODE_PATH`.

Zamysli sa, čo to znamená: formát nevaliduje len kód, ktorý ho produkuje.
Druhá implementácia, v druhom jazyku, s pinovaným third-party validatorom,
nezávisle potvrdzuje, že každý emitovaný dokument sedí s publikovaným
kontraktom. Rust core a Node checker musia súhlasiť — ak sa rozídu, CI sa
rozbije. Toto repo robí ekvivalent, keď CSS sada audituje *buildnutý* výstup
v `public/dist/` (WCAG AA kontrast počítaný z dodaných tokenov) namiesto
dôvery zdrojovému CSS: overuj artefakt, nie zámer.

## Čo ti to dáva

Každá garancia z dielu 3 má zodpovedajúce vynucovanie: reprodukovateľné
buildy cez `--locked`, štýl cez `fmt --check`, linty ako chyby cez clippy,
MSRV testovaný na troch OS, deterministický výstup testovaný proti zlatým
bajtom a wire kontrakty posúdené nezávislou Node sadou. Nič z toho nie je
voliteľné, nič nie je „best effort“ — pipeline je zmluva.

To je mindset, ktorý diel 5 potrebuje: ak chceš kŕmiť výstup nástroja AI
agentovi a veriť jeho odpovediam, nástroj musí byť na svoj vlastný výstup
takto paranoidný. Čo nás privádza k vrstve nad jazykom úplne — k
**sémantickému modelu**:

[ďalej: sémantický model aplikácie](/blog/semanticky-model-pre-deti)

## Zdroje

- [Lekalo — CLI kontrakt (Development checks)](https://github.com/ichinya/lekalo/blob/main/docs/cli.md) — fmt/check/clippy/test gates
- [The Rust Book — Testing](https://doc.rust-lang.org/book/ch11-00-testing.html) — unit a integračné testy
- [Rust Clippy](https://github.com/rust-lang/rust-clippy) — linty ako chyby
- [Ajv](https://ajv.js.org/) — JSON Schema validator použitý contract checkermi
