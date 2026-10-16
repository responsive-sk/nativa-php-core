# Článok: prečo je `evolutions/` prázdny a čo sa dnes prekročilo

**16. októbra 2026**

---

## Časť I — `evolutions/` je prázdny. Niekto to spozoruje, nikto to nespraví

V tomto repozitári sú štyri priečinky pod `.ai-factory/`:

```
plans/          7 súborov
patches/        4 súbory
evolutions/     0 súborov     ← prázdne
skill-context/  0 súborov     ← prázdne
```

Päťdesiatšesťdesiat riadkov, štyri plány, štyri patche — a **dve priečinky sú úplne prázdne**.

*(Tento blok je stav v okamihu písania. Neskôr, keď sa to rozhodnutie zavrelo, je
`patches/` 5 a `evolutions/` 1 — beh `$aif-evolve` sa uskutočnil. `skill-context/` je
**stále 0**, a to je tá polovica, na ktorej stojí celá point.)*

Toto nie je zanedbanie náhody. `$aif-evolve` je v tomto projekte **nainštalovaný** — je
v `.agents/skills/aif-evolve/` a má vlastný SKILL.md. A tento SKILL.md hovorí presne to,
čo tie dve priečinky majú byť:

> Raw patches (`.ai-factory/patches/*.md`) sú zdrojový materiál.
> Skill-context rules (`.ai-factory/skill-context/*`) sú kompaktný, znovupoužiteľný výstup.
> `evolutions/` je evolučný denník.

Teda: **nástroj existuje, bol nainštalovaný, a nikdy nebol spustený.**

Je to dôležité preto, lebo to nie je kozmetická chyba. Skill-context nie je dokumentácia —
je to **vstup, ktorý `$aif-implement`, `$aif-fix` a `$aif-improve` čítajú predtým, než si
prečítajú patche**. Prázdny skill-context znamená, že každé budúce kolo agent znova objavuje
to isté. Dnešné kolo stálo dosť času (pozri Časť II), a každá z tých chýb je presne vec,
ktorú mal jeden `SKILL.md` zachytiť navždy a nikto to nenapísal.

Najbližším skutočným artefaktom je preto nie evolúcia, ale **posledný patch** —
`.ai-factory/patches/2026-10-16-theme-branch-reconciliation.md`, 01:22 dnes.

---

## Časť II — čo ten posledný patch nechal otvorené

Patch je písaný vzorne. Namiesto „zrevidujme ten diff" robí to, čo má:

- `git merge-tree --write-tree master feature/erik-minimal-home` → **exit 0**, žiadny konflikt;
- skutočný merge v worktree, automatický, bez zásahov;
- `composer test --filter Erik` → **11 testov / 91 asercií**, celá suite **291 / 1248**, exit 0;
- pre griffe jeden konflikt v `heroSlide()`, vyriešený a overený;
- na koniec **Reproduce** blok, aby sa to dalo zopakovať.

A potom jedna veta, ktorá je celý point patchu:

> Verdicts are the user's; this records what the attempt cost and proved.

**Rozhodnutie sa nikdy neuzavrelo.**

A to má dnes merateľný dôsledok:

```
feature/erik-minimal-home      1 nemergnutý commit, +802 riadkov, 58 commitov pozadu
feature/griffe-hero-slide      1 nemergnutý commit, +102 riadkov, 58 commitov pozadu
```

Pokus bol spravený naozaj, výsledok bol merateľný, a napriek tomu stojí. Deväťsto riadkov
práce, z ktorých nič nebolo v `masteru`. Nie preto, že by boli zlé — preto, že sa nikto
neopýtal „tak čo, merge?" a nikto neodpovedal.

Nie je to chyba patchu. Je to vlastnosť zápisu, ktorý nechá rozhodnutie otvorené **potichu**.
Otvorená položka v `docs/kernel-status/` má hlas — niekto ju prečíta. Otvorená položka
v súbore s príponou `.md`, o ktorom nikto nevie, nemá.

### Čo z toho plynie pre dnešný úklid

Keď som dnes upravoval vetvy, `feature/erik-minimal-home` a `feature/griffe-hero-slide`
som **nesmazal** — hoci „uprac" znelo jednoznačne. Overil som ich stromy a zistil, že majú
po jednom nemergnutom commitu a 58 commitov za sebou. Zmazanie by stálo 904 riadkov práce,
na ktoré sa nikto nepozrel. Vetvy, ktoré **pravidelne mažem**, mali stromy identické
s masterom (overené porovnaním tree SHA, nie odhadom) — to boli bezpečné. Tieto dve nie.

