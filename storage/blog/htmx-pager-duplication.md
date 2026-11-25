---
title: "Kliknite na stránku 3 ... a pager sa zdvojnásobil"
date: "2026-08-29"
version: "1.0.2"
tags: ["htmx", "pagination", "bug", "php", "admin-panel"]
excerpt: "Každý klik na stránku vytvoril nový pager. Starý zostal. Potom sa objavil tretí. Príčina bol jeden HTML element mimo swap targetu."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Architecture"
translation: "htmx-pager-duplication"
published: true
---

Kliknite na stránku 3. Pager sa zdvojnásobil. Kliknite znova. Ztrojnásobil sa. Štyri pagery na sebe, každý trochu iný, niektoré s Prev, niektoré s Next, ani jeden s oboma.

Admin panel vyzeral rozbitý. Kód bol správny. Alebo sa tak zdalo.

## Nastavenie

HTMX paginácia bola priamočiara. Každý link na stránku cielil na `#pages-content` s `outerHTML` swapom:

```html
<a href="?page=2"
   hx-get="/mark/pages?page=2"
   hx-target="#pages-content"
   hx-swap="outerHTML"
   hx-push-url="true">2</a>
```

Klik na link. Server vráti novú tabuľku + novú pagináciu. HTMX nájde `#pages-content` v odpovedi, nahradí starý. URL v prehliadači sa aktualizuje. Čisté.

Vyhľadávací input sedel nad tabuľkou. Cielil na rovnaký `#pages-content`:

```html
<input type="search" name="q"
    hx-get="/mark/pages"
    hx-trigger="keyup changed delay:300ms, search"
    hx-target="#pages-content"
    hx-swap="outerHTML">
```

Vyhľadávanie a paginácia zdieľali rovnaký cieľ. Obe vracali rovnaký `$grid` blob, search input, count info, tabuľka, paginácia, v jednom HTML reťazci.

## Kaskáda

Čo sa stalo pri kliknutí na stránku:

1. Používateľ klikne stránku 3
2. HTMX odošle GET `/mark/pages?page=3`
3. Server vráti celý `$grid`: search input + count info + `<div id="pages-content">tabuľka + paginácia</div>`
4. HTMX nájde `#pages-content`, nahradí cez `outerHTML`
5. Starý pager je preč. Nový sa objaví. Zatiaľ čisté.

Ale odpoveď obsahovala aj search input, s vlastným `hx-trigger="search"`. Po swape prehliadač prehodnotil stav formulára. `search` event sa spustil na pôvodnom inpute (ktorý zostal v DOM, mimo `#pages-content`). To spustilo druhý HTMX request. Druhá odpoveď obsahovala ďalšiu pagináciu. `outerHTML` nahradil `#pages-content` znova, ale prvé nahradenie už bolo dokončené. Dva pagery teraz existovali v DOM.

Každé ďalšie kliknutie zopakovalo cyklus.

## Oprava

Oddelte, čo plná stránka potrebuje, od toho, čo HTMX potrebuje.

`renderGrid()` vracia kompletné HTML pre prvé načítanie stránky, search input, count info, tabuľka, paginácia. `renderContent()` vracia iba element `<div id="pages-content">`: count info, tabuľka, paginácia. Žiadny search input.

```php
// Plné načítanie stránky
$searchInput . $this->renderContent($pages, $locale, ...);

// HTMX partial — iba target element
if (AdminLayout::isHtmx($request)) {
    return Response::html($this->renderContent($pages, $locale, ...));
}
```

Search input sa nikdy neobjaví v HTMX odpovediach. Žiadna kaskáda. Žiadna duplikácia.

## Čo HTMX očakáva

HTMX s `outerHTML` swapom nájde target element v odpovedi a nahradí pôvodný. Elementy mimo targetu v odpovedi sa zahodia.

Ale „zahodia“ znamená, že prehliadač ich nikdy nevloží do DOM, neznamená, že nikdy neexistovali v odpovedi. Ak odpoveď obsahuje elementy s vlastnými HTMX triggermi, a tieto elementy zodpovedajú DOM selektorom, prehliadač môže spustiť eventy na pôvodných (nezamenených) elementoch.

Pravidlo: HTMX partial odpoveď by mala obsahovať **iba** element, ktorý sa mení. Nič pred ním. Nič po ňom. Ak server vracia `$searchInput . $targetDiv`, a `$searchInput` má `hx-trigger`, pôvodný search input zostáva v DOM a môže sa znova spustiť.

`renderGrid()` zostáva rendererom pre plnú stránku, search input, count info, tabuľka, paginácia. `renderContent()` spracúva HTMX cestu. Keď staviate pagináciu s HTMX, volajte `renderContent()`. Keď potrebujete plnú stránku, volajte `renderGrid()`. Dve metódy, jedna pravda.

---

Magia frameworku je taká dobrá, aké dobré HTML mu naservírujete. HTMX vás nechráni pred tým, aby ste v partial odpovedi vrátili priveľa. Zodpovednosť je vaša.

---

→ [MoonShine Features + HTMX](/blog/moonshine-htmx-features): toast notifikácie, live search, form submit
→ [CSP a onclick](/blog/csp-onclick-spravodliva-chyba): tichá bezpečná diera, ktorá zabila inline handlery

## Zdroje

- [htmx.org, Documentation](https://htmx.org/docs/): oficiálna HTMX dokumentácia
- [htmx.org, hx-swap](https://htmx.org/attributes/hx-swap/): swap atribút a jeho možnosti
- [htmx.org, hx-target](https://htmx.org/attributes/hx-target/): target selektor špecifikácia
- [MDN, DOM Manipulation](https://developer.mozilla.org/en-US/docs/Web/API/Document_object_model): DOM API základy
- [HTMX Patterns, Pagination](https://htmx.org/examples/pagination/): oficiálny pagination pattern
