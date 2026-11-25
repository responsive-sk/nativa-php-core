---
title: "Bonus pre členov ... formuláre: cudzí píšu, ty si to len čítaš"
date: "2026-10-03"
version: "1.0.0"
tags: ["forms", "csv", "security", "php", "architecture"]
excerpt: "Deviaty bonus len pre registrovaných. Formulár nie je komentár s inou nálepkou: schému píšeš ty, odpovede píšu cudzí a ty ich dostaneš do schránky a do CSV. Prečo je past na robota prázdna, prečo sa slug nikdy nerozšíri do cudzieho jazyka, prečo Excel z úvodzoviek nič nevyrobí — a čo sa stane, keď si trieda zostaví meno za behu. Na reálnom kóde z tohto repa."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Bonus"
members_only: true
translation: "bonus-forms"
published: true
---

> Toto je deviaty **bonus pre členov**. Predchádzajúce diely: [zámok
> members-only](/blog/bonus-members-only-pre-deti), [immutable audit
> log](/blog/bonus-audit-log-pre-deti), [fulltext search cez
> FTS5](/blog/bonus-fts-search-pre-deti), [komentáre s
> moderáciou](/blog/bonus-komentare-pre-deti), [média ako cudzie
> súbory](/blog/bonus-media-pre-deti), [debug ako
> retroskop](/blog/bonus-debug-pre-deti), [jazyky ako
> zmluva](/blog/bonus-jazyky-pre-deti) a [článok ako produkt s
> číslom](/blog/bonus-vydavanie-pre-deti). Dnes miesto, kam cudzí
> nepíše s jednou vetou ... kam **vyplní** celú schému, ktorú si ty
> zostavil.

Si vývojár. Tvoj článok číta cudzí a ty máš nad nim kontrolu, lebo si
ho napísal ty.

Komentár si už postavil ... a [pamätáš](/blog/bonus-komentare-pre-deti),
ako to dopadlo: jeden voľný text, jedna kolácia, jedna schránka na
prečítanie.

Teraz vložíš pod článok miesto, kde sa pýtaš na veci, ktoré vieš
vyjadriť presne. Meno, e-mail, predmet. Alebo otázka s viacerými
možnosťami. Alebo otázka, kde jedna z možností je správna.

Schému píšeš ty. Odpovede píše cudzí.

A rozdiel medzi týmto a komentárom je väčší, ako sa na prvý pohľad
zdá ... nie preto, že je to iný kód, ale preto, že **dodanie** je iné.

## Formulár nie je komentár s inou nálepkou

Komentár má jedno pole. Napíšeš vetu, ktorá sa uloží, a končíš.

Formulár má **schému**, ktorú ty editujes v `/mark/forms` a ktorej sa
nedeployuje nič. Pridáš pole, zmeníš typ, zrkadlíš to do druhého
jazyka ... a zmení sa to, čo sa kreslí na stránke aj to, čo sa ukladá
do databázy.

| Typ | Čo nakreslí | Čo uloží |
|---|---|---|
| `contact` | polia s typom (text, e-mail, textarea, select) | riadok na odpoveď poľa |
| `poll` | otázku na riadok, radio alebo checkbox | riadok na **každú zvolenú možnosť** |
| `quiz` | ako anketa, plus správnosť po otázke | odpovede plus `score` a `max_score` |

Ten prostredný riadok tabuľky je celý design anketi.

Jeden riadok na jednu zvolenú možnosť znamená, že výsledok nie je
reťazec, ktorý musíš rozložiť ... je to `GROUP BY` nad tabuľkou
odpovedí. Päť ľudí zvolilo „modrá“ a ty spítaš databázu, nie parser.

Anketa s checkboxmi má dve otázky a štyri možnosti a odpoveď môže mať
viac riadkov. To nie je chyba normalizácie, to je celý bod.

Kvíz navyše odpočítava. A per otázka, nie per odpoveď: odpoveď, ktorá
je pol správna, sa nepočíta. Všetko alebo nič.

## Schéma bez deployu

