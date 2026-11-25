---
title: "Vízia super rýchleho PHP ... debata s DeepSeekom o filozofii nášho stacku"
series: "Prečo je PHP rýchle"
series-part: "1"
date: "2026-08-15"
version: "1.0.2"
tags: ["php", "performance", "philosophy", "ai"]
excerpt: "Debata s DeepSeekom o vízii super rýchleho PHP: prečo čisté PHP s nulovými závislosťami poráža Next.js aj Astro, prečo sme vyhodili cache a dostali 100/100 v PageSpeed, a čo z tejto debaty zostalo ako filozofia nášho stacku."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "super-fast-php-philosophy"
published: true
---

Dnes som mal nezvyčajnú debatu. Nie s kolegom, nie s klientom ... s DeepSeekom.

Nešlo o konkrétny bug ani o ďalšiu funkciu; šlo o víziu **super rýchleho PHP**
a o filozofiu, na ktorej staviame super rýchly PHP web. Z konverzácie sa
nakoniec vykľul celkom slušný audit nášho stacku ... a jeho závery si zaslúžia
záznam.

Toto je záznam.

## Vízia: „PHP C chunks“

Celú našu predstavu vieme zhrnúť do jednej vety: náš web je **„PHP C
chunks“** ... to, čo Astro robí pre statické weby (prekompilovaný HTML,
minimum JavaScriptu), robíme my v čistom PHP priamo na serveri.

Astro + Cloudflare sa dnes považujú za zlatý štandard rýchlosti: Astro
predrenderuje stránky do statického HTML a Cloudflare ich doručí z
najbližšieho bodu siete. Žiadny ťažký runtime na klientovi, žiadna hydratácia,
žiadna réžia. Len hotový HTML.

My máme to isté ... len bez Astra, bez build procesu a bez CDN:

| Aspekt | Astro + Cloudflare | Naše riešenie (PHP na serveri) |
|---|---|---|
| „Prekompilácia“ | Áno ... statický HTML build | Netreba ... PHP je už pripravené na spustenie |
| Runtime | Node.js / Deno | PHP interpreter (jadro napísané v C) |
| Doručenie obsahu | Globálne CDN | Vlastný server, priamy HTTP request |
| Režia | Build proces + správa CDN | Žiadna ... request ide rovno do PHP |
| Výsledok v PageSpeed | Výborné skóre | 100/100 |

Čo presne v tej krabici je?

- **Zero-dependency PHP 8.5 runtime** ... žiadne Composer balíčky na
  produkcii. Odstránením závislostí sme eliminovali obrovskú časť réžie,
  ktorá spomaľuje typické PHP aplikácie.
- **Hand-rolled PSR-4 autoloader** ... žiadny `vendor/autoload.php`, vlastný
  autoloader je rýchlejší a plne auditovateľný.
- **Per-module SQLite** ... žiadny DB server, žiadna sieťová latencia. Dáta
  sú lokálne, čo je z pohľadu rýchlosti takmer ako práca so súbormi.
- **Native PHP templates** ... žiadny Twig, žiadny Blade, žiadna kompilácia
  šablón. Kód je už PHP; nie je čo prekladať.

DeepSeek to zhrnul presne: *„Toto je presne duch C chunks ... iba to, čo je
potrebné.“*

## „PHP C chunks“ nie je metafora

Možno to znie ako slogan, ale je to doslovný popis toho, ako PHP funguje.

PHP je interpreter napísaný v C, ktorý so sebou nosí obrovskú knižnicu
**prekompilovaných C funkcií**:

| Vrstva | Čo to je | Kde to beží |
|---|---|---|
| Built-in funkcie (`str_replace`, `preg_match`, `json_encode`, `hash`, PDO…) | C kód skompilovaný priamo do interpreteru | Natívny C kód |
| Rozšírenia (SQLite, mbstring, openssl…) | C knižnice ... SQLite je celý napísaný v C | Natívny C kód |
| Náš `src/` | Tenká orkestračná vrstva medzi requestom a C funkciami | Interpretovaný PHP bytecode |

Keď spracujeme request, väčšinu skutočnej práce robí C kód ... PHP je len
lepidlo, ktoré C funkcie volá. A keďže nemáme žiadne Composer runtime balíky,
medzi našim kódom a tými C chunkmi nie je ani jedna ďalšia interpretovaná
vrstva. Žiadny framework, žiadny vendor, žiadne „PHP v PHP“.

A PHP sa sám snaží byť prekompilovaný:

- **OPcache** ... skompiluje skript do bytecode raz a potom ho len spúšťa.
  To je „prekompilácia“ v Astro zmysle, len na úrovni jazyka.
