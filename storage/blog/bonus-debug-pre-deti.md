---
title: "Bonus pre členov ... debug: retroskop namiesto živého vystavenia"
date: "2026-09-17"
version: "1.0.3"
tags: ["debug", "php", "security", "observability", "architecture"]
excerpt: "Šiesty bonus len pre registrovaných. Debug panel je zbraň s bezpečnostnou poistkou: 404 namiesto 403, maskovanie env pred zobrazením, snapshot po skončení requestu. Prečo je „vždy zaregistrovaná route, ktorá neexistuje“ lepší nápad než tajná cesta — na reálnom kóde z tohto repa."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Bonus"
members_only: true
translation: "bonus-debug"
published: true
---

> Toto je šiesty **bonus pre členov**. Predchádzajúce diely: [zámok
> members-only](/blog/bonus-members-only-pre-deti), [immutable audit
> log](/blog/bonus-audit-log-pre-deti), [fulltext search cez
> FTS5](/blog/bonus-fts-search-pre-deti), [komentáre s
> moderáciou](/blog/bonus-komentare-pre-deti) a [média ako cudzie
> súbory](/blog/bonus-media-pre-deti). Dnes nástroj, ktorý vidí všetko
> ... a v produkcii neexistuje.

Si vývojár. Appka v produkcii začne robiť niečo nezmyselné.

Prvá myšlienka: pozriem sa do logu. Druhá: pustím debug panel. Tretia —
a tá je tá správna: *debug panel, ktorý beží v produkcii, je
bezpečnostná diera so svetlom.*

Tento kit to rieši inak. Panel `/debug` je zbraň s poistkou — a každý
detail jeho návrhu je tá poistka.

## Vždy zaregistrovaný, nikdy existujúci

Route `/debug` je zaregistrovaná vždy. Len správa, ktorú odpovie, sa
mení podľa prostredia:

```php
// src/Modules/Debug/Interfaces/HTTP/Actions/DebugAction.php — poistka
if (!Env::bool('APP_DEBUG', false)) {
    return Response::html($this->notFoundPage(), 404);
}
```

Prečo 404 a nie 403? Pretože 403 je priznanie.

404 hovorí „táto adresa tu nie je“ — a pre útočníka je to pravda.
Panel v produkcii neexistuje. 403 by povedalo „existuje, len ti ho
nedám“ ... čo je mapa, nie múr. Cesta, o ktorej útočník vie, že tam
je, je cesta, ktorú bude obklopovať.

Z rovnakého dôvodu ani `robots.txt` o `/debug` nepíše — allow-whitelist
spomína len verejné povrchy. Tajomné cesty sa nezminujú. Prvé pravidlo
bunkra: neexistujúci objekt sa nebráni.

## Panel vidí všetko ... okrem tajomstiev

Keď panel beží (dev), ukazuje: request, matched route, env premenné,
otvorené DB spojenia, SQL log, časovanie fáz.

A práve env premenné sú miesto, kde si panel môže streliť do nohy.
`.env` nesie heslá, tokeny, DSN — celý zoznam kľúčov od domu.

Panel preto nepíše env, ale env **po cenzúre**:

```php
// DebugAction.php — kľúče, ktoré sa nikdy nezobrazia
private const array SECRET_FRAGMENTS = ['PASS', 'SECRET', 'TOKEN', 'DSN', 'AUTH', 'KEY'];

private function isSecretKey(string $key): bool
{
    return array_any(
        self::SECRET_FRAGMENTS,
        static fn (string $fragment): bool => str_contains(strtoupper($key), $fragment),
    );
}
```

Logika je opačná, než by si čakal. Neexistuje zoznam „zobraz tieto,
skry tie“ — existuje zoznam fragmentov, ktoré **vždy** skryjú. Kľúč
`DB_PASSWORD`, `API_TOKEN`, `AWS_SECRET_KEY` ... všetky padnú do siete
podľa mena.

Buďme úprimní: maskovanie podľa mena nie je dokonalé. Kľúč pomenovaný
`HESLO_K_DB` by prešiel. Preto panel nikdy nenecháva na produkcii
zapnutý — maskovanie je druhá línia, nie prvá.

