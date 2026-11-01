---
title: "Nativa PHP Core: jadro, ktoré dokáže, že je odstrániteľné"
date: "2026-10-12"
tags: ["nativa", "php", "architektúra", "moduly", "kernel"]
excerpt: "Kernel-only strom, ktorý drží dve veci naraz: runtime bez jedinej Composer závislosti a moduly, ktoré sa dajú pridať aj preukázateľne odobrať. Čo z toho vyplýva a čo sme museli najprv vyvrátiť."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architektúra"
published: true
---

**Nativa PHP Core** je kernel-only strom: dve modulové vetvy idú celé >
`Modules\Core` a `Modules\Database` >
plus jedna téma.
Všetko ostatné je modul >
ktorý sa do stromu **skopíruje** (`bin/cms module:add`) >
a dá sa **preukázateľne odstrániť** (`bin/cms module:remove`).
Tento článok je o tom, čo z toho vyplýva: o pravidlách >
ktoré držia hranicu, o meraniach, ktoré ich overujú, a o dvoch veciach >
ktoré sme museli najprv vyvrátiť, aby sme im mohli veriť.

## Prečo existuje samostatný strom

Showcase je plná stránka a plná stránka **nemôže** demonštrovať >
že niečo je odstrániteľné: to, na čom by to demonštrovala >
je vždy nainštalované.
Tvrdiť „modul je voliteľný" >
a mať ho pritom napevno prepojený v jadre je presne tá chyba >
ktorá sa v produkcii prejaví ako 500 na každej stránke po tom >
čo niekto vypne modul, ktorý „sa predsa nepoužíva".

Kernel-only strom je preto testovacia pomôcka aj architektonické tvrdenie.
Import z 2026-10-11 doň priniesol `src/Modules/Core` >
a `src/Modules/Database` **verbatim** >
nič sa neupravovalo.
Import, ktorý začne „opravovať veci", je fork a fork už nevie povedať >
čo robil originál.
Spolu s kódom tak prišlo aj celé prepojenie showcase >
desiatky `use Modules\<X>\…` v jadrových súboroch.

Zvyšok práce je odstraňovanie tých hrán >
a zapisovanie každej z nich, vrátane tej, ktorá sa neodstráni >
ale prevedie na kontrakt.

## Pravidlo hranice

> Súbor jadra nesmie importovať modul. Modul smie importovať jadro.

Znie triviálne, ale je to jediné pravidlo, ktoré drží celý model pokope.
Rozhranie, ktoré potrebuje modul, patrí modulu >
nie jadru.
Jadro vlastní routing, dvojicu request/response >
middleware pipeline aj jeho discovery, migrátor runner >
SQLite connection manager, resolver assetov, renderovanie Markdownu >
chybové stránky a textové SEO plochy (`/robots.txt`, `/sitemap.xml`) >
degradované na to, čo dokážu odpovedať s **ničím** nainštalovaným.

Jadro dnes vlastní aj jednu tému, ktorá naschvál **nemá CSS**.
Stránka, ktorú vykreslí, je platná a neštylizovaná.
To je rozdiel medzi „funguje" a „je navrhnutá" >
a je čestnejšie ho priznať než zamaskovať.

## Porty: neprítomnosť je platná odpoveď

Prvá z hrán, ktorá sa neodstranila, ale prevedela, je shell port.
Meno stránky, aktívna téma, predvolené locale >
zoznam jazykov a navigačné menu už nechodia z modulov >
prichádzajú z portu `ShellContextProvider` >
ktorý jadro **objaví** cez atribút `#[AsShellContextProvider]`.
Ak nie je nainštalovaný **žiadny** provider, strom sa aj tak vykreslí.

To je najdôležitejšia časť návrhu: `null` nie je chyba, je to odpoveď.
Tá istá schéma sa opakuje pri autentifikácii >
rate limitera a prekladaní locales >
port vlastní jadro, implementácia prichádza s modulom.

## Katalóg modulov a životný cyklus

Dva adresáre, dva rôzne svety:

- `modules/` je **katalóg** : commitnutý, šestnásť slices, text;
- `src/Modules/<Name>` (okrem `Core`/`Database`) je **nainštalovaná kópia** >
  operatorský stav, ktorý je v `.gitignore`, presne ako databázový súbor.

Bez tohto rozlíšenia by každé `module:add` stage-ovalo runtime stav >
do histórie jadra a tvrdenie „kernel-only strom" by zhnilo jeden commit >
za druhým.

Nástroj `bin/cms` je naschvál čisté PHP bez závislostí >
to, čo spravuje moduly, musí bežať aj tam, kde `vendor/` nemusí existovať.
A práve tam sme našli prvú skutočnú chybu >
`ModuleInstaller::copyTree()` nikdy nevytvoril cieľový adresár >
a iterátor vydáva obsah slice v poradí **readdir**.
Slice, ktorého koreň obsahuje súbor skôr než adresár >
teda odovzdal ten súbor vo chvíli, keď cieľ ešte neexistoval.
Na jednej stanici test prešiel, v CI padol >
a to je definícia chyby, ktorá sa nedá odchytiť testom >
ktorý beží len na jednom súborovom systéme.
Opravené v kontrakte: `copyTree()` najprv vytvorí cieľ, potom kopíruje.

