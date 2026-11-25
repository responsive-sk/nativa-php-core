---
title: "Bonus pre členov ... jazyky: návštevník volí, prehliadač nerozhoduje"
date: "2026-09-17"
version: "1.0.2"
tags: ["locales", "i18n", "php", "architecture", "bilingual"]
excerpt: "Siedmy bonus len pre registrovaných. Dvojjazyčná appka je zmluva o poradi: kto rozhoduje o jazyku — URL, hlavička, cookie, session alebo nastavenie? A prečo tento kit zámerne ignoruje Accept-Language ... pretože prehliadač nie je majiteľ jazyka. Na reálnom kóde LocaleMiddleware."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Bonus"
members_only: true
translation: "bonus-locales"
published: true
---

> Toto je siedmy **bonus pre členov**. Predchádzajúce diely: [zámok
> members-only](/blog/bonus-members-only-pre-deti), [immutable audit
> log](/blog/bonus-audit-log-pre-deti), [fulltext search cez
> FTS5](/blog/bonus-fts-search-pre-deti), [komentáre s
> moderáciou](/blog/bonus-komentare-pre-deti), [média ako cudzie
> súbory](/blog/bonus-media-pre-deti) a [debug ako
> retroskop](/blog/bonus-debug-pre-deti). Dnes otázka, ktorú si kladie
> každá viacjazyčná appka: *kto rozhoduje o jazyku?*

Si vývojár. Appka má dva jazyky — SK a EN. Píšeš route, šablóny,
obsah ... a raz ti niekto položí otázku, ktorá znie banálne:

„V akom jazyku má bežať táto stránka?“

Banálna nie je. Je to zmluva o poradi — a každá appka, ktorá ju
nepísala vedome, ju napísala omylom.

## Rebrík, ktorý rozhoduje

`LocaleMiddleware` (`src/Modules/Locales/Interfaces/HTTP/Middleware/LocaleMiddleware.php`)
nesie poradie priamo v docblocke — a každý stupeň má inú váhu:

| priorita | zdroj | kto tlačí |
|---|---|---|
| 0 | URL prefix (`/en/blog`) | návštevník, explicitne |
| 1 | `X-Locale` hlavička | API klient |
| 2 | cookie | návštevník, kedysi |
| 3 | session | aktuálna relácia |
| 4 | nastavenie `site.locale` | majiteľ appky |

Zákon rebríka: **čím vyššia priorita, tým explicitnejšia vôľa**.
URL prefix je návštevník, ktorý si povedal jazyk do otvorena — preto
vyhráva nad cookie z minulosti.

A každý stupeň rebríka je len iný druh pamäti: táto pamäť žije v adrese,
tá v cookie, tá v relácii. Rovnaká lekcia ako pri [stave mimo
kontextu](/blog/stav-mimo-kontextu) — stav má nosič a každý nosič má
inú životnosť.

## Prehliadač nerozhoduje

A teraz riadok, ktorý je celý bonus v jednej vete.

Middleware **zámerne nepoužíva** `Accept-Language` hlavičku. Prehliadač
ju posiela pri každom requeste — jazyk, ktorý má nastavený v systéme.

Prečo ju ignorovať? Lebo prehliadač nie je majiteľ jazyka.

Slovenské kníhkupectvo sídliace v Bruseli má v prehliadači
`Accept-Language: fr-BE` ... a chce čítať po slovensky. Ak by appka
sledovala hlavičku, rozhodla by za ňu inštalácia systému, nie ona sama.

Site default vždy vyhráva nad preferenciou prehliadača — návštevník si
jazyk volí explicitne. Je to rozhodnutie s cenou: prvá návšteva ukáže
default, nie „tvoj“ jazyk. Ale je to **jej** rozhodnutie, nie rozhodnutie
Opera/Chrome/Edge.

Porovnaj s ukážkami v tomto bloge: veta bez podmienky je `mixed`.
„Zobrazíme stránku v jazyku čitateľa“ znie krásne — a skrýva otázku,
*čo je jazyk čitateľa*. Toto repo odpovedá: to, čo si zvolil. Nič viac.

## Prefix, ktorý sa maže presne raz

Najjemnejší bug viacjazyčných appiek je dvojitý strip.

