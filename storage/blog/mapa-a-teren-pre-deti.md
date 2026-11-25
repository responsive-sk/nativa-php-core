---
title: "Rust a Lekalo pre deti ... diel 11: mapa hovorí 393, terén 962"
series: "Rust & Lekalo"
series-part: "11"
date: "2026-09-16"
version: "1.0.2"
tags: ["rust", "lekalo", "agenti", "dokumentacia", "architektura"]
excerpt: "Ranná zdravotná kontrola repozitára prebehla na zeleno, a predsa našla chybu. Nebola v kóde: bola v mape. AGENTS.md sľubovalo 393 testov, realita mala 962. O mapách, ktoré starnú pomalšie než terén, a o tom, čomu smie agent veriť, keď číta."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "map-vs-terrain"
published: true
---

V [dieli 10](/blog/agent-state-pre-deti) sa myšlienka z Rust
fingerprintu stala nástrojom. Dnes nič nové nevzniklo, dnes sa ozval
nástroj, ktorý už beží. Ranná zdravotná kontrola repozitára: `make
pre-commit`, šesť gatí, všetky zelené. A predsa jedno číslo v reporte
nesedelo.

## Čo sa stalo

Práca bola hotová, strom čistý, takže kontrola mala byť rutina. A bola,
pokiaľ agent nezhrnul výsledok a nestál nad jedným riadkom:
`AGENTS.md` v sekcii o quality gates sľuboval 393 testov, PHPUnit ich
odpálil 962. A výrok o assertions: 1220 verzus 3145.

Číslo 393 bolo raz pravda, v auguste, keď minimal-téma dokončila
míľnik. Odvtedy prišli media, lekalo, komentáre, TOC, audit,
agent-state nástroje; každý míľnik pridal testy a nikto neprepísal
mapu. Chyba nebola v kóde ani v gateoch. Číslo starnelo potichu.

```
$ make pre-commit
...
OK (962 tests, 3145 assertions)
```

A najhoršie: **žiadny gate nespadol**. Lint, analýza,
rector, cs-fixer, testy, lekalo, všetko zelené. Pretože žiadny gate
nepočíta vzdialenosť medzi mapou a terénom. To je problém, ktorý sa
nehlási. Len mlčí a čaká, kým si ho niekto prečíta.

## Oprava: jeden riadok

Oprava stála jednu náhradu:

```diff
- PHPUnit (unit + integration + css + architecture; 393 tests, 1220 assertions, incl. …
+ PHPUnit (unit + integration + css + architecture; 962 tests, 3145 assertions, incl. …
```

Čísla sme nevymazali, číslo v dokumentácii je často najužitočnejšia
vec, čo mapa hovorí. Riešenie nebolo odstrániť sľub, ale znovu ho
urobiť pravdivým. A súčasne pripísať pravidlo: **číslo v dokumentácii
je tvrdenie s dátumom expirácie.** O tom, ktoré tvrdenia ešte žijú,
rozhodujú gaty, nie mapa.

## Prečo to nie je kosmetika

Terén sa hýbe každým míľnikom. Mapa sa kreslí rukami a nič nemeria ich
vzdialenosť. Dve úprimné cesty von: buď generovať všetky čísla do
dokumentácie z nástrojov, alebo nechať dokumentáciu úplne bez čísel.

Generovanie sme odmietli. Je to mini-Bazel a piata dohoda hovorí: sto
percent alebo nič. Dokument bez čísel tiež nie je odpoveď, práve
čísla bývajú najužitočnejšie tvrdenia. „Zero-dependency runtime“ je
tiež číslo; netestuje ho mapa, testujú gaty. Zvolili sme stredu: čísla
v mape smú byť, smú aj starnúť. Ale každý, kto mapu číta, musí vedieť,
že sa pozerá na fotografiu s dátumom, nie na živý prenos.

A tu sa vracia diel 10. Celý agent-state protokol stojí na tom, že
agent číta stav namiesto hádania. Trik funguje len vtedy, keď je stav
sám pravdivý. Zastarané číslo v mape ticho znehodnotí každé
rozhodnutie, ktoré z neho agent odvodí.

Rust to rieši dôkladne:
[doc-testy](https://doc.rust-lang.org/rustdoc/write-documentation/documentation-tests.html)
sa kompilujú a púšťajú v `cargo test`. Dokumentácia nemôže klamať o
správaní kódu, lebo ju kompilátor aj spúšťa. Najbližšie, čo má tento
kit, sú testy, ktoré čítajú „dokumentačné“ povrchy ako dáta: CSS audit
počítaný z naozaj zabudovaných tokenov, smoke test, ktorý nabootuje
dist, a pravidlo, že každý článok musí mať zdroje, stojí na ňom
`BlogArticleSourcesTest`.

---

Počet 962 zostáva pravdou len do zajtra, a to je v poriadku.
Fotografia zostáva fotografiou, aj keď terén medzičasom zmení tvar.
Nenahrádza kontrakt: kontrakty preberajú gaty.

---

## Ľudská poznámka

Mapa nie je terén. Keď sa rozchádzajú, neopravuje sa terén, opravuje
sa mapa. A keď si ju ďalší človek alebo ďalší agent prečíta, verí jej
presne tak, ako sme jej verili my ráno: že ju niekto prešiel včera.

Doklad v reálnom čase: skôr, než článok vyšiel, číslo uvedené vyššie
zastaralo. Zaradením textu do testovacej plochy pribudli dva testy a
962 sa stalo 964. Mapa dostala opravu v deň vydania. Terén nezostal
stáť ani hodinu.

---

→ [Diel 10, agentovi stačí štyri vydychy](/blog/agent-state-pre-deti),
revízny report, gate resume a determinizmus
→ [Rust documentation tests](https://doc.rust-lang.org/rustdoc/write-documentation/documentation-tests.html),
dokumentácia, ktorú spúšťa aj kompilátor

## Zdroje

- `AGENTS.md`: riadok „Build & Quality Gates“ (opravený na 962 testov /
  3145 assertions)
- `composer test`: ranný beh: 962 testov, 3145 assertions (PHPUnit 12)
- [Diel 10, agentovi stačí štyri vydychy](/blog/agent-state-pre-deti)
- [Rust documentation tests](https://doc.rust-lang.org/rustdoc/write-documentation/documentation-tests.html)
