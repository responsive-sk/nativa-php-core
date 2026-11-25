---
title: "Témy pre deti ... diel 3: kontrakt bez zákazníka"
series: "Témy pre deti"
series-part: "3"
date: "2026-10-03"
version: "1.0.0"
published: true
tags: ["architecture", "themes", "refactoring", "tests"]
excerpt: "ThemeRenderer mal dve implementácie a nula volaní ... a žiadny test si to nevšimol. Prvý diel postavil hranicu, druhý rozdelil frontend, tretí nájde kód, ktorý nikto nezavolá, a pravidlo, ktoré ho už nenájde."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "a-contract-with-no-callers"
---

> [Diel 1](/blog/temy-ako-moduly-pre-deti) postavil hranicu medzi jadrom a témou.
> [Diel 2](/blog/balik-pre-kazdu-temu) rozdelil frontend na päť balíkov.
> Tento diel nájde kód, ktorý v tej hranici ostal ... a ktorý nikto
> nezavolal.

Si PHP vývojár. Otvoríš interface, vidíš dve triedy, ktoré ho implementujú,
a myslíš si: „toto sa používa".

Používa sa.

Nie.

## Plán, ktorý sa nepodarilo

Diel 1 postavil `ThemeModule` — kontrakt s jednou metódou na každú verejnú
plochu. Písal som tam aj jednu vetu o tom, čo urobíme s tým starým.

```php
// src/Themes/starter/StarterRenderer.php — plán z ADR-0003
// "Renderer keeps the ThemeRenderer six public methods as
//  explicit one-line delegates until a later change folds them in."
```

Ten neskorší krok neprišiel.

A to, čo namiesto neho ostalo, neboli jednoriadkové delegáty. Boli to **plné
tele** — dvesto riadkov kódu, ktorý niekto napísal, niekto prečítal a nikto
nikdy nespustil.

## Audit namiesto predpokladu

Najprv som si povedal, že „to je určite niekde použité, len to hľadám zle".

Tak som to overil. Neodhadom — grepom.

| Čo som hľadal | Čo som našiel |
|---|---|
| `implements ThemeRenderer` | 2 — `StarterRenderer`, `erik\Renderer` |
| Miesta s typovou anotáciou (`: ThemeRenderer`, `ThemeRenderer::`) | **0** |
| `$obj->blogIndex(`, `$obj->homepage(`, `$obj->blogArticle(` … | **0** |
| Referencie mimo `src/Themes/` | 0 (jedna vetva v docbloku) |

Každý hit v tretej kolóne patril `$module->` na `ThemeModule`, nie na renderer.

Toto je dôležitá časť práce a zároveň najzábavnejšia: **zmeranie namiesto
predpokladu**. Kým som negrepol, `ThemeRenderer` bol v mojej hlave funkčný
kontrakt. Po grepe bol mŕtvy.

| Bez auditu | S auditom |
|---|---|
| „Používa sa, len to nevidím“ | „Nepoužíva sa vôbec“ |
| „Dva implementátori = živé použitie“ | „Dva implementátori = dve kópie tej istej mŕtvej veci“ |
| Refaktor s hádačkou | refaktor s faktom |

## Čo mŕtvy kontrakt ťahal so sebou

Riadky nie sú celý príbeh. Najzaujímavejšie bolo, čo zmizlo **okrem** metód.

```php
// src/Themes/erik/Renderer.php — toto zmizlo spolu s kontraktom
$articles[] = new Article(
    slug: self::str($card, 'slug'),
    title: self::str($card, 'title'),
    date: self::str($card, 'date'),
    // … 15 ďalších polí čítaných z netypovaného poľa
);
```

Ten `toArticles()` mapoval `array $articles` — pole kariet — na `Article` VO.
Súčasne existovali **dva tvary jedného konceptu**: `Article` a „pole, ktoré
sa práve tak volá“.

A jadro malo vlastný tvár: `PageContext` nesúce články, mená, počty, kontext
paginácie — všetko typované.

Takže mŕtvy kontrakt nebol zbytok po refaktore. Bol **zámernou poznámkou
k štýlu**, ktorý kontrakt popierol. Mŕtvy kód zvyčajne hovorí niečo o tom, čo
kto vtedy veril, že je správne.

Z výsledku:

| Súbor | Pred | Po | Čo zostalo |
|---|---|---|---|
| `ThemeRenderer.php` | 92 | zmazané | — |
| `StarterRenderer.php` | 443 | 120 | článok + TOC |
| `erik\Renderer.php` | 266 | 135 | 10 statických metód |

Celkovo **−573 riadkov kódu**, ktorý sa nikdy nevykonal.

## Testy si to všimli — zlým spôsobom

Najzábavnejší okamih refaktoru: PHPStan level 9 bol zelený **okamžite** po
tom, ako som zmazal metódy.

Nie „zelený po chvíli". Zelený na prvý raz.

Level 9 rozlišuje každé volanie metódy proti jej triede. Ak by niekoho bolo
volanie `ThemeRenderer::blogIndex(...)`, PHPStan by povedal „taká metóda
neexistuje".

Takže level 9 nebol zelený ako vôľa dostatočný test. Bol zelený ako
**dôkaz**. Ako meranie, nie ako vôľa dostatočný test.

A ostatné testy? Taktiež zelené. 3608 testov, 21496 tvrdení, ani jeden nepadol.

Lebo test, ktorý volá `ThemeModule::blogIndex()`, nevie, že vedľa neho stojí
Interface, ktorý nikto nevolá. Ako by mal vedieť? Žiadny test neoveruje
„niekto niečo nepoužíva“.

## Pravidlo, ktoré chýbalo

Projekt mal guard na špekulatívne rozhrania. Bol správny — len bol úzky.

