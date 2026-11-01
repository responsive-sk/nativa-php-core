---
title: "True/false nestačí. A predsa stačí : tri argumenty za boolean v Rustom posadnutom strome"
date: "2026-10-23"
tags: ["nativa", "php", "typy", "rust", "boolean", "architektúra"]
excerpt: "Z pohľadu Rust typov je boolean príliš úzky. Zmerané v tomto strome: 238 metód vracia bool, 98 z nich je predikát, skutočný enum je jeden. Tri argumenty, prečo je to v poriadku : a jedno miesto, kde bool naozaj klame."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architektúra"
published: true
---

> „z pohladu RUST typov > nestaci iba true/false > ale potrebujeme aspon dva
> argumenty preco je stary boolean C true/false v realnom case platny"

Toto je dilema, ktorá vyzerá ako voľba medzi dvoma jazykmi.
Nie je.
Otázka nie je „koľko hodnôt má návratový typ", ale **kde žije dôvod >
keď odpoveď nie je áno**.
Boolean je zlý presne vtedy >
keď ten dôvod nemá iné miesto a musí sa zmestiť do `false`.
Keď ho iné miesto má >
a to miesto prežije proces >
je boolean najmenšia úplná odpoveď, akú jazyk ponúka.

Tento text je o tom, kde je tá hranica v Nativa PHP Core >
a je postavený na meraní, nie na vkuse.

---

## Časť I > Čo Rust naozaj hovorí (a čo mu pripisujeme)

Rust nemá problém s dvoma hodnotami.
Má problém s dvoma **inými** vecami:

1. **s `int`-om, ktorý hrá bool** : C-éčkový `0`/`-1`/`42` je typ s 2³² stavmi,
   ktorý sa tvári ako typ s dvoma;
2. **s `errno`** : dôvod zlyhania je procesovo globálna premenná, ktorú medzi
   volaniami prepíše ktokoľvek, takže „prečo" sa nedá preniesť ani vrátiť.

`Result<T, E>` je odpoveď na druhý problém.
`bool` v Ruste zostal a je to normálny typ s dvomi konštruktormi >
`std` ho vracia na každom kroku:

```rust
"abc".contains('b')     // bool
vec.is_empty()          // bool
option.is_some()        // bool
iter.any(|x| x > 0)     // bool
```

Keby bol boolean anti-vzor >
`Vec::is_empty()` by vrátil `enum Emptiness { Empty, NotEmpty }` >
a každý volajúci by musel napísať `match` s jedným ramenom >
ktoré nikdy nevykoná.
Typový systém nerobí silnejším to, že premenuje `false`.

Takže prvý argument nie je kompromis, je to zhoda s Rustom >
**predikát je úplný výrok**.
Ak sú dve odpovede celý vesmír otázky, `bool` je presne ten typ.

---

## Časť II > Merané v tomto strome

Skript nad `git ls-files` (produkčný rozsah `src/` + `modules/` >
testy vynechané),
23. októbra 2026:

| Odpoveď | Počet | Príklad |
|---|---|---|
| `bool` | **238** metód v 128 súboroch | `ThemePreference::isResolvable()`, `DatabaseQueue::retryFailed()` |
| z toho predikáty (`is*`, `has*`, `can*`, `needs*`, `wants*`) | **98** (`is` 69, `has` 24, `can` 3, `needs` 1, `wants` 1) | `HandlerRegistry::has()`, `SettingsService::has()` |
| z toho výsledky operácií | **140** | `send()`, `retryFailed()`, `approve()`, `delete()` |
| `?T` (nullable) | **171** | `DatabaseQueue::findJob(): ?array` |
| `array{ok: bool, errors: list<string>}` | **4** súbory | `CommentsService::submit()` |
| stavová slovná zásoba ako `const string` | **2 × 3** hodnoty | `Comment::STATUS_*`, `Question::STATUS_*` |
| skutočný `enum` | **1** | `Period` (Cardboard) |

