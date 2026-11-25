---
title: "DSH vs other frameworks ... comparing agent platforms"
date: "2026-08-25"
version: "1.0.0"
tags: ["dsh-series", "deepseek", "comparison", "agents", "langchain", "autogen", "crewai"]
excerpt: "DeepSeek Harness vs LangChain vs AutoGen vs CrewAI vs OpenAI Swarm. Each has a different philosophy. DSH: everything is a plugin, no hard core, capability seams."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Comparison"
translation: "dsh-vs-iny-frameworky"
published: true
---

## Why compare

Agent frameworks are growing exponentially. Each promises the "best way" to build agents. But each has a **different philosophy**.

We'll compare5 mainstream frameworks:

| Framework | Philosophy | Core |
|-----------|-----------|------|
| DeepSeek Harness | Everything is a plugin | Cordis (TypeScript) |
| LangChain | Chain of thought | Python, LCEL |
| AutoGen | Multi-agent conversation | Python, Microsoft |
| CrewAI | Role-based agents | Python, LangChain |
| OpenAI Swarm | Lightweight handoffs | Python, stateless |

## DeepSeek Harness

**Philosophy:** "Everything is a plugin."

```
Everything is a capability seam.
Agent loop is a plugin.
Filesystem is a plugin.
Sandbox is a plugin.
```

**Pros:**
- 50 packages, each replaceable
- Capability seams (Definition / Provider / Consumer)
- Event-sourced sessions
- Sandbox (bwrap/Landlock/Seatbelt)
- TypeScript (type safety)

**Cons:**
- Developer preview (still evolving)
- TypeScript only (not Python)
- No community (yet)

**When to use:** When you need **full control** over agent architecture and have a TypeScript team.

## LangChain

**Philosophy:** "Chain everything."

```
Prompt → LLM → Parser → Tool → Memory → Output
Every step is a "chain" or "runnable"
```

**Pros:**
- Huge community
- Many integrations (100+ tools)
- LangGraph for state machines
- LangSmith for monitoring

**Cons:**
- Abstraction overload (too many layers)
- Python only
- High latency (many intermediate steps)
- Hard to debug

**When to use:** When you need to **quickly integrate** many external services and have a Python team.

## AutoGen (Microsoft)

**Philosophy:** "Agents talk to each other."

```
Agent A ←→ Agent B ←→ Agent C
Every agent is "conversational"
Conversation is the main mechanism
```

**Pros:**
- Multi-agent conversations
- Group chat between agents
- Code execution in sandbox
- Microsoft backing

**Cons:**
- Conversational paradigm is limiting
- Hard to control flow
- Python only
- No capability seams

**When to use:** When you need **multi-agent discussions** and have a Microsoft ecosystem.

## CrewAI

**Philosophy:** "Role-based agents."

```
Captain → Researcher → Writer → Reviewer
Every agent has "role", "goal", "backstory"
```

**Pros:**
- Simple API
- Role-based design (intuitive)
- Built-in delegation
- Task-based workflow

**Cons:**
- Depends on LangChain
- Limited flexibility
- No capability seams
- Python only

**When to use:** When you need a **simple multi-agent workflow** without complex configuration.

## OpenAI Swarm

**Philosophy:** "Lightweight handoffs."

```
Agent A → handoff → Agent B → handoff → Agent C
No persistent state
No tools (only handoff)
```

**Pros:**
- Ultra-light (~100 lines)
- Simple handoffs
- Stateless (easy to test)

**Cons:**
- No persistent sessions
- No tools
- No sandbox
- No capability seams

**When to use:** When you need a **simple orchestrator** for multi-agent routing.

## Comparison

| Aspect | DSH | LangChain | AutoGen | CrewAI | Swarm |
|--------|-----|-----------|---------|--------|-------|
| Core | Plugin | Chain | Conversation | Role | Handoff |
| Sessions | Event-sourced | Memory | State | State | Stateless |
| Tools | Scoped | Global | Global | Global | None |
| Sandbox | bwrap/Landlock | None | Code exec | None | None |
| Capability seams | Yes | No | No | No | No |
| Hot-swap | Yes | No | No | No | No |
| Language | TypeScript | Python | Python | Python | Python |
| Maturity | Preview | Stable | Stable | Stable | Experimental |

## Key difference: Capability Seams

Only DSH has **capability seams**. Other frameworks have:

| Framework | Instead of seams |
|-----------|-----------------|
| LangChain | Tool registry (global) |
| AutoGen | Registered functions |
| CrewAI | Tool descriptions |
| Swarm | None |

Capability seams enable:
- Swapping implementation without changing the consumer
- Scoping (each agent its own tools)
- Composition (sandbox + policy + provider)
- Testing (mock provider)

## Key difference: Event-sourced sessions

Only DSH and AutoGen have **persistent sessions**. But:

| Framework | Session type |
|-----------|-------------|
| DSH | Event-sourced (append-only log) |
| LangChain | Memory (state-based) |
| AutoGen | State (in-memory) |
| CrewAI | State (in-memory) |
| Swarm | Stateless |

Event-sourced sessions enable:
- History replay
- Compaction (log shrinking)
- Replication (P2P sync)
- Audit trail

## When to use what

| Need | Framework |
|------|-----------|
| Full control over architecture | DSH |
| Quick integration of 100+ services | LangChain |
| Multi-agent discussions | AutoGen |
| Simple role-based workflow | CrewAI |
| Lightweight orchestrator | Swarm |
| P2P distributed agents | DSH |
| Enterprise with sandbox | DSH |
| Prototype in an hour | CrewAI or Swarm |

## Conclusion

There is no "best" framework. Each solves a different problem:

- **DSH** = full control, modularity, security
- **LangChain** = integrations, community, development speed
- **AutoGen** = multi-agent conversations
- **CrewAI** = intuitive role-based agents
- **Swarm** = lightness, simplicity

If you're building a **production-grade, secure, distributed system** → DSH.
If you're building a **prototype with many integrations** → LangChain.
If you're building a **multi-agent discussion** → AutoGen.

→ [DeepSeek Harness ... 50 packages](/en/blog/deepseek-harness-architecture-overview): architecture overview
→ [Capability seams](/en/blog/capability-seams): the key differentiator
→ [Cordis ... a deep dive](/en/blog/cordis-deep-dive): plugin framework
→ [DSH and P2P](/en/blog/dsh-and-p2p): distributed agents

## Sources

- [DeepSeek Harness](https://github.com/deepseek-ai/deepseek-harness): open-source agent framework
- [LangChain](https://github.com/langchain-ai/langchain): Python framework
- [AutoGen](https://github.com/microsoft/autogen): Microsoft multi-agent
- [CrewAI](https://github.com/crewAIInc/crewAI): role-based agents
- [OpenAI Swarm](https://github.com/openai/swarm): lightweight orchestrator
