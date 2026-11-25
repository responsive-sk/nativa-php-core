---
title: "Bonus pre členov ... fulltext search: SQLite FTS5 v pamäti, žiadny server navyše"
date: "2026-08-13"
version: "1.0.3"
tags: ["search", "sqlite", "fts5", "fulltext", "php", "security"]
excerpt: "Tretí bonus len pre registrovaných. Vyhľadávanie na /blog?q= beží bez Elasticsearchu, bez Solru, bez jediného nového súboru na disku ... index žije v pamäti a postaví sa za pár milisekúnd. Ukážeme si FTS5, bm25 ranking a prečo sa do fulltext query nedá injektovať."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Bonus"
members_only: true
translation: "bonus-full-text-search"
published: true
---

Vyhľadávanie na webe zvyčajne znamená Elasticsearch alebo Solr. Ale čo keď postačí jedna virtuálna tabuľka v pamäti?

> Toto je tretí **bonus pre členov**. Blog má vyhľadávanie ... `/blog?q=…`
> ... a väčšina ľudí by na to nasadila Elasticsearch, Solr alebo aspoň
> novú tabuľku v databáze. Tento blog to celé zmestil do **jednej
> virtuálnej tabuľky v pamäti**, ktorá sa postaví a zase zmaže pri
> každom vyhľadávaní. Poďme sa pozrieť, ako to funguje a prečo to
> stačí.

## Vyhľadávanie bez infraštruktúry

Keď blog narastie na dvadsať článkov, príde otázka: „Potrebujeme
vyhľadávanie?“ A hneď za ňou tá druhá: „A čo na to nasadíme?“

Odpoveď v tomto repe je: **nič.** Žiadny nový server, žiadna nová
databáza, žiadny súbor navyše. Vyhľadávanie používa to, čo už máš ...
SQLite ... a jednu jeho funkciu, o ktorej sa málo vie: **FTS5**.

FTS5 je fulltext index zabudovaný priamo v SQLite. Poviem mu
„indexuj tieto textové stĺpce“ a on si postaví štruktúru, v ktorej
dokáže hľadať slová oveľa rýchlejšie než `WHERE title LIKE '%slovo%'`
(kde SQLite musí prejsť každý riadok a každý znak). A hlavne vie
**ohodnotiť relevantnosť** ... čo `LIKE` nikdy nevedel.

## Index v pamäti, postavený na počkanie

Najzaujímavejšie rozhodnutie: index nežije na disku. Žije
v `sqlite::memory:` ... v pamäťovej databáze, ktorá sa vytvorí,
naplní a zničí **pri každom vyhľadávaní**:

```php
$pdo = new \PDO('sqlite::memory:');
$pdo->exec('CREATE VIRTUAL TABLE blog_fts USING fts5(title, excerpt, tags, body, slug UNINDEXED)');
```

„Postaviť index na každý request? To predsa musí byť pomalé!“ ... je
to prekvapivo rýchle, pretože:

1. **Zdroj je malý.** Dvadsať článkov Markdownu je pár stoviek
   kilobajtov textu. FTS5 index z toho postaví za jednotky
   milisekúnd ... na stroji, kde by Elasticsearch bežal ako samostatný
   proces, ktorý musíš spravovať, update-ovať a monitorovať.
2. **Žiadna synchronizácia.** Index je **vždy aktuálny**, pretože
   sa stavia z aktuálneho stavu `storage/blog/`. Pridáš článok,
   a ďalšie vyhľadávanie ho už nájde. Žiadne cron joby na
   reindexáciu, žiadny „index je 3 dní starý“.
3. **Konzistencia zadarmo.** Vyhľadávanie používa rovnaké karty
   (`BlogArticlesService::all()`) ako index blogu, takže výsledky
   sa nikdy nemôžu rozísť so zoznamom článkov.

