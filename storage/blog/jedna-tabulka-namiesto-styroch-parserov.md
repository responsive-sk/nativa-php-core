---
title: "Jedna tabuľka namiesto štyroch parserov : ako preklep v CLI prestal byť tichý úspech"
date: "2026-11-10"
tags: ["nativa", "php", "cli", "architektúra", "kernel", "moduly"]
excerpt: "Ten istý preklep mal v štyroch príkazoch štyri rôzne následky: jeden ho odmietol, druhý spracoval dávku s predvoleným limitom, tretí ho prečítal ako dáta. Parser je teraz jeden a vlastní ho kernel : meranie hovorí 154 346 B pred a 159 059 B po."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architektúra"
published: true
---

## Časť I > Štyri parsery, štyri odpovede

Keď som v bin/cms písal tabuľku prijatých volieb,
vyzeralo to ako lokálna oprava jedného príkazu.
`php bin/cms queue:work --lmit=100` vtedy prešlo
a spracovalo dávku s predvoleným limitom 10.
Výstup vyzeral ako úspech, takže nikto nemal dôvod pozerať sa na argv.

Rovnaký preklep v inom príkaze mal iný koniec:

| príkaz | čo preklep `--lmit` spôsobil |
|---|---|
| `queue:work` | ticho spracoval dávku s limitom 10 |
| `outbox:process` | ticho použil predvolený limit |
| `generate-images` | ticho použil predvolený formát |
| `clickhouse:attach` | odmietol ho, ale bez mena prijatých volieb |

To nie je jeden bug, ktorý sa dá opraviť na jednom mieste.
Sú to štyri rôzne pravidlá pre tú istú otázku:
**ktoré voľby príkaz prijíma a čo sa stane s ostatnými.**
Kým odpoveď žije v tele každého príkazu, štvrtý príkaz bude mať
vždy o niečo inú odpoveď než prvý.

Pri meraní sa navyše ukázalo, že sa tie pravidlá už rozchádzajú
v maličkostiach, ktoré sa dajú vypísať:
Clickhouse príkaz odmietol aj `--dry-run=1`, teda flag s hodnotou,
Blog príkaz prijal `--format` bez hodnoty a doplnil predvolenú,
Outbox prečítal `--no-prune=x` ako reťazec, ktorý nie je `x`.
Každé z tých rozhodnutí bolo v svojom teste obhájiteľné.
Dokopy už netvorili systém, ale zbierku.

## Časť II > Jedno miesto, ktoré rozhoduje

Parser som preto presunul do kernelu ako
`Modules\Core\Interfaces\CLI\CommandOptions`,
vedľa `CliOutput`, ktorý moduly už importujú.
Rozhranie je jedna metóda:

```php
CommandOptions::parse(
    $arguments,
    valueOptions: ['email', 'password', 'name'],
);
```

Volajúci dostane dve pole: `options` a `positional`.
Parser pritom drží štyri pravidlá, nie štyri implementácie:

- `--name value` a `--name=value` sú tá istá voľba,
- flag odmietne hodnotu, lebo flag hodnotu nemá,
- alias existuje len vtedy, keď ho volajúci deklaroval,
- pozície sa vrátia volajúcemu, parser ich neinterpretuje.

Druhé pravidlo je dôležitejšie, než vyzerá.
Keď `--dry-run=1` prejde, niekto si myslí, že prácu spustil naostro,
a príkaz mu odpovie ako pri obyčajnom `--dry-run`.
Ticho prijatá hodnota je horšia než odmietnutá voľba,
pretože odmietnutie sa dá prečítať a hodnota sa nedá vrátiť.

Presun nie je zadarmo a meria sa to v kernel shape.
`make kernel-size` pred zmenou: 154 346 B v 37 súboroch.
Po zmene: 159 059 B v 38 súboroch, Core 8 interface a 28 concrete.
`KernelShapeTest::MAX_APPROVED` išiel z 27 na 28,
teda jeden runtime súbor, ktorý schvaľuje test, nie review.
`bin/build/kernel-divergence.txt` dostal riadok `OWNED`,
lebo trieda patrí nám a nie upstreamu.

