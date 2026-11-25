---
title: "OpenSpec vs tickets ... agilita v ére AI"
date: "2026-08-25"
version: "1.0.2"
tags: ["security-series", "openspec", "agility", "ai", "planning", "architecture", "vision"]
excerpt: "Ticket hovorí 'urob X'. OpenSpec hovorí 'prečo X existuje a aký je jeho dopad'. Keď AI agent implementuje priamo zo specu, ticket stráca zmysel ako task a získava zmysel ako intent."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Vision"
translation: "openspec-vs-tickets-agility-in-the-ai-era"
published: true
---

Tradičný ticket hovorí „urob X“. OpenSpec hovorí „prečo X existuje a aký je jeho dopad“. Keď AI agent implementuje priamo zo specu, ticket stráca zmysel ako task a získava zmysel ako intent.

## Ticket už nemá zmysel ako task

Tradičný ticket:
```
TICKET-1234: Pridaj tlačidlo "Delete" na stránku používateľov
Status: To Do
Assignee: Jano
Estimate: 2h
```

Keď AI agent vie implementovať priamo zo specu, tento ticket je **mŕtvy**. Nie preto, že by bol zlý ... ale preto, že:

1. AI vie čo je „tlačidlo Delete“ ... nepotrebuje ticket
2. AI vie kde ho dať ... nepotrebuje assignee
3. AI vie ho implementovať ... nepotrebuje estimate

Ale ticket **získava zmysel** ako intent:
```
INTENT-1234: Používatelia musia môcť mazať svoje účty
Prečo: GDPR, user autonomy
Dopad: Users module, RBAC, audit log
```

## Tri úrovne riadenia

### Úroveň 1: Ticket (čo robíme)

```
"Pridaj tlačidlo Delete"
"Napíš test pre login"
"Oprav CSS bug"
```

Ticket je **inštrukcia**. Hovorí čo, nie prečo. V ére AI agentov je ticket **redundantný** ... agent vie čo urobiť zo specu.

### Úroveň 2: Spec (prečo to robíme)

```markdown
## Requirement: User account deletion

The system SHALL allow users to delete their own accounts.

### Scenario: User deletes own account
- GIVEN a logged-in user
- WHEN they click "Delete my account"
- THEN their data is permanently removed
- AND an audit log entry is created
- AND they are logged out
```

Spec je **kontrakt**. Hovorí prečo, aký je dopad, aké sú edge cases. AI agent vie implementovať priamo zo specu.

### Úroveň 3: Intent (prečo to existuje)

```
GDPR Article 17 ... Right to Erasure
Users must be able to delete their accounts.
Business impact: legal compliance, user trust.
Technical impact: Users module, RBAC, audit log, session cleanup.
```

Intent je **kontext**. Hovorí prečo celá vec existuje. Ani spec, ani ticket to nedokáže.

## OpenSpec: Spec ako source of truth

OpenSpec nie je ticketing system. Je to **specifikačný jazyk** pre:

1. **Ľudí** ... aby rozumeli čo sa stavia
2. **AI agentov** ... aby vedeli čo implementovať
3. **Testy** ... aby overili či sa to podarilo
4. **Budúcnosť** ... aby sa vedelo prečo sa to stalo

```yaml
# OpenSpec change
change_id: user-account-deletion
status: in_progress

# Spec ... čo sa má stať
specs:
  - capability: user-deletion
    requirements:
      - "System SHALL allow users to delete own accounts"
      - "System SHALL create audit log entry"
      - "System SHALL invalidate all sessions"

# Tasks ... ako sa to urobí
tasks:
  - 1.1 Create UserDeletionService
  - 1.2 Add DELETE /users/{id} endpoint
  - 1.3 Add audit log integration
  - 1.4 Add session invalidation
  - 1.5 Write tests
```

## Prečo tickets strácajú zmysel

### 1. AI agent nepotrebuje ticket

