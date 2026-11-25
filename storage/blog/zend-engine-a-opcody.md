---
title: "Zend engine a opcody ... ako PHP naozaj beží"
date: "2026-08-15"
version: "1.0.2"
tags: ["php", "zend", "engine", "performance"]
excerpt: "Čo je Zend engine, ako PHP premení zdroják na opcody a prečo je celý interpreter napísaný v C ... prvá časť série o tom, prečo je PHP v skutočnosti sada prekompilovaných C chunkov."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "zend-engine-and-opcodes"
published: true
---

Keď napíšeš PHP skript a pošleš request, čo sa stane? Väčšina
vývojárov povie „spustí sa to“. Ale medzi tvojím zdrojákom a odpoveďou beží
celý reťazec krokov, ktoré má na starosti **Zend engine** ... a práve tam
začína odpoveď na otázku, prečo je PHP rýchle, keď ho používaš správne.
Toto je prvá časť série o tom, ako PHP naozaj funguje a prečo je v jadre
sadou prekompilovaných C chunkov.

PHP nie je interpretovaný jazyk v tradičnom zmysle. Zdroják sa neposúva riadok po riadku. Najprv sa skompiluje do opcodov — a až tie sa vykonávajú. Kto to nevie, nerozumie, prečo OPcache funguje.

## Čo je Zend engine

Zend engine je **srdce PHP** ... časť, ktorá tvoj skript skompiluje
a vykoná. Keď hovoríme „PHP 8.5“, v skutočnosti hovoríme o PHP interpreteri,
ktorého jadro je Zend engine. Zvyšok (štandardná knižnica, rozšírenia,
bindings) sa naň napája.

Dôležité: Zend engine **nie je klasický kompilátor**. Neprekvapivo
nevyrába `.exe` ani strojový kód dopredu. Je to kombinácia dvoch vecí:

1. **Kompilátor** ... premení zdroják na **opcody** (bajtkód podobný assembleru).
2. **Virtuálny stroj (Zend VM)** ... tie opcody vykonáva.

To je presne ten model, ktorý poznáš z Javy (bytecode + JVM) alebo
z .NET (IL + CLR). PHP len túto prácu robí pri každom requeste ... pokiaľ
nemáš OPcache, o ktorom bude reč v tretej časti série.

## Ako PHP spracuje skript

Reťazec vyzerá takto:

```
zdroják → lexing → parsing → kompilácia → opcody → exekúcia (Zend VM)
```

### 1. Lexing ... zo znakov na tokeny

Lexer prečíta zdroják a rozdelí ho na **tokeny**. Napríklad:

```php
<?php
echo "Ahoj";
```

sa stane niečo ako `T_OPEN_TAG`, `T_ECHO`, `T_CONSTANT_ENCAPSED_STRING`,
`;` ... každý token je už kategorizovaný: „toto je kľúčové slovo echo“,
„toto je reťazec“, „toto je bodkočiarka“.

### 2. Parsing ... z tokenov na strom

Parser vezme tokeny a postaví z nich **AST** (abstract syntax tree) ...
stromovú štruktúru, ktorá zachytáva logiku tvojho kódu: `echo` je uzol
s jedným dieťaťom ... reťazec „Ahoj“.

### 3. Kompilácia ... zo stromu na opcody

Kompilátor prejde AST a vyrobí z neho **opcody** ... jednoduché inštrukcie
pre Zend VM. Pre náš príklad:

```text
line  op                          return
----  --------------------------  ------
2     ECHO „Ahoj“                 2
      RETURN                      1
```

Opcode je atomická inštrukcia: „vypíš tento reťazec“, „vráť hodnotu“,
„sčítaj dve premenné“, „zavolaj túto funkciu“. Je to najnižšia úroveň,
na ktorej PHP reprezentuje tvoj kód ... a je úplne nezávislá od syntaxe,
v ktorej si ho napísal.

### 4. Exekúcia ... Zend VM spúšťa opcody

Zend VM berie opcody jeden po druhom a každý vykonáva cez špeciálny
**handler**. A tu prichádza kľúčová vec: tie handlery sú napísané v C.

## Opcody sú prekompilovaný kód

V momente, keď je skript skompilovaný do opcodov, zdroják už nie je
potrebný. Zend VM pracuje výhradne s opcodmi ... a ak ich OPcache
podrží v pamäti, nasledujúci request už nemusí robiť lexing, parsing
ani kompiláciu. Skript je „prekompilovaný“ presne v tom zmysle, v akom
Astro predrenderuje stránky do hotového HTML: práca sa spraví raz,
a potom sa len doručuje.

Ak chceš opcody vidieť na vlastné oči, stačí VLD rozšírenie
(Vulcan Logic Disassembler):

```bash
php -dvld.active=1 script.php
```

Nie je súčasťou PHP ... doinštaluje sa zvlášť ... ale ukáže ti presne to,
čo Zend engine z tvojho kódu vyrobil.

## A celé je to v C

Zend engine je napísaný v C. Jeho hash tabuľky, zval (hodnota premennej),
spracovanie reťazcov (`zend_string`), exekučná slučka ... všetko C.
Keď tvoj opcode `ECHO "Ahoj"` beží, vykoná ho C funkcia. Keď voláš
`str_replace`, `json_encode` alebo PDO ... voláš C funkciu. Dokonca aj
samotné vykonávanie tvojho kódu (Zend VM) je C kód.

To je doslova to, o čom hovoríme v článku o filozofii nášho stacku
([„PHP C chunks nie je metafora“](/blog/filozofia-super-rychleho-php)):
PHP je tenká vrstva syntaxe nad obrovskou knižnicou C funkcií. Zend
engine je dôkaz ... nie slogan.

## Zhrnutie

- **Zend engine** = kompilátor + virtuálny stroj (Zend VM), srdce PHP
- Reťazec: zdroják → tokeny (lexing) → AST (parsing) → **opcody** (kompilácia) → exekúcia
- **Opcode** = atomická inštrukcia pre Zend VM (`ECHO`, `RETURN`, volania funkcií…)
- Opcody sú „prekompilovaný“ kód ... zdroják netreba, OPcache ich drží v pamäti
- **Zend engine je napísaný v C** ... každý opcode, každá built-in funkcia, každý bežiaci kód je nakoniec C
- VLD rozšírenie ukáže opcody: `php -dvld.active=1 script.php`

V ďalšej časti série si prejdeme históriu ... ako sa PHP a Zend engine
dostali od nástroja jedného človeka až po dnešné PHP 8.5.

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — bezpečnostné best practices
- [W3C — Web Standards](https://www.w3.org/standards/) — web štandardy