To je rovnaký „no generated map“ vzor ako zvyšok repa: nič sa
neukladá, všetko sa odvodzuje na požiadanie. Preto nemusíš nič
invalidate-ovať.

## bm25: prečo je relevantný výsledok hore

Hľadanie „čistý kód“ vráti články, ktoré to slovo obsahujú. Ale
ktorý je prvý? Tu prichádza **bm25** ... algoritmus na ohodnotenie
relevantnosti, ktorý FTS5 vie počítať priamo v SQL:

```sql
SELECT slug FROM blog_fts
WHERE blog_fts MATCH :match
ORDER BY bm25(blog_fts, 10.0, 5.0, 3.0, 1.0)
LIMIT 20
```

Čísla za `bm25` sú **váhy stĺpcov** v poradí, v akom boli definované
v tabuľke: `title=10`, `excerpt=5`, `tags=3`, `body=1`. Znamená to:

- slovo v **titule** váži 10× viac než v tele,
- slovo v **excerptu** 5×,
- v **tagoch** 3×,
- v **tele** 1×.

Výsledok: článok s hľadaným slovom v titule predbehne článok, ktorý
ho má len kdesi v tele. Test to overuje priamo ... dva fixtúrové
články, jedno slovo raz v titule a raz v tele, a asercia hovorí,
že ten s titulom je v HTML skôr:

```php
self::assertLessThan(
    strpos($html, 'href="/blog/zz-rank-body"'),
    strpos($html, 'href="/blog/zz-rank-title"'),
);
```

## Prečo sa do fulltext query nedá injektovať

Tu prichádza najzákernejší detail. FTS5 `MATCH` má vlastnú syntax ...
`AND`, `OR`, `NOT`, úvodzovky, zátvorky. A presne tá syntax je
klasická brána k **query injection**: čo ak používateľ napíše
`"a" OR "b"` a zmení si tým logiku vyhľadávania? Čo keď to
skombinuje s chybovým výstupom?

Riešenie v repe je elegantné ... **query sa nikdy neposiela do MATCH
tak, ako prišla.** Najprv sa rozseká na slová, a z každého slova sa
urobí **citovaná prefix fráza**:

```php
// vstup:  hello OR world
// tokeny: ["hello", "OR", "world"]
$match = implode(' ', array_map(
    static fn (string $token): string => '"' . $token . '"*',
    $tokens,
));
// MATCH:  "hello"* "OR"* "world"*
```

Operátory FTS5 (`AND`, `OR`, `NOT`, úvodzovky, zátvorky, interpunkcia)
sa tokenizáciou **vyhodia** ... ostane len čisté slovo. `"OR"` sa tak
nespráva ako operátor, ale ako obyčajné slovo, ktoré sa má hľadať.
Test to overuje presne tým najnebezpečnejším príkladom:

```php
$response = (new BlogIndexAction())->handle(Request::create('GET', '/blog', ['q' => 'hello OR world']));

// Článok "hello-world" obsahuje hello AJ world ... skutočný OR by ho vrátil.
self::assertStringNotContainsString('href="/blog/hello-world"', $response->getContent());
```

Hviezdička za slovom je **prefix search**: `"hello"*` nájde aj
`hello-world`, `helloed`, `helloworld`. Čiže skratky a skloňovanie
fungujú bez toho, aby si musel písať vlastné lemmatizátory.

## Diakritika: „cisty“ nájde „čistý“

Slovenčina má háčiky a dĺžne. Používateľ, ktorý píše „cisty kod“
(namiesto „čistý kód“), by čakal, že výsledky nájde. FTS5 s
tokenizerom **unicode61** to zvláda zadarmo ... pri indexovaní
aj pri vyhľadávaní stiahne diakritiku, takže „cisty“ a „čistý“
sa správajú ako to isté slovo:

```php
$html = (new BlogIndexAction())->handle(Request::create('GET', '/blog', ['q' => 'cisty']));

self::assertStringContainsString('href="/blog/co-je-cisty-kod-pre-deti"', $html);
```

