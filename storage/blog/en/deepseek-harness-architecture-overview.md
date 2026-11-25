---
title: "DeepSeek Harness ... 50 packages, one agent operating system"
date: "2026-08-25"
version: "1.0.0"
tags: ["dsh-series", "deepseek", "architecture", "cordis", "plugins", "agents"]
excerpt: "DeepSeek Harness isn't a framework ... it's a modular operating system for agents. 50 packages, capability seams, event-sourced sessions, sandbox. Let's look inside."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Architecture"
translation: "deepseek-harness-architektura"
published: true
---

## What is DeepSeek Harness

DeepSeek Harness (dsh) isn't another LLM API wrapper. It's an **agent operating system** ... an environment where agents run, and every capability is a separate package.

Look at `packages/` and you'll find **50 modules**. Each has its own role, its own contract, and its own provider. None is "the important one."

```yaml
# Simplified view of the structure
packages/
  core/          # API spine ... session, tools, agent loop
  llm/           # LLM capability ... abstract service + provider adapters
  sandbox/       # Process confinement ... bwrap/Landlock/Seatbelt
  fs/            # Filesystem ... seam, local impl, file tools
  shell/         # Bash ... executor seam, local impl
  terminal/      # Persistent PTY ... owner-scoped sessions
  code-runtime/  # Code execution ... worker-thread provider
  skill/         # Skills ... provider registry, catalog/loader
  subagent/      # Subagents ... provider-registry, delegation tool
  workflow/      # Workflows ... worker-thread engine
  extensions/    # Self-modification ... live plugin inspection
  session/       # Durable sessions ... JSONL/SQLite backends
  credentials/   # Auth ... env-over-.env provider
  web/           # Web access ... search/fetch providers
  interaction/   # Human collaboration ... approval seams
  # ... and 35 more packages
```

## Capability Seams ... the key pattern

Every capability in DSH has **three roles**:

```
Service Definition (contract)
    ↓
Service Provider (implementation)
    ↓
Consumer (user)
```

Example for filesystem:

| Role | Package | What it does |
|------|---------|-------------|
| Definition | `fs/seam` | Defines API: read, write, list, watch |
| Provider | `fs/local` | Local filesystem implementation |
| Consumer | `fs/tools` | Model-facing tools (read_file, write_file) |

Why does this matter? Because **extension plugins depend on Service Definitions, never on concrete providers**. You can swap the local filesystem for S3, SFTP, or FUSE without changing a single line in the consumer package.

```
Traditionally: [App] → [Concrete FS] → [Disk]
DSH:           [App] → [FS Seam] → [Local FS]  ← replaceable
                         ↓
                      [S3 FS]     ← alternative provider
```

## Vendor layer ... DeepSeek owns the framework

DeepSeek didn't just use Cordis ... they **vendored the entire framework layer** into their own `@deepseek-ai/*` scope:

| Package | Upstream | What it does |
|---------|----------|-------------|
| `@deepseek-ai/cordis` | cordiverse/cordis 4.0.0-rc.7 | Core plugin framework |
| `@deepseek-ai/cosmokit` | deepseek-harness fork | Utility library |
| `@deepseek-ai/schemastery` | deepseek-harness fork | Schema validation |
| `@deepseek-ai/cordis-plugin-loader` | @cordisjs/plugin-loader | Runtime plugin loading |
| `@deepseek-ai/cordis-plugin-hmr` | @cordisjs/plugin-hmr | Hot module replacement |
| `@deepseek-ai/cordis-plugin-timer` | @cordisjs/plugin-timer | Disposal-aware timers |

Why? Because they publish Cordis as **part of Harness**, not as a dependency. Every harness package declares `cordis` as a peer dependency ... and when you publish the harness, you publish the framework layer too.

## Core group ... the API spine

`core/` is the product backbone:

| Package | ctx key | Role |
|---------|---------|------|
| `scope` |, | Scoped-context registration primitive |
| `session` | `ctx.sessions` | Event-sourced session log |
| `system-prompt` | `ctx.systemPrompt` | Prompt and tool-schema assembly |
| `tools` | `ctx.tools` | Scoped tool registry |
| `agent` | `ctx.agents` | Agent interface and event vocabulary |
| `agent-default-model` | `ctx.agentDefaultModel` | Default model selection |
| `agent-loop` | `ctx.agentLoop` | Default concrete agent driver |

Key insight: **`agent-loop` is a plugin, not a hard core**. You can replace it with your own implementation without breaking the rest of the system.

## Other groups

### LLM capability
`llm/` separates the abstract service from providers. You can use DeepSeek, OpenAI, Anthropic, or a local model ... the consumer doesn't even know which provider is running.

### Sandbox
`sandbox/` confines processes. Three backends:
- **bwrap** (Bubblewrap) ... Linux, filesystem isolation
- **Landlock** ... Linux kernel sandboxing
- **Seatbelt** ... macOS sandboxing

The agent doesn't have access to the entire disk. Only the workspace and temporary files.

### Sessions
`session/` is event-sourced ... an append-only log, not state-based. Backends: JSONL (file) or SQLite (database). Compaction shrinks the log without losing history.

### Self-modification
`extensions/` allows the agent to modify itself at runtime. It's not theoretical ... they have `live plugin inspection` and `model-written plugin mount/unmount` for this.

## How it differs from traditional frameworks

| Aspect | Traditional framework | DeepSeek Harness |
|--------|----------------------|------------------|
| Core | Hard | None (everything is a plugin) |
| Agent loop | Fixed | Replaceable plugin |
| Filesystem | Direct calls | Capability seam |
| Sandbox | `exec()` | bwrap/Landlock/Seatbelt |
| Sessions | State-based | Event-sourced |
| Extensions | Plugin manager | Self-modification |

→ [DeepSeek Harness and Cordis](/en/blog/deepseek-harness-and-cordis): the plugin framework for tomorrow
→ [The fridge as a P2P server](/en/blog/the-fridge-as-a-p2p-server): hardware for P2P nodes
→ [Outbox pattern ... when cache isn't enough](/en/blog/outbox-pattern-when-cache-isnt-enough): async distribution

## Sources

- [DeepSeek Harness](https://github.com/deepseek-ai/deepseek-harness): open-source agent framework
- [Cordis](https://github.com/deepseek-ai/deepseek-harness/tree/master/vendor/cordis): TypeScript plugin framework
- [Capability Seams design](https://github.com/deepseek-ai/deepseek-harness/blob/master/.agents/notes/implemented/architecture/2026-06-13-capability-seams.md): architecture document
