---
title: "Fog a Sun ... ako písať problémy, ktoré udržia pozornosť"
date: "2026-08-29"
version: "1.0.2"
tags: ["writing", "narrative-rhythm", "technical-writing", "blog"]
excerpt: "Problém musí byť osobný — nie 'problém X existuje', ale 'stalo sa Y a bolo to zlé.' Ako fázy fog a sun vytvárajú napätie, ktoré udrží čitateľa."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Writing"
translation: "rhythm-part-2-fog-and-sun"
published: true
---

Prečítajte si túto vetu: „Výkonové problémy sa môžu vyskytnúť pri použití argon2id na shared hostingu.“

A teraz túto: „Prvý prihlasovací pokus: server spadol. Druhý tiež. Tretí ... rovnaký výsledok.“

Oba popisujú rovnaký problém. Jedna je poznámka k dokumentácii. Druhý je príbeh. Rozdiel je v hmle.

## Fáza hmly

Hmla je zmätok, záhada, problém, ktorý ešte nebol vyriešený. Je to časť článku, kde čitateľ nepozná odpoveď — ale chce ju.

Fáza hmly musí byť **osobná**. Nie „problém X existuje“ ale „stalo sa Y a bolo to zlé.“

```markdown
# Zle: abstraktný problém
Výkonové problémy sa môžu vyskytnúť pri použití argon2id
na shared hostingu s obmedzenou alokáciou RAM.

# Dobre: osobný problém
Mali sme hotový, otestovaný, bezpečný systém.
PasswordHasher používal PASSWORD_ARGON2ID.
Všetko fungovalo na vývoji, v Dockeri, na VPS.

Potom prišiel shared hosting.

Prvý prihlasovací pokus: server spadol.
```

Prvý príklad popisuje podmienku. Druhý popisuje udalosť. Udalosti sú zapamätateľné. Podmienky sa zabúdajú.

## Prečo krátke vety vytvárajú napätie

Fáza hmly používa staccato rytmus — krátke vety, každá pridáva tlak:

```markdown
Kliknite na stránku 3. Pager sa zdvojnásobil.
Kliknite znova. Ztrojnásobil.
Štyri pagers na sebe, každý trochu iný —
niektoré s Prev, niektoré s Next, ani jeden s oboma.
```

Každá veta je úder. Čitateľ cíti, ako problém narastá. To je napätie — a napätie udrží ľudí pri čítaní.

Ak napíšete fázu hmly v dlhých, plynúcich vetách, napätie sa rozplynie. Čitateľ preskakuje. Hmla sa vyjasní priskoro.

## Fáza slnka

Slnko je jasnosť — moment, keď čitateľ pochopí problém a vidí riešenie. Ale riešenie musí byť **zaslúžené**. Ak preskočíte z hmly do slnka, čitateľ sa cíti podvedený.

```markdown
# Zle: uponáhľané riešenie
Riešenie je použiť bcrypt namiesto argon2id.

# Dobre: zaslúžené riešenie
Jeden riadok. Žiadna chyba v logike, žiadny bug.
Len algoritmus, ktorý si pýta viac pamäte,
než server má.

Prepísal som PASSWORD_ARGON2ID na PASSWORD_BCRYPT.
Bcrypt potrebuje ≈4 MB RAM ... 16-krát menej.
```

Druhý príklad dáva čitateľovi pocítiť „aha!“ moment. Riešenie je jednoduché — ale je prezentované ako objav, nie ako príkaz.

## Code bloky sú dôkaz, nie dekorácia

Vo fáze hmly code ukazuje problém:

```php
// PasswordHasher.php ... pred zmenou
public const string ALGORITHM = PASSWORD_ARGON2ID;
```

Jeden riadok. Žiadna chyba v logike, žiadny bug. Len algoritmus, ktorý si pýta viac pamäte, než server má. Kód je dôkaz.

Vo fáze slnka code ukazuje opravu:

```php
// PasswordHasher.php ... po zmene
public const string ALGORITHM = PASSWORD_BCRYPT;
```

Rovnaká štruktúra, iná konštanta. Čitateľ vidí zmenu a okamžite ju chápe.

Code bloky v HMLA sú „pozri, toto je rozbité.“ Code bloky v SLNKO sú „pozri, takto sme to opravili.“ Code bloky kdekoľvek inde sú dekorácia.

## Medzera medzi hmlo a slnkom

Najlepšie články majú moment ticha medzi problémom a riešením. Pauza. Vodorovná čiara. Veta, ktorá nechá čitateľa sedieť s problémom predtým, než pôjde ďalej.

```markdown
Admin panel vyzeral rozbitý.
Kód bol správny. Alebo sa tak zdalo.

---

Čo sa stalo pri kliknutí na stránku:
```

Tá medzera je miesto, kde žije zvedavosť čitateľa. Nepreskakujte ju.

---

Fáza hmly získava pozornosť čitateľa. Fáza slnka ju odmeňuje. Preskočte ktorúkoľvek a článok sa stane dokumentáciou — technicky správnou, emocionálne mŕtvou.

---

→ [Part 3 — Night, Object, Theme](/blog/rhythm-part-3-night-object-theme) — časti, ktoré robia články zapamätateľnými
→ [Part 1 — Článok, ktorý nikto nedočítal](/blog/rhythm-part-1-nobody-finished) — prečo rytmus znamená viac

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — bezpečnostné best practices
- [W3C — Web Standards](https://www.w3.org/standards/) — web štandardy