- **JIT (od PHP 8.0)** ... horúce cesty kompiluje za behu až do natívneho
  strojového kódu. Vtedy už aj naša malá PHP vrstva beží ako C.

Takže „PHP C chunks“ nie je slogan ... je to popis architektúry. My sme
z nej len odstránili všetko, čo tam nepatrí.

## Cache nám spomaľovala LCP. Vyhodili sme ju.

Najpoučnejšia časť debaty sa netýkala toho, čo sme pridali, ale toho, čo sme
**odstránili**. Dlho sme mali zapnutú cache ... a ona nám paradoxne škodila.

Keď server po dlhšej dobe „zaspal“, prvé načítanie stránky trvalo 3,8 sekundy
a cache situáciu len zhoršovala, namiesto aby pomáhala.

Prešli sme si všetky klasické riešenia:

1. **Wake-up mechanizmus** ... pravidelné pingy, aby server nikdy nezaspal.
   Funguje, ale je to „krép“, nie systémové riešenie.
2. **opcache.preload** ... načítať kľúčový kód do pamäte pri štarte. Pre našu
   architektúru ideálne, ale rieši to len teplý štart.
3. **PHP-FPM workers** ... udržať minimálny počet pripravených workerov. Tiež
   len zmierňuje príznak, nie príčinu.

A potom prišla otázka, ktorá všetko zmenila: **prečo to riešiť, keď to môžeme
neriešiť?**

Cache sme vyhodili. A niekoľko dní bez cache majú naše public pages perf testy
**100/100** ... nie preto, že by sme cache používali múdrejšie, ale preto, že
sme ju prestali používať vôbec. Žiadna ďalšia vrstva, žiadne straty, žiadna
kompilácia. Natívne PHP šablóny, ktoré bežia priamo.

Z tejto skúsenosti dnes žijeme motto **„Rýchlejší ako cache“** a pravidlo,
ktoré aplikujeme na všetko nové: *pridávaj funkcie, nie vrstvy.*

## Prečo nie Next.js s node_modules na serveri?

V debate padla aj otázka, ktorú si kladiem stále: **prečo niekto dá na server
SSR + node_modules a potom plače pri dependency injections?**

Vždy mi to prišlo iracionálne ... a DeepSeek to potvrdil.

Uvažovanie je jednoduché: **nakompilujem → otestujem → servujem.** Build
prostredie (kde kompiluješ) a runtime prostredie (kde beží aplikácia) sú
úplne oddelené. Na produkčný server pošleš len hotový, funkčný kód ... žiadny
kompilátor, žiadny Node.js runtime, žiadne tisíce priečinkov v `node_modules`.

Prečo sa to napriek tomu nedeje? Pretože mainstreamové frameworky to majú
postavené inak:

- **React runtime a hydratácia** ... Next.js posiela na klienta React runtime
  a po načítaní HTML ho „preberá“ JavaScriptom. To je réžia, ktorú náš PHP
  rieši úplne bez: posielame hotový HTML a nič viac.
- **Dependency hell** ... jednoduchý SSR projekt znamená tisíce balíčkov,
  build nástroje a konflikty verzií. My máme na produkcii nulové závislosti.
- **Pomalé štandardné cesty** ... Next.js vie byť rýchly, ale vyžaduje to
  statický export, CDN a extrémnu disciplínu. Naša štandardná cesta je rýchla
  sama osebe.

A koľko to celé váži? Dev prostredie tohto projektu zaberá ~215 MB ... z toho
98 MB `vendor/` a 112 MB `node_modules/`. Produkčný dist ... to, čo naozaj
beží na serveri ... má **1,7 MB**. Pomer je takmer **126 : 1**:

| Čo | Veľkosť |
|---|---|
| Dev prostredie (vendor + node_modules + src) | ~215 MB |
| Produkčný dist (zero-dependency) | 1,7 MB |
| Pomer | ~126 : 1 |

Celý náš produkčný server je menší ako jedna fotka z mobilu ... a beží
rýchlejšie ako väčšina aplikácií s 200 MB nástrojov za chrbtom.

Je to ako s jedlom v reštaurácii: my varíme v kuchyni a hosťovi podávame
hotový tanier. Kto kompiluje na serveri pre rôzne prehliadače, varí hosťom
pri stole ... a ešte sa čuduje, že je to pomalé a stresujúce.

Sme v roku 2026 a na produkcii stále bežia kompilátory. Nemusíme.

## Cloudflare kúpil Astro. My sme na edge už teraz.