Žiadna vlastná transliteračná tabuľka, žiadne `iconv` triky ... to
všetko spraví tokenizer. Jedna vec, ktorú unicode61 **nerozlišuje**,
je veľkosť písmen: vyhľadávanie je case-insensitive, čo je pri
fulltexte skoro vždy to, čo chceš.

## Čo s members-only článkami

V [predošlom bonus článku](/blog/bonus-members-only-pre-deti) sme
si ukázali, ako sa zamyká obsah. Čo ale vyhľadávanie? Indexuje
members-only články, a tým sa ich tajné telo „nevyzradí“?

Áno, indexuje ... a **nie**, nevyzradí. Telo gated článku sa používa
len na ranking vo vnútri per-request pamäťového indexu, ktorý sa
zničí hneď po odpovedi. Výsledky vracajú len **slugy**, a stránka
výsledkov renderuje štandardné karty (titulok + excerpt) ... úplne
rovnako ako feed. Samotný článok stráži login gate. V kóde je to
priamo zdokumentované ako záväzok: keby sa niekedy chcelo vo výsledkoch ukazovať snippety tela, gated body sa musia z indexu
vyhodiť **skôr**, než sa to stane.

## Pravidlo bez testu sa nedodržiava

Vyhľadávanie je pokryté celým radom testov, ktoré overujú, že
sľuby držia:

| Test | Čo stráži |
|---|---|
| `testSearchFindsArticlesByTitle` | hľadanie podľa titulu |
| `testSearchFindsArticlesByBodyText` | hľadanie podľa tela článku |
| `testSearchFoldsDiacritics` | „cisty“ → „čistý“ |
| `testSearchMatchesPrefixes` | „chunky“ → „chunky-rule...“ |
| `testSearchRanksTitleAboveBody` | bm25 váhy (titul > telo) |
| `testSearchDoesNotInjectFtsOperators` | `OR` sa nespráva ako operátor |
| `testSearchQuotesAndParensAreHarmless` | úvodzovky/zátvorky neublížia |
| `testSearchKeepsEscapedQueryInInputValue` | `<script>` v query sa escapuje |
| `testSearchEmptyQueryShowsFullIndex` | prázdne `q` = normálny index |

Ten posledný je pekný detail: keď `q` chýba alebo je prázdne,
stránka sa správa ako obyčajný blog index ... žiadne „0 results“
bez dôvodu.

## Čo si z toho odniesť

1. **Fulltext nemusí znamenať Elasticsearch** ... SQLite FTS5 zvládne
   malé datasety s nulovou infraštruktúrou.
2. **Index v pamäti = vždy aktuálny** ... postaví sa na počkanie,
   takže nie je čo synchronizovať ani invalidate-ovať.
3. **bm25 váhy riadia poradie** ... titul 10×, telo 1×; relevantnosť
   sa testuje, nie verí.
4. **Sanitizuj MATCH, nie len SQL** ... fulltext syntax je tiež
   injektovateľná; tokenizuj query na citované prefix frázy.
5. **Diakritika zadarmo** ... unicode61 tokenizer stiahne háčiky
   a dĺžne pri indexovaní aj pri vyhľadávaní.

A keď nabudúce niekto povie „na vyhľadávanie potrebujeme
Elasticsearch“ ... vieš, že na dvadsať článkov stačí jedna virtuálna
tabuľka v pamäti. 

## Zdroje

- [SQLite — FTS5](https://www.sqlite.org/fts5.html) — virtuálne tabuľky, bm25, unicode61 tokenizer
- [OWASP — Injection Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Injection_Prevention_Cheat_Sheet.html) — prečo MATCH sanitizujeme ako SQL
- [PHP Manual — PDO](https://www.php.net/manual/en/book.pdo.php) — prepared statements, cez ktoré beží MATCH
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
