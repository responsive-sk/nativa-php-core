---
title: "Rust a Lekalo pre deti ... diel 8: autorizačný kontrakt, kto smie čo, a ako to stráži strict review"
series: "Rust & Lekalo"
series-part: "8"
date: "2026-09-08"
version: "1.0.2"
tags: ["rust", "lekalo", "autorizacia", "semanticky-model", "bezpecnost"]
excerpt: "Diel 7 skončil jedným zeleným riadkom: strict review validoval čisto. Tento článok je o dokumente, ktorý to umožnil, lekalo/authorization.yaml: aktéri, reťaz rolí, chránené efekty, IR-digest pin, mapping evidence a čo strict review naozaj kontroluje (a poctivo aj nekontroluje)."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-authorization-contracts"
published: true
---

> Toto je diel 8 série **Rust a Lekalo**. V dieli 7 model vyrástol,
> pribudol blog, autorizácia a gates, a strict review začal prechádzať
> ([diel 7](/blog/lekalo-model-rastie-pre-deti)). Dnes si rozoberieme
> dokument, ktorý to umožnil, a čo strict naozaj kontroluje, vrátane
> dier, ktoré nevidí.

Diel 7 skončil jedným zeleným riadkom:

```bash
$ lekalo validate --strict
validated strict profile v1.0.0: 23 rules, 0 errors, 0 warnings, 0 info
```

Tento článok je príbeh dokumentu, ktorý tento zelený riadok umožnil. Všetko
nižšie je živý výstup z tohto repozitára.

## 1. Jeden dokument, uzavretý slovník

Autorizácia v Lekalo žije v presne jednom súbore projektu,
`lekalo/authorization.yaml`: nikdy per-modul, nikdy v target kóde. Jeho
slovník je uzavretý: pevná množina aktérov, rolí, policy, scope
bindingov, field permission a mapping evidence. Žiadne runtime
principály, žiadne tokeny, žiadne mená middleware, žiadne secrety. Len
sémantické id a ohraničené symbolické hodnoty.

Aktéri sú najzaujímavejšia časť:

- `public`: bez autentifikácie; len výslovne verejné policy/efekty.
- `identity.user`: vyžaduje prihláseného používateľa.
- `internal`: dôveryhodná runtime identita na deklarovanej hranici
  (naše `users.find_user_by_email` / `find_user_by_id`).
- (`identity.service` a `system.job` v slovníku sú, ale my ich
  nepoužívame, kit nemá žiadne služby ani plánované joby.)

Reťaz rolí zrkadlí RBAC kitu s výslovnou implikáciou:

```yaml
roles:
  - id: users.role.guest
  - id: users.role.member
    implies: ["users.role.guest"]
  - id: users.role.admin
    implies: ["users.role.member"]
```

`admin` zahŕňa `member`, `member` zahŕňa `guest`. Dedičnosť je
deklarovaná, nikdy skrytá v target kóde.

## 2. Policy: kto smie čo

Policy je uzavretá kombinácia aktéra, rozhodnutia (`allow`/`deny`),
zoznamu `applies_to` operácií, povinných rolí a povinného `error_ref`
na existujúci error-kontrakt symbol. Štyri policy pokrývajú Users
povrch:

```yaml
policies:
  - id: users.policy.public_auth_flow
    actor: public
    decision: allow
    error_ref: "users.error.unauthorized"
    applies_to: ["users.register", "users.login",
                 "users.request_password_reset", "users.reset_password"]
  - id: users.policy.member_session
    actor: identity.user
    decision: allow
    error_ref: "users.error.unauthorized"
    applies_to: ["users.logout", "users.current_user"]
    roles: ["users.role.member"]
  - id: users.policy.admin_role_management
    actor: identity.user
    decision: allow
    error_ref: "users.error.forbidden"
    applies_to: ["users.create_role", "users.delete_role",
                 "users.sync_role_permissions", "users.list_roles"]
    roles: ["users.role.admin"]
```

Ďalšie dve pokrývajú Blog povrch (`blog.policy.public_read` pre šesť
read query plus počítanie videní, `blog.policy.admin_editing` pre CRUD
pod `users.role.admin`). Všimni si rozdelenie `error_ref`: verejné
povrchy zamietajú cez `users.error.unauthorized`, chránené cez
`users.error.forbidden`: slovník je pripnutý, aj keď #62 ešte nemá
per-project deklaračný povrch (viď Poctivé limity).

## 3. Chránené efekty: desať zlyhaní, ktoré to začali

Strict review je deny-by-default. Jeho jadrová kontrola je jednoduchá:
*každý chránený efekt potrebuje aspoň jednu allow policy pokrývajúcu
jeho operáciu.* Entity create/update/delete, event enqueue, cache
writes, publikácia, mutujúce veci, plus query reads s field grantmi.

Keď sa Blog modul pridal do modelu, prvý strict beh zlyhal na desiatich
takýchto chybách:

```json
{
  "id": "authorization.effect-unprotected",
  "code": "LEK-AUTH-009",
  "data": { "effect": "create", "operation": "blog.create_article" }
}
```

Celý zoznam bol celý blog povrch,`create_article`, `update_article`,
`delete_article`, `record_view`, plus všetkých šesť read query. Modul
bol namodelovaný, ale nikto nepovedal, kto sa ho smie dotýkať. Strict
povedal: ešte nie je hotové.

## 4. `model_ref` pin: hra s digestom