`/mark/forms` zobrazuje definície, filtrované podľa jazyka a typu.
Vytvorenie a úprava zdieľajú **jednu akciu**, pretože dve trasy sú
v podstate ten istý formulár s jedinou otázkou navyše:

```php
// src/Modules/Forms/Interfaces/HTTP/Actions/FormEditorAction.php
#[Route(path: '/mark/forms/create', method: 'GET|POST', name: 'forms.create')]
#[Route(path: '/mark/forms/{id}/edit', method: 'GET|POST', name: 'forms.edit')]
final class FormEditorAction
```

Keďže ide o jednu triedu, formulár nemožno vytvoriť s poľom, ktoré sa
potom nedá upraviť. Je to hlúpa chyba, ktorá sa v inom codebase zjaví
po roku a uvidí ju až ten, kto formulár prvý naplní.

Stav definície je tri hodnoty:

| Stav | Kreslí sa verejne? | Prijíma odpovede? |
|---|---|---|
| `draft` | nie | nie |
| `published` | áno | áno |
| `closed` | nie | nie |

`draft` a `closed` sú na oboch cestách **404**, nie tichý súhlas.

Toto je najdôležitejšia veta článku, takže ju zopakujem inak: návštevník
nesmie nikdy veriť, že správa odišla, keď neodišla. Tichý súhlas
odmietnutia je najkrajšia chyba, akú môžeš spraviť formulárom.

## Ako sa formulár dostane do článku

Dve cesty, jeden mechanismus. V tele textu:

```markdown
Napíšte nám, ak máte nápad.

{{form:kontakt}}
```

Alebo v front matter, celý formulár pod obsahom:

```yaml
---
title: "Anketa"
form: anketa-tema
published: true
---
```

Obe cesty sa stretávajú v jednom mechanizme — v registri blokov
markdownu v Core (`#[AsMarkdownBlock]`, `MarkdownBlockRegistry`).

A tu je dôvod, prečo je to v Core a nie vo forme: `PageViewAction` a
`BlogArticleAction` vedia iba **kľúč**. Nikdy neimportujú
`Modules\Forms`. Modul sa objaví v obsahu bez toho, aby Core, Pages
alebo Blog vedeli, že existuje.

Druhá polovica tej istej zmluvy: shortcode vo vnútri ohraničeného
kódu sa **nerozšíri**. Článok, ktorý dokumentuje syntax, musí stále
vidieť `{{form:...}}` doslovne.

```markdown
Toto je príklad, nie živý formulár:

{{form:kontakt}}
```

Blok prijíma **vykreslený HTML**, nie zdroják. Najprv si rozdelí
dokument na úseky a tie, ktoré sú vnútri `<pre>` alebo `<code>`,
skopíruje doslovne. Všetko ostatné expanduje.

Dvojica tagov sa tu neskočí po prvom `</pre>`: jediný `<pre>` môže
v sebe nosiť viacero ohraničených blokov. Kto by spároval tagy
len podľa poradia, vystrel by po druhom fence a odtiaľ by spustil
formulár vnútri príkladu.

A slug sa nehľadá ako reťazec v ľubovoľnej podobe — pred každým
hľadaním prejde vzorom `#(?:<p>)?\{\{form:([a-z0-9][a-z0-9_-]*)\}\}#`.
Zástupný znak sa do vzoru ani raz nedostane.

Cena za toto je jedna: shortcode, ktorý nič neriešky, zmizne potichu.
Preklep, koncept, draft. Návštevník neuvidí ani `{{…}}`, ani chybu.

## Jeden slug, jeden jazyk

Stĺpec `locale` robí slovenský a anglický formulár dvoma riadkami,
ktoré nezdielajú nič ... vlastnými slugmi `kontakt` a `contact`.

Voľný slug je voľný **v každom jazyku zvlášť**. Unikátnosť je dvojica
`(slug, locale)` ... to isté slovo môže v každom jazyku pomenúvať svoj
formulár.

