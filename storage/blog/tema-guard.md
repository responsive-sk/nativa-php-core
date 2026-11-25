---
title: "Guard tém ... čo smie a čo nesmie"
date: "2026-10-04"
version: "0.1.0"
published: false
tags: ["témy", "guard", "css", "architektúra"]
excerpt: "Každá téma vykresľuje tie isté plochy, takže aj triedy musia byť tie isté. Guard to overuje na deviatich verejných plochách a privádza na to, že porovnávať treba markup, nie bajty a nie dokumentáciu."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
---

Máš štyri témy. Všetky štyri vykreslia článok, index, tagy, autora, komentáre, formulár. Vyzerajú inak, a majú vyzerať inak. Ale **triedy** pod nimi musia byť tie isté, iba s iným prefixom. Keď prestali byť, nikto to neodhalil.

Toto je zápis o guarde, ktorý to vyžaduje. Píšem ho pre seba, nie pre blog.

## Ako sa to stalo

Téma má prefix. `starter` používa `st-`, `erik` `er-`, `magazine` `mag-`, `moonshine` `ms-`. `CommentSection` v jadre stavia triedy z **prefixu aktívnej témy**, takže comment na magazínovej stránke je `mag-comments` a nikdy `st-comments`.

A stylesheet ... zdieľaný.

`comments.css` ležal v `src/base/`: adresári **byte-pinned** medzi balíčkami. A byte-pinned zdieľaný súbor môže niesť iba **jeden** prefix. Takže magazine aj moonshine ship-ujú 21 pravidiel `.st-comments`, kým vykreslia `mag-comments` a `ms-comments`. Ani jedno z nich sa nemohlo matchnúť.

Komentáre na magazíne boli holé. Erik ich mal vlastné, starter mal. A `FrontendPackageParityTest` bol **zelený**, pretože porovnával bajty, a bajty sa zhodovali.

| Čo zlyhalo | Prečo nešlo zachytiť |
|---|---|
| `base/comments.css` má jeden prefix pre štyri témy | guard kontroloval bajty, tie boli v poriadku |
| Pravidlo pod `.st-article` v magazine | selector zomrel, ale jeho bajty sa nemenili |
| `DesignBlockVocabularyTest` | číta dokumentáciu, nie vykreslený DOM |

Guard, ktorý porovnáva nesprávnu vec, je horší než žiadny, dáva pocit, že niečo drží.

## Čo guard vyžaduje

`tests/Architecture/ThemeDomParityTest.php` číta **vykreslený markup** každej témy na **deviatich plochách** a tvrdí tri veci.

| Invarianta | Prečo |
|---|---|
| Žiadny cudzí prefix | trieda pod cudzím prefixom nenačíta žiadny stylesheet danej témy |
| Žiadna BEM trieda bez prefixu | `block__element` bez prefixu nepatrí nijakej téme |
| Každá plocha sa vykreslí pod svojou témou | bez toho by 404 fallback porovnal chybnú stránku a prešiel |

Beží v `composer test`, **bez servera**: trasy dispatchuje in-process cez kernel, ako `ThemeModuleContractTest`.

Tretia kontrola je dôležitá. Bez nej by plocha, ktorá nič nenájde, vypluvala chybnú stránku, obe prvé invarianty by porovnali error shell navzájom a **prešli by z nesprávneho dôvodu**. Stalo sa mi to pri vlastnom probe a guard to teraz blokuje.

## Čo je povolené

Guard **nemení** dizajn. Témy sa majú líšiť. Preto má zoznam výnimiek, každá s dôvodom:

- `st-theme-toggle__icon`: magazine a moonshine si ho požičiavajú zámerne. `theme-toggle.js` sa viaže na presne túto triedu a oba Controls sa musia zhodovať, inak sa toggle otočí dvakrát. To bola reálna chyba.
- `st-article__tag`, `st-author*`: stavajú ich **`BlogArticleHelper` a `AuthorAvatar` v jadre**. Jadro nemá prefix témy na ponúknutie.
- `m-pubswitch__track`: erikov locale prepínač si požičiava **admin** idiom a jeho critical CSS je napísané práve na tento selektor.
- `navbar__actions`: bez prefixu v každej téme, štýlované Core kritickým CSS.

