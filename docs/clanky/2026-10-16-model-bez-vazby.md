# Článok: model, ktorý nikto nespúšťal > a väzba, ktorá chýbala

**16. októbra 2026**

---

## Časť I > Dilema, ktorú bolo lacnejšie zmerať než vyriešiť

O 20:47 padla otázka, ktorá vyzerá ako otázka o organizácii súborov:

> bin cms je iba init construktor pre zakladny app run ... lekalo ale musí kontrolovať
> každý modul ... dilema ... každý modul svoje lekalo aj testy?

Vo vete je predpoklad, ktorý sa dá prečítať dvakrát a ani raz si ho nevšimnúť:
**„lekalo musí kontrolovať každý modul."** Ak to platí, potom je to, kde model leží,
skutočná otázka — a presun `lekalo/modules/<modul>/` do `modules/<Modul>/lekalo/` je
odpoveď.

Ak to neplatí, otázka je iná a odpoveď je inde.

Rozdiel medzi tými dvoma svetmi sa nedal vyriešiť úvahou, ale jedným príkazom. Od otázky
po zmeranú odpoveď to trvalo **69 sekúnd** (20:47:41 → 20:48:50). Rozhodnutie, čo s tým,
trvalo potom **16 minút** — a to je zdravý pomer, nie zlý: číslo bolo lacné, voľba nebola.

---

## Časť II > Čo povie jeden `module:remove`

Model je dnes **21 adresárov, jeden na modul** — to znamená, že je už po moduloch
rozdelený, len leží inde. Veľkosti ukazujú, že väčšina z toho sú skoro prázdne obálky:
**štrnásť** z nich je jediný súbor pod 500 B a skutočných modelov je **sedem** — od
`audit` (4 516 B / 5 súborov) po `blog` (24 712 B / 8):

```
griffe             213 B  1 file(s)      core               492 B  1 file(s)
outbox             231 B  1 file(s)      audit             4516 B  5 file(s)
media              234 B  1 file(s)      comments         12569 B  7 file(s)
validation         234 B  1 file(s)      pages            13799 B  8 file(s)
security           238 B  1 file(s)      queue            15625 B  6 file(s)
mono               240 B  1 file(s)      questions        17781 B  8 file(s)
erik               243 B  1 file(s)      users            24174 B  8 file(s)
settings           244 B  1 file(s)      blog             24712 B  8 file(s)
locales            252 B  1 file(s)
cardboard          266 B  1 file(s)
database           313 B  1 file(s)
scheduler          324 B  1 file(s)
kernel_theme       416 B  1 file(s)
```

Potom sa stal test celého predpokladu. Modul `Media` je malý, jeho model má 234 B,
a `module:remove` už vie pracovať s `--force`:

```
$ php bin/cms module:remove Media --force
model Media stale existuje: ANO
--- lekalo-check po odstraneni Media ---
lock checked sha256:e77647f7... resolver 0.2.16 (adapters 0, generators 0, profiles 0, capabilities 0)
validated default profile v0.6.4: 23 rules, 0 errors, 0 warnings, 0 info
exit=0
```

**Model opísal modul, ktorý v strome už nebol, a brána zostala zelená.** Nie je to
medzera v pokrytí. Je to odpoveď na dilemu: Lekalo **nekontroluje ani jeden modul** —
a `adapters 0` nie je interpretácia, je to riadok, ktorý vypísal samotný resolver,
vedľa `generators 0, profiles 0, capabilities 0`.

Po meraní sa `Media` vrátil späť (`module:add Media`), model preň je tam, kde bol, a
všetko je zelené. Zmerať sa dalo aj niečo, čo by úvaha nechala otvorené: **množiny si
sedia.** Dnešná aritmetika (overená znovu, keď sa tento článok písal):

| | počet |
|---|---|
| modelové slices | **21** |
| z toho katalogovaných modulov | **18** |
| z toho jadro (`core`, `database`, `kernel_theme`) | **3** |
| katalogovaných modulov celkom (vrátane `Scratch`) | **19** |
| katalogovaných modulov **bez** modelu | **1** — `Scratch` |
| nainštalovaných modulov bez modelu | **0** |

Takže model nie je neúplný a nie je ani o modul napred. **Množina je na oboch stranách
rovnaká. Chýba väzba medzi množinou a kódom** — a to je vlastnosť, ktorú nezmení ani
presun, ani reorganizácia, ani nový priečinok.

---

## Časť III > Prečo presun do `modules/<Name>/lekalo/` nič neopraví

Dva dôvody, oba merateľné, nie odhadnuté.

**1. `module:remove` maže presne jednu zložku. `src/Modules/<Name>`.**