### Ako sa to zavrelo

Vetvy som **znova zmeral**, najprv. Vrcholy vetiev sú z 12. októbra a samotný patch je
z dnešnej 01:22 — meral proti `masteru`, ktorý je dnes **o 13 commitov starý**. Staré
meranie je hádanka oblečená do citácie.

| | erik (#8) | griffe (#9) |
|---|---|---|
| `merge-tree` | **exit 0**, 0 konfliktov | exit 1, **1** konflikt |
| merge v worktree | automatický, bez zásahov | vyriešený, iný konflikt žiadny |
| testy modulu | **11 / 91 asercí** | **30 / 126 asercí** |
| diff do mastera | **+802** | **+108** |

**Obidve čísla asercí sedia na patchi presne** — 91 a 126. To je tá kontrola, ktorá
hovorí, že ranné meranie proti `masteru` o 13 commitov staršiemu ešte platí na dnešnom.

Rezolúcia konfliktu nebola mince: **větev skladá scénu, master argument.**
Větev pridáva `heroVisual()` a eyebrow kicker; master zmenil `revealLines()` tak, že
si berie druhý argument a hero sa dopredu odhalí. Vzát větvovou jednoargumentovú
volbu by sa **čistě zmergovalo a potichu vrátilo masterovu zmenu animácie** — a právě
to ten konflikt spôsobuje. Rozdiel je teraz komentár v kóde, nie tvrdenie v patchi,
ktorý nikto nečíta.

Riziká boli rôzne a boli zmerané:

| | erik | griffe |
|---|---|---|
| `*Theme.php` na masteri | **0 commitov** | **+68 / −44** |
| `*ThemeTest.php` na masteri | 1 commit | **7 commitov** |

Erik je čisté přičtenie do souboru, který nikdo nesahal. Griffe přistupuje k hero,
které si master mezitím přepsal. Obě suite jsou zelené — a **„suite prochází" je tvrzení
o správnosti, ne o designu.** Proto to má aj tělo PR #9 napsané, a ne až ako překvapení
po mergi.

### A past, kterou jsem si přiváděl tentýž den

První přeměření vyrobilo **zelenou suite z rozbitého prostředí**. Worktree jsem osadil
`cp -a "$MAIN/src/Modules" .` — a čerstvý `git worktree add` repozitáře, jehož modulové
řezy jsou v `.gitignore`, už `src/Modules` **má**. Takže `cp -a` adresář zkopíroval do
sebe ako `src/Modules/Modules`. Pět instalovaných modulů místo 22, **267 testů
přeskočilo**, a každý modulový test hlásil OK, protože většina jich vůbec nespustila.

Vypovídal o tom `git status` s necommitovaným `Modules/`, ne výstup suite. Fix je
koncová `/.`, která kopíruje obsah.

To je **tretia** past v téže rodině jako ty dvě, co patch zaplatil — symlink na
`vendor/` láme tree hash v `ModuleLifecycleTest`, a reinstalace všech modulů v pořadí
`ls` zničí instalovanou sadu, protože Cardboard ↔ Outbox sú kruhové. Ale tahle je
horší než obě předchozí, lebo **právě ju patch doporučoval**: jeho blok `Reproduce`
predpisoval `cp -a <main>/src/Modules .` doslova. Nasledovať patch presne je ten
jediný krok, ktorý ťa nenechá chytiť, že sa mýliš. Patch teraz nesie všetky tri,
aj kontrolu `ls src/Modules | wc -l`, ktorú reproducer nemal. Zelená suite bola po
celý čas najmenej informatívny signál, aký bol po ruke.

A štvrtá, menšia: na masteri ležal commit s týmto článkom, napísaný o 19:21 a stále
lokálny, zatiaľ čo sa toto rozhodnutie uzatváralo. Záznam o tom, že sa uzavrelo
posledné nezavreté rozhodnutie, bol čitateľný len na stroji, ktorý ho napísal. Rovnaká
chyba ako prázdna `evolutions/` a gitignorovaná `patches/` — len v mierke jedného
commitu.

Výsledok: **360 testů / 1557 asercí**, admin-smoke **85 kontrol** (témy nepridávajú
adminovú plochu), lint, PHPStan L9 aj Lekalo exit 0. Oba PR zelené 8/8.

---

## Časť III — čo sa dnes prekročilo (Boot 32)

**15 commitov** do `mastera`, dva mergnuté PR-y. Päť vecí, každá s meraním.

### 1. Modulové príkazy, ktoré sa nedali načítať

`php bin/cms outbox:process` vypísal usage a skončil exit 1. Problém nebol, že chýbali —
**nedali sa načítať vôbec**. Oba dedili `Symfony\...\Command` a `symfony/console` je
`require-dev`. Joby `admin-smoke` a `boot` zámorne bežia **bez `vendor/`**. Teraz oba
plain-PHP: `$argv` dovnútra, exit kód von, `CliOutput` — šev, ktorý už používa
`DatabaseMigrator`.

Zmerané v strome bez `vendor/`: oba sa načítajú a bežia.

### 2. Jeden kernelový kontrakt menoval dev balíček

`MigratorInterface::run()` bolo `CliOutput | OutputInterface` — a fungovalo. Lebo PHP pri
matchovaní **union typu** ticho preskočí triedu, ktorú nedokáže resolvnuť. Dev rameno nikdy
nebolo kandidátom.

Teda: **hlavné tvrdenie tohto repozitória** — že runtime nemá žiadne Composer závislosti —
stálo na jazykovom správaní, ktoré nikto nezapísal, v jedinom repozitári, ktorého celý
zmysel je nemajú. Teraz `run()` berie `CliOutput` a nič iné, v jedenástich súboroch, a
`KernelContractTest` bráni, aby akýkoľvek publikovaný kernelový kontrakt pomenoval niečo
mimo `Modules\Core`.

### 3. Worker, ktorý nemal čo doručiť

`queue:work` existoval jedno kolo. Teraz má prvého producenta: **zápis článku zařadí jeho
obálku**. Spúšťač sa **nezamýšľal, ale zmeral** — outbox publish event je nesprávny dvakrát
(funguje na zrušenie publikovania, prehĺdne editáciu publikovaného článku), takže producent
sídli tam, kde sa už čistila cache.

Payload sú **dva skaláre**, `{slug, locale}`. Nie náhodou: `jobs.payload` žije v `queue.db`
a vykresľuje sa na `/mark/queue/{id}`. Credential v payloade je credential v druhej databáze
a na obrazovke — čo presne audit našiel, keď sa reset token objavil v logu.

### 4. Retry sa nerozložil v čase

`work()` pouštal zlyhávajúcu úlohu bez odkladu, takže jeden hovor spálil všetky pokusy —
`max_attempts` obmedzoval *pokusy*, nie *minúty*. Teraz exponenciálny backoff so stropom,
pripnutý na injektované hodinky (`DatabaseQueue` prijímal `?callable $now` od prvého
commitu a **žiadny test mu ho nikdy neposkytol**).

Viditeľný dôsledok: worker, ktorý **bude opakovať, vrací 0**; worker, ktorý niečo
**zaparkoval, vrací 1**.

### 5. Model, který nikto nespuštal

`lekalo/` bola priečinka, ktorú nič nespúšťalo — `grep -c lekalo .github/workflows/ci.yml`
bolo **0**. Dnes je brána, s pinnutým checksumom zdieľaným s `nativa-minimal`, a modul
`queue` je zmodelovaný (bol 22bajtovým stubom).

---

## Časť IV — päť chýb, ktoré našlo behanie a nie čítanie

Toto je najcennejšia časť dnešného dňa, pretože každá z nich prešla lintom, PHPStanom
aj recenziou očí.

| | |
|---|---|
| `--no-prune` **prunoval** | príznak „neprunuj" zapínal prune |
| úspešné vykreslenie exit 1 | obrátená logika v prvom návrhu |
| preklep v slugu exit 0 | vykreslil nič a ohlásil úspech |
| queue retry zčervenal beh | jeden retry mohol spraviť `queue:work` exit 1 |
| brána tvrdila 79 kontrol | je **78** |

Posledné dve sú dôležité:

**Štvrtá** znamená, že číslo „1" prestalo niečo znamenať. A človek, ktorý si vypestoval
zvyk ignorovať červenú, si ju zanesie aj na tie, na ktorých záleží.

**Piata** bola chyba mne samému. Boot 31 zapísal 79 kontrol a toto číslo je bolo už
mergnuté. Zmeral som to prehratím z worktree, ktorého `admin-smoke.sh` je byte-zo `masteru`
— takže 78 je definitívne master, nie artefakt worktree. Pravdepodobná príčina: **číslo
bolo brané z filtrovaného behu namiesto počítania toho, čo skript vypíše** — presne tá
chyba, ktorú tento repozitár už raz spravil s `make lint` (`|| true`) a potom s `$?`
za pipelinou.

Log má teraz na mieste vetu, prečo sa to stalo. Oprava čísla bez dôvodu by bola polovicu práce.

---

## Časť V — tri veci, z ktorých sa jedna v priebehu dňa zavrela

Časť II hovorila o vetvách, ktoré nikto neuzavrel; odvtedy sú zavreté. Nech je ale
súladné, aby aj toto bolo napísané:

- **Griffe hero je správny a zelený, nie zvolený.** Žiadny test nerozhodne, či
  dekoratívna scéna patrí vedľa animácie, ktorú si `master` prepísal. Ten hlas
  zostáva otvorený a je hlasom reviewera, nie suite.

- **Mazanie článku obálku nezachováva — je to opravené (Boot 34).** Pôvodne
  `BlogCrudService::delete()` bol jeden `unlink()` markdownu a obálka zostávala na
  disku, stále servírovaná pod `max-age=31536000` na URL, na ktoré už nič neodkazuje.
  Brána to uklízila v skripte, produkt nie — a Lekalo model to popisovalo **opačne**,
  čo bol druhý merateľný dôkaz, že model bez adaptéra nemôže byť skontrolovaný.
  Teraz to robí produkt, brána tvrdí a model hovorí pravdu.

  Ale oprava, ktorá by len viac `unlink`ovala, by rozbila dve veci: plochý
  `blog-images/{slug}.svg` nenesie lokále, takže patrí aj druhej, a `resolveCover()`
  dáva explicitnému `image:` z front matter prednosť pred generovaným súborom.
  A **negatívna kontrola to chytila, nie testy**: testy posielali `sk`, admin posiela
  `sk_SK`, a `collides()` porovnáva jazykový kód — článok sa pri `sk_SK` zhodol **sám so
  sebou** a vlastnú obálku preskočil. Celá modulová suite bola zelená a produkt
  stále uklížal.
- **Queued reset link** je bezpečnostné rozhodnutie pre operátora — ale odmerané
  (Boot 35), nie odložené. Front by daroval záruku opakovania pri dočasnom výpadku
  mailera (dnes `HttpPasswordResetMailer` výnimku chytí, zapíše a vráti), a kúpil by
  ju proti invariantu, ktorý audit už zavedol: **plaintext token existuje len na ceste
  k príjemcovi.** `password_reset_tokens` drží `sha256`, `app.log` token nikdy, a
  `failed_jobs.payload` by držal plaintext **bez obmedzenia** — maže ho len kliknutie
  admina „Drop this job", žiadny reaper ani retencia. Handler si token nedokáže
  načítať, lebo z hashu sa nedá. Osloviť ten invariant alebo nie je volanie operátora,
  ktoré má vidieť cenu.

---

## Čo z toho plynie

Dnes sa urobilo **merateľné** veľa a zapísalo sa to do `docs/kernel-status/` ako Boot 32.
Zapísalo sa to ako záznam — čo bolo zmerané, čo sa zmenilo, a čo sa **nezavrelo**.

A práve tu je rozdiel medzi tým, čo `.ai-factory/patches/` robí, a tým, čo by
`$aif-evolve` robil. Patch zaznamenáva, čo sa pokúsilo a čo to stálo. Log zaznamenáva, čo
sa zmenilo. **Evolúcia zaznamenáva, čo sa z toho má zostať nastálo.**

Päť chýb dnes, päť vetiev `SKILL.md`, ktoré by ich zachytili bez následkov. Jedno
spustenie `$aif-evolve` by ich možno zapísalo naraz — a zajtrajší `$aif-implement` by
vedel, že `--no-prune` je prepínač, ktorý niečo vypína.

Prázdna priečinka nevadí. Nevadí dovtedy, kým si na ňu nespoľahne niekto, kto verí, že
niečo obsahuje.

---

*Článok je retrospektíva. Čísla v ňom sú merané; ak niektoré z nich už neplatí, je to dôvod
pre ďalšiu opravu, nie dôvod na to ho zmazať.*