Guard, ktorý nevie pomenovať svoje výnimky, sa zmaže pri prvej nepríjemnosti. Tento zoznam je v kóde, nie v hlave.

## Čo sa zmenilo

Guard prišiel na dva defekty v kóde, ktorý už bol v repó.

**`src/Themes/magazine/Sections/Home.php`** vykresľoval `st-card__link` v bloku `mag-home-feature-card`. Preklep z kopírovania starterovho `Hero.php`. Magazine nemal na tú triedu **žiadne CSS**: odkaz „Čítať článok" na hero karte bol bez štýlu.

**`.mag-page`** sa nikdy nevykreslil. Koreň je `mag-article-page`. Osem pravidiel v `utilities.css` a päť v `critical/footer.css` bolo mŕtvych, takže zdieľané starter komponenty vpadli späť do starterového vzhľadu. Magazinové tagy mali `--st-tag-bg` namiesto `--mag-tag-bg`.

| | Pred | Po |
|---|---|---|
| tag chip pozadie | `#eff6ff` | `rgba(217, 119, 6, 0.12)` |
| orámovanie | žiadne | `1px rgba(245, 158, 11, 0.35)` |

## Čo viem a čo nie

**Nejde o tretiu os.** Dve invarianty porovnávajú, čo jedna téma **vykreslí**, s tým, čo vykreslí druhá. Ani jedna neporovnáva CSS **selector** s markupom. Pravidlo viazané na wrappa, ktorý sa nevykreslí, prejde obe: selector nie je trieda, ktorú ktokoľvek vykreslí, a žiadna téma nevykreslí triedu, ktorú by matchol. `.mag-page` prešiel presne takto.

Postavil som ju a zmeral. Zadržala, lebo hlási asi 60 selektorov, ktoré sú v poriadku:

- `tok-*` stavia v JavaScripte ako `'tok-' + t.type`, pričom `t.type` pochádza z tretej strany knižnice. Z repozitára je jediná z dvanástich tried literálom.
- `form__submit`, `comments__item` a podobné stavajú **`$prefix . '-…'` v jadre** a nikde sa neobjavia napísané.
- `m-btn` a `m-card` sú v **verejnom** balíku moonshine. Či je to únik admin tried, alebo zámerné zdieľanie, je otázka zámeru, na ktorú test neodpovie.

Kým sa na to nerozhodne, guard by kričal vyplave a prvý, kto na neho narazí, by ho vymazal. Radšej nech zostane zelený a chýba.

## Ako pokračovať v theme factory

Nová téma musí vyplniť guard alebo opraviť príčinu.

1. **Vykresli všetkých deväť plôch** pod novou témou a nechaj test zbehnúť. `composer test` → `ThemeDomParityTest`.
2. **Prefix z `ThemeModule::prefix()`**, nie z názvu témy. `erik` je `er`, `moonshine` je `ms`. Guard to overí, ale povie len `ms-` bolo očakávané.
3. **Blok, ktorý stavia jadro** (`CommentSection`, `FormBlockRenderer`, `BlogInlineEditor`), vykresľuje triedy pod **prefixom témy**. CSS musí byť per-téma, nie v `base/`. Na to je precedens: `bin/generate-form-css.mjs` a dnes aj `bin/generate-comments-css.mjs`.
4. **Blok, ktorý kreslíš ty** (tvoj `Sections/`), dostane `er-` triedy. Jedna cudzia trieda spôsobí červené.
5. **Selector, ktorý nič nevykresľuje, guard nechytí.** Ak premenúš wrappa, grepni staré meno.

Bod päť je jediná slabina. Kým tretia os nie je, je to na teba.

## Zdroje

- [`tests/Architecture/ThemeDomParityTest.php`](https://github.com/evan70/nativa-minimal/blob/master/tests/Architecture/ThemeDomParityTest.php): guard, tri invarianty, zoznam výnimiek
- [`bin/generate-comments-css.mjs`](https://github.com/evan70/nativa-minimal/blob/master/bin/generate-comments-css.mjs): precedens pre per-témové bloky
- [`docs/design-blocks.md`](/docs/design-blocks): matica blokov, ktorú guard dopĺňa
- [`docs/theme-layout.md`](/docs/theme-layout): kam nová téma priraďuje súbory