```php
self::deleteTree(AppPaths::instance()->src('Modules') . DIRECTORY_SEPARATOR . $name);
```

Katalógu `modules/<Name>/` sa nedotkne a `lekalo/modules/<Name>/` nevidí vôbec. **Model
by teda po presunutí do modulu zostal rovnako nesmrteľný ako dnes** — presun by zmenil
adresu nemennosti, nie jej povahu. Ten istý zápis je v `ModuleInstaller::remove()`
obklopený kontrolami (validita mena, jadro, `isInstalled`, `importedBy`), ale posledný
riadok je jediné miesto, kde sa niečo na disku skutočne stratí.

**2. Lekalo nemá pojem „inštalovaný".** Loader číta súbory, nie strom. Preto `adapters 0`
— a preto presun modelu mení to, **kde dokumentácia žije**, nie to, či je pravdivá.

A jedna vec, ktorá patrí do tej istej rodiny ako prázdne `evolutions/` z predošlého
článku: Lekalo **vie** importovať — `lekalo load --help` hovorí *„Load YAML/JSON sources,
resolve imports, and emit the canonical model"*. Lenže `imports` sa v žiadnom modeli
tohto stromu nevyskytuje ani raz. **Schopnosť existuje a je nepoužitá**, takže návrh
„každý modul má svoje lekalo" je mechanicky možný už dnes — a napriek tomu sám o sebe
nič nezachráni, kým neexistuje kontrola, ktorá by mala čo kontrolovať.

To je celý rozdiel medzi presunom a opravou: **presun bez väzby je presun problému.**

---

## Časť IV > Väzba, ktorá stála pár riadkov

Rozhodnutie znelo „väzba + presun", v tomto poradí. Väzba je test v štýle
`KernelContractTest` — dva zoznamy adresárov proti sebe, žiadny adaptér, žiadny zásah do
Lekala, žiadne schémy. `tests/Architecture/ModelCatalogueContractTest.php`:

- každý adresár v `lekalo/modules/` pomenuje modul, ktorý katalóg pozná;
- každý katalogovaný modul má modelový slice;
- jadro modelované zámerne (`core`, `database`, `kernel_theme`) je pomenované **zoznamom**,
  nie vzorom — aby sa výnimka nedala ticho rozšíriť;
- a negatívna kontrola natrvalo: `Media` je modul, na ktorom bola celá vec meraná.

Prvý beh testu spadol — a to je jeho vlastná malá história. `new ModuleCatalog()` volá
konštruktor, ktorý chce dva korene, a `ArgumentCountError` bol prvý, čo test povedal.
`ModuleCatalog::fromAppPaths()` pritom existoval celý čas. Rovnaká chyba ako
v ktoromkoľvek inom kole: **riešenie existovalo a hľadalo sa inde.** Popri tom zmizol
aj vlastný nepodarený closure s `use (&$catalogue)`, ktorý si zavádzal premennú, ktorá
v tom scope nikdy neexistovala.

A potom väzba okamžite chytila niečo skutočné: `Scratch`. Je v katalógu a nemá model —
a **nesmie ho mať**. Je to lifecycle fixture (`ModuleLifecycleTest` ho inštaluje a
odstraňuje, `bin/build/install-catalogue.sh` ho z rovnakého dôvodu preskakuje), takže
modelový slice by do modelu zapísal testovací artefakt ako pravdu. Riešenie je pomenovaná
výnimka `CATALOGUE_FIXTURES`, nie vzor — z rovnakého dôvodu, z akého je pomenované jadro.

Negatívna kontrola sa nepredpokladala, ale spravila: dočasný `lekalo/modules/_probe/`
zhodil test s vlastnou hláškou (`describes "Probe", which the catalogue does not know`),
a po `rmdir` je strom späť na 21 adresároch.

A čísla sedia na seba, čo je pri tomto druhu práce najlepší dôkaz, že sa nič neskrylo:

```
pred  väzbou:  371 testov / 1594 asercií / 1 skip
po   väzbe:  375 testov / 1635 asercií / 1 skip      (+4 testy, +41 asercií = presne ten súbor)
```

---

## Časť V > Kde to skončilo: `429` o 21:05:07

Toto je časť, ktorú by predošlý článok nazval záznamom, o ktorom vie len stroj, ktorý ho
napísal.

Session, ktorá celý deň merala, **neskončila rozhodnutím. Skončila limitu.** Posledná
otázka padla o 20:47:41, odpoveď na ňu bola zmeraná a napísaná o 20:48:50, voľba
postupu (`väzba + presun`) prišla o 21:04:34, test vznikol o 21:04:43, o 21:04:52 spadol
na konštruktor, o 21:05:01 zaznela veta *„`fromAppPaths()` existuje. Opravím aj vlastný
nepodarený closure"* — a o **21:05:07** prišlo:

