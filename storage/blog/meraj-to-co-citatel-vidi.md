---
title: "Merať to, čo čitateľ vidí ... tri chyby, ktoré nevidela žiadna brána"
date: "2026-10-05"
version: "1.0.0"
tags: ["css", "testing", "themes", "accessibility"]
excerpt: "Obsahový pás, linka cez text a komentáre o 176px vpravo — tri chyby na jednej stránke jednotka, ktoré prešli všetkými testami. O tom, prečo obdĺžnik DOM nesvedčí o tom, či je vec vidieť, a prečo zelený beh dokaz môže klamnúť."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "measure-what-the-reader-sees"
published: true
---

> Tento článok je o jednej stránke a o troch chybách, ktoré na nej boli.
> O tom, prečo o nich nevedel ani jeden test v tomto repozitári, a prečo na ich
> odhalenie bolo treba otvoriť skutočný prehliadač. A ešte o tom, ako som si
> pri vlastnej práci overil, že môj nový test naozaj zlyháva, keď opravu zrušim.

## Čo čitateľ vidí

Otvorte si článok na mobile a prejdite prstom po obrazovke.

Hľadáte obsah ... a nachádzate pruh. Dva pixelové linky hore a dole a medzi
nimi nič. Deväť odkazov na kapitoly je v HTML, ale na obrazovej ploche nie je
ani jeden, takže sa nedajú rozlúčiť, čo ten puh má byť.

Pozriete dolu a text vám prechádza svislá linka. Nesúvisí s ničím, neoddeľuje
žiadne dva stĺpce, lebo stĺpec je jeden. Vyzerá ako kôp, ktorý niekomu ostal na
stole.

A keď dočítate, komentáre pod článkom začínajú o dvesto pixelov napravo od
textu, ktorý odpovedajú. Sú širšie ako miera samotného textu a patria inému
návrhu.

Toto nie je chyba, ktorú niekto nahlási. Čitateľ si povie, že téma je taká
divná. A autor, ktorý si myslí, že všetko má, ide ďalej písať.

## Ako prešla testami

Túto stránku pokrývali štyri vrstvy kontroly: špecifikácia, kód, PHPUnit a
quality brány. Všetky zelené.

Prečo? Lebo všetky štyri čítajú **zdroj**. A zdroj bol v poriadku. Pás obsahu
mal triedu `je-article-toc__disclosure`, zoznam mal `je-article-toc__list` a
CSS mal breakpoint na `48em`. Každý riadok bol rozumný.

Chyba bola v tom, **čo tie riadky urobili na obrazovke**.

Prvá je najzaujímavejšia, pretože porušuje pravidlo, ktoré platí vo všetkých
jazykoch, kde existuje `<details>`. Pás obsahu bol zabalený do `<details>`,
ktorý sa otváral celý sám. Lenže CSS nedokáže `<details>` otvoriť. Chrome
preskočí jeho obsah pri kreslení cez `::details-content` a trieda `display:
flex` na zozname sa nikdy neuplatní.

A aby to nebolo dosť, `<summary>`, teda rúčka, ktorá by pás otvorila, mal
mediálny dotaz, ktorý ju schoval na širokých obrazovkách:

```css
/* src/Themes/jednotka/Frontend/... — toc.css jednotka */
@media (min-width: 48em) {
  /* Na širokej obrazovke je obsah stále otvorený — žiadne zverejnenie. */
  .je-article-toc__disclosure > summary {
    display: none;
  }
}
```

Presne ten dotaz, ktorý má spraviť pás trvalým, zrušil rúčku, ktorou by sa dal
otvoriť. Výsledok bol dva pixely linky.

Druhá chyba je menej zábavná, ale rovnako neviditeľná pre zdroj. Token
`--je-rail` mal hodnotu `4.5rem` a meral šírku rozloženia, ktoré v téme už nie
je. Keď to rozloženie odišlo, token zaostal a linka sa presunula o 48px **dovnútra**
stĺpca s textom. Token zostal v súbore, čítal ho jeden riadok CSS a nikto si
nesedol a neprepytoval, či ešte meria niečo existujúce.

Tretia je najzábavnejšia pre to, odkiaľ sa vzala. `comments.css` je v téme
**generovaný** zo starteru, prefix stampovaný. Viesol si so sebou starterovú
mieru:

```css
/* Frontend/packages/jednotka/src/comments.css — generované zo starteru */
--comments-width: 720px;
```

Jednotka má mieru `34rem`, čo je 544px. Takže komentáre prišli o 176px širšie
ako text, ku ktorému patria. A oprava, ktorá vyzerala správne, neurobila nič:

```css
.je-comments {
  max-width: var(--je-measure);
}
```

Jedna trieda má rovnakú špecificitu ako generované pravidlo, ktoré je v súbore
neskôr, takže vyhralo ono. Toto je vlastnosť CSS, ktorú treba povedať nahlas:
**pravidlo, ktoré príde neskôr, vyhrá remízu**. Oprava musela niesť dve triedy.

## Obdĺžnik nevie, čo je vidieť

Keď som písal prvý check, zlyhal som na vlastnej chybe. Tvrdenie na zjavový
pás som napísal ako „zoznam má nulovú výšku, pás je zavretý".

A ono neplatilo.

