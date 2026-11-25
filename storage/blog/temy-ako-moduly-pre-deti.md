---
title: "Témy pre deti ... diel 1: téma ako modul"
series: "Témy pre deti"
series-part: "1"
date: "2026-10-02"
version: "1.0.0"
published: true
tags: ["php", "architecture", "themes", "moduly"]
excerpt: "Téma nebola vec, bola rozliata zmes ... dnes je modul s kontraktom, konektorom a testom, ktorý hranicu stráži. Prvý diel série o témach: čo sa zmenilo a kam to smeruje."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "themes-as-modules"
---

> [Auto-discovery a registry](/blog/theme-architecture) bolo jedno riešenie,
> [porovnanie tém](/blog/theme-comparison) iné hľadisko. Táto séria začína
> tam, kde obe skončili ... téma ako modul. Diel 1: kontrakt, konektor
> a hranica, ktorú stráži test.

Si PHP vývojár. Pridáš do blogu novú plochu — povedzme stránku s archívom
mesiacov. Otvoríš akciu, dopíšeš renderovanie, siahneš po chrome v layoute...
a o chvíľu meníš desiaty súbor.

To nie je zlyhanie disciplíny. Je to zlyhanie hranice.

Téma nebola vec. Bola rozliata zmes.

## Téma ako rozsypaný diff

Koľko miest musel pochopiť človek, ktorý chcel upraviť jednu tému? Tri:

1. `FrontendLayout` vetvil na mená tém — päťkrát `'erik' === $bundle` vyberalo
   chrome, BEM prefixy a critical CSS fallback priamo v jadre.
2. Akcie skladali stránky tém samy — `erikIndexPage()`, `erikHome()` a ich
   sesterské pomocníky importovali `Themes\*` sekcie a lepili hero + sekcie
   + widgety v tele akcie.
3. `Themes\ThemeRenderer` bol dedičný interface — polia namiesto kontextov,
   `callable $t` namiesto prekladov, žiadne vlastníctvo shellu.

Modul je jednotka, ktorú skopíruješ, otestuješ a vymažeš. Rozliatá zmes
nie je jednotka — je to pátranie.

## Kontrakt ... 66 riadkov

Refaktor `0907f3a` postavil opačný smer. Každá zapojená téma implementuje
jeden kontrakt:

```php
// src/Themes/ThemeModule.php — kontrakt (výňatok)
interface ThemeModule
{
    public function name(): string;
    public function bundle(): string;
    public function prefix(): string;

    public function header(ShellContext $context): string;
    public function footer(ShellContext $context): string;
    public function pageWrap(string $content): string;

    public function homepage(PageContext $context): string;
    public function blogIndex(PageContext $context): string;
    // ... blogSearch, blogArticle, blogTags, blogCategories, authorProfile
}
```

Explicitné metódy, žiadne `__call`. Téma vypĺňa výmenu: meno, asset bundle,
BEM prefix, chrome a jednu metódu na každú verejnú plochu.

Výmena je celá. Nič v kontrakte nehovorí „ako vyzeraj" — kontrakt hovorí
„čo musíš dať".

## Konektor ... 36 riadkov

Kto mapuje meno témy na modul? Jedno miesto:

```php
// src/Modules/Core/Interfaces/HTTP/ThemeKernel.php — konektor
final class ThemeKernel
{
    /** @return array<string, class-string<ThemeModule>> */
    private const array MODULES = [
        ThemeResolver::PUBLIC_THEME => StarterTheme::class,
        ThemeResolver::ADMIN_THEME => MoonshineTheme::class,
        ThemeResolver::ERIK_THEME => ErikTheme::class,
    ];

    public static function for(string $theme): ThemeModule
    {
        $class = self::MODULES[$theme] ?? self::MODULES[ThemeResolver::PUBLIC_THEME];

        return new $class();
    }
}
```

`ThemeKernel` je jediný súbor pod `src/Modules`, ktorý smie importovať
`Themes\`. Nie dohoda — test.

`ThemeOwnershipTest` prejde celý `src/Modules` a pri každom inom `use Themes\`
importe zlyhá. Hranica, ktorú stráži stroj, je hranica.

## Kernel má dáta, téma má HTML

Druhá polovica výmeny: kontexty. Akcia počíta dáta, téma skladá značky.

```php
// src/Modules/Blog/Interfaces/HTTP/Actions/BlogIndexAction.php — akcia dnes
$module = ThemeKernel::for($theme);