```php
// Tradične: Jano dostane ticket, číta opis, implementuje
// S OpenSpec: agent číta spec, implementuje, testuje

$agent->implement(
    spec: 'user-deletion',
    change: 'user-account-deletion'
);
// Agent vie:
// - Čo implementovať (spec requirements)
// - Kde implementovať (project structure)
// - Ako otestovať (acceptance criteria)
// - Prečo (intent z proposal.md)
```

### 2. Ticket nemá versioning

Ticket je snapshot v čase. OpenSpec je **živý dokument**:

```yaml
# OpenSpec sa mení spolu s kódom
change_id: user-account-deletion
status: done

# Spec zostáva ako historický záznam
specs:
  - capability: user-deletion
    requirements:
      - "System SHALL allow users to delete own accounts"  # Stále platí
      - "System SHALL export data before deletion"          # Pridané neskôr
```

Keď sa zmenia požiadavky, OpenSpec sa aktualizuje. Ticket zostane starý.

### 3. Ticket nemá acceptance criteria

Ticket hovorí „urob to“. OpenSpec hovorí „urob to TAKTO a over TAKTO“:

```markdown
### Scenario: User deletes own account
- GIVEN a logged-in user with role "user"
- WHEN they send DELETE /users/me
- THEN response is 204 No Content
- AND user is removed from database
- AND all sessions are invalidated
- AND audit log entry is created with actor = current user
```

AI agent vie presne čo overiť. S ticketom by musel hádať.

## OpenSpec + AI Factory = nový workflow

```
Ľudia:     Intent → OpenSpec change → Review
AI agent:  OpenSpec change → Implement → Test → Commit
CI/CD:     Commit → PHPStan → Tests → Deploy
```

Ľudia definujú **prečo** (intent). AI agent robí **ako** (implement). CI/CD overí **či** (test).

Ticket v tomto flow nemá miesto. Nahradil ho **intent** + **spec**.

## Ticket ako retro tool

Ticket nie je zlý ... je **retro**. Fungoval v svete kde:

1. Ľudia manuálne čítali opisy
2. Ľudia manuálne implementovali
3. Ľudia manuálne testovali

V svete kde AI agent vie čítať spec a implementovať, ticket je **medzikrok** ktorý stráca zmysel.

Ale intent zostáva. A OpenSpec je jeho jazyk.

**Ticket je inštrukcia. Spec je kontrakt. Intent je kontext.**

V ére AI agentov potrebujeme menej inštrukcií (AI vie čo robiť) a viac kontextu (AI potrebuje vedieť prečo).

OpenSpec nie je náhrada za ticket ... je to **evolúcia** z ticketu na intent-driven development:

```
Ticket:    "Urob X"              → AI agent nepotrebuje
Spec:      "Urob X takto a over" → AI agent implementuje
Intent:    "Prečo X existuje"    → AI agent rozhoduje
```

A keď to spojíte s Cordis (agent mení prostredie), Outbox (async spracovanie) a P2P (distribúcia), máte kompletný ekosystém pre budúcnosť softvérového inžinierstva.

Ticket neumrel. Evolvoval do intentu.

→ [Čo je OpenSpec](/blog/co-je-openspec-pre-deti): spec ako zdroj pravdy
→ [Anatómia change](/blog/anatomia-change-pre-deti): päť súborov, päť otázok
→ [Životný cyklus change](/blog/zivotny-cyklus-change-pre-deti): od nápadu po archív

## Zdroje

- [Manifesto for Agile Software Development](https://agilemanifesto.org/): pôvodný agile manifest
- [Martin Fowler, Design Stagnation](https://martinfowler.com/articles/is-agile-dead.html): diskusia o budúcnosti agility
- [OpenSpec, Specification as Code](https://openspec.dev/): oficiálna dokumentácia OpenSpec
- [GitHub Copilot, How AI Assists Development](https://github.com/features/copilot): AI nástroje pre vývoj
- [Google, BARD vs Developers](https://ai.google/discover/bard): AI impact na softvérové inžinierstvo