## Časť III > Tabuľka, ktorá je aj help

`bin/cms` si tabuľku ponechal.
Nedá sa presunúť do modulu, pretože help a dispatch musia čítať
to isté, a to isté musí byť na jednom mieste:

```php
private static function commandOptions(): array
{
    return [
        'queue:work' => ['--limit='],
        'module:remove' => ['--force'],
        // ...
    ];
}
```

Parser dostane z tej tabuľky zoznam volieb a chybovú správu obohatí
o meno príkazu a o zoznam tých, ktoré prijíma:

```
cms: unknown option "--lmit" for queue:work; known: --limit=
```

To je celý rozdiel medzi odmietnutím a hádaním.
Predtým sa dalo dozvedieť, že voľba neexistuje, ale nie to,
ktorá existuje; operátor musel otvoriť zdroják alebo help.

Modulové príkazy tabuľku nemajú, ale deklarujú svoje voľby
na jednom riadku a parser je vynúti predtým, než príkaz čokoľvek urobí:

```php
$parsed = CommandOptions::parse(
    $argv,
    ['limit', 'prune-interval'],
    self::FLAGS,
);
```

Diff v moduloch je pritom skoro celý mínus:
243 zmazaných riadkov a 57 pridaných.
Zmazané sú štyri privátne slučky a jedna kópia `CommandOptions`
v Clickhouse; pridané sú importy a deklarácie.
Kód, ktorý sa nedá napísať dvakrát, sa nemôže rozísť.

## Časť IV > Čo teraz odmietne

Testy nemenia tvrdenia o tom, čo je správne správanie.
Pribudol test, ktorý porovná tabuľku s dispatchom a s helpom,
a test, ktorý prejde každú deklarovanú voľbu.
Ten druhý je dôležitý, pretože odhalí voľbu, ktorú parser pozná
a príkaz ju nikdy neprečíta.

Overené príkazy a ich odpovede:

```
$ php bin/cms queue:work --lmit=3
cms: unknown option "--lmit" for queue:work; known: --limit=

$ php bin/cms module:remove Blog --forse
cms: unknown option "--forse" for module:remove; known: --force
```

Obe skončia s exit 1 a nič neurobia.
To je iný druh istoty než „test prešiel“:
zlyhanie je vidieť skôr, než sa zapíše riadok do databázy.

Celý strom má po zmene 1 138 testov a 7 415 asercií,
`make extract-check` hovorí 186 identických a 102 zaznamenaných odchýlok
a `make admin-smoke` prejde vrátane `queue:work` a `queue:prune`.
Smoke je tu dôležitejší než unit test, lebo bootuje server
a volá tie isté príkazy, aké volá operátor.

## Časť V > Čo to stálo a čo zostalo

Cena je jedna trieda v kengine a jeden záznam v schválenom inventári.
Úspora je menej vidieť, lebo sa neprejaví ako riadok:
štvrtý príkaz už nemôže mať vlastné pravidlo pre `--name value`.

Pravidlo „parser vlastní kernel“ má aj hranice a je lepšie ich pomenovať
než ich neskôr objaviť.
`user:admin` odpovedá na `--help` a `-h` pred parserom,
lebo to nie sú voľby, ktoré príkaz číta;
globálny `--help` by bol nový kontrakt, nie migrácia.
Kernelové riadky v helpe stále píšu len `--limit=N`,
hoci parser prijme aj `--limit N`,
pretože test kontraktu z tých riadkov ťahá tokeny do tabuľky
a obe formy by mu rozbili extrakciu.
Pod tabuľkou je preto jedna veta, ktorá to hovorí za všetky riadky naraz.

A posledná hranica: parser nerozozná preklep v pozícii.
`user:admin a@b.c pass` je platné volanie,
`user:admin a@b.c pas` je tiež platné a heslo bude `pas`.
To sa nedá vyriešiť tabuľkou, pretože pozícia nemá meno,
a preto to zostáva na používateľovi.

Zvyšok je už len dôsledok jedného rozhodnutia:
keď sa pravidlo sťahuje na jedno miesto, ubúdajú miesta,
kde môže byť odpoveď iná.