A anglický request nikdy nerozšíri slovenskú definíciu. Ani keby mal
rovnaký slug. Kto sa na to spýta, dostane 404, nie „tichú“ odpoveď
v cudzom jazyku.

Je to iné, než keď máš jeden obsah a dve jeho podoby. Formulár nie je
preklad — je to **samostatná vec v každom jazyku**, ktorá má vlastný
text poľa, vlastné možnosti a vlastné odpovede. V prekladoch sa to
vybavuje prekladovým front matter. Tu to vybavuje stĺpec.

## Spam: tri vrstvy, najlacnejšia prvá

Ani jedna z nich nie je CAPTCHA a žiadna neprislúba, že zastaví
odhodlaného útočníka.

**Honeypot** je pole, ktoré človek neuvidí. Nielen `display: none`,
ale natívne `hidden`, takže sa ho nedostane ani čítačka obrazovky, ani
klávesnica. Vyplnené znamená, že odpoveď dostane **rovnaké tiché
úspechy** ako skutočná.

Robot sa dozvie presne to, čo chceš: nič.

A nič sa ani neuloží. Toto nie je veta do dokumentácie, je to tvrdenie
jedného unit testu:

```php
// tests/Unit/FormsSubmissionServiceTest.php
testAFilledHoneypotGetsASilentSuccessAndStoresNothing
```

**Limit na IP** je ten istý `RateLimiter`, ktorý drží komentáre.
Sekundú obrana objemu.

**CSRF** vynucuje globálne middleware; formulár len nosí pole.

Každý zápis do administrácie sa audituje a pri každom odoslaní sa
vyprázdni cache stránky, aby sa z neskôr nevrátila stránka s
výsledkami pred tvojím hlasom.

## Doručenie: schránka a CSV

**Schránka a CSV sú jediné kanály. Nie je e-mail, nie je webhook.**

Je to rozhodnutie o rozsahu a preto nemôže byť export stratový.
`/mark/forms/{id}/submissions` je čítacia plocha: počty nových
a prečítaných, stĺpce podľa definície, stránkovanie.

Export je obyčajný GET, lebo tabuľka chce „stiahnuť“ a `curl` tiež:

```bash
curl -OJ http://127.0.0.1:8000/mark/forms/1/submissions.csv
```

Tri veci, ktoré sa musia znamenuť:

**UTF-8 BOM.** Excel vo Windows číta holý UTF-8 CSV ako latin-1 a
zmení každé slovenské á na mojibake. BOM je tri bajty na začiatku
súboru a odpovedzou je čitateľný text.

**Riadok na odpoveď, stĺpec na otázku.** Id ukladania sa v exporte
spája späť s menovkou, ktorú si zadal v editore, a otázka s viacerými
odpoveďami sa v jednej bunke spája cez `; `.

**Neutralizácia vzorca.** Každá hodnota pochádza od návštevníka.
Hodnota, ktorá začína na `=`, `+`, `-` alebo `@`, je pre Excel vzorec.

A tu je chyták, na ktorý narazil unit test, nie rozum:

```php
// src/Modules/Forms/Interfaces/HTTP/FormSubmissionCsv.php
public function cell(string $value): string
{
    if (1 === preg_match('/^[=+\-@\t\r]/', $value)) {
        $value = "'" . $value;
    }
```

Uvodzovky nestačia. Excel ich pri importe odhadzuje a vzorec
vyhodnotí aj tak. Apostrof ostane v bunke ako text — to je jediná
forma, ktorú tabuľka rešpektuje.

Prvá verzia tejto metódy bola napísaná správne podľa štandardu CSV
a bola nesprávna pre Excel. Oba systémy sú štandard. Problém bol medzi
nimi.

## Štyri témy, jeden blok

Blok sa kreslí pod **prefixom aktívnej témy** — `st-form`, `er-form`,
`ms-form`, `mag-form`. Je to tá istá zmluva, akú má
[`CommentSection`](/blog/bonus-komentare-pre-deti) v erikovi.