Dve čísla z tej tabuľky stoja za komentár.
**Jeden enum v celom produkte** nie je triumf, je to fakt o štýle >
tento strom drží slovnú zásobu stavov v string konštantách >
ktorých jednoznačnosť stráži `NameOwnershipContractTest` (jedna hodnota = >
jedno meno).
To je náhrada za exhaustiveness, nie jej ekvivalent >
a v Časti VII je miesto, kde to naozaj bolí.

A **98 predikátov zo 238** je presne tá polovica >
kde by enum bol dekorácia:
`isResolvable()` odpovedá na otázku >
ktorá nemá tretiu možnosť.

---

## Časť III > Argument 1: predikát sa nedá obohatiť

```php
// src/Modules/Core/Domain/ThemePreference.php
public static function isResolvable(?string $stored, array $available): bool
{
    return is_string($stored) && '' !== $stored && in_array($stored, $available, true);
}
```

Volajúci je jedna podmienka:

```php
return is_string($stored) && self::isResolvable($stored, $available) ? $stored : $fallback;
```

Keby `isResolvable()` vrátil `enum Resolvable { Yes, No, ...
}`, tretie rameno by nikto nenapísal >
nemalo by význam.
Typ by narástol o vetvu, ktorú nikto nemôže obslúžiť >
a čitateľ by musel overiť, že tá vetva naozaj neexistuje.
To je horší typ, nie lepší.

Rovnaké príklady v strome:
`HandlerRegistry::has(): bool` >
`ThemeRegistry::has(): bool`, `DocsCatalog::has(): bool`.
Všetky sú „mám kľúč?"
dve odpovede, obe použiteľné, žiadna tretia.

**Toto je prvý argument:
** boolean je správny >
keď je otázka úplná a odpoveď `false` nie je skratka pre nič >
je to celá informácia.

---

## Časť IV > Argument 2: dôvod má lepší domov než signatúra

Toto je jadro celej dilemy a je to zároveň miesto >
kde C-éčkový model zlyhal a kde ho Nativa opravuje inak než Rust.

