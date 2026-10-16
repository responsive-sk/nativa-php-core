# Článok: slovenský web, anglická stránka — a šiesta funkcia portu

**17. októbra 2026**

---

## Časť I — Tri riadky, ktoré si odporujú

Kolo sa začalo kontrolou, ktorá nemala nič nájsť. Miesto toho narazila na tri riadky,
ktoré sa navzájom vylučujú:

```
settings    locale = sk_SK, theme = jednotka          (config.db)
rendered    <h1 class="je-hero__title">Minimal PHP 8.5 Starter Kit</h1>
locales.db  home.hero_title / sk_SK = "Minimálny PHP 8.5 starter kit"
```

Web je nastavený ako slovenský. Preklad v databáze **existuje** — je uložený, má `group`
`home`, bol tam od 11. októbra, keď ho zasadil migrátor modulu Locales. A stránka, ktorú
návštevník dostane, je anglická. Nie preto, že by preklad chýbal, ale preto, že **v celom
strome nebolo nič, čo by sa naň vedelo opýtať**.

Toto je presne tá trieda chyby, ktorú strom nevidí: **chýbajúci riadok v prekladovej
tabuli nie je nikde chyba.** Je to fallback v téme. Žiadny test nespadne, žiadny log
nepípne, layout sa vykreslí celý — len v jinom jazyku.

Množstvo práce v tom čísle je väčšie, než sa na prvý pohľad zdá:

| | |
|---|---|
| kľúčov v skupine `home` | **25** |
| jazykov, v ktorých sú zasiate | **2** (`sk_SK`, `en_US`) |
| z nich téma naozaj vykresľuje | **21** |
| zvyšné 4 (`cta_start`, `eyebrow`, `message`, `title`) | nevykresľuje nikto — sú to zvyšky showcase-u |
| kľúčov, ktoré sa z portu dostali do témy | **0** — port vtedy žiadnu skupinu neodpovedal |

A aby to bolo celé, tých 21 kľúčov má fallback: téma `jednotka` si nesie vlastnú anglickú
konštantu `DEFAULT_COPY`. Tá tam nie je omylom — **prinieslo ju predošlé kolo**, keď sa
showcase portoval do jadra ako modul, a jadro vtedy posielalo `copy: ['title' => $siteName]`
a nič viac. Anglická podlaha bola v tej chvíli jediná možná odpoveď. Toto kolo len
pomenovalo, čo tá podlaha znamená na slovenskom webe.

---

## Časť II — Prečo to nebola chyba témy

Prvý inštinkt je ísť do témy a prečítať Locales odtiaľ. To by bolo rýchle a **nesprávne**,
a dôvod je merateľný: téma, ktorá si importuje modul, je presne tá väzba, ktorú tento
strom odstraňuje. Všetky štyri témy v strome to majú vyriešené rovnako — **žiadna z nich
Locales nečíta**:

```
KernelTheme, MonoTheme, ErikTheme, JednotkaTheme   → 0 čítaní locales.db
```

A jadro samo to má napísané vo vlastnom docblocku: showcase domov patrí aplikácii, nie
jadru, a jeho ledger odpoveď je `M` — *presunie sa do modulu, ktorý vlastní text, ktorý
vykresľuje*. Lenže kým sa nepresunie, musí existovať spôsob, ako sa jadro **opýta** bez
toho, aby importovalo. Ten spôsob v strome už bol a volá sa `ShellContextProvider`:

| # | otázka portu |
|---|---|
| 1 | `siteName()` — ako sa web volá |
| 2 | `theme()` — ktorá téma je aktívna |
| 3 | `defaultLocale()` — ktorý jazyk vlastní `/` |
| 4 | `languages()` — aké jazyky web ponúka |
| 5 | `navigation($slot, $path)` — menu |
| **6** | **`copy($group, $locale)` — texty, ktoré plocha vykresľuje** |

Päť z tých otázok bolo vyriešených dávno. Šiesta bola jediná, ktorá do renderera ešte
vchádzala ako reťazec z jadra. Nebola to nová diera — bola to **posledná nedokončená
položka zoznamu, ktorý existoval celý čas**.

---

## Časť III — Rozšírenie portu, nie nová trieda jadra

Rozhodnutie, ktoré padlo: texty doplní jadro z Locales. A hneď prvé obmedzenie, ktoré
to určilo, nie je technické ale procesné — `KernelShapeTest` robí z **novej konkrétnej
triedy v jadre** schvaľovací akt:

