---
title: "DeepSeek Harness ... 50 balíkov, jeden agentný operačný systém"
date: "2026-08-25"
version: "1.0.2"
tags: ["dsh-series", "deepseek", "architecture", "cordis", "plugins", "agents"]
excerpt: "DeepSeek Harness nie je framework ... je to modulárny operačný systém pre agentov. 50 balíkov, capability seams, event-sourced sessions, sandbox. Poďme sa pozrieť dovnútra."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Architecture"
translation: "deepseek-harness-architecture-overview"
published: true
---

Otvorte 'packages/' v DeepSeek Harness. Nájdete 50 balíkov. Každý má svoju úlohu, svoj kontrakt a svojho providera. Žiadny nie je ten dôležitý.

Tradičné frameworky majú jedno jadro a milión pluginov. DSH má plugin, ktorým je všetko, agent, nástroje, filesystem, shell, sandbox. Ak niečo nefunguje, vymeníte plugin. Nie framework.


## Čo je DeepSeek Harness

DeepSeek Harness (dsh) nie je ďalší LLM API wrapper. Je to **agentný operačný systém** ... prostredie v ktorom bežia agenti, a každá schopnosť je samostatný balík.

Keď sa pozriete na `packages/`, nájdete **50 balíkov**. Každý má svoju úlohu, svoj kontrakt a svojho providera. Žiadny nie je „ten dôležitý“.

```yaml
# Zjednodušený pohľad na štruktúru
packages/
  core/          # API spine ... session, tools, agent loop
  llm/           # LLM capability ...抽象ná služba + provider adapters
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
  # ... a ďalších 35 balíkov
```

## Capability Seams ... kľúčový vzor

Každá schopnosť v DSH má **tri roly**:

```
Service Definition (kontrakt)
    ↓
Service Provider (implementácia)
    ↓
Consumer (používateľ)
```

Príklad pre filesystem:

| Rola | Balík | Čo robí |
|------|-------|---------|
| Definition | `fs/seam` | Definuje API: read, write, list, watch |
| Provider | `fs/local` | Implementácia pre lokálny filesystem |
| Consumer | `fs/tools` | Model-facing nástroje (read_file, write_file) |

Prečo je to dôležité? Lebo **extension plugins závisia na Service Definitions, nikdy na concrete providers**. Môžete vymeniť lokálny filesystem za S3, SFTP alebo FUSE bez toho, aby ste zmenili čo i len jeden riadok v consumer balíku.

```
Tradične:  [App] → [Concrete FS] → [Disk]
DSH:       [App] → [FS Seam] → [Local FS]  ← vymeniteľné
                     ↓
                  [S3 FS]     ← alternatívny provider
```

## Vendor layer ... DeepSeek vlastní framework

DeepSeek nevendloval len Cordis ... **vendloval celú frameworkovú vrstvu** do `@deepseek-ai/*` scope:

| Package | Upstream | Čo robí |
|---------|----------|---------|
| `@deepseek-ai/cordis` | cordiverse/cordis 4.0.0-rc.7 | Core plugin framework |
| `@deepseek-ai/cosmokit` | deepseek-harness fork | Utility library |
| `@deepseek-ai/schemastery` | deepseek-harness fork | Schema validation |
| `@deepseek-ai/cordis-plugin-loader` | @cordisjs/plugin-loader | Runtime plugin loading |
| `@deepseek-ai/cordis-plugin-hmr` | @cordisjs/plugin-hmr | Hot module replacement |
| `@deepseek-ai/cordis-plugin-timer` | @cordisjs/plugin-timer | Disposal-aware timers |

Prečo? Lebo publishujú Cordis ako **súčasť Harness**, nie ako závislosť. Každý harness balík deklaruje `cordis` ako peer dependency ... a keď publikujete harness, publikujete aj frameworkovú vrstvu.

## Core group ... API spine

`core/` je chrbtica produktu:

| Balík | ctx key | Úloha |
|-------|---------|-------|
| `scope` |, | Scoped-context registration primitive |
| `session` | `ctx.sessions` | Event-sourced session log |
| `system-prompt` | `ctx.systemPrompt` | Prompt a tool-schema assembly |
| `tools` | `ctx.tools` | Scoped tool registry |
| `agent` | `ctx.agents` | Agent interface a event vocabulary |
| `agent-default-model` | `ctx.agentDefaultModel` | Default model selection |
| `agent-loop` | `ctx.agentLoop` | Default concrete agent driver |

Kľúčové: **`agent-loop` je plugin, nie pevné jadro**. Môžete ho vymeniť za vlastnú implementáciu bez toho, aby ste rozbili zvyšok systému.

## Ďalšie skupiny

### LLM capability
`llm/` oddeľuje abstraktnú službu od providerov. Môžete použiť DeepSeek, OpenAI, Anthropic, alebo lokálny model ... consumer ani nevie, ktorý provider beží.

### Sandbox
`sandbox/` obmedzuje procesy. Tri backends:
- **bwrap** (Bubblewrap) ... Linux, filesystem isolation
- **Landlock** ... Linux kernel sandboxing
- **Seatbelt** ... macOS sandboxing

Agent nemá prístup k celému disku. Len k workspace a dočasným súborom.

### Sessions
`session/` je event-sourced ... append-only log, nie state-based. Backendy: JSONL (súbor) alebo SQLite (databáza). Compaction zmenšuje log bez straty histórie.

### Self-modification
`extensions/` umožňuje agentovi meniť sám seba za behu. Nie je to teória ... majú na to `live plugin inspection` a `model-written plugin mount/unmount`.

## Ako sa to líši od tradičných frameworkov

| Aspekt | Tradičný framework | DeepSeek Harness |
|--------|-------------------|------------------|
| Jadro | Pevné | Žiadne (všetko je plugin) |
| Agent loop | Pevný | Vymeniteľný plugin |
| Filesystem | Priame volanie | Capability seam |
| Sandbox | `exec()` | bwrap/Landlock/Seatbelt |
| Sessions | State-based | Event-sourced |
| Extensions | Plugin manager | Self-modification |

## Zdroje

- [DeepSeek Harness](https://github.com/deepseek-ai/deepseek-harness) ... open-source agentný framework
- [Cordis](https://github.com/deepseek-ai/deepseek-harness/tree/master/vendor/cordis) ... TypeScript plugin framework
- [Capability Seams design](https://github.com/deepseek-ai/deepseek-harness/blob/master/.agents/notes/implemented/architecture/2026-06-13-capability-seams.md) ... architektonický dokument

→ [Capability seams](/blog/capability-seams): Service Definition / Provider / Consumer
→ [Cordis ... hlboký ponor](/blog/cordis-hlboky-ponor): plugin framework
→ [DSH a P2P](/blog/dsh-a-p2p): distribuovaní agenti v sieti
