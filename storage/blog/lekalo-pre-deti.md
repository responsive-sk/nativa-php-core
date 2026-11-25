---
title: "Rust a Lekalo pre deti ... diel 6: Lekalo deep dive — Rust nástroj analyzujúci PHP projekt, naživo"
series: "Rust & Lekalo"
series-part: "6"
date: "2026-09-06"
version: "1.0.1"
tags: ["rust", "lekalo", "semanticky-model", "impact", "architektura"]
excerpt: "Finále série. Všetko doteraz bola príprava: jazyk, tooling, kvalita, modelová vrstva. Dnes spustíme Lekalo end-to-end na Users modeli v tomto repozitári — validácia, grafy závislostí, impact analýza a kontextové kapsuly — s živým výstupom a poctivými limitmi."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-semantic-model"
published: true
---

> Toto je diel 6 — **finále** série **Rust a Lekalo**. Päť dielov prípravy:
> jazyk ([diel 1](/blog/preco-rust-pre-deti)), mentálny model
> ([diel 2](/blog/ownership-pre-deti)), tooling ([diel 3](/blog/rust-cli-pre-deti)),
> kvalita ([diel 4](/blog/rust-testovanie-pre-deti)) a modelová vrstva
> ([diel 5](/blog/semanticky-model-pre-deti)). Dnes prestaneme opisovať
> a začneme spúšťať: Lekalo, naživo, na Users modeli v tomto repozitári.

Model je ten, čo sme postavili ako fungujúci príklad — `lekalo/` v koreni
repo: projekt `nativa`, modul `users`, **53 definícií** pokrývajúcich
registráciu, autentifikáciu, sessions, RBAC a password reset. Každé číslo
nižšie pochádza z čerstvého behu CLI na tomto projekte. Všetko si môžeš
zreprodukovať.

## 1. Najprv validácia

```bash
$ lekalo validate --project .
validated default profile v1.0.0: 23 rules, 0 errors, 0 warnings, 0 info
```

Dvadsaťtri sémantických pravidiel — referencie sa rozlíšia na správne druhy,
identity menujú deklarované polia, typové grafy zostávajú acyklické, ID sú
validné — a model je čistý. To je kontraktová kontrola z dielu 4 pri práci:
model je *známe* dobre sformovaný, skôr než ho niečo konzumuje.

## 2. Lock

`lekalo lock --check` overuje, že committnutý lockfile pinuje presné product,
contract a capability identity:

```
lock checked sha256:a30ce8… resolver 1.0.0 (adapters 0, generators 0, profiles 0, capabilities 0)
```

Reprodukovateľnosť z dielu 3, aplikovaná na modelovú vrstvu: rovnaký model,
rovnaká revízia nástroja, rovnaké bajty.

## 3. Graf závislostí

Model sa stane grafom. `lekalo graph export --json` ukáže celý obraz:

```
55 nodes, 106 edges
```

`lekalo graph show users.user` prejde jeden symbol a ukáže, na čom závisí
a — zaujímavejšie — čo závisí na *ňom*:

```
entity users.user
  dependent references effect:users.effect_user_created -> entity:users.user (canonical)
  dependent references entity:users.session -> entity:users.user (canonical)
  dependent returns operation:users.find_user_by_email -> entity:users.user (canonical)
  ...
```

A `lekalo graph callers users.register` ukáže, kto všetko siaha na registračný
command:

```
operation users.register
  caller exposes endpoint:users.endpoint_register -> operation:users.register (canonical)
  caller authorizes policy:users.password_min_length -> operation:users.register (canonical)
  caller authorizes policy:users.unique_email -> operation:users.register (canonical)
  caller references scenario:users.register_login_flow -> operation:users.register (canonical)
```

Jeden príkaz, tri druhy volajúcich: HTTP endpoint, policy, ktoré ho gateujú,
scenario, ktoré ho pokrýva. To je odpoveď na „čo sa tohto týka?“, ktorú sme
sľúbili v dieli 1.

## 4. Impact analýza

Teraz hlavný ťahák. Čo sa stane, ak sa zmení entita `User`?

```bash
$ lekalo impact users.user --project .
impact symbol (entity:users.user)
  direct 7 transitive 12 mandatory-public 3
  risks 6
  gates 9
```

Sedem symbolov priamo závisí na `User`, ďalších dvanásť tranzitívne. Tri sú
mandatory public povrchy. Šesť riskov a deväť gates na review. Skôr než sa
dotkneš riadku kódu, máš ohraničený change-radius — presnú odpoveď na
„aké veľké to bude?“ z dielu 1.

