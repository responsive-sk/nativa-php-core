---
title: "Bonus pre členov ... komentáre: cudzinci píšu, publikuješ ty"
date: "2026-09-17"
version: "1.0.2"
tags: ["comments", "moderation", "php", "security", "architecture"]
excerpt: "Štvrtý bonus len pre registrovaných. Komentárový formulár je jediné miesto, kde táto appka verí cudzincom ... a verí im nula. Ukážeme si, prečo každý komentár začína ako pending, prečo rate limit beží skôr než validácia, prečo sa email hosťa nikdy nezobrazí a ako vyzerá moderácia v /mark/comments ... všetko na reálnom kóde z tohto repa."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Bonus"
members_only: true
translation: "bonus-comments"
published: true
---

> Toto je štvrtý **bonus pre členov**. Predchádzajúce diely: [zámok
> members-only](/blog/bonus-members-only-pre-deti), [immutable audit
> log](/blog/bonus-audit-log-pre-deti) a [fulltext search cez
> FTS5](/blog/bonus-fts-search-pre-deti). Dnes príde na rad formulár, ktorý
> je jediným write path-om otvoreným cudzincom ... komentáre. Ako písať do
> vlastnej databázy niečo, čo napísal ktokoľvek, aby z toho nebola škoda?
> Presne o tom je tento diel ... na reálnom kóde.

Si vývojár. Tvoja appka číta dáta z vlastnej databázy, overuje vlastných
používateľov, renderuje vlastné šablóny.

Všetky tieto vzťahy majú jediné spoločné: kontrolu máš ty.

A potom umiestniš pod článok formulár, do ktorého môže napísať ktokoľvek
na svete. Meno, email, text ... odoslať. Jeden klik a niečo cudzie letí
do tvojej databázy.

Komentárový formulár je jediné miesto v celej appke, kde cudzinec dostane
prostriedok na zápis. Z tohto pohľadu je to najriskantnejšie miesto celého
projektu ... a presne preto v tomto repe začína s nulou dôvery.

## Meno hosťa je tvrdenie, nie fakt

Komentár môže napísať prihlásený čitateľ aj hosť. Rozdiel nie je v formulári
... je v tom, odkiaľ pochádza meno:

| | Hosť | Prihlásený čitateľ |
|---|---|---|
| Meno | `guest_name` ... tvrdenie odosielateľa | meno z účtu ... fakt z databázy |
| Email | `guest_email` ... uchovaný na moderáciu, nikdy sa nezobrazí | z účtu, rovnako neverejný |
| Identita v zázname | žiadna | `user_id` |
| Status po odoslaní | pending | pending |

Entita `Comment` to nesie v konštruktore a jedna jej metóda je
dôležitejšia, než na prvý pohľad vyzerá:

```php
// src/Modules/Comments/Domain/Entities/Comment.php
/**
 * Display name — the name captured at submit time (the signed-in user's
 * account name or the guest's display name). Never the email.
 */
public function displayName(): string
{
    $name = $this->guestName;

    return null !== $name && '' !== $name ? $name : $this->id;
}
```

„Never the email.“ Email hosťa existuje na jediný účel ... aby ho admin
videl pri moderovaní. Na stránke sa nikdy neobjaví.

Zobrazuje sa meno zadané pri odoslaní, alebo ak hosť meno nezadal,
anonymný identifikátor. Hosť, ktorý sa vydáva za kráľa Belgicka, sa môže
na stránku podpísať ako kráľ Belgicka ... uvidí sa meno, nikdy email.

Tvrdenie ostáva tvrdením. Na fakt ho zmení až moderátor.

## Pending je východzie

Žiadna detekcia spamu, žiadne skóre, žiadne „vyzerá to slušne“. Entita
nepozná nič medzi odoslaním a publikovaním:

```php
// src/Modules/Comments/Domain/Entities/Comment.php
public const string STATUS_PENDING = 'pending';

public const string STATUS_APPROVED = 'approved';

public const string STATUS_REJECTED = 'rejected';
```

`Comment::create()` nastavuje východzí status na `pending` ... bez výnimky,
pre hosťa aj pre prihláseného. Na verejnej stránke sa renderujú len
komentáre s `approved`.

Povšimni si, čo tento návrh robí s odpovednosťou. Publikovanie nie je
neprítomnosť spamu ... publikovanie je rozhodnutie, ktoré niekto urobil.

Hláška „necháme to auto-approve, spam snáď príde málo“ je presne opak:
rozhodnutie, že rozhodnutie urobiť nechceš.

## Rate limit skôr než validácia

V `CommentsService` je jeden detail, ktorý sa ľahko napíše naopak:

