---
title: "C rozšírenia ... ako sa k Zend enginu pripája C kód"
date: "2026-08-18"
version: "1.0.2"
tags: ["php", "c", "extensions", "zend"]
excerpt: "Ako vzniká PHP rozšírenie v C, čo je PHP-C API a prečo je SQLite v C najrýchlejšia cesta k dátam ... štvrtá časť série, kde sa „PHP C chunks“ stáva doslova pravdivým."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "php-c-extensions-api"
published: true
---

Doteraz sme hovorili, že PHP je v jadre C ... engine, opcody, JIT. Teraz si
ukážeme, ako sa k tomu C kód **pripája zvonka**: cez **rozšírenia**.
Práve tu sa fráza „PHP C chunks“ stáva doslova pravdivou ... keď zavoláš
`json_encode` alebo otvoríš SQLite databázu, vykoná sa C kód, ktorý je do
PHP zapojený ako knižnica.

Keď zavoláte `json_encode` alebo otvoríte SQLite databázu, vykoná sa C kód. Nie PHP. C. A tento C kód je do PHP zapojený ako knižnica, cez rozšírenie.

PHP samo o sebe je pomalý interpret. Ale keď potrebujete pracovať s JSON, obrázkami, kryptografiou alebo databázou, nemôžete čakať, kým PHP interpretuje každý riadok. Potrebujete C.

## Čo je PHP rozšírenie

Rozšírenie je **zdieľaná knižnica napísaná v C** (súbor `.so`), ktorá sa
do PHP načíta cez `php.ini`:

```ini
extension=foo.so
```

Po načítaní zaregistruje funkcie, triedy a konštanty presne ako
všetky ostatné ... z pohľadu PHP kódu nie je rozdiel medzi `str_replace`
(ktorý je v jadre) a funkciou z tvojho rozšírenia. Obe sú C funkcie.

## Ako rozšírenie vyzerá

Základom je **PHP-C API** ... sada C makier a štruktúr, ktorými sa
rozšírenie pripojí k Zend enginu. Najjednoduchšie rozšírenie, ktoré
pridá funkciu `hello()`, vyzerá takto:

```c
/* ext/hello/hello.c */
PHP_FUNCTION(hello)
{
    RETURN_STRING("Ahoj z C!");
}

/* registrácia funkcií pre PHP */
const zend_function_entry hello_functions[] = {
    PHP_FE(hello, NULL)
    PHP_FE_END
};
```

- `PHP_FUNCTION(hello)` ... makro, ktoré definuje C funkciu volateľnú
  z PHP
- `RETURN_STRING(...)` ... vráti hodnotu cez `zval`, internú reprezentáciu
  PHP hodnoty v C
- `zend_function_entry` ... tabuľka, ktorá hovorí Zend enginu: „tieto
  funkcie ponúkam“

Nový skeleton sa vygeneruje jediným príkazom:

```bash
cd ext && ./ext_skel --extname=hello
```

A potom stačí `phpize && ./configure && make` ... a máš C kód bežiaci
ako natívna PHP funkcia.

## zval a zend_string ... C reprezentácia PHP

Pod kapotou sú všetky PHP hodnoty C štruktúry:

- **zval** (Zend Value) ... univerzálna reprezentácia premennej: integer,
  string, array, object…
- **zend_string** ... reťazec s dĺžkou a hashom v hlavičke, takže
  vyhľadávanie v asociatívnych poliach nemusí reťazec hashovať znova

Keď tvoj PHP kód robí `$user['name']`, Zend engine hľadá v C hash
table pomocou `zend_string` ... a toto je presne dôvod, prečo sú PHP
polia tak rýchle: nie je to „interpretované pole“, je to C hash table.

## SQLite v C ... najrýchlejšia cesta k dátam

A teraz tá časť, ktorá je pre náš stack najdôležitejšia. Celý SQLite je
**C knižnica** ... jeden `.c` súbor so stovkami tisíc riadkov C kódu.
PHP k nemu pristupuje cez tenké bindings (`pdo_sqlite`, `sqlite3`),
ktoré sú tiež C.

Keď náš kód beží:

```
PHP kód → pdo_sqlite (C) → SQLite engine (C) → súbor .db
```

Medzi naším kódom a dátami nie je žiadna interpretovaná vrstva. Žiadny
ORM, žiadny query builder, žiadny „PHP server“. Priamo C volania do
C knižnice, ktorá číta dáta zo súboru. To je dôvod, prečo per-module
SQLite architektúra nášho stacku beží tak rýchlo: každý dotaz je len
pár C volaní.

## Kedy písať rozšírenie (a kedy nie)

Vlastné C rozšírenie dáva zmysel, keď máš naozaj kritický výpočet,
ktorý v PHP interpretácii nevládze. Pre drvivú väčšinu webov ... vrátane
nášho ... to netreba: všetko podstatné (reťazce, regex, JSON, SQLite,
hashing) už je C. Písať rozšírenie, aby si „zrýchlil“ to, čo už je C
volanie, je presne tá vrstva navyše, proti ktorej je celá táto séria.

- **Rozšírenie** = zdieľaná C knižnica (`.so`), ktorá do PHP registruje
  funkcie a triedy cez **PHP-C API**
- `PHP_FUNCTION()` + `zend_function_entry` ... ako sa C funkcia stane
  PHP funkciou; skeleton cez `ext_skel`
- **zval** a **zend_string** ... PHP hodnoty sú pod kapotou C štruktúry;
  PHP polia sú C hash tables
- **SQLite je celý v C** ... naše dotazy sú priame C volania, žiadna
  interpretovaná vrstva medzi kódom a dátami
- Vlastné rozšírenia sa oplatí písať len pre naozaj kritické výpočty ...
  inak je to vrstva navyše

Záverečná časť série bude o filozofii: prečo „menej vrstiev“ nie je
len preferencia, ale prirodzený záver celej histórie PHP ... a čo to
znamená pre náš stack.


→ [Zend engine a opcody](/blog/zend-engine-a-opcody): ako PHP naozaj beží
→ [OPcache a JIT](/blog/opcache-a-jit-prekompilacia): prekompilácia na úrovni jazyka
→ [História PHP](/blog/historia-php-od-fi-po-8): od nástroja jedného človeka po Zend Engine 4

## Zdroje

- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP štandardy
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): bezpečnostné best practices
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
- [W3C, Web Standards](https://www.w3.org/standards/): web štandardy
