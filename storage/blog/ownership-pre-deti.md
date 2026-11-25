---
title: "Rust a Lekalo pre deti ... diel 2: ownership — tá jedna myšlienka, ktorá robí Rust bezpečným bez runtime"
series: "Rust & Lekalo"
series-part: "2"
date: "2026-09-06"
version: "1.0.2"
tags: ["rust", "lekalo", "architektura", "zaciatocnici", "pamat"]
excerpt: "Diel 2 série. PHP uvoľňuje pamäť garbage collectorom; Rust ju uvoľňuje v čase kompilácie. Myšlienka, ktorá to umožňuje, je ownership — a správa sa úplne inak ako PHP polia. Porovnáme copy-on-write s move sémantikou a pozrieme sa na reálnu hranicu z tohto repa."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "rust-ownership"
published: true
---

> Toto je diel 2 série **Rust a Lekalo**. V [dieli 1](/blog/preco-rust-pre-deti)
> sme si povedali, že Rust nemá garbage collector ani runtime — pamäť sa
> uvoľňuje **v čase kompilácie**, deterministicky. Dnes sa pozrieme na to, kto
> rozhoduje, kedy sa hodnota môže uvoľniť. Ďalšie diely: [diel 3 ...
> Rust tooling](/blog/rust-cli-pre-deti), [diel 4 ... kvalita v Ruste](/blog/rust-testovanie-pre-deti),
> [diel 5 ... sémantický model](/blog/semanticky-model-pre-deti) a
> [diel 6 ... Lekalo deep dive](/blog/lekalo-pre-deti).

V [dieli 1](/blog/preco-rust-pre-deti) sme povedali, že Rust nemá garbage
collector ani runtime — pamäť sa uvoľňuje **v čase kompilácie**. Ale za tým
tvrdením sa skrýva otázka: ak žiadny runtime nezametá pamäť, tak *kto*
rozhodne, kedy sa hodnota môže uvoľniť? Odpoveď je najdôležitejšia myšlienka
v Ruste — a tá, ktorá PHP vývojárovi pripadá najcudzejšia:

**Každá hodnota má presne jedného vlastníka. Keď sa scope vlastníka skončí,
hodnota sa uvoľní.**

To je celé. Žiadny collector, žiadny zametač, žiadna pauza „stop the world“.
Jedno pravidlo, vynútené kompilátorom, ktoré mení pamäťové chyby na chyby
kompilácie. V tomto dieli si povieme, ako sa pravidlo líši od PHP
modelu a čo má spoločné s metódou, ktorú si v tomto repozitári už možno čítal.

## PHP: copy-on-write, neviditeľné zdieľanie

PHP polia sú známe tým, že kopírovanie je lacné. To preto, lebo PHP ich nekopíruje — používa **copy-on-write**: dve premenné môžu ukazovať na
to isté pole v pamäti a PHP duplikuje dáta až v momente, keď jedna z nich
*zapíše*.

```php
$user = ['name' => 'Eva', 'roles' => ['admin']];
$copy = $user;                  // lacné — zdieľajú jedno pole v pamäti
$copy['roles'][] = 'editor';    // teraz PHP kopíruje, lebo nastal zápis
```

Toto je neviditeľné a zhovievavé. Môžeš odovzdať to isté pole piatim
funkciám a PHP si zdieľanie a kopírovanie vyrieši za teba. Nikdy nemyslíš na
to, kto `$user` „vlastní“ — a to je presne ten problém. Rust hovorí: ak to
nikto nevlastní, tak *kedy* sa to uvoľní, je cudzí problém runtime. PHP si
kúpilo túto slobodu tým, že prijalo runtime (a collector).

## Rust: move, nie copy

V Ruste priradenie hodnoty inému menu hodnotu nekopíruje — **presúva**
vlastníctvo.

```rust
let user = User::new("Eva");
let moved = user;   // vlastníctvo `user` sa presúva do `moved`
// println!("{user}"); // chyba kompilácie: use of moved value
```

Po presune `user` neexistuje. Kompilátor to vie a použitie starého mena je
chyba kompilácie — nie crash, nie null, nie nedefinovaná hodnota. Chyba
kompilácie, skôr než program vôbec beží. To je tá výmena: PHP ti dovolí
zdieľať všetko a platí runtime; Rust robí vlastníctvo explicitné a platí
prísnosťou v čase kompilácie.

Malé hodnoty, ktoré je lacné kopírovať (čísla, booly), implementujú `Copy`
a priradením sa stále kopírujú. Ale čokoľvek s reálnou váhou — string,
databázový riadok, request — sa *presúva*. A ak chceš hodnotu *použiť* bez
toho, aby si ju vlastnil, požičiaš si ju.

## Požičiavanie: veľa čitateľov, jeden spisovateľ

Ownership by bol nepoužiteľný, keby sa hodnoty nedali zdieľať. Preto má
Rust **požičiavanie**: referencia `&T`, ktorá ti dovolí hodnotu čítať bez
toho, aby si ju vlastnil.

```rust
fn count_roles(user: &User) -> usize { user.roles.len() }

let n = count_roles(&user); // požičanie — `user` stále vlastníš a môžeš použiť
```

