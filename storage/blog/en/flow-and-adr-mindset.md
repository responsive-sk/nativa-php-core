---
title: "Flow and ADR mindset behind the minimal starter kit"
date: "2026-08-04"
version: "1.0.0"
tags: ["php", "architecture", "openspec", "adr", "workflow"]
excerpt: "OpenSpec-native workflow, zero-dependency runtime and the ADR mindset behind building a minimal PHP 8.5 starter kit from scratch."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "flow-a-adr-starter-kitu"
published: true
---

Most starter kits are forks. This one was built from the first line. Every decision documented, every trade-off recorded.

## From scratch, but systematically

The whole starter kit was built through the **OpenSpec-native workflow**:
every change has its own change with `proposal.md`, `design.md` and
`tasks.md`. The specification is written before the implementation, and
quality is checked continuously ... not at the end.

## Key ADRs

| # | Decision | Choice | Why |
|---|----------|--------|-----|
| ADR-1 | From-scratch vs fork | **From scratch** | No legacy baggage, auditable line by line |
| ADR-2 | One DB vs per-module | **Per-module SQLite** | Model isolation, natural module addition |
| ADR-3 | Framework vs zero-dep | **Zero-dependency** | Production without vendor/, full control |
| ADR-4 | Routing | **`#[Route]` attributes** | Action scanning, route-name → URL generation |
| ADR-5 | Frontend baseline | **Chrome 70 + fallbacks** | The same strategy as the cascade theme |

## Security by default

- Strict CSP with per-request nonce
- DB-backed rate limiting
- CSRF on all state-changing POSTs
- Admin role gate on `/mark/*`

The starter kit remains a collection of decisions, not a framework. Each ADR is a door you can open or close. The architecture is transparent — you see every choice because you made it.

---

A decision recorded is a decision remembered. A decision undocumented is a decision lost.

---

→ [OpenSpec workflow](/blog/what-is-openspec) — how changes are structured
→ [Anatomy of a change](/blog/anatomy-of-a-change) — from proposal to implementation

## Sources

- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP standards
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — security best practices
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
- [W3C — Web Standards](https://www.w3.org/standards/) — web standards