```
nová trieda v Modules\Core  →  ALLOW_LIST + MAX_APPROVED + riadok v docs/kernel-shape.md
nové rozhranie / metóda     →  zadarmo („the shape wants them, so they are free")
```

Takže nový registrátor by bol drahý, a metóda na porte, ktorý už odpovedá na päť otázok, je
zadarmo. Zmena je preto malá a rozložená presne takto:

| Kde | Čo pribudlo |
|---|---|
| `ShellContextProvider` | `copy(string $group, string $locale): array` — kľúčované PLNÝM kľúčom; kľúč, ktorý provider nemá, je **neprítomný, nikdy prázdny** |
| `ShellContextProviderRegistry` | `copy()` — **zjednotenie po kľúčoch**, prvý provider, ktorý kľúč drží, ho vyhráva |
| `LocalesShellContextProvider` | odpovedá skupinu priamo z `locales.db`; nezasiate locale sa zdrží, nenahrádza |
| `SettingsShellContextProvider` | odpovedá `[]` — vlastní tabuľku menu a ani jeden preložený reťazec |
| `HomepageAction` | `['title' => $siteName] + copy('home', $locale)` — stále žiadny import modulu |

Dva detaily v pravidle zjednotenia sú zámerné a nie sú náhodou cyklu:

**Je to po kľúčoch, nie po providerovi.** Modul, ktorý prekladá svoje tri reťazce, nemá
prepisovať dvadsať, ktoré už nesie locale modul — a provider, ktorý odpovie skupinu, ktorú
odpovedá aj druhý, ju nemá vyprázdniť celú.

**Prázdny reťazec nie je odpoveď.** Riadok v tabuľke s prázdnou hodnotou nemaskuje
providera za sebou, z rovnakého dôvodu, z akého každá iná metóda portu berie `''` ako
„nemám": podlaha témy je lepší text než prázdno, a prázdno je presne to, ako vyzerá
polovypísaná tabuľka prekladov.

A jedna vec, ktorá zostala nedotknutá zámerne: `title` stále odpovedá jadro, nie port.
`ErikTheme` a `MonoTheme` čítajú krátke kľúče (`title`, `lede`, `hero_eyebrow`), ktoré
skupina `home` neobsahuje — takže toto kolo ich **nemenilo ani o znak**. Additívne, nie
prepisujúce.

---

## Časť IV — Negatívne kontroly

Brána, ktorá nevie spadnúť, nie je brána. Obe polovice pravidla zjednotenia preto boli
najprv rozbité zámerne:

| kontrola | výsledek |
|---|---|
| vypadne guard „prvý vyhráva" (neskorší provider prepíše) | **červená** — `home.merge_probe_b` sa vrátil s hodnotou neskoršieho providera |
| `''` prestane byť „nemám" | **červená** — prázdno maskovalo providera za sebou |
| databáza sa stane zdrojom (do zasiateho riadku sa zapíše `MARKER-SK`) | **`MARKER-SK`** — port číta tabuľku, neopakuje zapamätanú konštantu |
| všetko vrátené späť | **bajt na bajt identické**, zelená |

Tretia kontrola je tá, ktorá má cenu aj mimo tohto kola: test, ktorý by tvrdil „port
odpovedá `Minimálny PHP 8.5 starter kit`", je zelený aj vtedy, keď port nečíta databázu
vôbec a hodnotu má zapísanú v sebe. Až zápis značky **cez** seed to rozlíši.

---

## Časť V — Čo z toho vyšlo, merané na bežiacom serveri

```
GET /                       HTTP 200 · 54 330 bajtov
<h1 class="je-hero__title"> Minimálny PHP 8.5 starter kit
je-hero__subtitle           Malý a čitateľný základ: modulárny monolit, per-module SQLite…
je-hero__actions            Prejsť na blog · Otvoriť admin
je-section__title   ×2      Menej vrstiev · Super rýchle PHP
je-card             ×6      6 textov slovenských; 3 z 6 titulkov anglické — viď koniec (je to obsah seedu)
je-stat__value              215 MB dev → 1,7 MB prod
<title>                     Nativa PHP Core
```

Anglických predvolených textov témy nezostal na stránke ani jeden:
`Minimal PHP 8.5 Starter Kit` → 0, `Fewer Layers` → 0, `Super Fast PHP` → 0,
`Read the blog` → 0. Stránka je o **125 bajtov** väčšia než tá anglická z predošlého kola
(54 205 B), čo je presne ten rozdiel, ktorý urobí slovenčina v diakritike.