Borrow checker je tá časť kompilátora, ktorá tieto referencie stráži. Jeho
pravidlá znejú ako pravidlá vyhadzovača: buď **jeden spisovateľ**, alebo
**veľa čitateľov** — nikdy oboje naraz. Ak sa pokúsiš rozdať mutovateľnú
referenciu, kým žije iná referencia, program sa neskompiluje. Data races —
dve časti kódu, ktoré súčasne mutujú tú istú pamäť — sa stávajú nemožnými,
pretože kompilátor zakazuje vzor, ktorý ich spôsobuje.

Toto je ten „borrow checker“ z dielu 1: disciplína, ktorú PHP vynucuje
varmi a disciplínou (alebo ju potichu ignoruje), si Rust vynucuje v čase
kompilácie, pre každý program, vždy.

## Scope je miesto uvoľnenia

Keďže každá hodnota má jedného vlastníka a scope vlastníka končí na
zatváracej zátvorke, pamäť sa uvoľňuje **keď sa scope skončí** — vzor
sa volá RAII (resource acquisition is initialization) a neplatí len pre
pamäť. Súbory, locky, databázové spojenia: čo získavaš, to uvoľňuješ tým,
že hodnotu dropneš. Žiadne `finally` bloky, žiadny `unset()`, žiadne
zabúdanie.

```rust
{
    let handle = File::open("app.log")?; // získané tu
    // ... práca s handle ...
} // handle sa tu automaticky zavrie a uvoľní
```

Porovnaj s PHP, kde súbor zatváraš cez `fclose()`, lock uvoľňuješ cez
`unlock()` a dúfaš, že si nezabudol. Rust odpovedá: scope *je* upratovanie.

## Hranica z tohto repa: `User::fromRow()`

Tu je spojenie s kódom, ktorý si môžeš otvoriť hneď teraz. V
`src/Modules/Users/Domain/Entities/User.php` sa entita vytvára z raw
databázového riadku:

```php
public static function fromRow(array $row): self
{
    return new self($row);
}
```

Pozri sa, čo robí konštruktor s tým raw riadkom: normalizuje `is_active` na
poriadny boolean a `last_login_at` na nullable string — **raz**. Komentár v
kóde to hovorí najlepšie: *“Normalized once at the boundary — no re-guessing
per call.“*

Toto je ownership myslenie v PHP šatách. Raw riadok **prekročí hranicu**
(databáza → doména) a na tej hranici je skonzumovaný a premenený na typovaný,
vlastnený objekt. Potom je raw tvar riadku už niekoho iného starosť — entita
vlastní svoj stav a nič si znova neodvodzuje. Každý volajúci dostane
konzistentného, normalizovaného `User` namiesto hádania, čo bolo v
`$row['is_active']`.

V Ruste by tá istá hranica bola explicitná v typovom systéme — funkcia,
ktorá riadok *skonzumuje* a vráti entitu:

```rust
fn from_row(row: Row) -> User { /* row je presunutý dnu, skonzumovaný, preč */ }
```

— znemožňuje použiť riadok potom, a to konštrukčne. Normalizácia sa stane
raz, lebo riadok sa fyzicky nedá prečítať dvakrát.

Princíp je v oboch jazykoch rovnaký: **normalizuj na hranici, potom
vlastni výsledok.** Rust má len kompilátor, ktorý to „raz“ vynucuje.

## Čo ti ownership dáva

- **Žiadny use-after-free** — kompilátor pozná životnosť hodnoty a odmieta
  kód, ktorý sa dotýka uvoľnenej pamäte.
- **Žiadny double-free** — jeden vlastník znamená jedno uvoľnenie,
  konštrukčne.
- **Žiadne data races** — pravidlo jedného spisovateľa sa kontroluje v čase
  kompilácie.
- **Deterministické upratovanie** — scope je miesto uvoľnenia; žiadne GC
  pauzy.

PHP tieto chyby nemá, lebo im runtime predchádza — za cenu runtime. Rust ich
nemá, lebo im predchádza kompilátor — za cenu naučiť sa myslieť vo
vlastníkoch a požičaniach. Tvrdenie z dielu 1 bolo, že z toho robí Rust
správny nástroj na dlhobežný, bezpečnostne kritický tooling. Lekalo, nástroj
na konci tejto série, stojí presne na týchto garanciách: CLI, ktoré parsuje
modely, stavia grafy a pri tom nikdy nesmie poškodiť pamäť.

## Čo bude ďalej

Ownership je mentálny model; **tooling** je to, ako s ním žiješ deň čo deň.
Diel 3 sa pozrie na cargo workspaces, CLI a jednu vec, ktorú Rust robí
lepšie než takmer čokoľvek iné — deterministický, byte-identical výstup,
ktorý je presne to, čo robí Lekalo `graph export` reprodukovateľným:

[ďalej: Rust tooling](/blog/rust-cli-pre-deti)

## Zdroje

- [The Rust Book — Understanding Ownership](https://doc.rust-lang.org/book/ch04-00-understanding-ownership.html) — ownership, borrowing, move sémantika
- [The Rust Book — Smart Pointers](https://doc.rust-lang.org/book/ch15-00-smart-pointers.html) — RAII a vlastníctvo zdrojov
- [PHP Manual — Arrays](https://www.php.net/manual/en/language.types.array.php) — pozadie copy-on-write