A tu nie je nový surface v téme. `ThemeModule` je nedotknutý,
`FormBlockRenderer` si prefix dostáva ako argument a modul nikdy
neimportuje `Themes\`.

Prefix sa skladá **za behu** z `ThemeModule::prefix()`, takže žiadna
statická kontrola ho neuvidí. Zdroj súborov je jeden:

```
Frontend/packages/starter/src/form.css   # upravuješ iba tento
        │  node bin/generate-form-css.mjs
        ├── Frontend/packages/erik/src/erik/form.css
        ├── Frontend/packages/moonshine/src/moonshine/form.css
        └── Frontend/packages/magazine/src/magazine/form.css
```

`FrontendFormParityTest` hláda tri veci: že každá kópia nesie vlastný
prefix, že zhodné sú aj selektory, a že ten prefix je stále ten istý,
aký vracia `ThemeModule::prefix()`.

Toto nie je teória. Kontrola `bin/forms-check.mjs` si raz zmrazila
prefix `st-` priamo v očakávaných reťazcoch a na eriku, moonshine aj
magazine hlásila desať chýb ... pričom **každá stránka bola správna**.
Preklep si stál za tridsať sekúnd čítania a jedným prepisom sa zmenil
na to, že si prefix číta z vykresleného bloku a kríži ho s CSS, ktoré
téma naozaj dodáva.

Dve témy, dva zdroje, jedna zhoda alebo chyba.

## Čo formulár nie je

Úprimná sekcia, pretože bez nej sa článok číta ako reklama.

**Nie je doručovacia služba.** Žiadny e-mail, žiadny webhook. Keď sa
export stratí, stratil sa export.

**Nie je moderovacia fronta.** Odpovede sa doručujú, nekúria. Životný
cyklus je `new → read → archived`, pretože schránka na čítanie má iný
zmysel než schránka na schvaľovanie. Ak potrebuješ schvaľovanie,
postav iný cyklus ... tento by ti klamal.

**Nie je preklad.** Jeden formulár, jeden jazyk, vlastná schéma.

**Nie je nahrávanie súborov.** Žiadny upload, žiadny antivírus, žiadny
sandbox. Len polia.

**Nie je náhrada za stránku.** Formulár má vlastné polia, vlastné
štítky a vlastné stavovanie. Ak chceš na stránke dva štítky a jedno
pole, stránka je kratší a edituje sa bez schémy.

## Prakticky

1. `php bin/cms forms:seed` — štyri ukážkové formuláre a stránky
   `/anketa` a `/test`, v ktorých uvidíš anketu aj kvíz vyzrkadľované
   z markdownu.
2. Vlož `{{form:kontakt}}` do stránky alebo `form: kontakt` do front
   matteru článku a otvor ju v inej téme. Ak to funguje iba v
   starteri, hľadaj prefix.
3. `node bin/forms-check.mjs http://127.0.0.1:8000` — a čítaj výstup
   ako výpoveď. Toto je jediný test, ktorý vidí celý reťaz
   naraz: middleware → akcia → session → markdown blok pri
   vykresľovaní nasledujúcej stránky.

Bod, ktorý by som si zapísal: **forma sa neskladá z polí, ktoré
vykreslíš, ale z polí, ktoré vieš prečítať.** Ten rozdiel sa
neodhalí pri písaní šablóny, ale pri exporte do tabuľky, kde zrazu
zjistíš, že si zabudol na stĺpec.

## Zdroje

- [OWASP — CSV Injection](https://owasp.org/www-community/attacks/CSV_Injection)
  — prečo úvodzovky nestačia a čo s tým robi tabuľka
- [SQLite — SELECT](https://www.sqlite.org/lang_select.html) — `GROUP BY`
  ako dôvod, prečo anketu neukladáme do jedného stĺpca
- [docs/forms.md](https://github.com/evan70/nativa-minimal/blob/master/docs/forms.md)
  — celý modul na jednej strane
- [Štvrtý bonus — komentáre](/blog/bonus-komentare-pre-deti) — odkiaľ
  tento cyklus vyrástal