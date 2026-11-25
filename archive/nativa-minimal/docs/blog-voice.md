# Blog voice — štylistická príručka „pre deti"

Táto príručka kodifikuje hlas článkového blogu tohto kitu — ten istý hlas,
ktorý nesú série **PHP types for kids** a **Rust & Lekalo**. Cieľ je rovnaký
ako vždy: aby z lekcie zostalo **pravidlo, nie vzduch**. Najčistejšie ukázky,
ktoré tento dokument len popisuje:

- [`storage/blog/preco-rust-pre-deti.md`](/blog/preco-rust-pre-deti) / [`storage/blog/en/why-rust.md`](/en/blog/why-rust)
- [`storage/blog/typuj-pred-iteraciou.md`](/blog/typuj-pred-iteraciou) / [`storage/blog/en/type-before-iterating.md`](/en/blog/type-before-iterating)

## Jadrové pravidlo

> „Pre deti" zjednodušuje **cestu k pravde**, nikdy nie **samotnú pravdu**.

Čitateľ je inteligentný, ale v téme nový. Nie je hlúpy a nie je zákazník,
ktorého treba naladiť. Preto hlas znie ako **kámoš, ktorý nelichotí**:
jednou rukou hladí po hlave, druhou ukazuje na realitu. Lichotenie je
`mixed` — vyzerá ako všetko, nestojí za nič.

## Tri pravidlá hlasu

### 1. Kámoš, ktorý nelichotí

Píš priamo, v druhej osobe, krátkymi vetami. Ale každú vetu, ktorá by
prechválila, hneď vyvaž úprimnosťou:

> Buďme úprimní: Rust je zlý fit na typické CRUD webové aplikácie.

Kámoš, ktorý ti lichotí, nie je kámoš. Každý článok má preto aspoň jednu
sekciu alebo vetu typu „kedy to **neplatí**" — bez nej článok nie je
dôveryhodný, len reklama.

### 2. Pravda má deklarovaný typ

Tvrdenie bez podmienky je `mixed`. Ak niečo platí len za istých okolností,
podmienka patrí do textu — ako `?? null` + fallback na boundary:

- nie „Rust je rýchly", ale „Rust sa oplatí v **toolingu** — dlhobežné,
  výpočtovo náročné alebo bezpečnostne kritické veci";
- nie „normalizuj dáta", ale „normalizuj **raz, na boundary** — nie v každej
  iterácii cyklu".

Nadnesené superlatívy sú zakázané. Každé „rýchle" má mať číslo, mieru alebo
príklad z repa.

### 3. Cast na reálny kód z repozitára

Každý príklad pochádza z **tohto projektu** — `src/init.php`,
`BlogIndexAction::articles()`, reálny commit, reálny front matter. Abstraktné
`foo`/`bar` príklady nepatria do článku. Je to tá istá disciplína ako
„cast before iterating": ak príklad nemôže prísť z boundary tohto kitu,
nerobí v článku prácu.

## Typografia a rukopis

| Značka | Pravidlo | Ukázka z článku |
|---|---|---|
| ` ... ` | Tri bodky s medzerami ako **rukopisná značka** — v titulkoch a vo vetách namiesto dvojbodky/pomlčky. Podpis hlasu. | „Rust a Lekalo pre deti ... diel 1", „PHP ti pamäť uvoľňuje ... neviditeľne, v nepredvídateľných momentoch" |
| — | pomlčka s medzerami na skutočné odbočky | „zero-dependency, self-contained, minimálne" |
| **bold** | len pojmy, ktoré článok **definuje** | **ownership**, **boundary**, **žiadny runtime** |
| `` `code` `` | všetko technické: súbory, funkcie, hodnoty | `src/init.php`, `is_array()`, `list<string>` |
| „ " vs " " | SK články používajú nízke úvodzovky „ ", EN články rovné " " | „šuplík s nápisom…" / "systems programming" |
| tabuľky | vždy, keď sa porovnávajú dva prístupy — bez milosti | tabuľka „Without normalization / With boundary normalization“ v part 3 |
| odrážky | max 1 úroveň vnorenia; dlhšie ako 2 riadky → odstavec | zoznam dlhobežné/výpočtové/bezpečnostné |
| `--` | **zakázané v próze** — jediný znak `—` (alebo ` ... `). Dvojitá pomlčka žije výhradne v kóde a CLI (`cargo fmt --all`, `--admin-password`). | kód: `clippy --workspace --all-targets` |
| prídavné mená | **úplný zákaz dvojíc** — dve prídavné mená nikdy vedľa seba, ani technické páry. Druhý výraz prelož do genitívy alebo podstatného mena: „hashovací kód" → „hash kódu", „nový hashovací kód" → „nový hash". | — |

