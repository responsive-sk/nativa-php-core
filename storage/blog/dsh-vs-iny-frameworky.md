---
title: "DSH vs iné frameworky ... porovnanie agentných platforiem"
date: "2026-08-25"
version: "1.0.3"
tags: ["dsh-series", "deepseek", "comparison", "agents", "langchain", "autogen", "crewai"]
excerpt: "DeepSeek Harness vs LangChain vs AutoGen vs CrewAI vs OpenAI Swarm. Každý má inú filozofiu. DSH: všetko je plugin, žiadne pevné jadro, capability seams."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Comparison"
translation: "dsh-vs-other-frameworks"
published: true
---

## Prečo porovnávať

Agentné frameworky rastú exponenciálne. Každý sľubuje „vhodný spôsob“ ako stavať agentov. Ale každý má **inú filozofiu**.

Porovnáme 5 mainstream frameworkov:

| Framework | Filozofia | Jadro |
|-----------|----------|-------|
| DeepSeek Harness | Everything is a plugin | Cordis (TypeScript) |
| LangChain | Chain of thought | Python, LCEL |
| AutoGen | Multi-agent conversation | Python, Microsoft |
| CrewAI | Role-based agents | Python, LangChain |
| OpenAI Swarm | Lightweight handoffs | Python, stateless |

## DeepSeek Harness

**Filozofia:** „Everything is a plugin.“

```
Všetko je capability seam.
Agent loop je plugin.
Filesystem je plugin.
Sandbox je plugin.
```

**Výhody:**

- 50 packages, každý vymeniteľný
- Capability seams (Definition / Provider / Consumer)
- Event-sourced sessions
- Sandbox (bwrap/Landlock/Seatbelt)
- TypeScript (type safety) **Nevýhody:**
- Developer preview (stále sa vyvíja)
- TypeScript only (nie Python)
- Žiadna komunita (zatiaľ)

**Kedy použiť:** Keď potrebujete **plnú kontrolu** nad agent architektúrou a máte TypeScript tím.

## LangChain

**Filozofia:** "Chain everything."

```
Prompt → LLM → Parser → Tool → Memory → Output
Každý krok je "chain" alebo "runnable"
```

**Výhody:**

- Obrovská komunita
- Množstvo integrácií (100+ tools)
- LangGraph pre state machines
- LangSmith pre monitoring **Nevýhody:**
- Abstraction overload (príliš veľa vrstiev)
- Python only
- Vysoká latencia (veľa medzikrokov)
- Ťažké debugovať

**Kedy použiť:** Keď potrebujete **rýchlo integrovať** veľa externých služieb a máte Python tím.

## AutoGen (Microsoft)

**Filozofia:** „Agents talk to each other.“

```
Agent A ←→ Agent B ←→ Agent C
Každý agent je „conversable“
Konverzácia je hlavný mechanizmus
```

**Výhody:**

- Multi-agent conversations
- Group chat medzi agentmi
- Code execution v sandboxe
- Microsoft backing **Nevýhody:**
- Conversational paradigm je obmedzujúci
- Ťažké kontrolovať flow
- Python only
- Žiadne capability seams

**Kedy použiť:** Keď potrebujete **multi-agent diskusie** a máte Microsoft ekosystém.

## CrewAI

**Filozofia:** "Role-based agents."

```
Captain → Researcher → Writer → Reviewer
Každý agent má "role", "goal", "backstory"
```

**Výhody:**

- Jednoduché API
- Role-based design (intuitívne)
- Built-in delegation
- Task-based workflow **Nevýhody:**
- Závisí na LangChain
- Obmedzená flexibilita
- Žiadne capability seams
- Python only

**Kedy použiť:** Keď potrebujete **jednoduchý multi-agent workflow** bez komplexnej konfigurácie.

## OpenAI Swarm

**Filozofia:** „Lightweight handoffs.“

```
Agent A → handoff → Agent B → handoff → Agent C
Žiadne persistent state
Žiadne tools (len handoff)
```

**Výhody:**

- Ultra-ľahký (~100 riadkov)
- Jednoduché handoffs
- Stateless (ľahké testovať) **Nevýhody:**
- Žiadne persistent sessions
- Žiadne tools
- Žiadne sandbox
- Žiadne capability seams

**Kedy použiť:** Keď potrebujete **jednoduchý orchestrátor** pre multi-agent routing.

## Porovnanie

| Aspekt | DSH | LangChain | AutoGen | CrewAI | Swarm |
|--------|-----|-----------|---------|--------|-------|
| Jadro | Plugin | Chain | Conversation | Role | Handoff |
| Sessions | Event-sourced | Memory | State | State | Stateless |
| Tools | Scoped | Global | Global | Global | Žiadne |
| Sandbox | bwrap/Landlock | Žiadny | Code exec | Žiadny | Žiadny |
| Capability seams | Áno | Nie | Nie | Nie | Nie |
| Hot-swap | Áno | Nie | Nie | Nie | Nie |
| Language | TypeScript | Python | Python | Python | Python |
| Zrelosť | Preview | Stable | Stable | Stable | Experimental |

## Kľúčový rozdiel: Capability Seams

Len DSH má **capability seams**. Ostatné frameworky majú:

| Framework | Namiesto seams |
|-----------|---------------|
| LangChain | Tool registry (global) |
| AutoGen | Registered functions |
| CrewAI | Tool descriptions |
| Swarm | Žiadne |

Capability seams umožňujú:

- Výmenu implementácie bez zmeny consumer
- Scoping (každý agent svoje tools)
- Compozícia (sandbox + policy + provider)
- Testovanie (mock provider)

## Kľúčový rozdiel: Event-sourced sessions

Len DSH a AutoGen majú **persistent sessions**. Ale:

| Framework | Session type |
|-----------|-------------|
| DSH | Event-sourced (append-only log) |
| LangChain | Memory (state-based) |
| AutoGen | State (in-memory) |
| CrewAI | State (in-memory) |
| Swarm | Stateless |

Event-sourced sessions umožňujú:

- Replay histórie
- Compaction (zmenšenie logu)
- Replication (P2P sync)
- Audit trail

## Kedy použiť čo

| Potreba | Framework |
|---------|-----------|
| Plná kontrola nad architektúrou | DSH |
| Rýchla integrácia 100+ služieb | LangChain |
| Multi-agent diskusie | AutoGen |
| Jednoduchý role-based workflow | CrewAI |
| Ľahký orchestrátor | Swarm |
| P2P distribuovaní agenti | DSH |
| Enterprise s sandboxom | DSH |
| Prototyp za hodinu | CrewAI alebo Swarm |

## Záver

Neexistuje "univerzálny" framework. Každý rieši iný problém:

- **DSH** = plná kontrola, modulárnosť, bezpečnosť
- **LangChain** = integrácie, komunita, rýchlosť vývoja
- **AutoGen** = multi-agent konverzácie
- **CrewAI** = intuitívne role-based agenty
- **Swarm** = ľahkosť, jednoduchosť Ak staviate **produktívny, bezpečný, distribuovaný systém** → DSH.
Ak staviate **prototyp s množstvom integrácií** → LangChain.

→ [DeepSeek Harness ... 50 balíkov](/blog/deepseek-harness-architektura) — architektonický prehľad
→ [Capability seams](/blog/capability-seams) — Service Definition / Provider / Consumer
→ [Chladnička ako P2P server](/blog/chladnicka-ako-p2p-server) — hardvér pre P2P uzly

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — bezpečnostné best practices
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
- [W3C — Web Standards](https://www.w3.org/standards/) — web štandardy
