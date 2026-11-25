---
title: "GitHub Actions pre deti ... osem jobov a jeden príkaz doma"
date: "2026-10-08"
version: "1.0.3"
tags: ["ci", "github-actions", "test", "browser", "architektura"]
excerpt: "Osem jobov v GitHube a jeden príkaz doma. Dva gate v CI — prvý rýchly, druhý dlhý — a jeden príkaz doma, ktorý robí to isté, len bez čakania. Čo robia, prečo bežia paralelne a kde ešte duplikujú."
author: "nativa.dev@example.test"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "ci-for-kids"
published: true
---

Si PHP vývojár a hľadáš spôsob, ako spraviť push, čo vráti zelené fajky.

Tu je to.

## Osem jobov, dva príkazy

Náš workflow je súbor `.github/workflows/ci.yml`.

Má 743 riadkov a osem jobov, ktoré bežia paralelne: `phpstan`, `code-style`, `rector`, `tests`, `acceptance`, `browser`, `supply-chain` a `lekalo`.

Päť z nich spúšťaš aj doma na localhost:

| Job v CI | Príkaz doma | Čo to chytá |
|---|---|---|
| `phpstan` | `composer phpstan` | typy, level 9, nulová tolerancia |
| `code-style` | `composer cs-fix-dry` | formát kód nedáva komentovať |
| `rector` | `composer rector-dry` | automatické úpravy, ktoré by sa mali opraviť |
| `tests` | `composer test` | 4535 testov, 33141 asertov |
| `lekalo` | `make lekalo-check` + `make lekalo-trace` | semantický model a trace manifesty |

Doma to celé volá jeden príkaz.

```sh
# Makefile — pre-commit je to isté, čo CI, len v jednom procese
make pre-commit   # lint + analyze + test + lekalo-check + lekalo-trace
```

Prečo sú to tie isté príkazy a nie dva podobné zoznamy?

Lebo zoznam, ktorý existuje dvakrát, sa raz rozíde.

Keby sme mali `composer test` inde než v CI, prvý človek, ktorý pridá test do jedného a zabudne na druhý, zistí pravdu až o týždeň — a pravda bude červený `master`.

## Gate, ktorý existuje len doma, zhnije

Acceptance suite u nás dlho žila v Makefile ale nebežala v CI.

Nebola zlá.

Bola to sada Codeception testov, ktorá sa dala spustiť proti živému serveru — a spúšťal ju málokto, takže hnila celé týždne, keď sa témy menili.

Keď sme ju pustili, zlyhala na troch zo štyroch tém: test špeciálne ošetroval jednu tému a pre ostatné kontroloval markup, ktorý v nich nikdy ani nebol.

Nikto si to nevšimol, pretože súbor, ktorý sa nespúšťa, nič nehlási.

Dnes je acceptance vlastný job v CI.

Je to ale drahý job — štartuje server, migruje databázy a seeduje fixtúry — a práve preto do CI patrí: keď je gate drahý, musí sa spúšťať bez toho, aby si naň niekto musel spomenúť.

## Tri štarty aplikácie ... a jedna duplikácia

Dva z našich jobov — `acceptance` a `browser` — potrebujú bežiacu aplikáciu.

Sekvencia je vždy rovnaká: vytvor `storage` adresáre, spusti `php bin/cms migrate`, nasaď `php bin/cms seed`, do toho ešte `php bin/cms forms:seed`, potom štart servera a čakanie, kým odpovie.

```sh
# .github/workflows/ci.yml — acceptance aj browser si štartujú server sami
php -S 127.0.0.1:8000 -t public bin/dev/dev-server-router.php > storage/logs/browser-server.log 2>&1 &
for i in $(seq 1 30); do
  if curl -fsS -o /dev/null http://127.0.0.1:8000/ 2>/dev/null; then break; fi
  sleep 1
done
```

Táto sekvencia je ale v súbore trikrát: pre `acceptance`, pre `browser` a raz pre debug server na porte 8010.

A buďme úprimní: toto je mínus.

Tri kópie jednej pravdy sú tri miesta, kde sa zabudne na `forms:seed` — a prejaví sa to ako chyba v téme ale nie ako chýbajúci seed.

Plán je jeden skript, ktorý celú sekvenciu vlastní, a CI ho len zavolá s portom a logom.

Kým ho nemáme, je to naša najväčšia duplikácia v CI.

## Prehliadač, ktorý vidí to, čo DOM nevidí

Najzvláštnejší job je `browser`.

Má 29 krokov a 17 z nich spúšťa Node skript, ktorý hovorí s reálnym Chromom cez Chrome DevTools Protocol.

Robíme to preto, že DOM test vidí strom, ale nie to, čo čitateľ vidí na obrazovke.

Keď je na stránke téma, ktorú práve testujeme, prepneme ju príkazom.

```sh
# .github/workflows/ci.yml — každý check dostane deterministickú tému
php bin/dev/set-theme.php starter
node bin/checks/toc-scrollspy-check.mjs http://127.0.0.1:8000/blog/<slug> st-article-toc__link
```

Téma sa nastavuje 16-krát v rámci jobu, pretože check bez deterministickej témy meria niečo, čo si nevybral.

Čo našiel prehliadač a čo testy nevideli? Zoznam je nepríjemne dlhý:

- scrollspy okruhu, ktorý sledoval scroll a nikdy neaktualizoval aktívnu položku,
- prepínač témy, ktorý bol naviazaný dvakrát, takže prvý klik nič nerobil,
- burger v téme `magazine`, ktorý sedel 78px od pravého okraja,
- menu, ktoré ignorovalo Escape a nevrátilo fokus,
- odkaz na karte, ktorý bol vnorený do iného odkazu,
- web font, ktorý po výmene posunul hero o 50px — 0.336 CLS,
- editor, ktorý sa otvoril ako sheet až na druhý klik.

