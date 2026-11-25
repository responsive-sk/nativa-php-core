---
title: "OpenSpec vs tickets ... agility in the AI era"
date: "2026-08-25"
version: "1.0.0"
tags: ["security-series", "openspec", "agility", "ai", "planning", "architecture", "vision"]
excerpt: "A ticket says 'do X'. OpenSpec says 'why X exists and what its impact is'. When an AI agent implements directly from spec, the ticket loses meaning as a task and gains meaning as an intent."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Vision"
translation: "openspec-vs-tickets"
published: true
---

## A ticket no longer makes sense as a task

Traditional ticket:
```
TICKET-1234: Add "Delete" button to user page
Status: To Do
Assignee: Jano
Estimate: 2h
```

When an AI agent can implement directly from spec, this ticket is **dead**. Not because it's bad ... but because:

1. AI knows what "Delete button" means ... doesn't need a ticket
2. AI knows where to put it ... doesn't need an assignee
3. AI can implement it ... doesn't need an estimate

But the ticket **gains meaning** as an intent:
```
INTENT-1234: Users must be able to delete their accounts
Why: GDPR, user autonomy
Impact: Users module, RBAC, audit log
```

## Three levels of management

### Level 1: Ticket (what we're doing)

```
"Add Delete button"
"Write test for login"
"Fix CSS bug"
```

A ticket is an **instruction**. It says what, not why. In the era of AI agents, a ticket is **redundant** ... the agent knows what to do from the spec.

### Level 2: Spec (why we're doing it)

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

A spec is a **contract**. It says why, what the impact is, what edge cases exist. An AI agent can implement directly from the spec.

### Level 3: Intent (why it exists)

```
GDPR Article 17 ... Right to Erasure
Users must be able to delete their accounts.
Business impact: legal compliance, user trust.
Technical impact: Users module, RBAC, audit log, session cleanup.
```

Intent is **context**. It says why the whole thing exists. Neither spec nor ticket can do that.

## OpenSpec: Spec as source of truth

OpenSpec isn't a ticketing system. It's a **specification language** for:

1. **Humans** ... to understand what's being built
2. **AI agents** ... to know what to implement
3. **Tests** ... to verify it was done
4. **The future** ... to know why it was done

```yaml
# OpenSpec change
change_id: user-account-deletion
status: in_progress

# Spec ... what should happen
specs:
  - capability: user-deletion
    requirements:
      - "System SHALL allow users to delete own accounts"
      - "System SHALL create audit log entry"
      - "System SHALL invalidate all sessions"

# Tasks ... how to do it
tasks:
  - 1.1 Create UserDeletionService
  - 1.2 Add DELETE /users/{id} endpoint
  - 1.3 Add audit log integration
  - 1.4 Add session invalidation
  - 1.5 Write tests
```

## Why tickets lose meaning

### 1. AI agent doesn't need a ticket

```php
// Traditional: Jano gets a ticket, reads description, implements
// With OpenSpec: agent reads spec, implements, tests

$agent->implement(
    spec: 'user-deletion',
    change: 'user-account-deletion'
);
// Agent knows:
// - What to implement (spec requirements)
// - Where to implement (project structure)
// - How to test (acceptance criteria)
// - Why (intent from proposal.md)
```

### 2. Ticket has no versioning

A ticket is a snapshot in time. OpenSpec is a **living document**:

```yaml
# OpenSpec changes with the code
change_id: user-account-deletion
status: done

# Spec remains as historical record
specs:
  - capability: user-deletion
    requirements:
      - "System SHALL allow users to delete own accounts"  # Still valid
      - "System SHALL export data before deletion"          # Added later
```

When requirements change, OpenSpec is updated. The ticket stays old.

### 3. Ticket has no acceptance criteria

A ticket says "do it." OpenSpec says "do it THIS way and verify THIS way":

```markdown
### Scenario: User deletes own account
- GIVEN a logged-in user with role "user"
- WHEN they send DELETE /users/me
- THEN response is 204 No Content
- AND user is removed from database
- AND all sessions are invalidated
- AND audit log entry is created with actor = current user
```

The AI agent knows exactly what to verify. With a ticket, it would have to guess.

## OpenSpec + AI Factory = new workflow

```
Humans:    Intent → OpenSpec change → Review
AI agent:  OpenSpec change → Implement → Test → Commit
CI/CD:     Commit → PHPStan → Tests → Deploy
```

Humans define **why** (intent). AI agent does **how** (implement). CI/CD verifies **what** (test).

A ticket has no place in this flow. It's been replaced by **intent** + **spec**.

## Ticket as a retro tool

A ticket isn't bad ... it's **retro**. It worked in a world where:

1. Humans manually read descriptions
2. Humans manually implemented
3. Humans manually tested

In a world where an AI agent can read a spec and implement, a ticket is an **intermediate step** that loses meaning.

But intent remains. And OpenSpec is its language.

## The lesson

**A ticket is an instruction. A spec is a contract. Intent is context.**

In the era of AI agents, we need fewer instructions (AI knows what to do) and more context (AI needs to know why).

OpenSpec isn't a replacement for tickets ... it's an **evolution** from tickets to intent-driven development:

```
Ticket:    "Do X"              → AI agent doesn't need it
Spec:      "Do X this way and verify" → AI agent implements
Intent:    "Why X exists"      → AI agent decides
```

And when you combine it with Cordis (agent changes environment), Outbox (async processing), and P2P (distribution), you have the complete ecosystem for the future of software engineering.

A ticket didn't die. It evolved into intent.

→ [DeepSeek Harness and Cordis](/en/blog/deepseek-harness-and-cordis) — agent framework for intent-driven development
→ [The fridge as a P2P server](/en/blog/the-fridge-as-a-p2p-server) — decentralized cognition
→ [Cache wrappers ... good servant, bad master](/en/blog/cache-wrappers-good-servant-bad-master) — evolution stage

## Sources

- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP standards
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
- [Web.dev — Performance](https://web.dev/performance) — Core Web Vitals
- [Lighthouse Documentation](https://developer.chrome.com/docs/lighthouse/) — Lighthouse tool
