---
title: "Rust a Lekalo pre deti ... diel 9: nezmeniteľné deklarácie, 0.1.31, komentáre a #63 invarianty"
series: "Rust & Lekalo"
series-part: "9"
date: "2026-09-11"
version: "1.0.1"
tags: ["rust", "lekalo", "invarianty", "architektura"]
excerpt: "Diel 8 pripol autorizačný kontrakt. Tento týždeň prišlo niečo nové: prvý modul, ktorý sa priznal k importom (komentáre potrebuje blog aj users), admin edit používateľa ako command, a issue #63, tri stavové stroje a štyri invarianty deklarované ako príloha, vrátane dvoch poctivých dier, ktoré model sám pomenoval."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Architecture"
translation: "lekalo-invariants"
published: true
---

> Toto je diel 9 série **Rust a Lekalo**. V dieli 7 model vyrástol na 106
> uzlov a stal sa gate-om ([diel 7](/blog/lekalo-model-rastie-pre-deti)).
> Diel 8 rozoberal autorizačný kontrakt a strict review
> ([diel 8](/blog/lekalo-autorizacia-pre-deti)). Dnes: verzia 0.1.31,
> tretí modul v grafe, komentáre, a nový druh dokumentu, ktorý 0.1.31
> priniesla: invarianty a prechody stavov ako príloha k modelu (#63).

Séria sa doteraz točila okolo jednej myšlienky: **deklarovať, nie
vynucovať**. Tento diel je o prvom dokumente, ktorý ide krok ďalej,
nedeklaruje len *čo existuje* a *kto smie čo*, ale *čo sa môže a čo sa
nemôže nikdy stať*. Všetky čísla nižšie sú živý výstup z tohto
repozitára (verzia 0.1.31).

## 1. Základňa: 124 definícií, 128 uzlov

Keď diel 6 spúšťal Lekalo prvýkrát, model mal 53 definícií a graf 55
uzlov. Diel 7 zdvojnásobil na 106. Dnes:

```bash
$ lekalo graph export --json
status: valid
nodes: 128 edges: 226
```

Tretí modul je **comments**: čítateľské komentáre k článkom,
`comments.db`, guest/identity autori, CSRF + per-IP throttle na submit
a admin moderácia. K nemu pribudol v Users module admin edit povrch
(`users.update_user`) so svojím efektom a novým `users.flag` skalárom.
Graf rastie lineárne, ale čo je zaujímavejšie, rastie aj *hustota*:
226 hrán na 128 uzlov.

## 2. Prvý modul, ktorý sa priznal k importom

Doteraz boli moduly v grafe susedia bez vzťahov. Comments je prvý
modul, ktorý deklaruje cross-module importy:

```json
{
  "id": "comments",
  "kind": "module",
  "imports": ["blog", "users"]
}
```

A graf to hneď vie dokázať:

```text
module:comments --requires--> module:blog
module:comments --requires--> module:users
```

Je to malý, ale významný posun. Kit už dávno vie, že komentár sa viaže
na článok (`article_slug: blog.slug`) a že autor môže byť prihlásený
používateľ (`user_id: users.user?`). Model to doteraz vedel len
implicítne, cez typové referencie. Od 0.1.31 to je deklarovaná
závislosť modulov, ktorú validátor kontroluje: importuješ modul, ktorý
neexistuje? Chyba. Referencuješ typ z neimportovaného modulu? Chyba.
Prvýkrát model dokumentuje nielen *čo je*, ale aj *čo závisí na čom*.

## 3. users.update_user: admin edit ako kontrakt

Nový command v Users module:

```json
{
  "id": "users.update_user",
  "kind": "command",
  "input": [
    { "name": "id", "type": { "ref": "users.identifier" }, "required": true },
    { "name": "name", "type": { "ref": "users.display_name" }, "required": true },
    { "name": "email", "type": { "ref": "users.email" }, "required": true },
    { "name": "is_active", "type": { "ref": "users.flag" }, "required": true }
  ],
  "effects": ["users.effect_user_updated"]
}
```

Žiadna veda, formulár v `/mark/users`. Ale kontrakt je kontrakt:
dva nové uzly (`users.update_user`, `users.effect_user_updated`),
nový skalár `users.flag` (boolean pre checkboxy) a povinná allow
policy v autorizácii:

```yaml
- id: users.policy.admin_user_management
  actor: identity.user
  decision: allow
  error_ref: "users.error.forbidden"
  applies_to: ["users.update_user"]
  roles: ["users.role.admin"]
```

Strict review to pohltil bez jediného nového zlyhania, lekciami
z dielu 8 už vieme, že každý mutujúci efekt potrebuje allow policy,
takže sme ju napísali skôr, než strict stihol zaškrtnúť
`LEK-AUTH-009`.

## 4. comments.submit: deny policy namiesto allow

Zaujímavý kontrast: komentáre modelujeme **deny** policy. `inspect`
ukazuje prečo:

```bash
$ lekalo inspect comments.submit
symbol: comments.submit
kind: command
module: comments
contract:
  input:
    - article_slug: blog.slug [required]
    - user_id: users.user?
    - guest_name: comments.display_name?
    - guest_email: comments.guest_email?
    - body: comments.body [required]
    - ip_address: comments.ip_address?
policies: state=available
  - comments.article_must_exist deny
  - comments.identity_or_guest_required deny
  - comments.throttled_per_ip deny
```

Verejný povrch s podmienkami: článok musí existovať, komentár musí
mať identitu (prihlásený používateľ alebo guest meno/email) a IP
musí prejsť throttle-om. Každá podmienka je samostatná deny policy,
čitateľný zoznam pravidiel, ktoré môžu platiť súčasne. Autorizácia
(na rozdiel od týchto doménových pravidiel) zostáva v
`lekalo/authorization.yaml`: `comments.policy.public_submit` pre
`comments.submit` pod aktorom `public` a `comments.policy.admin_moderation`
pre tri admin operácie pod `users.role.admin`.

## 5. #63: invarianty a prechody ako príloha

To hlavné, čo 0.1.31 priniesla: issue #63, invariant-transition,
ako core library modul. Žiadny CLI subcommand ešte nečíta projektové
prílohy, ale kontrakt (`lekalo/invariant-transition/v1.0.0`) je
verejný a verifikovaný, takže sme mohli náš stavový model deklarovať
ako prílohu v `docs/lekalo/invariant-transition.nativa.json` a
overiť ju proti reálnej verzii 0.1.31.

Tri stavové priestory:

| Stavový priestor | Stavy | Prvá | cyclePolicy |
|---|---|---|---|
| `nativa.blog.article` | `public` ⇄ `members_only` | `public` | allow |
| `nativa.comments.moderation` | `pending`, `approved`, `rejected` | `pending` | allow |
| `nativa.users.account` | `active` ⇄ `inactive` | `active` | allow |

Štyri invarianty, tri `member_of_set` (stav je vždy presne jeden z
deklarovaných) a jeden `field_value` (čerstvo submitnutý komentár je
vždy `pending`). Šesť prechodov. Napríklad moderácia:

```json
{
  "transitionId": "nativa.transition.comment_approve",
  "fromStates": ["pending", "rejected"],
  "toState": "approved",
  "command": "comments.approve",
  "policyRef": "authorization.nativa/blog-admin-editing",
  "errorRefs": ["errors.comments/unknown-comment"]
}
```

Prečo `fromStates` obsahuje aj `rejected`? Pretože v tomto runtime
neexistujú terminálne stavy, admin môže schváliť zamietnutý komentár
a naopak. Preto `cyclePolicy: allow`: stroj je otvorene cyklický,
žiadne falošné „final“ štítky.

A tu je dizajnové rozhodnutie, ktoré stojí za slová: **cmdref je
command, nie endpoint**. Prechod nespúšťa HTTP cesta, ale sémantická
operácia,`comments.approve`: na ktorú sa viaže policyRef a
errorRefs. Endpoint `/mark/comments/{id}/approve` je len jedným
z možných volajúcich.

## 6. Overenie: dve plné, dve poctivé diery

Overenie prechodu medzi deklaráciou a runtime riadi `verificationMappings`.
Naše štyri mapovania sú rozdelené presne na polovicu, a to je ten
najdôležitejší riadok v tomto článku:

```json
{
  "mappingId": "nativa.mapping.comment_lifecycle_tested",
  "subject": { "kind": "transition", "id": "nativa.transition.comment_approve" },
  "relation": "tested_by",
  "status": "full",
  "evidence": { "resultStatus": "pass", "executableDigest": "sha256:81309a4c…" }
}
```

`nativa.mapping.comment_lifecycle_tested` a
`nativa.mapping.gate_state_tested` sú `full`: skutočné integračné
testy v tomto repozitári (`CommentsTest`, `BlogMembersTest`) pokrývajú
tieto prechody, s evidence digest-om.

`nativa.mapping.comment_status_runtime` a
`nativa.mapping.account_state_runtime` sú `gap`: invarianty
`comment_status_member` a `account_state_member` deklarujeme, ale
runtime ich **nevynucuje**; riadky v SQLite by teoreticky mohli
niesť cudzí status, keby niečo obišlo služby. Mapovanie to hovorí
na rovinu:

```json
{
  "mappingId": "nativa.mapping.account_state_runtime",
  "subject": { "kind": "invariant", "id": "nativa.invariant.account_state_member" },
  "relation": "verified_by",
  "targetRef": { "targetKind": "runtime_method", "targetId": "runtime.nativa/users-admin-toggle" },
  "status": "gap",
  "confidence": "low"
}
```

Mohli sme obidve mapovania ohnúť na `full`. Nesmeli sme. Audit log
tohto kitu je immutable, pretože BEFORE UPDATE/DELETE triggre
SQLite nedovolia nič prepísať, ale status komentára a `is_active`
sú bežné stĺpce, ktoré služby menia. Deklarovať `full`, keď runtime
nič nevynucuje, by bola lož do kontraktu. Model, ktorý hovorí
nepravdu o svojich hraniciach, je horší ako žiadny model.

## 7. Ako príloha vznikla: wire kontrakt na vlastnej koži

Príloha žije v `docs/lekalo/`, nie v `lekalo/`: loader vynucuje
uzavretý koreň `lekalo/` (`project.yaml`, `modules`, `targets`,
`authorization.yaml`), takže prílohy nemajú kanonický domov. Keď
upstream vyberie miesto, presunieme. (Celý postup je zdokumentovaný
v `docs/lekalo-model.md`.)

Samotný zápis bol učenie:

- **ASCII-strict.** Em-dash v description? Odmietnuté. Apostrof?
  Odmietnuté. Kontrakt je wire formát, nie prosa.
- **camelCase.** `stateSpaceId`, nie `state_space_id`: odlišné od
  YAML dokumentov v `lekalo/`.
- **Každý prechod potrebuje errorRefs.** Prechod bez chybovej
  referencie je nekompletný, vždy existuje spôsob, ako zlyhať, a
  kontrakt si to vyžaduje vypísať.
- **Self-loopy sa odmietajú.** Prechod zo stavu do toho istého
  stavu interaguje s `deadPolicy`/`cyclePolicy` a reachability
  kontrolami, deklarovať ho nemôžete, aj keby ste chceli.
- **policyRef má namespaced gramatiku** (`ns.name/name`), nie
  semantické id, preto `authorization.nativa/blog-admin-editing`.

Digesty v prílohe: `modelRef.digest` je kontraktový digest Modelu
1.0.0 z locku; `irRef.digest` je SHA-256 **kanonického IR JSON
bez trailing newline**, overené proti reálnej `model_ref` pin
recenzii. Po každej zmene modelu treba oba refreshnúť.

## Poctivé limity (stav k 0.1.31)

- **#63 nemá CLI povrch.** `lekalo_core::invariant_transition` je
  library modul; žiadny subcommand nečíta projektové prílohy. Naša
  príloha je verifikovaná scratch harness-om nad `lekalo-core`,
  nie gate-om.
- **Error kontrakty (#62)**: stále žiadny per-project deklaračný
  povrch; `errorRefs` v prechodoch a `error_ref` v policy sú
  pripnuté stabilné id bez payloadov.
- **Extended effects (#26)**: event/job/cache sémantika stále
  nedeklarovateľná.
- **Autorizačné policy stále nie sú inspectovateľné** (z dielu 8)
  a v #13 grafe sa stále neobjavujú.

---

Hlbšia pointa tohto dielu: doteraz model hovoril, čo systém *robí*,
commandy, query, endpointy, policy. Invarianty hovoria, čo systém
*znamená*: komentár je vždy v jednom z troch stavov, čerstvý komentár
je vždy pending, účet je vždy active alebo inactive. A keď deklarácia
zlyhá, keď runtime niečo z toho nedokáže zaručiť, model to pozná
ako `gap`, nie ako ticho. Počítaču nezáleží na tom, čo si zamýšľal;
mapovanie s `status: gap` je spôsob, ako sa priznať, že zámer a
realita sú stále dve rôzne veci.

→ [Diel 8, autorizačný kontrakt](/blog/lekalo-autorizacia-pre-deti): kto smie čo a čo strict review kontroluje
→ [Diel 7, model rastie](/blog/lekalo-model-rastie-pre-deti): 106 uzlov, gate na každom pushi
→ [Dokumentácia modelu na GitHub-e](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md): vrátane sekcie o #63 a wire pravidlách prílohy

## Zdroje

- [Lekalo](https://github.com/ichinya/lekalo): repozitár projektu (product 0.1.31)
- [Lekalo issue #63](https://github.com/ichinya/lekalo): invariant-transition core modul (kontrakt `lekalo/invariant-transition/v1.0.0`)
- `docs/lekalo/invariant-transition.nativa.json`: príloha tohto repozitára (tri stavové priestory, štyri invarianty, šesť prechodov)
- `docs/lekalo-model.md`: dokumentácia sémantického modelu (sekcie o komentároch, 0.1.31 a #63)
- `lekalo/modules/comments/`: comments modul: entity, commandy, deny policy