```php
// src/Modules/Comments/Application/Services/CommentsService.php
private const int RATE_LIMIT = 10;

private const int RATE_WINDOW = 60;

// Rate limit first — invalid payloads must not bypass throttling.
if (!$this->isAllowed($slug, $ip)) {
    return ['ok' => false, 'errors' => ['Too many comments — try again in a minute.']];
}

$errors = $this->validate($slug, $userId, $guestName, $guestEmail, $body);
```

Limit je 10 komentárov za 60 sekúnd, kľúčovaný na IP + slug. Ale poradie
je to podstatné: **limit beží skôr než validácia**.

Keby to bolo naopak, útočník by posielal odpadové payloady ... neplatný
email, prázdny body ... a validácia by ich odmietla skôr, než by sa
limitu dotkli. Spam by nikdy neprešiel vstupnou bránou.

Takto každý pokus platí. Aj ten nevalidný ... zvlášť ten nevalidný.

A jedna výnimka, ktorá je v kóde explicitne zapísaná: loopback adresy
(`127.0.0.1`, `::1`, `localhost`) sú oslobodené od limitu, aby lokálny
vývoj nebol throttlený. Produkcia výnimku nemá.

## PRG ... starý vstup sa nestráca

Akcii `CommentSubmitAction` vraví jej docblock, ako to má byť: PRG flow
... Post/Redirect/Get. Po úspechu redirect späť k článku:

```php
// src/Modules/Comments/Interfaces/HTTP/Actions/CommentSubmitAction.php
if ($result['ok']) {
    return Response::redirect('/blog/' . $slug . '#comments');
}
```

Pri chybe sa článok renderuje znovu ... s chybami formu inline a so
starým vstupom, ktorý prechádza cez request atribúty. Čitateľ nepíše
komentár znovu len preto, že zle zadal email.

A CSRF? Netreba riešiť v akcii ... `CsrfMiddleware` vynucuje token
globálne. Akcia zostáva čistá: slug, vstupy, service, redirect.

## Moderácia ... /mark/comments

Fronta pending komentárov žije v admin sekcii `/mark/comments`:

- **schváliť** ... komentár sa objaví pod článkom,
- **odmietnuť** ... zmizne z verejnosti, zostane v databáze,
- **zmazať** ... odíde navždy.

Každé admin rozhodnutie zapisuje immutable audit log ... kto, čo, kedy
... presne tak, ako to popisuje [druhý bonus](/blog/bonus-audit-log-pre-deti).
Moderácia nie je výnimka z pravidiel repozitára, je ich ďalší klient.

A ako všade v tomto repe: nerozhoduje sa dojmom, ale testom. `CommentsTest`
beží na hermetických scratch DB (`DB_COMMENTS`) a stráži celý tok
... hosť odošle pending komentár a dostane redirect, hosť nevidí
neschválený obsah, admin schváli a komentár sa zobrazí.

## Kedy tento model neplatí

Buďme úprimní: pending-by-default nie je model pre každý web.

Je to zmluva, ktorá vymieňa okamžitosť za bezpečnosť. Real-time komunita
... chat, fórum s aktívnymi vláknami ... by ju nezniesla. V tomto modeli
neexistujú odpovede na komentáre (entita nemá `parent_id`), neexistujú
notifikácie a neexistuje spam filter ... moderátor je filter.

Druhá úprimnosť: limit kľúčovaný na IP znamená, že celá sieť za jednou
 adresou (škola, firma) zdieľa jednu kvótu. Pre členský blog s desiatkami
komentárov denne je to správna výmena. Pre portál s miliónmi ... nie.

## Čo si z toho odniesť

1. **Formulár komentárov je jediný write path pre cudzinov** ... preto
   začína nulou dôvery, nie optimizmom.
2. **Meno hosťa je tvrdenie** ... zobrazuje sa meno, nikdy email; email
   je nástroj moderátora.
3. **Pending je východzie** ... publikovanie je rozhodnutie, nie
   neprítomnosť spamu.
4. **Rate limit pred validáciou** ... inak nevalidné payloady obchádzajú
   limit zadarmo.
5. **PRG + starý vstup** ... žiadne duplicitné odoslania, žiadne straty
   textu pri chybe.

→ [Zámok members-only](/blog/bonus-members-only-pre-deti) — jeden flag vo front matter
→ [Immutable audit log](/blog/bonus-audit-log-pre-deti) — každé admin rozhodnutie sa zapisuje
→ [Fulltext search cez FTS5](/blog/bonus-fts-search-pre-deti) — index v pamäti, žiadna infraštruktúra

## Zdroje

- [OWASP — Automated Threats to Web Applications](https://owasp.org/www-project-automated-threats-to-web-applications/) ... hrozby, ktorým rate limit stojí v ceste
- [MDN — Post/Redirect/Get](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Location) ... vzor, na ktorom stojí odoslanie formulára
- [PHP Manual](https://www.php.net/manual/) ... oficiálna PHP dokumentácia
- [Nativa Minimal Documentation](/docs/getting-started) ... oficiálny návod tohto kitu