Druhé zlyhanie bolo subtílnejšie: `LEK-AUTH-010 authorization.mapping-stale`
s `data: {policy: model_ref, state: stale-model}`.

`model_ref` pinuje presný IR payload, proti ktorému boli policy
napísané. Digest nie je digest z lockfile a nie je hash zdrojových
súborov, je to SHA-256 **kanonického IR dokumentu**:

```bash
$ lekalo load --ir --json | jq -r .ir | sha256sum
```

(`ir` objekt vo vnútri `--json` envelope, bajt po bajte, ako je
emitovaný.) Náš prvý strict beh bol odmietnutý, lebo pripnutý digest
predchádzal Blog modulu. Prepočítanie po pridaní modulu a nové
pripnutie dokumentu kontrolu vyčistilo. Jedna poctivá poznámka:
dostať to správne chvíľu trvalo, pozreli sme sa na vlastné fixture-y
CLI; digest golden planner projektu sedel presne na to, čo
`sha256(load --ir --json .ir)` produkuje. Vzorec je reprodukovateľný
a teraz zdokumentovaný v `docs/lekalo-model.md`.

## 5. Mapping evidence: dôkaz, že adapter videl kontrakt

Strict review si pýta aj adapter mapping evidence, sekciu `mappings`,
ktorá zaznamenáva, ktorý target videl ktorú policy na ktorej revízii:

```yaml
mappings:
  - target: "php-nativa"
    authorization_contract_ref: "dev.lekalo.authorization@1.0.0"
    semantic_policy_ref: "blog.policy.public_read"
    mapping_state: full
    evidence_ref: "lekalo/targets/php-nativa.yaml"
    observed_revision: "sha256:44334cbad…"
```

`mapping_state` je páka: strict prejde len `full`; `partial`/
`unsupported`/`unknown` nie. Adapter hlási `unsupported` radšej, než by
optimisticky prešiel. Pre nás PHP target deklaruje plnú evidence,
digest jeho config súboru je observed revision, a gate zostáva poctivý
v tom, že to je declaration-level evidence, nie generovaný middleware.

## 6. Čo strict review je: a nie je

Tri fakty, ktoré stoja za to povedať rovno:

1. **Strict je deny-by-default.** Žiadny match, chýbajúca policy,
   nerozlíšená referencia, všetko blokuje. Defaultný profil je
   poradný: nikdy strict ticho neoslabí, len neblokuje.
2. **Deklarácia, nie vynucovanie.** Lekalo dokáže, že policy sú dobre
   sformované, operácie existujú a efekty sú pokryté. Nikdy ich
   nespúšťa. RBAC kitu zostáva ručne písané PHP; model a runtime sa
   zhodujú preto, že oba držíme poctivé, nie preto, že jeden generuje
   druhý.
3. **Gate to udrží povedané.** Keď je strict v CI, pridanie commandu
   bez allow policy rozbije build. Dokument prestane byť dokumentácia
   a stane sa kontrakt.

## Poctivé limity (stav k 0.1.30)

- **Autorizačné policy sa nedajú inspectnúť.** `lekalo inspect
  users.policy.public_auth_flow` odpovie `inspect.symbol-unknown`.
  Dokument sa validuje, ale ešte sa nedá projektovať po symboloch.
- **Nie sú ani v grafe závislostí.** `graph export` ukazuje `authorizes`
  hrany len od *model-level* policy (`users.password_min_length`,
  `blog.cover_owned_by_article`, …), autorizačné policy sú samostatná
  kontraktová rodina a v 0.1.30 sa v #13 grafe neobjavia.
- **Error kontrakty (#62)**: `error_ref` hodnoty sú pripnuté stabilné
  id, ale pre ich payload-y ešte nie je per-project deklaračný povrch.
- **Extended effects (#26)**: event/job/cache sémantika je
  zdokumentovaná, v tejto verzii nedeklarovateľná.

Toto je model, ktorý hovorí pravdu o svojich vlastných hraniciach, čo
je presne na to ten nástroj.

---

A tu je hlbšia pravda: autorizácia je jediné miesto, kde „povedať to“
znamená rovnako veľa ako „urobiť to“. Lekalo nedokáže zastaviť zlého aktéra za behu, to
robia tvoje guardy. Čo dokáže, je donútiť ťa napísať, kto smie čo,
nedovoliť buildu prejsť, kým to nemáš, a potom ten sľub kontrolovať
pri každom pushi. Počítaču nezáleží na tom, čo si zamýšľal; gate
záleží na tom, čo si napísal.

→ [Diel 7, model rastie](/blog/lekalo-model-rastie-pre-deti): blog vstúpil do modelu a strict začal prechádzať
→ [Diel 6, Lekalo deep dive](/blog/lekalo-pre-deti): živý beh, ktorý začal modelovú cestu
→ [Dokumentácia modelu na GitHub-e](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md): layout, re-lock workflow, sekcia o autorizácii

## Zdroje

- [Lekalo](https://github.com/ichinya/lekalo): repozitár projektu (product 0.1.30)
- [Lekalo, Authorization](https://github.com/ichinya/lekalo/blob/main/docs/authorization.md): aktéri, scope, policy, strict review, mapping evidence
- `lekalo/authorization.yaml`: autorizačný kontrakt tohto repozitára
- `docs/lekalo-model.md`: dokumentácia sémantického modelu (sekcia o autorizácii)
