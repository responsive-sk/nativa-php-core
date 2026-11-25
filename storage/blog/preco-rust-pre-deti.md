---
title: "Rust a Lekalo pre deti ... diel 1: prečo Rust pre PHP vývojára"
series: "Rust & Lekalo"
series-part: "1"
date: "2026-09-06"
version: "1.4.2"
tags: ["rust", "lekalo", "architektura", "tooling", "zaciatocnici"]
excerpt: "Nová séria. Rust je jazyk, v ktorom je napísaný Lekalo — nástroj na sémantické modelovanie, ktorý analyzuje tento PHP projekt. Diel 1 vysvetľuje, čo Rust je (bez GC, bez runtime, skompilovaný binárny súbor), prečo by to PHP vývojára malo zaujímať, a mapuje celú šesťdielnu sériu."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "why-rust"
published: true
---

> Toto je diel 1 série **Rust a Lekalo**. Mapa série: [diel 2 ... ownership a
> borrow checker](/blog/ownership-pre-deti), [diel 3 ... Rust tooling](/blog/rust-cli-pre-deti),
> [diel 4 ... kvalita v Ruste](/blog/rust-testovanie-pre-deti),
> [diel 5 ... sémantický model aplikácie](/blog/semanticky-model-pre-deti) a
> [diel 6 ... Lekalo deep dive](/blog/lekalo-pre-deti). Dnes si povieme, prečo
> by PHP vývojára mal zaujímať jazyk, ktorý nikdy nepoužije na písanie webovej
> aplikácie.

Si PHP vývojár. Píšeš webové aplikácie. Niekto povie „Rust“ a tvoj mozog si to
potichu založí do šuplíka s nápisom „systémové programovanie, nie pre mňa“.
Táto séria existuje preto, aby ten šuplík spochybnila — pretože
najzaujímavejšia vec, ktorá sa deje v tomto repozitári, je analyzovaná
nástrojom napísaným v Ruste.

Ten nástroj sa volá **Lekalo** — sémantický model aplikácie a „compiler“ pre
AI-assisted development. Použili sme ho na to, aby sme postavili model
Users modulu tohto kitu (auth, RBAC, password reset) a aby sme odpovedali na
otázky typu *„čo sa rozbije, keď zmením entitu `User`?“* — bez čítania
jediného riadku kódu. Diel 6 je celý o ňom. Diely 1 až 5 sú most, ktorý
k nemu vedie.

## Čo Rust je

Rust je **kompilovaný** jazyk. `rustc` (alebo cargo, jeho build nástroj)
premení tvoj zdroják na natívny binárny súbor — jeden spustiteľný súbor,
ktorý na cieľovej mašine nepotrebuje žiadny interpreter. Z toho vyplývajú dve
vlastnosti, a obe sú presným opakom toho, čo ti dáva PHP:

**Žiadny garbage collector.** PHP ti pamäť uvoľňuje — reference counting a
cycle collector, neviditeľne, v nepredvídateľných momentoch. Rust ti pamäť
uvoľňuje tiež, ale **v čase kompilácie**: každá hodnota má presne jedného
vlastníka a v momente, keď vlastník opustí svoj scope, je pamäť uvoľnená.
Deterministicky. Žiadne pauzy, žiadne prekvapenia, nič na ladenie.

**Žiadny runtime.** Keď príde PHP request, Zend engine nabootuje, načíta kód,
vykoná ho a všetko zase zmetie. Runtime je s tebou vždy. Rust binárka runtime
nemá — „runtime“ sa počas buildu skompiloval preč. Spustiteľný súbor *je*
aplikácia.

## Príbeh runtime — z tohto repozitára

Tento kit stojí na filozofii, ktorá zrazu robí Rust povedomým: zero-dependency,
self-contained, minimálne. Pozri sa na `src/init.php` — PSR-4 `autoload_register`
fallback, ktorý nabootuje celú aplikáciu **bez** Composer autoloaderu. Žiadny
framework runtime na inštaláciu, žiadny vendor adresár na deploy, len PHP
a zopár súborov.

```php
// src/init.php — „runtime" tohto kitu je pár stovák riadkov PHP
spl_autoload_register(static function (string $class): void {
    // vyrieš Modules\* a Templates\* zo src/ a Templates/
});
```

Rust odpovedá na otázku „môžem deploynúť bez runtime?“ radikálnejšie: žiadny
runtime neexistuje, pretože ho compiler upiekol do jedného statického
binárneho súboru. Skopíruješ jeden súbor na server a beží. Duch `src/init.php`
— „najmenší životaschopný základ“ — je pre Rust default, nie tvrdo
vydobytá výhra.

Rovnaký inštinkt stojí aj za `bin/verify-build.php` v tomto repu: build
verifikácia, ktorá výstup *zmluvne* kontroluje (žiadny `@layer`, ES2015,
self-hosted assety) namiesto toho, aby mu dôverovala. Rust projekty žijú na
tom istom inštinkte — zmluvy, overené v čase buildu, ktoré kričia pri
probléme. Viac v dieli 4.

## Pravda nesie typ

Ownership stráži pamäť. Rust ale stráži niečo hlbšie ... **pravdy**.