if ('' !== $query) {
    $content = $module->blogSearch(
        PageContext::forBlogSearch($request, $theme, $locale, $query, $result, $results),
    );
} else {
    $articles = $service->all($locale);
    $content = $module->blogIndex(
        PageContext::forBlogIndex($request, $theme, $locale, $articles, page: $page, perPage: self::PER_PAGE),
    );
}
```

Každá plocha je jedno volanie. `PageContext` nesie články, počty zobrazení,
stránkovanie — to, čo akcia vypočítala. `ShellContext` nesie menu, locale dáta
a prihlásenie — to, čo chrome potrebuje.

| | Vlastní kernel | Vlastní téma |
|---|---|---|
| Dáta | články, menu DTO, preklady, auth stav | — |
| HTML | — | chrome, locale switcher, user link, telá stránok |
| SEO head | canonical, hreflang, OG, JSON-LD | — |
| CSS/JS wiring | nonce placeholder, `<body class>` z mena modulu | critical CSS zoznam, theme-init skript |

`FrontendLayout` dnes nemá ani jednu vetvu na meno témy. Je to hlavičkový
konektor — a to je celá jeho práca.

## Pred a po

| | Pred | Po |
|---|---|---|
| Pridať verejnú plochu do tém | akcia + layout + téma — až ~10 súborov | kontrakt + 3 moduly, každý jednou metódou |
| Pridať celú tému | priečinok + whitelist + select + 6 akcií | priečinok + 1 riadok v `ThemeKernel::MODULES` |
| `use Themes\` pod `src/Modules` | šesť akcií + layout | **1** — `ThemeKernel` |
| Vetvy na meno témy vo `FrontendLayout` | 5 | **0** |
| Ako zistíš porušenie hranice | čítanie | zlyhanie `ThemeOwnershipTest` |

Čísla z repa: kontrakt má 66 riadkov, konektor 36, `src/Themes/` nesie
57 PHP súborov a celý súbor `FrontendLayout` má 461 riadkov — z toho nula
porovnaní mien tém.

## Kedy to neplatí

Buďme úprimní — tri veci tento model nerieši.

Téma **nie je modul v zmysle DDD**. Nemá routes, migrácie ani databázu;
prezentácia nedostáva `ModuleInterface::register()`. Priečinok pod
`src/Themes/` nie je vertical slice — je to prezenter. Kto tu čakal DDD
slices, dostal zámerné „nie".

Kontrakt **má cenu**. Nová verejná plocha znamená metódu vo všetkých troch
moduloch. To nie je omyl — ak si nedovíš zapísať plochu trikrát, možno
nepatrí do spoločného kontraktu, ale do jednej témy.

A dedičný `Themes\ThemeRenderer` **ešte žije**. Renderer facády ponechávajú
jeho šesť metód ako jednoriadkové delegáty. Zjednotenie s `ThemeModule`
je naplánované neskôr — teraz by to bola dvojitá zmena v jednom commite.

## Vízia ... priečinok, ktorý môžeš vymazať

Čo z toho rastie? Tri smery — všetky grounded v tom, čo už v repu stojí.

Téma je **priečinok**. `starter`, `erik`, `moonshine` — každý nesie svoje
`Chrome/`, `Sections/`, `assets/critical/` a žiadnu stopu v jadre. Pridať
štvrtú tému znamená napísať priečinok a pridať riadok. Ubrať tému znamená
`rm -r` a riadok. Vôbec nepotrebujeme desať tém — potrebujeme témy,
ktoré sa dajú niesť.

Slovná zásobu blokov **drží matica**. Deväť štrukturálnych druhov — nav,
footer, grid, section, form, widget, hero, card, toc — je zapísaných
v `docs/design-blocks.md` a `DesignBlockVocabularyTest` kontroluje, že
varianty z matice naozaj existujú v kóde. Dokument a runtime sa nemôžu
rozísť.

A model **rozpráva to isté ako kód**. Lekalo modeluje témové moduly
a dizajnové tokeny; `ThemesModelParityTest` kontroluje, že varianty v modeli
sú tie isté ako varianty v resolveri. Dizajn tu nie je len farba — je to
zmluva s WCAG kontrastami, ktorú stráži stroj.

To je vízia: téma ako produkt, ktorý má kontrakt, testy a model. Nie téma
ako kúzlo vo verzovacom riadku.

## Čo bude ďalej

V druhom diele pridáme štvrtú tému krok za krokom — priečinok, kontrakt,
jeden riadok v konektore a vlastný Vite entry. Uvidíme, čo kontrakt chce
a čo nie ... a kde sa zastavíme skôr, než vznikne špekulatívna abstrakcia.

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [Vite](https://vite.dev/) — oficiálna dokumentácia build nástroja
- [PHPUnit](https://phpunit.de/) — dokumentácia testovacieho frameworku
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod kitu
