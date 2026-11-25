---
title: "Rust a Lekalo pre deti ... diel 12: obálky ako kontrakt"
series: "Rust & Lekalo"
series-part: "12"
date: "2026-09-16"
version: "1.0.2"
tags: ["rust", "lekalo", "determinizmus", "blog", "architektura"]
excerpt: "Ranný commit „regenerate all covers\" pretrel identitu celej série, lebo jediná pravda o nej žila v rukách toho, kto práve spúšťal CLI. Nový generátor obálok presúva identitu tam, kde vec žije — do článku — a obálku mení na dôkaz."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "covers-as-contracts"
published: true
---

V [dieli 11](/blog/mapa-a-teren-pre-deti) bola mapa zastaraná a terén
pravdivý. Dnes ten istý týždeň pokračuje: stojí pred nami séria s
jedenástimi dielmi, ktorá má vyzerať ako séria — a nástroj, ktorý o tom
rozhoduje, to vôbec nevedel.

## Čo sa stalo

Obálky blogu generuje CLI. Má prepínače: šablóna, farba. Ráno niekto
spustil regeneráciu všetkého — a prepínače vtedy odkázali na default.
Rust šablóna celej série (hexagóny, oranžová) sa pretrela na modrý
kód. Nikto to nechcel; nástroj nemal z čoho vedieť, že tých dvadsať
článkov patrí dokopy. Jediná pravda o identite série žila v rukách
toho, kto práve písal príkaz.

To nie je chyba jedného behu. Je to chýbajúci kontrakt.

## Štyri pravidlá nového generátora

Generátor sa prepísal. Nie pre krajšie hexagóny — pre štyri pravidlá,
ktoré z neho robia nástroj, ktorému možno veriť. Riešenie sa nezačalo
kódom, ale otázkou: kde má tá-ktorá pravda žiť?

**Prvé: rovnaký článok, rovnaké bajty.** Žiadne časové pečiatky,
triedené výpisy, pozíčné pravidlá namiesto „náhodného riadku“. Dva
behy musia dať identický súbor. Overené md5.

**Druhé: identita série je vlastnosť článku.** Dva riadky vo front
matter a nástroj vie, čo má robiť aj bez prepínačov:

```diff
 title: "Rust a Lekalo pre deti ... diel 12"
+series: "Rust & Lekalo"
+series-part: 12
```

Sériová identita sa hasuje na jednu dvojicu šablóna-farba pre
všetkých členov. Prepínače CLI ostali len ako override — „regenerate
all“ už nemôže nič pretrieť, lebo default sa číta z článku, nie z
rúk operátora.

**Tretie: obálka je dôkaz.** Na obálke je terminálové okno s výňatkom
z článku — reťazom `cover-code:` → prvý `diff` blok → prvý kódový
blok. A kontraktový test, ktorý nad každým vydaným článkom kontroluje,
že riadky na obálke sú doslova v článku. Kód na obálke nemôže byť
ozdoba; je to citát s určeným zdrojom.

**Štvrté: regenerácia mení URL.** Obálky sa servujú s ročným
`Cache-Control: immutable` — a práve sme sa presvedčili, že po
regenerácii prehliadač servuje rok starú pravdu. Lokálna obálka má
preto dnes `?v=` hash obsahu: nové bajty, nová adresa.

## Prečo to nie je kosmetika

Diel 11 končil otázkou: čomu smie agent veriť, keď číta mapu?
Odpoveď bola: kontraktom — veciam, ktoré niečo kontroluje. Generátor
je malý príklad veľkej zásady: **vlastnosť veci patrí do veci, nie do
rúk operátora.** Rust má rovnaký inštinkt inak — nič nenosí v
„hlave“ kompilátora; typy, traitove hranice a doc-testy žedia tam,
kde žije kód, a stroj ich kontroluje pri každom behu. Kontrakt, ktorý
sa nekontroluje, je ozdoba. Kontrakt, ktorý sa kontroluje, je mapa,
ktorá starne spolu s terénom.

A terén sa hneď prihlásil. Číslo 964, ktoré [diel 11](/blog/mapa-a-teren-pre-deti)
ešte ráno dopisoval do mapy, doobeda zastaralo: sadu dopĺňajú
kontraktové testy generátora a mapa hovorí 1353 testov. Vzorec z dielu
11 sa zopakoval naživo — číslo v dokumentácii je tvrdenie s dátumom
expirácie, a jediné, čo ho udržiava pravdivým, je beh gatí. Doklad v
reálnom čase: než tento text vyšiel, zastaralo aj 1353 — články série
priniesli ďalších šesť testov a mapa hovorí 1359.

---

## Ľudská poznámka

Keď veci patria dokopy, nepíš to do príkazu. Napíš to tam, kde vec
žije — nech to prečíta každý, kto príde po tebe, a nech to stroj
skontroluje za teba. CLI override zostáva vynikajúci nástroj — len nie
pre identity, ktoré majú prežiť viac než jeden beh. Identita, ktorú
drží len pamäť jedného behu, nie je identita. Je to náhoda, ktorá sa
ešte nestihla zopakovať.

---

→ [Diel 11 — mapa hovorí 393, terén 962](/blog/mapa-a-teren-pre-deti) —
o číslach s dátumom expirácie
→ [Rust documentation tests](https://doc.rust-lang.org/rustdoc/write-documentation/documentation-tests.html)
— dokumentácia, ktorú kontroluje stroj

## Zdroje

- `src/Modules/Blog/Application/Services/CoverGenerator.php` — pravidlá
  1–4 v kóde
- `tests/Unit/CoverCodeIsFromArticleTest.php` + `tests/Unit/CoverDeterminismTest.php`
  — kontrakty (pôvod výňatku, byte-determinizmus, cache-buster)
- `docs/blog-covers.md` — dokumentácia generátora
- [Diel 11 — mapa hovorí 393, terén 962](/blog/mapa-a-teren-pre-deti)
- [Rust documentation tests](https://doc.rust-lang.org/rustdoc/write-documentation/documentation-tests.html)