Každá z týchto chýb prešla všetkými PHP testami, pretože PHPUnit vie o CSS a o fokuse presne toľko, čo o počasí.

Preto máme aj check bez JavaScriptu: `bin/checks/nojs-check.mjs` vypne vykonávanie skriptov cez `Emulation.setScriptExecutionDisabled` a skontroluje, že disclosure sa aj tak otvorí a formulár sa aj tak odošle.

## Test, ktorý číta zoznam, nedokazuje zoznam

Toto je lekcia, ktorú sme tento týždeň dostali dvakrát.

Prešli sme si kampaňou, v ktorej viac ako sto súborov spomínalo skripty v adresári `bin/`.

Skripty sme presunuli do podadresárov podľa roly a nástroje na to máme: `BinLayoutTest` aj `CheckScriptsWiredTest`.

A napriek tomu bolo štrnásť check skriptov rozbitých — importovali `'./lib/cdp.mjs'`, čo po presune znamená `bin/checks/lib/cdp.mjs`.

Node by pri behu vyhodil `ERR_MODULE_NOT_FOUND`.

Ale `composer test` nespúšťa ani jeden check.

`CheckScriptsWiredTest` kontroluje, že skript je uvedený v CI — teda reťazec v súbore, nie to, či sa dá spustiť.

`CheckScriptsShareCdpTest` kontroluje, že check používa zdieľanú knižnicu — nie to, či ju vie načítať.

Všetky brány boli zelené a štrnásť skriptov bolo mŕtvych.

Rovnakú chybu sme mali o jednu vrstvu nižšie: šestnásť skriptov počítalo cestu k repozitáru ako `dirname(__DIR__)` alebo `$(dirname "$0")/..`.

Kým sedeli priamo v `bin/`, bola to pravda.

Po presune to bol `bin/`.

Tichá polovica je horšia než tá hlasná: skript, ktorý z tej cesty **číta**, spadne.

Skript, ktorý z nej **píše**, si vedľa seba vytvorí druhý strom a skončí s nulou.

A tak `bin/build/build-dist.sh` namieril `DIST_DIR` do `bin/dist`, `mkdir -p` ho vytvoril a build hlásil úspech.

Dnes cestu k repozitáru nehľadá nikto počítaním — hľadá ju marker `src/init.php` cez `bin/lib/repo-root.mjs`, `repo-root.php` a `repo-root.sh`.

A `BinLayoutTest` okrem mapy adresárov rozresolvuje každú statickú relatívnu referenciu pod `bin/`.

Každá nová brána je u nás overená mutáciou, ktorá **musí** zlyhať.

Guard, ktorý sme nikdy nevideli spadnúť, je komentár.

Presne to sa stalo pravidlu o dĺžke metódy: bolo zelené mesiace, pretože počítalo zátvorky a v PHP je `{$row['id']}` token, ktorý vyzeral ako koniec tela. Metóda mala 115 riadkov a test videl 43.

## Kedy sa to oplatí (a kedy nie)

Browser job nie je v `make pre-commit` a je to zámer.

`make pre-commit` beží dve minúty.

Browser job potrebuje reálny Chrome, bežiaci server a seedované databázy — a trvá rádovo dlhšie.

Za tú cenu dostaneš meranie. Pri zmene jedného odseku v článku je to vyhodený čas.

Pri zmene chrome, vstupného bodu alebo markupu témy je to jediné, čo tú vec vôbec uvidí.

Preto je browser job v CI vždy, ale doma ho spúšťaš, keď meníš to, čo meria.

Druhá hranica: ani v CI nespúšťame všetko proti všetkému.

Check na otázky od čitateľa míňa jeden z piatich otázok, ktoré smie jedna IP adresa položiť za päť minút.

Preto jeho submit round trip beží v CI na dvoch témach a zvyšok dostane `QUESTIONS_SKIP_SUBMIT=1`.

Keby sme ho pustili na všetkých šiestich, narazili by sme na limiter a hlásili chybu v UI, ktorá neexistuje.

## Prakticky

1. Pred commitom spusti `make pre-commit`. Sú to tie isté príkazy, ktoré potom bežia v CI, len na tvojom stroji a bez čakania.

2. Keď meníš chrome, vstupný bod alebo markup témy, štartuj server a spusti príslušný check ručne. `php bin/dev/set-theme.php <téma>` dá deterministickú tému a `node bin/checks/<check>.mjs` povie, čo videl prehliadač.

3. Keď pridáš check, pridaj aj bránu, ktorá zlyhá, keď je zapojený zle — a over ju mutáciou. Slučka, ktorú nevieš rozbiť, je slučka, ktorej nemôžeš veriť.

## Zdroje

- [GitHub Actions — Workflow syntax](https://docs.github.com/en/actions/reference/workflow-syntax-for-github-actions) — joby, kroky a `timeout-minutes`
- [GitHub Actions — Running jobs in parallel](https://docs.github.com/en/actions/how-tos/writing-workflows/choosing-what-your-workflow-does/using-jobs-in-a-workflow) — prečo máme osem jobov namiesto jedného
- [shivammathur/setup-php](https://github.com/shivammathur/setup-php) — PHP 8.5 a rozšírenia v každom jobe
- [Chrome DevTools Protocol](https://chromedevtools.github.io/devtools-protocol/) — ako `bin/checks/*-check.mjs` hovoria s prehliadačom
- [PHPUnit — Test doubles a assertions](https://docs.phpunit.de/) — čo `composer test` naozaj overí
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