`CorePatternTest` hlási rozhranie, keď má **presne jednu** implementáciu a
žiadneho spotrebiteľa mimo vlastného modulu.

`ThemeRenderer` mal **dve**. Pravidlo hlási „presne jedna", takže dve
prechádzalo.

Pravidlo je chybné nie preto, že je zlé, ale preto, že je **špecifickejšie než
problém**. Problém nebol „príliš málo implementácií". Problém bol „nikto to
nepoužíva".

Nový guard `UnconsumedInterfaceTest` má dve ramená:

| Rame | Tvrdenie |
|---|---|
| `testEveryInterfaceHasAnImplementation` | každé rozhranie v `src/` má aspoň jednu implementáciu |
| `testEveryInterfaceHasAConsumerOutsideItsImplementors` | každé rozhranie mimo kernela má spotrebiteľa, ktorý nie je jeho vlastná deklarácia ani jeho implementátor |

Dve rozhodnutia, ktoré stáli za ním, a obe sú záporné skúsenosti.

### Anonymná trieda je implementátor

Prvá verzia skenu vyžadovala názov triedy. A hneď hlásila:

```
Interfaces with no implementation: CliOutput
```

Takmer som to zmazal.

`CliOutput` **je** implementovaný — v `Bootstrapper` takto:

```php
// src/Modules/Core/Infrastructure/Bootstrap/Bootstrapper.php:65
$output = new class () implements CliOutput {
```

Anonymná trieda nemá meno. Meno nemá, kým ju nenazveme. Regex som opravil na
`class` s voliteľným menom.

Pravidlo, ktoré zabilo živý kontrakt, bolo zle. Nie preto, že bolo prísne —
preto, že bolo neúplné.

### Jadro je imúne, ale nie slepé

`Modules\Core` a `Modules\Database` sú zdieľané jadro — jeho rozhrania sú
hlavičky („libc headers"). Nemusia mať spotrebiteľa vo vnútornej projekte.

Ale **nemusia mať ani jednu implementáciu**. Hlavička, ktorú nikto neinkluduje,
nie je hlavička. Je to poznámka.

## Druhá mŕtva vec, nájdená tým istým guardom

Nový test padol pri **prvom behu**. Nie kvôli `ThemeRenderer` — ten bol
zmazaný. Kvôli niečomu inému:

```
Interfaces with no implementation:
  ModuleInterface (src/Modules/Core/Interfaces/HTTP/ModuleInterface.php)
```

Žiadna trieda ho neimplementovala. Jediná reference mimo seba bola jeho
vlastná deklarácia.

```php
// src/Modules/Core/Interfaces/HTTP/ModuleInterface.php — zmazané (46 riadkov)
interface ModuleInterface
{
    public function name(): string;
    public function register(Kernel $kernel): void;
    public function migrations(): array;
}
```

Opisoval registráciu modulov. A kernel registráciu modulov **nahradil**:
middleware sa kreslí cez `#[AsMiddleware]`, migrácie cez `#[AsMigrator]`.
Atribútová discovery. Rozhranie zostalo ako schránka po niečom, čo odišlo.

Dva mŕtve kontrakty. Jeden som našiel auditom, druhý **guardom, ktorý som
napísal kvôli prvému**.

To je merateľný výsledok refaktoru: pravidlo, ktoré odstráni chybu, našlo
ďalšiu pri vytváraní, nie pri hľadaní.

## Kedy to neplatí

Buďme úprimní — tri veci.

**Guard má false positives a to je v poriadku.** Prvá verzia skenu
implementáciov označila `CliOutput` za mŕtvy kontrakt. Šlo o chybu v skene, nie
v kóde — a opraviť ju znamenalo opraviť pravidlo, nie obísť pravidlo. Keby som
dal `CliOutput` do zoznamu výnimiek, guard by stratil schopnosť nájsť ten
skutočný problém.

**Guard nevie, či je rozhranie „zámerné“.** Niektoré rozhrania majú nula
implementácií zámerne — aby sa zapísal kontrakt, ktorý príde neskôr. Guard ich
označí. V tomto projekte žiadne také nie sú a `ModuleInterface` medzi ne
nepatril (opisoval niečo, čo už fungovalo inak), takže sa výnimka nepridávala.

**460 riadkov mŕtveho kódu nie je najproblematickejší prípad.** Problem je
mŕtvy kód, ktorý vyzerá ako živý. `ThemeRenderer` mal dve implementácie, ktoré
sa navzájom volali cez vlastné statické metódy — volanie existovalo, len
nebolo to volanie, ktoré hľadáme. Bol čitateľný a úplne zbytočný. Človek, ktorý
by ho otvoril, by z vypisovaných metód nepoznal, že je mŕtvy.

## Čo bude ďalej

V štvrtom diele sa pozrieme na opačnú stránku tej istej mince: čo sa stane,
keď **guard príliš zúži**.

`CorePatternTest` hlási „presne jedna implementácia". Dve implementácie
prechádzajú. Ukázal som, že ten prípad je mŕtvy. Ale čo rozhranie, ktoré
implementuje niekto **zvonka**?

O tom, prečo „žiadny externý spotrebiteľa" nie je vždy chyba — a prečo je
odpoveď na to iná než zoznam výnimiek.

## Zdroje

- [PHP Manual — rozhrania](https://www.php.net/manual/en/language.oop5.interfaces.php) — oficiálna dokumentácia k `interface`
- [PHPStan](https://phpstan.org/) — statická analýza na úrovni 9
- [PHPUnit](https://phpunit.de/) — dokumentácia testovacieho frameworku
- [Refactoring Guru — Extract Interface](https://refactoring.guru/design-patterns/extract-interface) — kedy rozhranie vytvoriť a kedy nie
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod kitu