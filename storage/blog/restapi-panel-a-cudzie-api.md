---
title: "Skúšobný REST záznam ... cudzie API, ktoré čítame z panelu"
date: "2026-11-26"
version: "1.0.0"
tags: ["nativa", "php", "rest", "api", "panel", "jsonplaceholder"]
excerpt: "Záznam skuska v REST paneli ukazuje na JSONPlaceholder. Vysvetľujem, čo panel ukladá, kadiaľ ide request a prečo to nie je naše API."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
published: true
---

V paneli je služba Skúšobné API a jej základná adresa je `https://jsonplaceholder.typicode.com`.
To nie je náš server.
JSONPlaceholder je verejné demo API, ktoré prevádzkuje projekt Typicode
na učenie a prototypovanie.
Panel ho len číta a nič z neho neposiela na náš web.

## Čo ten záznam je

Riadok v tabuľke služieb hovorí, kam sa má panel pozerať.
Nie je to kópia dát ani nová databáza na našom serveri.
Je to pravidlo, ako prečítať jednu kolekciu z cudzieho API.

Záznam `skuska` má resource `posts`, polia `id`, `userId`, `title` a `body`
a akcie `list` a `read`.
Preto sa v detaile služby ukáže odkaz na položky a nič viac.

## Ako funguje jedno otvorenie

Otvoríš `/mark/rest/services/skuska/resources/posts/items`.
`ItemsIndexAction` nájde službu aj resource v `restapi.db`.
`ListReader` požiada `RequestFactory` o URL.
Tá vznikne spojením `base_url` a `path`,
teda `https://jsonplaceholder.typicode.com/posts` pre tento záznam.
`CurlTransport` prejde cez `SsrfGuard` a pošle GET.
Odpoveď je JSON, `Envelope` z neho vyberie zoznam a `FieldMapper` polia.
Tabuľka sa vykreslí z odpovede, ktorá práve prišla.

Celý reťaz je len čítanie.
V `restapi.db` nie sú články z JSONPlaceholderu.
Sú tam len pravidlá, podľa ktorých sa majú prečítať.

Každé obnovenie stránky je nový request.
Panel medzi dvoma zobrazeniami nič nedrží.

## Čo sa ukladá a čo nie

V `restapi.db` sú štyri tabuľky, services, resources, fields a relations.
Služba drží adresu, timeout a názov premennej s tokenom.
Resource drží cestu, id pole, akcie a pagináciu.
Polia držia mená, typy a to, či sa ukazujú v zozname.

Token sa neukladá.
Ukladá sa iba názov environmentálnej premennej,
z ktorej ho `RequestFactory` prečíta pri requeste.
Pri JSONPlaceholderi žiadny token netreba, služba je verejná.

Snapshot `storage/data/restapi/skuska.json` je export toho istého.
Vzniká pri každom uložení a dá sa preniesť na iný stroj.
Riadky v databáze sú za behu zdroj pravdy, snapshot je len ich čitateľná kópia.

## Paginácia a prečo `none`

JSONPlaceholder vráti holý zoznam 100 položiek.
Preto má resource `pagination: none`.
Pri stratégii `page` by `RequestFactory` pridal `?page=1&per_page=25`.
Pri `none` nepridá nič a `PageState` nastaví total na počet prijatých riadkov.

Tabuľka preto ukáže všetkých 100 naraz.
Pri produkčnom API by to bol zlý nápad, ale na skúšku je to najkratšia cesta k dátam.

## Kde je hranica bezpečnosti

Adresu píše operátor, a server ju potom volá.
To je presne tvar SSRF, preto `SsrfGuard` kontroluje viac než schému.

Zakáže iné schémy než http a https, credentials v URL
a adresy v súkromných, loopback, link-local alebo dokumentačných rozsahoch.
Zakáže aj host, ktorý sa na takú adresu preloží.

Verejný host prejde aj vtedy, keď je `RESTAPI_ALLOWED_HOSTS` prázdne.
Allowlist je výnimka pre súkromné adresy, nie zoznam všetkého povoleného.
Tento rozdiel je dôležitý a v staršom dokumente bol napísaný opačne.

Transport nesleduje redirecty, prečíta najviac 2 MiB tela a má vlastné timeouty.
Redirect by mohol viesť na adresu, ktorú guard nevidel.

## Prečo to nie je naše API

Naše API by bolo vstupom do nášho CMS.
Mal by vlastné cesty, vlastné prihlasovanie a vlastné limity.
Panel REST je výstup v druhom smere, číta cudzie API a ukazuje ho operátorovi.

Záznam `skuska` teda neznamená, že responsive.sk má REST API.
Znamená, že admin vie prečítať JSONPlaceholder.
Keby sme chceli vlastné API pre obsah, postavili by sme ho na ceste ako `/api/v1/articles`.
Až potom by sa dalo pridať ho do panela ako ďalšiu službu.

## Ako si to vyskúšaš

Otvor `/mark/rest`, klikni na Skúšobné API a potom na `posts`.
Prvý riadok má `id=1` a titulok, ktorý začína slovom `sunt`.
Detail položky otvoríš kliknutím na ID.

Ak chceš vlastný zdroj, zmeň `base_url` na svoje API a popíš resources a polia.
Ak host smeruje do súkromnej siete, pridaj ho do `RESTAPI_ALLOWED_HOSTS`.
Záznam zmažeš tlačidlom Delete service aj so snapshotom.

## Čo si z toho odniesť

Panel REST nie je naše API.
Je to pravidlo, ako čítať cudzie API, a jedno verejné demo,
na ktorom sa dá overiť, že pravidlo funguje.
Keď raz budeme mať vlastné API, bude to iný modul, iná cesta a iná autentifikácia.

## Zdroje

- [JSONPlaceholder](https://jsonplaceholder.typicode.com/), verejné demo API pre testovanie a prototypy
- [OWASP, SSRF Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html), prečo sa kontroluje cieľová adresa
- [MDN, HTTP request methods](https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods), čo znamená GET a prečo čítanie nič nemení
