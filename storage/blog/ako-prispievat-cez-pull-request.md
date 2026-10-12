---
title: "Ako prispievať cez pull request: päť brán a žiadne prekvapenia"
date: "2026-10-12"
tags: ["nativa", "github", "pull-request", "ci", "proces"]
excerpt: "Vetva z masteru, päť lokálnych príkazov a PR, ktoré prejde na prvýkrát. Čo kontroluje CI, prečo modulové testy skippujú a kde sú hranice, ktoré sa tu lámu najčastejšie."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Proces"
published: true
---

Tento strom má **päť CI brán** a každá z nich existuje preto, lebo raz niečo
pustila. Návod nižšie nie je želanie — je to prepis toho, čo tie brány reálne
kontrolujú. Keď lokálne prejde päť príkazov, PR prejde tiež.

## Raz po klone

```sh
git clone https://github.com/responsive-sk/nativa-php-core.git
cd nativa-php-core
cp bin/git-hooks/commit-msg .git/hooks/commit-msg
composer install
```

Hook nie je ozdoba: históra tohto repa nenesie **žiadny agent-footer**
(konvencia z nativa-minimal) a hook ho vymaže z každej commit message skôr,
než sa zapíše. `.git/hooks` sa neversionuje, takže každý čerstvý klon potrebuje
tú jednu kópiu. `composer install` je pritom len na dev-nástroje — runtime
žiadny `vendor/` nepotrebuje a jedna z CI úloh to aj dokazuje.

## Bežná práca

```sh
git checkout -b feat/nazov-veci
# ... zmeny ...
make lint && composer phpstan
composer test
composer cs-fix-dry && composer rector-dry
git commit
git push -u origin feat/nazov-veci
```

PR sa otvára z vetvy do `master` s popisom **čo** a **prečo** — nie ako.
„Pridaný guard do `CardboardServices`, lebo odstránenie Users pod Cardboardom
končilo holým fatalom" je dobrý popis. Výpis diffu popis nenahrádza.

## Čo kontroluje CI

1. **Lint + PHPStan** (`make lint`, `composer phpstan`) — každý PHP súbor,
   level 9 v kernel-scope.
2. **Code style** (`cs-fix-dry`, `rector-dry`) — suchý beh, nič neprepisuje,
   len hlási.
3. **PHPUnit** (`composer test`) — aj katalógové testy pod `modules/*/tests/`.
4. **Assets** — `Frontend/packages` sa postaví (`pnpm build`), manifest musí
   niesť `mark`, `mark-switch` a `moonshine`, v CSS nesmie prežiť `@layer`.
5. **Boot bez vendor/** — jadro nabootuje aj bez `composer install`.

Tretí bod má jednu zradu, na ktorú sa tu už raz narazilo: modulový test
v strome **bez svojho modulu** musí **skippovať, nie padať**. A pozor —
PHPUnit púšťa `tearDown()` aj po skippnutom `setUp()`, takže guard patrí
na obe miesta. Vzor je `TaxonomyRegistryTest` pri Blogu.

## Hranice, ktoré sa lámu najčastejšie

- **Katalóg vs. inštalácia.** Zdroj je `modules/<Meno>/`,
  `src/Modules/<Meno>` je gitignorovaný operator-state. Po úprave modulu ho
  lokálne presyncuj, inak testuješ starý kód a neveríš výsledku.
- **Hranica jadra.** Kernel-súbor nesmie `use Modules\<X>\…`; závislosti
  modulu číta katalóg z `use`-riadkov (`module:show X` → `closure:` musí
  zostať acyklická). Kto potrebuje výnimku, prizná ju FQ-inline a zdôvodní
  v docblocku — nie potichu.
- **Interné nástroje** (`.agents/`, `.ai-factory.json`) patria do
  `.gitignore`, nikdy do PR.
- **Väčší zásah** si zaslúži záznam v `docs/kernel-status.md`: boot-záznam
  s nameranými stavmi, nie plánom. Meranie staršie ako reštart servera sa
  nepočíta — opcache tu klame rovnako ochotne ako cache testov.

## Prečo to funguje

Pravidlá vyššie nie sú byrokracia, ale skratky k zelenému PR. Každé z nich
vzniklo z konkrétneho pádu: cyklus v grafe modulov, test červený len v CI,
stylesheet, ktorý chýbal potichu. Dodrž ich a review sa bude venovať
myšlienke zmeny, nie jej baleniu.