Emoji do článkov nepatria. Nadpisy H2 sú krátke a podstatné („Čo Rust je",
„Prakticky"), nie vtipné.

## Rytmus riadkov

Zdroják článku oddeľuje myšlienku na nový riadok — ale renderer súvislé
riadky zleje do jedného odstavca (`MarkdownRenderer`,
`implode(' ', $paragraph)`). Z toho vyplývajú dve pravidlá — jedno
viditeľné, jedno tiché:

1. **Prestávka si zaslúži odstavec.** Keď veta niečo hlboké povie
   (definícia, pointa, úprimnosť), nasleduje prázdny riadok a nový
   odstavec. Čitateľ zavrie oči, zamyslí sa — a okom ľahko nájde, kde
   text pokračuje. V HTML vznikne skutočná vizuálna prestávka.
2. **Myšlienka začína na novom riadku.** V zdrojáku začína každá veta
   novým riadkom, aj keď ich renderer potom zleje — source je pracovný
   rukopis, nie výsledok. Nikdy nelám veta v polovici myšlienky; koniec
   riadku je koniec myšlienky alebo jej prirodené odpočívadlo.
3. **Štruktúra vlastný celý riadok.** Nadpis (`## `) ani ohraničenie kódu
   (``` ) nikdy nie je zlepené s prózou — „veta. ## Nadpis“ alebo
   „text: ```php“ renderer nevykreslí ako štruktúru: riadok zleje
   do odstavca a nadpis/kód zmizne ako stena textu. Zavárací fence
   (``` ) ostáva sám na riadku. Stráži to stroj
   (`BlogVoiceTest::testArticleHeadingsAndFencesOwnTheirLine`).

Dlhý odstavec z viacerých hlbokých viet sa rozpadne — každá myšlienka
dostane vlastný priestor. Stena textu je porušením rytmu.

## Rytmus ako pomôcka, nie pravidlo

Siedem fáz (hák, hmla, slnko, noc, objekt, téma, outro) je nástroj
v ruke autora. Nie je to brána a nie je to meradlo kvality. Čitateľ
neskúša sedem fáz, on číta.

Blog je tu pre čitateľa — pre hostí a pre agentov. Nie oni pre nás.
To znamená aj to, že hlas sa mení podľa nich: nie podľa šablóny,
nie podľa toho, čo posledný článok vypadal na.

Tri veci pritom ostávajú tvrdé, lebo sú o spôsobe, ako píšeme, a nie
o vkuse: dôstojnosť slova (žiadne výplňové slová, žiadne ukazovadlá
namiesto reality), pravdivosť každého tvrdenia a reálny zdroj, z ktorého
dá sa overiť. Tie majú vlastné stroje — `HumanLanguageTest` a
`BlogVoiceTest` — a tie sa nemenia.

Nástroje v `bin/` (`audit-rhythm.php`, `rhythm-diagram.php`,
`tempo-analyzer.php`) sú poradcovia. Vracajú 0, kým ich nevyvoláš
s `--strict`, a do žiadnej brány nepatria: publikovaný korpus má
rovnaké skóre ako najslabšie články a `hello-world` nesplňuje všetkých
deväť kontrol. Vynútiť im body bolo by zavedenie pravidla, ktorému
všetko v tomto repozitári nevyhovuje.

## Ako sa hlas mení

Dôkaz, ktorý hlas mení:

- **Otázka čitateľa na tom istom mieste článku, znova a znova.** Kto sa
  na jednom bode zastaví dvakrát, ten bod nie je zlé napísaný veta,
  je zlé vysvetlené
- Čitateľ, ktorý musí prečítať odstavec dvakrát, aby zistil, o čom je
- Ticho po vete: veta, ktorá nič nesie

Čo hlas **nemení**:

- Skóre fáz. `docs/rhythm-score-matrix.md` je experiment z 26. 8. 2026,
  nie backlog
- Počet odstavcov, dĺžka viet, odhad času čítania
- Pravidlo, že článok musí mať všetkých sedem fáz

Nástroj, ktorý tento dôkaz zbiera, je plánovaná otázková schránka
(`Modules\Questions`, plán `openspec/changes/reader-questions-module.md`).
Kým je postavená, „sme tu pre čitateľa" je úprimná poloha, nie dôkaz —
a je lepšie povedať si to než vyplniť medzeru vlastným skóre.

## Anatómia článku

Poradie sekcií je stabilné — čitateľ sa v ňom oriantuje naprieč sériami:

1. **Front matter** — `title`, `excerpt`, `tags` (3–6), `category`
   (zvyčajne Architecture), `author` + `author_email`, `date`,
   `translation`; sériové články pridávajú `series` + `series-part`.
2. **Blockquote intro** (sériové články) — mapa série s odkazmi na ostatné
   diely + jedna veta o dnešnej téme.
3. **Úvod v druhej osobe** — jedna situácia čitateľa, nie definícia témy:
   „Si PHP vývojár. Píšeš webové aplikácie. Niekto povie „Rust" a tvoj mozog
   si to potichu založí do šuplíka…"
4. **Jadro** — H2 sekcie, každá nesie jednu myšlienku; kód z repa s
   komentárom-umiestnením (`// src/init.php — …`), porovnávacie tabuľky.
5. **Úprimná sekcia** — „Kedy sa to oplatí (a kedy nie)" alebo rovnocenná
   veta. Povinná.
6. **Záverečný most** — sériové články „Čo bude ďalej" (odkaz na ďalší diel
   + čo čitateľ získa), samostatné články „Prakticky" (číslované, max 3
   kroky, posledný bod je Pointa-veta: *„The loop then answers the question
   „what to do with it" ... not „what is it, actually"."*).
7. **`## Zdroje`** — vždy posledná sekcia: 3–5 oficiálnych odkazov
   (dokumentácia jazyka/nástroja) + odkaz na Nativa docs. Žiadne paywally,
   žiadne SEO farmy.

## Bilingvizmus

- SK je jazyk vzniku, EN je plnohodnotné zrkadlo (nie strojový preklad —
  krátke vety a tabuľky sa prekladajú dobre, preto ich hlas používa).
- Slugy sú **per-jazyk** (`preco-rust-pre-deti` ↔ `why-rust`) a viažu sa
  obojsmerne cez `translation:` front matter.
- Interné odkazy nesú locale prefix: SK článok linkuje `/blog/{slug}`,
  EN článok linkuje `/en/blog/{slug}`.
- Titulok série: SK „{Séria} pre deti ... diel N: {téma}", EN
  "{Series} ... part N: {topic}". Series-part v EN sa píše `part N`.

## Série

Séria je zmluva vo front matter. `series:` je **ID série** (stalý reťazec,
nikdy sa nemení — hashuje sa do identity obálky), titulok nesie zobrazovacie
meno. `series-part:` je pozícia v sérii a **čísluje sa verziovanie, nie
lineárne** — preto sa kolízie riešia vetvou, nie prečíslovaním:

| Séria | `series:` ID | Číslovanie | Stav |
|---|---|---|---|
| Rust a Lekalo | `Rust & Lekalo` | major.diel + vetvy minor | 13 major + 2 vetvy (9.1, 9.2) = 15 dielov |
| Prečo je PHP rýchle | `Prečo je PHP rýchle` / `Why PHP is fast` | lineárne 1–3 | 3 diely |
| PHP typy pre deti | — (titulok = ID, legacy) | lineárne 1–6 | 6 dielov |
| Clean code pre deti | — (legacy) | lineárne 1–6 | 6 dielov |
| Performance pre deti | — (legacy) | lineárne 1–5 | 5 dielov |
| OpenSpec pre deti | — (legacy) | lineárne 1–5 | 5 dielov |
| Refaktor frontendu | — (legacy) | lineárne | 1 diel |
| Bonus pre členov | — (legacy) | bez čísel — téma je názov | 4 bonusy |

Pravidlá:

1. **`series:` a `series-part:` idú vždy v páre** — jedno bez druhého je
   neúplná zmluva (test ich vynúti).
2. **`series-part` musí byť unikátny v rámci série a jazyka.** Pri kolízii
   (článok nadväzuje na už obsadené dielo) nový diel dostane **vetvu**:
   diely o témach vyšli chronologicky po dieli 9, ale číslo 9 bolo obsadené
   — dostali preto `9.1` a `9.2`. Ghost číslo na obálke aj badge (`DIEL 9.1`)
   vykreslí existujúci `CoverGenerator` bez zmeny kódu.
3. **Legacy série (bez ID) sa nedokončujú dozadu.** Ich titulky sú ID —
   unikátnosť titulkov stráži druhý test. Zavádzanie `series:` do starých
   sérií by zmenilo identity obálok (hash série) a vynútilo by regeneráciu
   ~50 obrázkov — zisk nestojí za to.

## Čo číslo na obálke hovorí čitateľovi

Obálka nesie **ghost číslo** — hodnotu `series-part:`. Čitateľ, ktorý vidí
`9.1`, má vedieť, čo mu číslo hovorí ... a čo nie:

| Na obálke | Čo to je | Ako to čítať |
|---|---|---|
| `DIEL 4` / `PART 4` | celé číslo — lineárny diel | pozícia v sérii, chronologické poradie |
| `DIEL 9.1` / `DIEL 9.2` | **vetva** — duológia, ktorá nadväzuje na diel 9 | „diel 9 sa naplnil, téma pokračuje v bočnej vetve" |
| žiadne číslo | bonusový článok | téma stojí mimo číslovanej série |

Vetva **nepokračuje lineárne radenie** — `9.1` nie je „desiaty diel". Je to
súrodenec dielu 9: čerpá z toho istého miesta v príbehu, ale vyšlo po ňom.
Kolíziu čísel nerieši prečíslovanie série (rozviali by sa externé odkazy),
ale vetvou — presne ako v softvéri.

K číslu na obálke patrí aj **verzia** — tá žije vo front matter
(`version:`), nie na obálke. Pre čitateľa, ktorý linkuje, platí jednoduchá
pomôcka:

- **párne MINOR (1.2.0, 1.4.0) = LTS** — stabilné vydanie, obsah zamrazený,
  mení sa už len patchmi. Takýto článok smiete linkovať do slajdov a
  dokumentácie bez strachu.
- **nepárne MINOR (1.3.0) = vývojová verzia** — ešte sa v nej vŕta.
- **0.x.y = draft** — rozpracovaný materiál, vôbec nie je vydanie.

Každý autor má za sebou nový článok posadiť do tejto schémy — to je
redakčná disciplína, ktorú kontrolný zoznam nižšie pripomína.

## Verzie článkov

Každý článok nesie `version:` front matter — rovnakú schému ako softvér,
lebo článok je produkt:

| Zmena | Bump | Príklad |
|---|---|---|
| preklep, typografia, oprava linku | **PATCH** (1.0.**1**) | „diel 8" → „diel 9.1" v odkaze |
| obsah: nová sekcia, úprimnosť, tabuľka, dôkaz | **MINOR** (1.**1**.0) | doplnená tabuľka porovnania |
| štruktúra: premenovanie, zlúčenie sekcií, nová pointa | **MAJOR** (**2**.0.0) | článok prepísaný do novej štruktúry |
| draft pred prvým publikovaním | **0.x.y** | `0.1.0` |

**LTS vydanie = párne MINOR** (1.**2**.0, 1.**4**.0) — verzia, ktorú linkuješ
ako stabilnú: dostáva len patche, obsah sa nemení. Nepárne MINOR sú
vývojové — tam sa ešte robí. Úprimná časť: test vynúti existenciu, formát
a 0.x pre drafty; **čo bumpneš, je redakčná disciplína** — bez histórie
vydaj to mechanicky neoveríš. Rovnako ako „pre deti“: pravidlo, ktoré
dodržiavaš len preto, že ho stráži stroj, nie je pravidlo.

### Ako sa diel stáva LTS

Označenie nie je pocit — stojí na dôkaze o linkovaní:

1. **Diel je linkovaný mimo korpusu** — karta na homepage
   (`HomepageAction`), ukážka v tejto príručke, odkaz v `docs/` alebo
   canonical spece. Kto linkuje, potrebuje stabilitu.
2. **Nebanknuté úpravy sa započítajú pred zamrazením** — ak diel medzi
   `1.0.0` a dnes prešiel obsahovým sweepom (rytmus, Zdroje, sériové
   front matter), je to MINOR: bumpne sa na párne MINOR a zamrzne.
3. **Odvtedy len PATCH** — preklepy a linky. Obsahová zmena v LTS dieli
   znamená rozbiť mraz (nový párny MINOR), nie potichu upraviť.

Aktuálne LTS diely: `opcache-a-jit-prekompilacia` /
`opcache-and-jit-precompilation` (karta na homepage, 1.2.0),
`preco-rust-pre-deti` / `why-rust` (ukážka v tejto príručke, 1.4.0 — mraz
prelámaný novým párnym MINOR po doplnení sekcie „Pravda nesie typ“,
presne podľa pravidla 3 vyššie) a `typuj-pred-iteraciou` /
`type-before-iterating` (druhá ukážka, 1.2.0).

## Kontrolný zoznam pred publikovaním

- [ ] Každé tvrdenie má buď podmienku („platí, keď…"), alebo dôkaz z repa
- [ ] Článok obsahuje aspoň jednu úprimnú vetu o tom, **čo to NIE je**
- [ ] Všetky príklady sú z tohto repozitára (súbor, commit alebo front matter)
- [ ] Nula nadnesených superlatívov; „rýchle" má číslo alebo mieru
- [ ] Sériový článok: blockquote mapa + titulok „ ... diel N:" + most na ďalší diel
- [ ] Porovnanie dvoch prístupov je v tabuľke, nie v odstavcoch
- [ ] `## Zdroje` posledná sekcia, oficiálne dokumentácie + Nativa docs
- [ ] SK aj EN verzia existuje, `translation:` je prepojené obojsmerne
- [ ] `version:` zodpovedá skutočnej zmene (PATCH/MINOR/MAJOR podľa tabuľky); draft má `0.x`
- [ ] Sériový článok: `series:` + `series-part:` v páre a `series-part` nie je už obsadený (tabuľka Séries)
- [ ] Typografia: ` ... `, —, **bold** len pre definované pojmy, žiadne emoji,
      žiadne `--` v próze, žiadne dvojité prídavné mená
- [ ] Rytmus riadkov: hlboká veta má vlastný odstavec, myšlienka začína na novom riadku
- [ ] Prečítané nahlas znie ako kámoš, ktorý ti povie pravdu — nie ako predajca