Zavretý `<details>` v Chrome **nezbalí geometriu svojich detí**. Obsah sa
preskočí pri kreslení, nie pri meraní, takže odkaz si stojne drží obdĺžnik
25px vysoký, čitateľný, s nenulovou šírkou — a na obrazovke nie je. Meranie
obdĺžnikom teda neodpovedá na otázku „je to vidieť?", a to isté meranie
neodpovedá ani na opačnú stránku.

Rozdiel vidno na jednej stránke:

| Otázka | Čo treba čítať | Čo vracia |
|---|---|---|
| Je to na obrazovke? | `Element.checkVisibility()` | `false` pre zavretý pás |
| Kde to leží? | `getBoundingClientRect()` | obdĺžnik, ktorý je úplne pravdivý a úplne zavádzajúci |
| Ušetrilo to miesto? | poloha prvého nadpisu pred a po | 382,6px — flow sa naozaj zavrie |

Tretí riadok je dôležitý. Pás sa zavrie, ale keby si nechal v ňom 380px prázdnej
papiera, atribútové tvrdenie by zostalo zelené ... a čitateľ by dostal horšiu
stránku než predtým, len s klikacím pásom navyše. Takže check meria posun textu,
nie iba prítomnosť atribútu.

## Zelený beh, ktorý nič netestoval

Túto časť píšem, pretože ma to stálo najviac a pretože je to najcennejšia
lekcia z celej práce.

Napísal som check, spustil ho a mal 21 z 21 zelených. Teraz som zrušil opravu,
aby som overil, že test **vie** zlyhať.Vrátil som `<details>` do zavretého
stavu a spustil ho znova.

Zelené. 21 z 21.

V práci totiž nebola oprava. `JednotkaRenderer.php` som mal v pracovnom strome
bez atribútu `open` už asi minútku, ale PHP drží preložený bytecode v OPcache
a `opcache.revalidate_freq` je v tomto kit-e **180 sekúnd**. Server šťastne
posielal pôvodný súbor. Check nebol zelený preto, že je správny. Bol zelený
preto, že bežal proti súboru, ktorý už neexistuje.

| Krok | Čo som čakal | Čo sa stalo |
|---|---|---|
| zrušiť opravu, spustiť check | červený | zelený |
| reštartovať server, spustiť check | červený | červený, 3 chyby |

Keď som toto vedel, stal som si otázku, ktorú si zaslúži každý test v tomto
repozitári: **čo by preklepoval?** Odpoveď na ňu je práve ten červený beh.

Tri opravy som teda zrušil jednu po druhej a všetky tri check zachytil:

| Zrušená oprava | Čo check vypísal |
|---|---|
| `open` z atribútov | `{"open":false,"h":0}` |
| linka späť na `4.5rem` | `gap: -48` |
| jednotriedový `.je-comments` | `720` proti `544` |

Tretí riadok ma zaujal najviac. Levý okraj komentárov bol v oboch prípadoch
správny, takže tvrdenie o polohe prešlo. Ako jediné prehodil to o šírke.
Jedna z tých dvoch meraní zachytila opravu, druhé by ju preklepalo.

## Kedy to neplatí

Úprimná časť, bez ktorej by bol tento článok reklama.

Prvý bod: toto **nie je** dôvod napísať ďalších pätnásť skriptov. Každý stojí
server, databázu so seedom a Chrome. Check si musí najprv nájsť vlastnú chybu
a potom ukázať, že ju naozaj chytá.

Druhý bod: PHPUnit testy a CSS audity si svoju prácu odviedli. `BuildCssAuditTest`
overil, že `--je-rail` je mrtvý token, a `ThemeModuleContractTest` overil, že
pás je v značke otvorený. Oba to mali zistiť pri prvom behu. Toto nie je
argument proti nim ... je to argument, že pokrývajú inú vrstvu než obrazovka.

Tretí bod, najdôležitejší: **tvrdenie „CSS neotvorí `<details>`" platí pre
Chrome a platí pre prehliadač, ktorý používame na meranie.** Nie je to
platforma-independent pravidlo a netvár sa, že je. Práve preto pás vôbec
posielame otvorený a zlepšujeme ho až skriptom.

## Prakticky

1. **Zmeraj maľovanie, nie rozloženie.** Ak pýtaš „je to vidieť?", `checkVisibility()`
   je prvá odpoveď. Obdĺžnik ti povie, kde niečo leží, nie či je na obrazovke.
2. **Každý nový check zruš.** Vrát opravy a spusti ho znova. Ak ostane zelený,
   nemáš test, máš zdanie testu. A po starte PHP servera, lebo OPcache drží
   tri minúty.
3. **Keď brána nevie, pridaj bránu s iným nástrojom.** Nie preto, že tá stará
   zlyhala, ale preto, že tieto chyby žijú v vrstve, kde ona nemá prístup.

## Zdroje

- [MDN — `<details>` a `::details-content`](https://developer.mozilla.org/docs/Web/HTML/Reference/Elements/details) — prečo CSS slot zatvoreného disclosure neotvorí
- [MDN — `Element.checkVisibility()`](https://developer.mozilla.org/docs/Web/API/Element/checkVisibility) — meranie maľovania namiesto geometrie
- [Chrome DevTools Protocol](https://chromedevtools.github.io/devtools-protocol/) — ako `bin/*-check.mjs` hovoria s prehliadačom
- [PHP Manual — OPcache](https://www.php.net/manual/en/book.opcache.php) — `opcache.revalidate_freq` a prečo bežíš proti starej verzii kódu
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod