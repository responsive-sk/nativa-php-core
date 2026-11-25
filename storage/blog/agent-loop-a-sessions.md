---
title: "Agent loop a sessions ... event-sourced denník agenta"
date: "2026-08-25"
version: "1.0.2"
tags: ["dsh-series", "deepseek", "agents", "sessions", "event-sourcing"]
excerpt: "Agent loop v DSH je vymeniteľný plugin, nie pevné jadro. Sessions sú event-sourced ... append-only log, nie state-based. Tool registry je scoped. Poďme sa pozrieť, ako agenti myslia."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Architecture"
translation: "agent-loop-and-sessions"
published: true
---

Agent beží v slučke. Načíta dáta, spracuje ich, vráti odpoveď. Ale čo sa stane, keď agent potrebuje urobiť desať vecí za sebou? Keď potrebuje pamätať si, čo urobil minule?

V tradičných frameworkoch je agent loop pevný. Nedá sa vymeniť. Nedá sa rozšíriť. Je to čierna skrinka, vstúpi request, výjde odpoveď. Ale agenti nie sú HTTP requesty. Agenti sú dlhodobé procesy, ktoré žijú, dýchajú a učia sa.

## Agent loop ... vymeniteľný driver


```yaml
# Core group
packages/core/
  scope/              # Scoped-context registration
  session/            # Event-sourced session log
  system-prompt/      # Prompt assembly
  tools/              # Tool registry
  agent/              # Agent interface
  agent-default-model/ # Default model
  agent-loop/         # THE concrete driver (vymeniteľný!)
```

### Ako agentLoop funguje

```typescript
// Vytvorenie agenta
const agent = await ctx.agents.create({
    sessionId: 'my-session-123',
    model: 'deepseek-chat',
    cwd: '/workspace'
})

// Agent beží ... turn → step → tool call → response
await agent.followup('Analyzuj súbor')

// Agent skončil ... session log je uložený
```

### Lifecycle

```
create() → setup() → enter registries → announce → start driver
    ↓
turn/step cycle (model volá, tooly bežia, odpovede prichádzajú)
    ↓
dispose() → stop driver → unwind scope → detach agent → detach session
```

Kľúčové: **creator a agent-loop provider sú co-owners**. Keď sa jeden odinštaluje, agent sa korektne cleanupne.

## Sessions ... event-sourced denník

Každá session je **append-only log** ... nie state-based.

```
Session Log:
  [1] user: "Analyzuj súbor"
  [2] agent: [tool_call: read_file, path: "src/app.ts"]
  [3] tool: [result: "import ..."]
  [4] agent: "Súbor obsahuje 3 importy..."
  [5] user: "Uprav importy"
  [6] agent: [tool_call: edit_file, ...]
  [7] tool: [result: "OK"]
```

### Backendy

| Backend | Čo je | Kedy použiť |
|---------|-------|-------------|
| JSONL | Append-only súbor | Vývoj, jednoduchosť |
| SQLite | Databáza | Produkcia, full-text search |

### Compaction

Log sa môže zmenšiť bez straty histórie:

```
Pred: 1000 entries (100MB)
Po compaction: 50 entries (5MB) ... sumár + posledné kroky
```

Compaction zachováva **kľúčové rozhodnutia** a maže **opakujúce sa kroky**.

## Tool registry ... scoped nástroje

Tool registry v DSH je **scoped** ... každý agent má svoje nástroje.

```typescript
// Tool registration
ctx.tools.register('read_file', {
    description: 'Read a file from the workspace',
    parameters: { path: { type: 'string' } },
    execute: async (params) => {
        return ctx.fs.read(params.path)
    }
})

// Tool execution ... model volá nástroj
const result = await ctx.tools.execute('read_file', { path: 'src/app.ts' })
```

### Tool categories

| Category | Balík | Príklady |
|----------|-------|----------|
| Filesystem | `tool-fs` | read, write, edit |
| Search | `tool-fs-search` | glob, grep |
| Shell | `tool-shell` | bash |
| Terminal | `tool-terminal` | persistent PTY |
| Web | `tool-web` | search, fetch |
| Subagent | `tool-subagent` | delegate |

### Parallel tool calls

Agent môže volať **viacero toolov súčasne**:

```typescript
// Config
maxParallelToolCalls: 10  // default

// Agent volá 3 tooly naraz
await Promise.all([
    ctx.tools.execute('read_file', { path: 'a.ts' }),
    ctx.tools.execute('read_file', { path: 'b.ts' }),
    ctx.tools.execute('read_file', { path: 'c.ts' })
])
```

## Inbox ... fronta správ

Agent má **inbox** ... fronta správ čakajúcich na spracovanie:

| Operácia | Čo robí |
|----------|---------|
| `followup()` | Pridá do next-turn FIFO, wake driver |
| `steer()` | Pridá do next-step inbox, wake driver |
| `inject()` | Pridá do next-step inbox, NE-wake driver |

```typescript
// followup ... nový turn
await agent.followup('Pokračuj v analýze')

// steer ... zmena smeru v aktuálnom turne
await agent.steer('Použi iný prístup')

// inject ... tichý vstup (čaká na wake)
await agent.inject({ context: 'extra data' })
```

### Event taxonomy

Každá operácia v inboxe publikuje event:

```
agent/inbox/inserted  → nová správa
agent/inbox/spliced   → zmena (insert, edit, remove)
agent/inbox/claimed   → správa prevzatá na spracovanie
agent/inbox/discarded → správa zrušená
agent/pre-step        → guard (môže zamietnuť)
```

## Invariant companion

`agent-loop/invariant` je optional companion, ktorý **nezávisle rebuilduje** request boundary z session logu. Slúži na:

- Overenie, že session log je konzistentný
- Detekcia duplicitných requestov
- Validácia message boundary

## Ako sa to líši od tradičných agent frameworkov

| Aspekt | Tradičný | DSH |
|--------|---------|-----|
| Agent loop | Pevný | Plugin |
| Session | State-based | Event-sourced |
| Tools | Global | Scoped per agent |
| Inbox | Request/Response | FIFO + priority |
| Parallel | Nie | maxParallelToolCalls |
| History | Stratená | Append-only log |

## Zdroje

- [DeepSeek Harness](https://github.com/deepseek-ai/deepseek-harness) ... open-source agentný framework
- [Agent Loop README](https://github.com/deepseek-ai/deepseek-harness/tree/master/packages/core/agent-loop) ... detailný popis
- [Session persistence](https://github.com/deepseek-ai/deepseek-harness/blob/master/.agents/notes/implemented/architecture/2026-06-14-session-persistence.md) ... session architecture

→ [Capability seams](/blog/capability-seams): Service Definition / Provider / Consumer
→ [DeepSeek Harness ... 50 balíkov](/blog/deepseek-harness-architektura): architektonický prehľad
→ [Cordis ... hlboký ponor](/blog/cordis-hlboky-ponor): plugin framework