```
provider.quota · Rate limit exceeded. Please try again later. · status 429
```

Proces zomrel vo vete, ktorá sľubovala opravu. Dôsledky, všetky overiteľné:

- **nič nebolo commitnuté.** `git status` bol prázdny ešte pred väzbou (merané
  o 20:48:25, hneď ako bol `Media` späť), a po nej ukazoval jediný súbor — test
  ako `untracked`. To je celý hmotný zvyšok kola;
- **žiadny boot record** pre toto kolo v `docs/kernel-status/` neexistuje;
- **žiadny článok** neexistoval. Tento sa píše o session neskôr, z jej odmeraných
  čísel — a presne to je tá chyba, ktorú predošlý článok pomenoval ako „rozhodnutie
  čitateľné len na stroji, ktorý ho napísal". Tentokrát to nebol jeden commit, ale celé
  kolo vrátane merania, ktoré ho ospravedlňovalo.

Druhá polovica zvoleného postupu — **presun modelu do `modules/<Name>/lekalo/` — sa
nezačala vôbec.** Model je stále tam, kde bol, a to je otvorená položka, nie hotová vec.

**Dodatok, ten istý deň večer.** Presun sa medzitým skúsil, zmeral a **vrátil späť** —
a to je presnejšie než „nezačal sa". Nástroj nemá šev, ktorý by model vlastnený modulom
dovolil:

```
presun slice mimo lekalo/   ticho vypadne z modelu: sources 272 -> 271,
                            definície modulu 0, load = valid, lock --check = exit 0
                            (ten istý digest e77647f7...)
symlink von z lekalo/       odmietnutý: structure.path-link / LEK-STR-013
                            (severity error, category security), status denied
imports: v module.yaml      nič: status valid, žiadna diagnostika
imports: v project.yaml     nič: nič zvonku do modelu nevstúpi
prázdny adresár modulu      chytené: structure.document-missing / LEK-STR-004, exit 1
```

Jediné, čo teda presun chytí, je `authorization.yaml`, ktorý sa na chýbajúce symboly
odkazuje (`LEK-AUTH-005`, exit 1) — takže celý strom spadne nahlas a osamotený modul
ako `media` potichu. Vetu „model má byť pri module" teda nejde splniť umiestnením
súborov: treba generátor s freshness bránou, alebo šev vo nástroji. Revert je
zmeraný — 61 presunutých súborov bolo zahašovaných **pred** zmazaním a zhodovalo sa
bajt na bajt s pôvodnými.

---

## Čo z toho plynie

Dilema „má mať každý modul svoje lekalo?" mala nesprávny predpoklad a meranie ho
vyvrátilo za 69 sekúnd: **nekontroluje sa nič, takže miesto súborov nie je problém.**
Model nie je neúplný — má presne tie moduly, ktoré má mať. Chýbala mu jediná vec a tá sa
nedá presunúť: **niekto, kto by ho porovnal s kódom.** Dnes je to test, ktorý sa zmestí
na obrazovku.

Čo väzba **nerobí**, aby to nebolo potichu: hovorí, že model a katalóg opisujú **tú istú
množinu** modulov. Nehovorí nič o tom, či niektorý opis sedí na kód. To je stále tá istá
otvorená vec ako v Boot 36 — kostra adaptéra je 20 súborov a 644 KB PHP, desať operácií
a štrnásť pomenovaných capabilities, a odporúčanie znelo **nestavať ho teraz**. Rovnako
otvorené zostáva ~20 opisov, ktoré `lekalo validate` nekontroluje, a presun do modulov.

Najlacnejšia vec, ktorú toto kolo kúpilo, nestála ani riadok produkčného kódu: **dilemu
nahradilo číslo.** 23 pravidiel, 0 chýb, exit 0 — a `adapters 0` v tom istom riadku.

A jedna vec navyše, ktorá je dnes jediný dôvod, prečo tento text existuje: **odpoveď
bola odmeraná v session, ktorú zabil limitu o 21:05.** Nezachránil ju článok, ktorý
nikto nenapísal — zachránili ju čísla, ktoré zostali v logu shellu. To je presne ten
rozdiel, pre ktorý má tento strom zvyk merať: **meranie prežije session. Zámer nie.**

---

*Článok bol napísaný v nasledujúcej session z meraní tej predošlej, pretože tá bola
prerušená. Čísla boli znovu overené pri písaní: 21 modelových adresárov s tými istými
veľkosťami, `23 rules, 0 errors, exit 0`, `adapters 0`, a suite 375/1635. Ak niektoré
z nich už neplatí, je to dôvod na ďalšiu opravu, nie na zmazanie článku.*