## Retroskop namiesto živého prenosu

Tretia poistka je najsubtílnejšia a najzaujímavejšia.

Panel nepozoruje vlastný request. Pozoruje **minulý**:

```php
// DebugAction.php — snapshot pattern
// The panel renders the LAST captured request — the /debug request
// itself collects nothing (its log and connections were reset at the
// start of this request by DebugServices::enable()).
$snapshot = DebugServices::lastRequest();
```

Prečo? Lebo panel, ktorý zbiera dáta o sebe, meria samého seba.
SQL log by obsahoval dotazy panela. Časy by patrili renderovaniu
panela. Diagnostika by hlásila diagnostiku — klasická pozorovateľská
slepota.

Snapshot berie `public/index.php` po tom, čo bežný request skončí.
Panel potom číta uzavretú minulosť — hotový príbeh s kompletným SQL
logom a časmi, do ktorého sa nič nedopisuje. Presne ten istý princíp
ako revízna správa z článku o [stave mimo
kontextu](/blog/stav-mimo-kontextu): stav sa zapisuje na disk, číta sa
neskôr, mimo diania.

## Debugbar s poistkou rovnakého typu

Do HTML odpovedí v dev režime sa vkladá plávajúca lišta — Time, Memory,
DB, link na `/debug`.

Aj ona nesie tri poistky. Vkladá sa len keď `APP_DEBUG=true` a odpoveď je HTML. Každý inline script nesie CSP nonce — `SecurityHeadersMiddleware`
ho substituuje per request, takže lišta nemôže otvoriť dieru v Content
Security Policy. A keď je debug vypnutý, middleware je priechodný —
nula bajtov navyše.

Debug tooling, ktorý by si vyžadoval vypnutie CSP, by bol zlé riešenie
aj keby fungoval.

## SQL log ako vedľajší produkt

Ako panel vidí dotazy? Nie cez interceptory ani magic.

`DebugServices::enable()` nainštaluje `LoggingPdoStatement` na nové
spojenia — každý `execute()` si zapíše SQL a čas. Bežný kód nič
nemení: používa PDO ako vždy. Len pod ním beží statement, ktorý si
robí poznámky.

| prístup | cena | riziko |
|---|---|---|
| ORM s event listenermi | celý ORM runtime | vendor lock |
| wrapper okolo celého PDO | reimplementácia API | drift |
| statement subclass | jeden súbor | minimálne |

Tretia cesta je v duchu celého kitu — minimálny zásah na správnom
mieste.

## Kedy panel neplatí (a povinná úprimnosť)

Debug panel nie je monitoring.

Nezbieha metriky, negraphuje, nevysielá alerty. Je to lupa na jeden
request — pozeráš sa cez neho, keď niečo hľadáš, nie stále. Produkčná
observability je iná disciplína s inými nástrojmi.

Druhá úprimnosť: snapshot pattern má cenu. Panel ukazuje minulosť —
ak si pýtaš detail práve bežiaceho requestu, vidíš ten predchádzajúci.
Pre živé ladenie jedného requestu existuje fallback, ale princíp
zostáva: diagnostika nie je súčasťou diania, ktoré popisuje.

## Prakticky

1. **Zapni si debug lokálne** ... `APP_DEBUG=true` v `.env`, panel na
   `/debug`, lišta v každom HTML.
2. **Skontroluj maskovanie** ... pridaj do `.env` testovací kľúč
   s `TOKEN` v mene a pozri sa, čo panel ukazuje. Mal by ukázať
   `***`.
3. **V produkcii nikdy** ... `APP_DEBUG=false` je jediná správna
   hodnota. Panel tam nie je skrytý — tam neexistuje.

## Zdroje

- [OWASP — Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html) ... prečo chybové hlášky nesmú zradiť povrch
- [MDN — Content Security Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP) ... nonce per request
- [PHP — PDOStatement](https://www.php.net/manual/en/class.pdostatement.php) ... základ, ktorý LoggingPdoStatement rozširuje
- [Nativa Minimal Documentation](/docs/getting-started) ... getting started guide tohto kitu
- [docs/blog-voice.md](/docs/blog-voice) ... príručka hlasu, v ktorej série bonusov žije