V januári 2026 Cloudflare kúpil tím z The Astro Technology Company ... tvorcov
Astra.  Framework ostáva open-source (MIT) a platform-agnostic, ale Cloudflare
tým získal celý stack od lokálneho vývoja až po edge doručenie. Ich stávka je
jasná: svet ide smerom k statickému prekompilovanému HTML s minimom
JavaScriptu, doručovanému z najbližšieho bodu siete.

A v apríli 2026 zašli ešte ďalej: **EmDash** ... full-stack TypeScript CMS
postavený na Astre, „duchovný nástupca WordPressu“, ktorého README hovorí
doslovne *„No PHP“*. Odpoveď Cloudflaru na svet WordPressu teda nie je opraviť
PHP, ale nahradiť ho.

Je na tom jedna krásna irónia: EmDash stavia na rovnakých myšlienkach ako my
... SQLite, FTS5, RSS, RBAC, audit log ... ale s ťažkým JS toolchainom a
scale-to-zero architektúrou, ktorá prináša cold starty. Teda presne problém
so 3,8 sekundovým LCP, ktorý sme my vyriešili odstránením vrstiev. Oni vrstvia
caches, aby dosiahli prijateľný výkon; my sme cache vyhodili a dostali 100/100.

A čo „PHP na edge“? Úprimná odpoveď: Cloudflare Workers nemá natívne PHP.

PHP tam beží len cez WASM (php-wasm ... PHP skompilovaný do WebAssembly): na
demo to funguje, ale s obmedzeniami ... žiadne natívne rozšírenia, pamäťové
limity, cold start a WASM réžia.

Produkčne realistický hybrid pre náš stack vyzerá takto:

| Vrstva | Čo servuje |
|---|---|
| Edge (Cloudflare alebo iné CDN) | Statický HTML, assets, cache ... hotový tanier |
| PHP origin (náš stack) | Dynamické chunk-y ... auth, komentáre, admin, full-text search |

To je presne záver z debaty s DeepSeekom ... „dáme pred PHP len CDN“. Nič
viac, nič menej. Nemusíme byť kúpení: edge vrstva je len proxy pred
zero-dependency jadrom, ktoré beží všade.

## Čo povedal DeepSeek a čo sme si zobrali

Debata nebola len o súhlasoch. Dve veci stáli za to zapísať.

**1. Mierka je dôležitá.** Porovnávať našu stránku s Google.com je ako
porovnávať šprint na 100 metrov s maratónom. Google beží maratón s ťažkým
batohom, my šprintujeme na 100 metrov bez záťaže. Oba výsledky sú dobré ...
pre iné účely. Naše 100/100 neznamená, že sme „lepší“ než Google; znamená to,
že robíme presne to, čo potrebujeme, a nič navyše.

**2. 100/100 nie je len o rýchlosti.** Skóre 100/100 znamená aj nulový posun
obsahu (CLS = 0) a okamžitú interaktivitu (výborný INP). Je to dôkaz
stability a disciplíny, nie len výkonného hardvéru.

A jedna vec, ktorú DeepSeek vytkol správne: **geografická distribúcia.** Náš
server je na jednom mieste, Cloudflare je všade. Riešenie je pritom jednoduché
a neporušuje našu filozofiu: nechať PHP backend taký, aký je, a pred neho dať
CDN. Výsledok: globálna rýchlosť Cloudflaru + výkon nášho PHP kódu.

## Zhrnutie

- **Vízia** = „PHP C chunks“ ... statický, prekompilovaný prístup v čistom
  PHP, bez build procesu a bez ťažkého runtime
- **Zero-dependency jadro** ... vlastný autoloader, per-module SQLite, native
  PHP šablóny
- **Cache nie je liek** ... keď spomaľovala LCP, vyhodili sme ju a dostali
  sme 100/100
- **Pridávaj funkcie, nie vrstvy** ... kompilácia a závislosti patria do
  build prostredia, nie na produkciu
- **Rýchlejší ako cache** ... a ak treba globálne doručenie, dáme pred PHP
  len CDN, jadro necháme bez závislostí
- **Edge nie je konkurencia** ... Cloudflare kúpil Astro a stavia „No PHP“
  svet (EmDash); naše jadro beží všade a PHP na Workers ide len cez WASM

## Zdroje

- [DeepSeek](https://github.com/deepseek-ai) ... AI model použitý v debate o filozofii stacku
- [Astro](https://astro.build/) ... framework, ktorého víziu debata porovnávala
- [Cloudflare Workers](https://developers.cloudflare.com/workers/) ... edge runtime, kde PHP beží len cez WASM
- [PageSpeed Insights](https://pagespeed.web.dev/) ... meranie, ktoré potvrdilo 100/100
- [Nativa Minimal Documentation](/docs/getting-started) ... getting started guide tohto kitu