POSIX syscall vracia `-1` a nastaví `errno`.
Status je boolean („podarilo sa"), dôvod je mimo neho.
Rust to opravil tým, že dôvod presunul **do návratovej hodnoty**.
Nativa robí tretiu vec:
dôvod presunul **do trvalého záznamu**.

Reálny prípad z tohto týždňa >
notifikácia operátorovi:

```php
// src/Modules/Core/Domain/Contracts/Mailer.php
public function send(string $to, string $subject, string $text): bool;
```

Má **tri skutočné stavy**:

| Stav | Čo sa stane | Návratová hodnota |
|---|---|---|
| transport prijal správu (2xx) | nič | `true` |
| transport odmietol / neodpovedal | `error_log` s endpointom a statusom | `false` |
| `admin_email` je prázdny | `error_log` menuje nastavenie v `/mark/settings` | `false` |

```php
// modules/Users/Application/Services/AdminNotifier.php
public function send(string $subject, string $text): bool
{
    if ('' === $this->recipient) {
        error_log(sprintf('[notifications] "%s" was not sent — admin_email is empty; set it at /mark/settings', $subject));

        return false;
    }

    return $this->mailer->send($this->recipient, $subject, $text);
}
```

Prečo to nie je chyba?
Lebo **volajúci nemá čo robiť s dôvodom**.
Pozrime sa na frontu, ktorá ten mail doručuje:

```php
// modules/Queue/Application/Services/DatabaseQueue.php — work()
$this->handlers->run($job->handler, $job->args);
$this->delete($job->id);
```

Fronta sa rozhoduje podľa toho, či handler **hodil výnimku** >
nie podľa návratovej hodnoty >
`HandlerRegistry` to má napísané >
aj v docblocku >
(„the return value is discarded by the worker; a job fails by throwing").
Notifikácia, ktorá sa neodoslala >
preto **zmizne z fronty a nič sa nereštartuje**.
To je zámer:
druhý mail za ten istý komentár je horší než chýbajúci mail >
ktorý je aj tak v logu.

Keby `send()` vrátil `Result<Delivered, NotDelivered>` >
handler by musel urobiť `match` >
a v oboch ramenách by urobil to isté:
nič.
Typ by sľuboval rozhodnutie, ktoré volajúcemu nepatrí.

**Tento argument má však tvrdú podmienku >
bez ktorej sa zrúti:
** dôvod musí byť dohľadateľný aj po smrti procesu.
`error_log` je minimum >
v tomto strome k nemu patrí `failed_jobs` (payload + error >
viditeľné v `/mark/queue`) a auditná tabuľka.
Boolean je čestný presne vtedy, keď je **strata dôvodu kompenzovaná**.
Bez toho by bol lož.

---

## Časť V > Argument 3: boolean sa nedá uložiť nesprávne

Tretí argument je o hranici procesu, nie o počte stavov.

Hodnota, ktorá sa **ukladá** alebo **posiela** >
musí mať menovanú slovnú zásobu.
Tento strom to má na dvoch miestach a obe sú trojstavové:

```php
// modules/Comments/Domain/Entities/Comment.php
public const string STATUS_PENDING  = 'pending';
public const string STATUS_APPROVED = 'approved';
public const string STATUS_REJECTED = 'rejected';
```

Komentár, ktorý by mal `bool $approved` >
nemá kde držať „čaká na schválenie" >
a presne to je predvolený stav každého nového komentára.
Rozdiel medzi `pending` a `approved` nie je „ešte nie / už áno" >
je to **fronta práce pre operátora** >
ktorá sa v paneli počíta a ktorú notifikácia oznamuje.

Rovnaká logika v novej notifikácii.
Payload nesie `kind` ako hodnotu, nie ako príznak:

```json
{"kind": "comment", "target": "e2e-check", "author": "Eva", "panel": "/mark/comments"}
```

a konzument ju spracuje `match`-om s explicitným `default`:

```php
return match ($kind) {
    'comment'  => $this->notifier->send('New comment awaiting moderation', ...),
    'question' => $this->notifier->send('New question awaiting moderation', ...),
    default    => $this->unknown($kind),
};
```

Tretia udalosť (napríklad „objednávka") má kam pribudnúť.
Keby payload niesol `isQuestion: bool` >
tretia udalosť by sa musela tváriť ako otázka alebo ako komentár >
a `default` rameno by neexistovalo >
neznámy stav by sa stal tichou zlúčeninou dvoch rôznych vecí.
To je presne ten druh chyby >
ktorý typový systém Rustu nekompromisne odmietne.

**Rozhodujúce pravidlo:
** boolean je bezpečný >
keď ho nikto neukladá a nikto neposiela cez hranicu procesu.
V okamihu, keď ide do databázy, do queue payloadu alebo do HTTP odpovede >
musí mať meno >
a to meno potrebuje validáciu, nie `true`/`false`.

---

## Časť VI > Kde by bool bol klamstvo (a jedno miesto, kde ním už je)

Tri miesta v tomto strome, kde by boolean zjedol informáciu:

1. **`kind` udalosti** : dve udalosti dnes, tretia zajtra (Časť V).
2. **Stav komentára/otázky** : tri hodnoty, jedna z nich je „fronta práce".
3. **`DatabaseQueue::work()`** : vracia **počty**, nie príznak:

```php
$result = QueueServices::work(null, self::WORK_LIMIT);
// ['processed' => 2, 'failed' => 0]
```

Panel ich vypíše ako `Processed 2 job(s) · 0 failed.`
Keby `work()` vrátilo `bool $ok`, operátor by sa nedozvedel >
či sa spracoval jeden job alebo desať >
a to je presne to číslo, podľa ktorého sa rozhoduje, či kliknúť znova.

A jedno priznanie, ktoré k tomuto textu patrí >
**`CommentsService::submit()` vracia `bool` s vedľajším kanálom.**

```php
return ['ok' => false, 'errors' => ['Comment body is required.']];
```

To je `Result` prezlečený za pole.
Invariant „`ok === true` ⇒ `errors` je prázdne" nie je v type >
je v hlavách volajúcich, a dva kľúče sa môžu rozísť.
Merané:
takýto tvar je v štyroch súboroch (`Comments`, `Media` dva >
`Users`).
Nie je to bug >
je to cena za to, že strom nemá `Result` typ.
Keby ho mal, toto by bol jeho prvý kandidát.

Podobne `retryFailed(): bool` stráca dôvod, prečo retry neprešiel.
Ale vedľa neho sedí `findFailed(): ?array`, ktoré ten dôvod vráti >
boolean je len „riadok existoval", detail je dostupný inou otázkou.
To je ten správny tvar:
**bool pre rozhodnutie >
bohatšia otázka pre obrazovku.**

---

## Časť VII > Pravidlo, ktoré z toho vychádza

Skôr než napíšeš `: bool`, tri otázky v tomto poradí:

| # | Otázka | Ak áno | Ak nie |
|---|---|---|---|
| 1 | Rozhodne sa volajúci **inak podľa dôvodu**? | `enum` / `Result` | pokračuj |
| 2 | Prežije hodnota **proces** (DB, queue, HTTP, session)? | `enum` + validácia + `match` s `default` | pokračuj |
| 3 | Sú dve odpovede **celý vesmír** otázky? | `bool` | `enum` |

A jedna veta, ktorá to celé drží:
**bool navonok >
slovná zásoba dovnútra**.
Na hranici, kde volajúci nemá čo robiť s dôvodom >
je boolean najmenšia čestná odpoveď.
Vo vnútri, kde sa stav ukladá alebo posiela, musí mať meno.

---

## Časť VIII > Prečo je to FreeBSD mindset a nie Rust mindset

FreeBSD drží základ malý a primitíva ostré >
`open()` vráti file descriptor alebo `-1`, `errno` povie prečo.
To nie je nedbalosť, je to rozhodnutie, že **dôvod patrí niekam >
kde sa dá prečítať, nie do každého volania**.
Rust na to isté miesto dal typ >
a je to lepšie pre kompilátor, ale nie zadarmo >
každý volajúci musí vetvu napísať, aj keď ju nemá ako obslúžiť.

responsive.sk je ten istý postoj z druhej strany:
malý web >
ktorý musí bežať na zdieľanom hostingu bez démona >
kde kód číta jeden človek o druhej ráno.
Tomu vyhovuje `if (!$ok)` s logom, nie `match` s tromi ramenami >
z ktorých dve robia to isté.

Ale pozor >
to nie je ospravedlnenie pre `int`.
`bool` je už typ s dvoma hodnotami; problém C-éčka bol `int` a `errno`.
Nativa si z FreeBSD berie primitívnosť, nie neistotu:
`bool` tam >
kde je otázka úplná, `string` slovník tam, kde sa stav ukladá >
a `error_log` + `failed_jobs` + audit tam >
kde by `errno` bol globálna premenná.

---

## Čo zostáva otvorené

- **`array{ok, errors}` v štyroch súboroch** je jediné miesto, kde bool naozaj
  nestačí a strom to vie. Malý value object (`SubmitResult`) by invariant
  presunul do typu; je to refaktor, nie oprava, a preto nie je v tomto článku
  hotový.
- **Jediný `enum` v produkte** (`Period`) znamená, že slovná zásoba `kind`
  a stavov je dnes string. `default` rameno s logom je bezpečnostná sieť, ale
  je to runtime, nie kompilátor: neznámy `kind` sa zapíše do logu namiesto
  toho, aby sa nedal vytvoriť.
- **`Mailer::send(): bool` stratí dôvod v okamihu, keď niekto vypne `error_log`
  alebo `failed_jobs`.** To je podmienka argumentu 2, nie detail implementácie >
  ak sa z logu stane „nice to have", boolean v tom mieste začne klamať.

---

*Merané 23. októbra 2026 v tej istej session >
v ktorej notifikácia vznikala >
238 metód s `: bool` v 128 súboroch (`is` 69, `has` 24, `can` 3, `needs` 1 >
`wants` 1, zvyšok výsledky operácií), 171 nullable návratov >
4 súbory s tvarom `ok`/`errors`, 2 trojstavové slovníky a 1 `enum`.
Príklady v článku sú skopírované z kódu >
ktorý v tej chvíli prechádzal bránami:
706 testov / 5808 asercií >
phpstan 0, cs 0, rector 0, mago-guard 0.
Ak niektoré číslo už neplatí, je to dôvod na ďalšie meranie >
nie na vymazanie odseku.*