V C je boolean bit. `true` alebo `false` — bez otázky, *kedy* platí,
za akého stavu, odkiaľ prišiel. Tajomná pravda, ktorá platí, lebo platí.

PHP má svoju verziu tej istej choroby ... voľné porovnanie
`"0" == false`. Pravda bez deklarovaného typu.

Rust takú pravdu odmieta preložiť. Namiesto bitu dáva enum a `match`,
ktorý musí byť vyčerpávajúci — zabudnutý stav nie je varovanie, je to
chyba kompilácie:

```rust
// pravda s kontraktom — kedy a za akého stavu
match session {
    Session::Fresh { .. } => Outcome::Allow,
    Session::HalfOpen { remaining } => Outcome::Challenge(remaining),
    Session::Expired => Outcome::Deny,
}
```

| | bit v C | enum v Rustu |
|---|---|---|
| čo nesie | hodnotu | hodnotu + stav + zdroj |
| kedy platí | pokým sa neprepíše | vo scope, ktorý deklaruje |
| zabudnutý prípad | ticho prejde | kompilátor kričí |

Toto repozitár nesie obe strany príbehu. `Period`
(`src/Modules/Cardboard/Domain/Model/Period.php`) je enum ... výber
s typom. `Comment::STATUS_PENDING`
(`src/Modules/Comments/Domain/Entities/Comment.php`) je reťazcová
konštanta ... pravda bez kompilátora: PHP neznámy status neodmietne,
poistí ho test (`CommentsTest`).

Kde jazyk nedá pravde typ, tam zmluvu dopĺňa test. Rovnaká práca,
rozdelený náklad.

**Ako dlho pravda platí.** V PHP je dĺžka pravdy skrytá — request
narazí, kontext žije, proces zomrie a s ním všetky otázky. Rust tú
dĺžku deklaruje: **lifetime** (`'a`) je parameter, ktorý hovorí, ako
dlho odkaz smie žiť. „Ako dlho sme už mimo kontextu“ tak prestáva byť
pocit ... stáva sa hranou, ktorú číta kompilátor.

**Lock ako zamrazená pravda.** `Cargo.lock` ani `lekalo.lock` nie sú
katastrofa ... sú výhoda. Verzie v grafe sa zamrazia a každý ďalší
build sa overuje proti zamrazenej pravde. Toto repozitár to robí
rovnako: `lekalo.lock` je commitnutý, `lekalo-check` je gate a
`composer audit --locked` beží v CI. Audit bez zamrazenia audituje mlhu.

Cena? Slovná zásoba navyše. Silnejšia pravda sa nepíše zadarmo ...
ale je to rovnaká výmena, ktorú robí tooling z dielu 3.

## Kedy sa Rust oplatí (a kedy nie)

Buďme úprimní: Rust je zlý fit na typické CRUD webové aplikácie. PHP model —
request dnu, HTML von, shared-nothing procesy — je na tú prácu *presne*
správny. Starter kit existuje, lebo PHP je na tú prácu stále vhodný nástroj na rýchle
stavanie webových aplikácií.

Rust sa oplatí inde: v **toolingu**. Čokoľvek, čo je

- dlhobežné (server, watcher, daemon),
- výpočtovo náročné (analyzer, compiler, search index),
- alebo bezpečnostne kritické (parsovanie nedôveryhodného vstupu, práca so
  secretmi),

ťaží z Rustovej kombinácie výkonu a garancií v čase kompilácie. To je
klasické teritórium „vymeň shell script za poriadny nástroj“ — a presne tam
žije **Lekalo**: CLI, ktoré načíta model, postaví graf, odpovie na impact
otázky a garantuje byte-identical výstup. Taký nástroj by si v PHP písal
nerád (potreboval by runtime, dlhobežný proces, opatrné narábanie s pamäťou)
a v Ruste je to nudne bezpečné.

## Čo bude ďalej

Pred toolingom a modelmi potrebuješ ten jeden koncept, ktorý robí Rust
bezpečným *bez runtime*: **ownership**. Je to rozdiel medzi „runtime to
skontroluje za behu“ a „compiler to skontroluje v čase kompilácie“ — a je to
to, čo PHP vývojárov na prvý deň najviac mýli, lebo PHP polia sa potichu
kopírujú a Rust hodnoty sa potichu presúvajú. To je [diel 2](/blog/ownership-pre-deti).

A na konci cesty [diel 6](/blog/lekalo-pre-deti) ukáže odmenu: Lekalo
analyzujúce *tento* projekt — Users modul s 53 sémantickými definíciami,
106 hranami závislostí a impact reportom, ktorý nám povie, čo všetko sa týka
entity `User`, skôr než sa dotkneme kódu.

## Zdroje

- [The Rust Programming Language](https://doc.rust-lang.org/book/) — oficiálna kniha o Ruste
- [Rust: what and why](https://www.rust-lang.org/what/rust) — oficiálny úvod
- [The Cargo Book](https://doc.rust-lang.org/cargo/) — build nástroj a package manager
- [Nativa Minimal Documentation](/docs/getting-started) — pozadie zero-dependency bootstrapu tohto kitu