Suity, obe polovice kola (všetko exit 0):

| Brána | Výsledok |
|---|---|
| `composer test` | **400 → 408 testov**, 4391 → 4410 asercií, 1 skip (existujúci) |
| `make analyze` | phpstan L9 `[OK] No errors` · rector clean · cs-fixer 0 zo 102 |
| `make lint` | čisté |
| `make lekalo-check` | 22 modulov validných samostatne, celý projekt validný |

Osem nových testov je rozdelené podľa toho, čo ktorý naozaj vie overiť: štyri v jadre
(`tests/Discovery/ShellContextCopyMergeTest.php`) pinnujú **pravidlo zjednotenia** cez dva
dočasné providery v inštalovanom slice, a štyri v module Locales
(`modules/Locales/tests/LocalesShellContextProviderTest.php`) pinnujú **odpoveď pre jazyk**
proti dočasnej `locales.db`, ktorú postaví migrátor modulu sám — nie proti tej v pracovnom
strome, ktorá je stav operátora a nie fixture.

---

## Čo z toho plynie

Najdrahšia vec na tejto chybe nebola jej oprava. Bolo to **ticho**: celá zmena prešla
štyrmi bránami, všetkými zelenými, a web pritom hovoril iným jazykom, než aký mal
nastavený. Nikde v reťazci nebola chyba — bola tam len neprítomnosť, a neprítomnosť je
v tomto strome definovaná ako fallback.

Preto k oprave patrí aj trvalá väzba, ktorá tú neprítomnosť spraví viditeľnou: test, ktorý
porovná **kľúče, ktoré téma v tomto strome vykresľuje**, s kľúčmi, ktoré skupina `home`
naozaj nesie, v oboch jazykoch. Kým platí „nevadí, keď riadok chýba", musí existovať
niečo, čo povie, že chýba. To je jediná časť kola, ktorá nie je o kóde.

**Čo kolo neuzavrelo**, aby to nebolo potichu:

- **Tri slovenské titulky kariet sú anglické v seede samotnom.**
  `home.layer1_card1_title`, `home.layer1_card3_title` a `home.layer2_card1_title` nesú
  `Zero dependencies`, `Per-module SQLite` a `PHP 8.5 + OPcache/JIT` v **oboch** jazykoch
  (`SeedData`, riadky 77/81/84). Stránka teraz vykresľuje to, čo tabuľka obsahuje —
  a prepísať tie tri je editácia prekladateľa, nie beh kódu.
- **`ErikTheme` a `MonoTheme` čítajú krátke kľúče**, ktoré skupina `home` neobsahuje, takže
  naďalej vykresľujú svoje predvolené texty. Port je pre ne pripravený v deň, keď sa ich
  reťazce presunú do skupiny.
- **Texty sa dostanú na domovskú stránku.** Blog index, článok a hľadanie zatiaľ z portu
  neberú nič.
- A jedno meranie, ktoré nestojí za zmienku len pre poriadok: `docs/kernel-status/*` sa
  na `/docs` **zámerne nepublikuje** (DocsCatalog má vlastný zoznam 16 guide-ov), takže
  záznam o tomto kole — *Boot 42* — je interný denník stromu, nie stránka na webe.

A jedna otvorená položka z inej rodiny, ktorú tento text len znovu pomenúva, aby
nezostala potichu — stav `.ai-factory/` v okamihu písania:

```
plans/          7 súborov
evolutions/     1 súbor      ← beh $aif-evolve sa medzitým uskutočnil
patches/        6 súborov
skill-context/  0 súborov    ← stále prázdny, a to je tá polovica, na ktorej stojí point
```

Evolučný denník teda existuje a obsahuje správu z behu — ale `skill-context/` je stále
nedotknutý, čo znamená, že každé ďalšie kolo si tie isté pravidlá objavuje znova. Presne
tak, ako to pomenoval predošlý článok, a presne to sa ani týmto kolom nezmenilo.

---

*Článok bol napísaný v tej istej session, v ktorej sa kolo meralo a opravovalo — na rozdiel
od predošlého, ktorý písala session nasledujúca, pretože tú predošlú prerušil limit.
Všetky čísla boli odmerané pri písaní: 25 kľúčov skupiny `home` v dvoch jazykoch, 408
testov / 4410 asercií, `[OK] No errors`, 22 modulov, `GET / → 200`, `Minimálny PHP 8.5
starter kit`. Ak niektoré z nich už neplatí, je to dôvod na ďalšiu opravu, nie na zmazanie
článku.*
