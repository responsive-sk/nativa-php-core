---
title: "Agent loop and sessions ... the event-sourced agent journal"
date: "2026-08-25"
version: "1.0.0"
tags: ["dsh-series", "deepseek", "agents", "sessions", "event-sourcing"]
excerpt: "The agent loop in DSH is a replaceable plugin, not a hard core. Sessions are event-sourced ... an append-only log, not state-based. The tool registry is scoped. Let's look at how agents think."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Architecture"
translation: "agent-loop-a-sessions"
published: true
---

## Agent loop ... the replaceable driver

In traditional frameworks, the agent loop is fixed. In DSH, it's a **plugin**. The `agent-loop` package is the only one in the entire harness that contains concrete loop logic. Everything else is abstract services or plugins.

```yaml
# Core group
packages/core/
  scope/              # Scoped-context registration
  session/            # Event-sourced session log
  system-prompt/      # Prompt assembly
  tools/              # Tool registry
  agent/              # Agent interface
  agent-default-model/ # Default model
  agent-loop/         # THE concrete driver (replaceable!)
```

### How agentLoop works

```typescript
// Creating an agent
const agent = await ctx.agents.create({
    sessionId: 'my-session-123',
    model: 'deepseek-chat',
    cwd: '/workspace'
})

// Agent runs ... turn → step → tool call → response
await agent.followup('Analyze this file')

// Agent finished ... session log is persisted
```

### Lifecycle

```
create() → setup() → enter registries → announce → start driver
    ↓
turn/step cycle (model calls, tools run, responses come back)
    ↓
dispose() → stop driver → unwind scope → detach agent → detach session
```

Key insight: **the creator and agent-loop provider are co-owners**. When one uninstalls, the agent cleans up correctly.

## Sessions ... the event-sourced journal

Every session is an **append-only log** ... not state-based.

```
Session Log:
  [1] user: "Analyze this file"
  [2] agent: [tool_call: read_file, path: "src/app.ts"]
  [3] tool: [result: "import ..."]
  [4] agent: "The file has 3 imports..."
  [5] user: "Fix the imports"
  [6] agent: [tool_call: edit_file, ...]
  [7] tool: [result: "OK"]
```

### Backends

| Backend | What it is | When to use |
|---------|-----------|-------------|
| JSONL | Append-only file | Development, simplicity |
| SQLite | Database | Production, full-text search |

### Compaction

The log can be shrunk without losing history:

```
Before: 1000 entries (100MB)
After compaction: 50 entries (5MB) ... summary + recent steps
```

Compaction preserves **key decisions** and removes **repetitive steps**.

## Tool registry ... scoped tools

The tool registry in DSH is **scoped** ... every agent has its own tools.

```typescript
// Tool registration
ctx.tools.register('read_file', {
    description: 'Read a file from the workspace',
    parameters: { path: { type: 'string' } },
    execute: async (params) => {
        return ctx.fs.read(params.path)
    }
})

// Tool execution ... model calls a tool
const result = await ctx.tools.execute('read_file', { path: 'src/app.ts' })
```

### Tool categories

| Category | Package | Examples |
|----------|---------|----------|
| Filesystem | `tool-fs` | read, write, edit |
| Search | `tool-fs-search` | glob, grep |
| Shell | `tool-shell` | bash |
| Terminal | `tool-terminal` | persistent PTY |
| Web | `tool-web` | search, fetch |
| Subagent | `tool-subagent` | delegate |

### Parallel tool calls

An agent can call **multiple tools simultaneously**:

```typescript
// Config
maxParallelToolCalls: 10  // default

// Agent calls 3 tools at once
await Promise.all([
    ctx.tools.execute('read_file', { path: 'a.ts' }),
    ctx.tools.execute('read_file', { path: 'b.ts' }),
    ctx.tools.execute('read_file', { path: 'c.ts' })
])
```

## Inbox ... the message queue

An agent has an **inbox** ... a queue of messages waiting to be processed:

| Operation | What it does |
|-----------|-------------|
| `followup()` | Add to next-turn FIFO, wake driver |
| `steer()` | Add to next-step inbox, wake driver |
| `inject()` | Add to next-step inbox, DON'T wake driver |

```typescript
// followup ... new turn
await agent.followup('Continue the analysis')

// steer ... change direction in current turn
await agent.steer('Use a different approach')

// inject ... silent input (waits for wake)
await agent.inject({ context: 'extra data' })
```

### Event taxonomy

Every inbox operation publishes an event:

```
agent/inbox/inserted  → new message
agent/inbox/spliced   → change (insert, edit, remove)
agent/inbox/claimed   → message taken for processing
agent/inbox/discarded → message canceled
agent/pre-step        → guard (can reject)
```

## Invariant companion

`agent-loop/invariant` is an optional companion that **independently rebuilds** request boundaries from the session log. It serves as:

- Verification that the session log is consistent
- Detection of duplicate requests
- Validation of message boundaries

## How it differs from traditional agent frameworks

| Aspect | Traditional | DSH |
|--------|-----------|-----|
| Agent loop | Fixed | Plugin |
| Session | State-based | Event-sourced |
| Tools | Global | Scoped per agent |
| Inbox | Request/Response | FIFO + priority |
| Parallel | No | maxParallelToolCalls |
| History | Lost | Append-only log |

→ [DeepSeek Harness ... 50 packages](/en/blog/deepseek-harness-architecture-overview): architecture overview
→ [Cordis ... a deep dive](/en/blog/cordis-deep-dive): plugin framework
→ [Capability seams](/en/blog/capability-seams): how DSH abstracts abilities

## Sources

- [DeepSeek Harness](https://github.com/deepseek-ai/deepseek-harness): open-source agent framework
- [Agent Loop README](https://github.com/deepseek-ai/deepseek-harness/tree/master/packages/core/agent-loop): detailed description
- [Session persistence](https://github.com/deepseek-ai/deepseek-harness/blob/master/.agents/notes/implemented/architecture/2026-06-14-session-persistence.md): session architecture