Request `/en/blog` príde, middleware odstrihne prefix ... a ak sa niekto
spýta znova, vidí už očistenú cestu `/blog` a vyhlási „prefix nebol“ —
explicitná voľba sa stráca.

Kód to rieši komentárom, ktorý stojí za prečítanie:

```php
// LocaleMiddleware.php — explicitLocale() sa rieši PRESNE RAZ
// explicitLocale() is resolved ONCE: detectFromUrl() rewrites the
// request path (strips the /sk|/en prefix), so calling it twice
// would make the second call see the already-stripped path and
// report the locale as not explicit.
$explicit = $this->explicitLocale($request);
```

`detectFromUrl()` mení request. Funkcia, ktorá mení svet, sa nedá volať
dvakrát naivne — druhé volanie číta svet, ktorý prvé zmenilo. Riešenie:
výsledok sa spočíta raz a uloží do atribútu `locale_explicit`.

Blog tento flag potrebuje na jemný rozdiel: `/blog` je default jazyk,
`/en/blog` je explicitná voľba — a canonical URL sa chová podľa toho.

## t() — trojstupňový fallback

Šablóny nepíšu slová. Píšu kľúče:

```php
// src/Modules/Locales/Interfaces/HTTP/LocalesServices.php
public static function t(string $key, string $default = '', ?string $locale = null): string
{
    $activeLocale = self::locale()->current()->code();

    return self::translations()->get($key, $locale ?? $activeLocale, $default);
}
```

Fallback reťazec: preklad v aktívnom jazyku → preklad v explicitne
požadovanom → `default` parameter → kľúč. Šablóna `t('nav.home', 'Home')`
nikdy nevykreslí `nav.home` — ak nič iné, tak aspoň anglický default.

Kľúče žijú v `locales.db`, spravujú sa v `/mark/locales` a importujú sa
JSONom — preklad je dáta, nie kód.

## Preložiť blog nie je preložiť UI (a povinná úprimnosť)

Dve vrstvy dvojjazyčnosti sú dve rôzne práce.

UI reťazce (`nav.home`) sa prekladajú kľúčmi — systématicky, raz.
Blog články sa prekladajú ako **samostatné súbory** s per-jazyk slugami
(`preco-rust-pre-deti` ↔ `why-rust`), prepojené `translation:` — lebo
článok nie je reťazec, je to text s vlastným hlasom. Strojový preklad
by hlas zabil.

Buďme úprimní: rebrík priorities má aj menej známu cenu. Cookie a
session sú pamäť medzi requestmi — kto zmení jazyk v cookie, prepadne
späť na default pri prvom url-prefixed linke. To nie je bug, je to
zmluva: adresa vyhráva. Ale je to prekvapenie, ktoré treba poznať.

A druhá: dva jazyky nie sú i18n. Pravá internacionalizácia (pluraly,
dátumy, RTL) je o rádovo viac práce — tento kit rieši dvojjazyčnosť,
nie svet.

## Prakticky

1. **Zmeň jazyk cez adresu** ... `/en/blog` — a vráť sa na `/blog`.
   Sleduj, čo sa stane s `<html lang>`.
2. **Pridaj kľúč v `/mark/locales`** ... a použi ho v šablóne cez
   `t()` — preklad je dáta, nie deploy.
3. **Pozri si `translation:` vo front mattere** ... každý článok
   ukazuje na zrkadlo a zrkadlo späť — dvojjazyčnosť ako obojsmerná
   zmluva.

Jazyk potom nie je nastavenie ... je to zmluva, ktorú čitateľ podpisuje
adresou.

## Zdroje

- [MDN — Accept-Language](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Accept-Language) ... hlavička, ktorú tento kit zámerne nečíta
- [W3C — Language tags in HTML and XML](https://www.w3.org/International/articles/language-tags/) ... jazykové značky a ich štruktúra
- [PHP — Locale](https://www.php.net/manual/en/class.locale.php) ... systémová lokalizácia nad rámec tohto kitu
- [Nativa Minimal Documentation](/docs/getting-started) ... getting started guide tohto kitu
- [docs/blog-voice.md](/docs/blog-voice) ... príručka hlasu, v ktorej série bonusov žije