Poctivá poznámka pod čiarou: Lekalo rozlišuje **deklarované** efekty (čo
model hovorí) od **detekovaných** (čo ukazujú dôkazy) a impact beh emituje
`evidence-unknown` varovania, lebo zatiaľ neexistuje žiadny PHP source
adapter. Analýza je taká dobrá, ako dobrý je model — čo je presne tá
výmena, o ktorej sme hovorili v [dieli 5](/blog/semanticky-model-pre-deti).
Drž model poctivý a impact report je poctivý.

## 5. Inspect: jeden symbol, všetko o ňom

`lekalo inspect users.login` projektuje jediný symbol s jeho kontraktom,
policy, efektmi a dependentmi:

```
symbol: users.login
kind: command
policies: state=available
  - users.active_account_required deny
effects: state=available
  - create operation:users.login -> users.session (canonical)
  - emit-event operation:users.login -> users.session_started (canonical)
dependents: state=available
  - exposes users.endpoint_login (canonical)
  - authorizes users.active_account_required (canonical)
```

Tvar login commandu — čo akceptuje, ktorá policy ho gateuje, čo vytvára,
kto ho volá — v jednej deterministickej projekcii.

## 6. Kontext pre AI agenta

A odmena pre skutočné publikum nástroja:

```bash
$ lekalo context users.login --budget 1500 --project .
- budget: limit 1500, estimated 360, minimum required 360, fits true
- complete: true
```

Ohraničená kontextová kapsula pre `users.login` — typované sémantické fakty,
žiadne source bajty, žiadne secrety, žiadne absolútne cesty — sa pohodlne
zmestí do 1500-token budgetu. Agent dostane tvar symbolu v ~360 tokenoch,
garantovane kompletne, z validovaného kontraktu namiesto prehrabávania sa
v súboroch.

## Ako si namodeluješ vlastný modul

Recept, ktorý sme použili, funguje pre akýkoľvek PHP modul tohto kitu:

1. **Najprv entity** — veci s identitou (`User`, `Role`, `Session`), ich
   polia a identity kľúče.
2. **Commandy a query** — akcie (`register`, `login`) a čítania
   (`find_user_by_email`), každé so vstupmi a efektmi.
3. **Policy** — pravidlá, ktoré gateujú commandy (heslové pravidlá,
   admin-only RBAC policy).
4. **Endpointy a scenáre** — naviaž commandy na routy, pokryj toky
   end-to-end.
5. **Validuj a zamkni** — `lekalo validate`, potom `lekalo lock`, a drž oboje
   zelené ako každý iný gate.

Jedna poznámka k názvosloviu: symboly sú **sémantické ID** (`users.user`),
nie PHP class mená. Nástroj modeluje slovník tvojej aplikácie, nie jej FQCN
— čo je presne to, čo ho robí jazykovo neutrálnym.

## Záver série

Šesť dielov, jeden oblúk: Rust nie je hrozba pre PHP, je to doplnok. PHP
zostáva správny nástroj pre samotnú webovú aplikáciu — tento kit je dôkaz.
Rust si miesto zarába v *toolingu* okolo aplikácie a Lekalo je tá
demonštrácia: Rust-built, kvalitou posadnutý, deterministicky vypisujúci
analyzer, ktorého celá práca je rozumieť PHP projektu lepšie.

Prešli sme od „načo by som sa učil Rust?“ cez ownership, tooling a kvalitu
k modelovej vrstve, ktorá robí aplikáciu strojovo čitateľnou — a napokon
k živému nástroju odpovedajúcemu na „čo sa rozbije, keď zmením `User`?“ na
tomto repozitári. To je celý pitch, dokázaný na našom vlastnom kóde.

Na prechádzku sériou: [diel 1 — prečo Rust](/blog/preco-rust-pre-deti),
[diel 2 — ownership](/blog/ownership-pre-deti), [diel 3 — rust tooling](/blog/rust-cli-pre-deti),
[diel 4 — kvalita v Ruste](/blog/rust-testovanie-pre-deti),
[diel 5 — sémantické modely](/blog/semanticky-model-pre-deti).

## Zdroje

- [Lekalo](https://github.com/ichinya/lekalo) — repozitár projektu (product 0.1.26)
- [Lekalo — CLI kontrakt](https://github.com/ichinya/lekalo/blob/main/docs/cli.md) — commandy, exit kódy, JSON wire
- [Lekalo — Impact](https://github.com/ichinya/lekalo/blob/main/docs/impact.md) — impact projekcie a risk/gate slovník
- [Lekalo — Graph](https://github.com/ichinya/lekalo/blob/main/docs/graph.md) — garancie grafu závislostí
- [Lekalo — Context](https://github.com/ichinya/lekalo/blob/main/docs/context.md) — ohraničené kontextové kapsuly
