---
title: "Biela obrazovka pre deti: pitva jedného kliku v /mark"
date: "2026-09-15"
version: "0.1.2"
tags: ["php", "htmx", "debugging", "testovanie", "architektura"]
excerpt: "Jeden klik na Unpublish a biela obrazovka. Za jednu noc z toho bolo päť prepojených opráv: rozbitý bulk flow, chýbajúci Published checkbox, drafty na verejnom blogu, triedenie podľa stavu a CI, ktoré padlo na chýbajúcej tabuľke. Pitva jedného kliku, krok za krokom, ako sa ladiť webovú appku tak, aby z toho profitoval celý projekt."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
published: false
---

Všetko začalo nevinne. V admin paneli som označil článok, klikol na
**Unpublish**: a dostal som bielu obrazovku. Žiadna chyba, žiadny log,
len prázdne biele plátno. Toto je príbeh jednej noci, počas ktorej z toho
jedného kliku vyšlo päť prepojených opráv. A áno, napísali sme k tomu
testy.

## Krok 1: čo dostal prehliadač?

Prvé pravidlo ladenia webovej appky: **nepozeraj sa na to, čo si klikol,
pozeraj sa na to, čo ti prišlo späť**. Zachytil som raw odpoveď z prehliadača
a tam stálo len 246 bajtov HTML, kúsok toast správy, v ktorom bežal skript
volajúci `window.mToast()`. Funkcia v tom fragmente neexistovala, skript
spadol na `TypeError` a prehliadač zostal s bielou stránkou.

Prečo prišiel fragment namiesto celej stránky? Lebo formuláre v bulk lište
nosili `hx-target` a `hx-swap`, ale **žiadne `hx-post`**. htmx ich preto
ignoroval a klik urobil klasické natívne odoslanie formulára. To by ešte
bolo v poriadku, keby formulár vedel, čo odosiela. Nevedel.

## Krok 2: koreň problému, checkboxy bývajú mimo formulára

Checkboxy `ids[]` žijú v tabuľke, formuláre akcií žijú vyššie. Štandardný
`form.submit()` teda odoslal **nula vybraných položiek**. Server spravil
prázdnu hromadnú operáciu, odpovedal toast fragmentom, ktorý v natívnom
priebehu nikto nevymenil, a biela obrazovka bola na svete.

Oprava má tri časti:

1. bulk formuláre sú odteraz **obyčajné POST formuláre** (celostránkový
   flow so redirectom späť, `?toast=` hláškou, nikdy viac fragment namiesto
   stránky),
2. capture-phase submit guard `mBulkInject()` skopíruje zaškrtnuté
   checkboxy do formulára ešte pred odoslaním,
3. potvrdzovací dialóg používa **`requestSubmit()`** namiesto
   `form.submit()`: lebo iba ten spustí submit listenerov, ktoré
   stráži bod 2.

## Krok 3: „a kde je pole draft/published?“

Používateľská otázka odhalila druhú, väčšiu chybu: formulár článku
**nemal pole Published vôbec**. Jediný spôsob, ako zmeniť stav, bola
(rozbitá) bulk lišta. Horšie, **editácia draftu ho potichu publikovala**,
lebo `buildMeta()` flag nikdy nezapisovala.

Oprava: Published checkbox v create aj edit formulári a jednoduché
**tlačidlo publish/unpublish v každom riadku tabuľky**: nový endpoint
`POST /mark/articles/{slug}/publish`, ktorý pre bežné POST redirectuje
späť a pre htmx vráti aktualizovaný riadok. Jeden klik, žiadna biela
obrazovka.

## Krok 4: drafty na verejnom blogu

Flag `published` žil len v admin vrstve. Verejný pipeline,
`BlogArticlesService::discover()`: ho nikdy nečítal, takže drafty
svojvoľne svietili na `/blog`, vo vyhľadávaní, tagoch, kategóriách,
autoroch, feedoch aj sitemap.

Opravili sme to **pri zdroji**: jeden filter v `discover()` skryje drafty
zo všetkých verejných povrchov naraz, a priama URL na draft vráti 404,
rovnako ako to modul Pages robil už dlhšie. Blog zaostával.

## Krok 5: zoradenie podľa stavu

Keď už človek vidí published/draft stavy, chce podľa nich aj triediť.
Klikateľné hlavičky stĺpcov (`?sort=&dir=` server-side, whitelistovaná
hodnota, `aria-sort` pre prístupnosť), a stav triedenia prežije
vyhľadávanie, filtráciu aj stránkovanie.

Malá odmena: moja prvá verzia comparatora mala prevrátený smer a „najnovšie prvé“ zobrazovalo najstaršie. **Integračný test to chytil
skôr, než to stihol používateľ.** Presne na to sú.

## Krok 6: lokálne zelené, CI červené

Všetko lokálne zelené, push, a CI padá: `no such table: menu_items`.
Sedemkrát. Skutočnosť: testy, ktoré renderujú celý admin layout, si
vyžiadajú tabuľku navigácie z `config.db`. Na vývojárskom stroji existuje
roky. **Na čerstvom CI checkoute neexistuje žiadna databáza** a priame
volanie akcií obchádza kernel, takže auto-migrácie nikdy nenastartujú.

Oprava: idempotentný migrátor v `setUp()` testu, presne ten istý vzor,
ktorý v repozitári už používali staršie testy. Naučená lekcia: **test
musí byť samostatný ako výsadba**, nielen súčasťou zeleného balíka.

## Čo si z toho odniesť

- **Biela obrazovka je vždy fragment v nesprávnom kontexte.** Prvá
  otázka znie „čo dostal prehliadač?“, nie „čo som klikol“.
- **Fixuj pri zdroji.** Filter v `discover()` opravil osem povrchov
  naraz; ôsmy patch na každom povrchu zvlášť by ich opravil nikdy.
- **UI sľubuje, čo testy garantujú.** Bez integračných testov by comparator
  bug aj CI rozdiel cestovali k používateľovi.
- **Jeden klik odhalí päť vrstiev.** Bulk flow → formuláre → publicačný
  stav → verejná viditeľnosť → triedenie. Webová appka nie je kopka
  izolovaných funkcií, je to reťaz, a reťaz treba testovať na oboch
  koncoch.

Výsledok noci: 943 testov, 3091 asercií, štyri zelené CI joby (PHPStan
level 9, PHP-CS-Fixer, Rector, PHPUnit) a admin panel, v ktorom už žiadny
klik neskončí na bielej plachetnici.

## Zdroje

- [htmx dokumentácia, atribúty, ktoré rozhodujú o správaní formulárov](https://htmx.org/docs/)
- [PHPUnit, integračné testy, ktoré chytili comparator bug aj CI rozdiel](https://phpunit.de/documentation.html)
- Zdrojový kód: `src/Modules/Cardboard/Application/Services/BulkActions.php`, `src/Modules/Blog/Application/Services/BlogArticlesService.php`, `src/Modules/Cardboard/Interfaces/HTTP/AdminLayout.php`