## Nulová závislosť runtime > a ako sme ju vyvrátili

Hlavné tvrdenie jadra je, že runtime má **nula** Composer balíkov.
`src/init.php` preto inštaluje vlastný PSR-4 fallback autoloader >
pre strom bez `vendor/`.
Aby to nebolo len tvrdenie, má CI job >
ktorý naschvál **nerobí** `composer install`: keby ho robil >
nikdy by tú vlastnosť neotestoval.

Ten job tvrdenie vyvrátil.
`Router::scanDirectory()` volal `class_exists()` >
na každý súbor v action adresároch >
čím sa **rodič** každej triedy stal load-time závislosťou *routingu*.
Jadro pritom samo vezie `MigrateCommand` a `ServeCommand` >
ktoré dedia z `symfony/console` >
čo je **dev** závislosť.
Checkout bez `vendor/` odpovedal **500 na každú požiadavku**, vrátane `/`.

Riešenie je prevzaté z upstreamu >
`Router::mayDeclareRoute()` prečíta súbor **ako text** a preskočí ho >
ak v ňom nie je token `#[Route(` >
skôr než sa nejaká trieda načíta.
Je naschvál zhovievavý >
v smere vynechania (falošný pozitív stojí jedno reflexné čítanie >
falošný negatív by ticho zahodil reálnu routu).
Namerané po oprave, v stromoch bez `vendor/`: `/`, `/sitemap.xml` >
`/robots.txt`, `/docs` → **200**; `/nope` a `/mark` → **404** (nikdy 403 >
admin je modul); `module:add` → `200` → `module:remove` → `404` >
a to aj so **14 nainštalovanými modulmi**.

## Extrakcia a ledger divergence

Strom nie je fork showcase >
je jeho **zdroj pravdy** pre jadro >
a `bin/build/extract-kernel.sh --check` je poistka >
z upstreamu zloží slice do dočasného adresára a porovná ho >
s týmto stromom.
Rozdiel je povolený len vtedy >
keď je zapísaný v `bin/build/kernel-divergence.txt` >
a to v oboch smeroch.
Nelegovaná ručná úprava padne rovnako ako upstream zmena >
ktorú nikto neprešiel.

Aktuálny stav: **246 identických, 42 zapísaných divergencií**.
Číslo nie je dekorácia >
je to zoznam miest, kde sa jadro od showcase líši, s dôvodom pri každom.

## Čo dnes meriame

| plocha | stav |
|---|---|
| súbory PHP v jadre | 72 v `Core` (≈8 800 riadkov), 2 v `Database` |
| katalóg / nainštalované | 16 slices / 14 modulov |
| testy (stanica) | 18 testov, 45 asertí, 1 skip |
| testy (kernel-only checkout) | 18 testov, 32 asertí, 5 skipov |
| `extract-check` | 246 identických / 42 zapísaných |
| build tém | 7 bundle v `public/dist` |

Kernel-only suita si pritom zaslúži vlastnú poznámku >
je to **iný strom** (žiadne nainštalované slices), takže testy >
ktoré menujú triedu voliteľného modulu, patria k modulu >
nie do `tests/`.
`tests/` je totiž v scope PHPStanu a `modules/` nie je.
Presne táto hranica raz zhodila CI na piatich chybách `unknown class` >
ktoré neboli chybou v kóde, ale chybou v umiestnení testu.

## Poučenia, ktoré stáli za to

Tri veci, ktoré sme si museli namerať, nie vymyslieť:

1. **Gate, ktorý nemôže zlyhať, je horší než žiadny.** `make lint` končil
   `|| true` >
     vypísal každú syntaktickú chybu a skončil s `exit 0`. A
   `cs-fix-dry` bez konfiguráku si config *vytvoril* a zapísal do
   `.gitignore`: „dry run", ktorý mení strom.
2. **Predvolená odpoveď nie je dôkaz.** `php -S` s opcache (CLI SAPI ju má
   zapnutú) obsluhoval kód, ktorý už na disku nebol >
     oprava vyzerala ako
   no-op, kým sa server nereštartoval.
3. **Lokálne zelené meranie je výpoveď o lokálnom prostredí.** „Anonymné"
   `git ls-remote` na privátny repozitár u nás uspelo >
     lebo git config niesol
   `credential.helper = store` a hovor bol autentifikovaný uloženým tokenom.
   S vypnutými helpermi je odpoveď jednoznačná: `Repository not found`.

## Kde to beží

Dnes jadro stojí na šiestich plochách >
ktoré odpovedajú bez jedinej Composer závislosti, s katalógom modulov >
ktoré sa dajú pridať aj odobrať, a s CI >
ktoré to všetko skúša na **čerstvom** checkout-e >
teda v tom tvare, ktorý je naozaj kernel-only.
Zdrojový strom je malý, čitateľný a jeho najzaujímavejšia časť nie je to >
čo obsahuje, ale to, čo dokáže **neobsahovať**.

