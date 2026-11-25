---
title: "Capability seams ... how DSH abstracts abilities from implementations"
date: "2026-08-25"
version: "1.0.0"
tags: ["dsh-series", "deepseek", "architecture", "seams", "providers"]
excerpt: "Every capability in DSH has three roles: Service Definition, Service Provider, Consumer. This pattern lets you swap the filesystem for S3, shell for SSH, or sandbox for Landlock ... without changing a single line in the consumer."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Architecture"
translation: "capability-seams"
published: true
---

## What is a Capability Seam

In traditional frameworks, the filesystem is directly wired to the disk. In DSH, the filesystem is a **capability seam** ... contract, implementation, and consumer are separated.

```
Traditionally: [App] → [Concrete FS] → [Disk]
DSH:           [App] → [FS Seam] → [Local FS]  ← replaceable
                         ↓
                      [S3 FS]     ← alternative provider
```

This pattern repeats across every DSH capability.

## Three roles of every seam

### 1. Service Definition (contract)

Defines the API ... what can be done, not how.

```typescript
// fs/seam ... defines the API
interface FileSystem {
    read(path: string): Promise<string>
    write(path: string, content: string): Promise<void>
    edit(path: string, patch: string): Promise<void>
    list(dir: string): Promise<string[]>
}
```

### 2. Service Provider (implementation)

Implements the contract for a specific environment.

```typescript
// fs-local ... local filesystem
class LocalFileSystem implements FileSystem {
    async read(path: string) { return fs.readFile(path, 'utf-8') }
    async write(path: string, content: string) { await fs.writeFile(path, content) }
    // ...
}

// fs-e2b ... remote E2B filesystem
class E2BFileSystem implements FileSystem {
    async read(path: string) { return this.e2b.readFile(path) }
    // ...
}
```

### 3. Consumer (user)

Uses the API without knowing which provider is running.

```typescript
// tool-fs ... model-facing tools
class FileSystemTools extends Service {
    async readFile(path: string) {
        // Uses ctx.fs ... doesn't know if it's Local, E2B, or S3
        return this.ctx.fs.read(path)
    }
}
```

## Example: Filesystem seams

DSH has **6 packages** for the filesystem:

| Package | Role | What it does |
|---------|------|-------------|
| `fs/` | Service Definition | Contract: read, write, edit, list |
| `fs-local/` | Provider | Local filesystem |
| `fs-e2b/` | Provider | Remote E2B filesystem |
| `fs-sandbox/` | Provider | Sandbox-enforcing filesystem |
| `fs-observation-policy/` | Policy gate | Observed-state + read-before-edit |
| `tool-fs/` | Consumer | Model-facing read/write/edit tools |

Key insight: **the policy gate (`fs-observation-policy`) is a plugin, not a service**. Drop it and you get an unconstrained provider instead of a broken tool.

## Example: Shell seams

| Package | Role | What it does |
|---------|------|-------------|
| `shell/seam` | Service Definition | Bash executor contract |
| `shell/local` | Provider | Local bash |
| `tool-shell` | Consumer | Model-facing bash tool |

## Example: Sandbox seams

| Package | Role | What it does |
|---------|------|-------------|
| `sandbox/seam` | Service Definition | Process confinement contract |
| `sandbox/bwrap` | Provider | Bubblewrap (Linux) |
| `sandbox/landlock` | Provider | Landlock (Linux kernel) |
| `sandbox/seatbelt` | Provider | Seatbelt (macOS) |

The agent doesn't have access to the entire disk. Only the workspace and temporary files. Which sandbox backend is used depends on the OS.

## Why this matters

### 1. Swap without changing the consumer

```typescript
// Before: local filesystem
await root.plugin(LocalFS)

// After: remote E2B filesystem
await root.plugin(E2BFS)

// Consumer (tool-fs) hasn't changed a single line
```

### 2. Composition

```typescript
// Sandbox + policy gate + local FS
await root.plugin(LocalFS)
await root.plugin(SandboxFS)     // fences writes
await root.plugin(ObservationPolicy)  // read-before-edit
```

### 3. Testing

```typescript
// In tests: mock filesystem
await root.plugin(MockFS)

// In production: real filesystem
await root.plugin(LocalFS)
```

## How it differs from dependency injection

| Aspect | DI (e.g., Symfony) | Capability Seams |
|--------|-------------------|------------------|
| Dependency | Constructor injection | Proxy resolution |
| Swap | New class in container | New plugin |
| Lifecycle | Container manages | Fiber manages |
| Scoping | Request scope | Plugin scope |
| Composition | Aliases, bindings | Plugin stacking |

DI tells you "which class is used." Capability seams tell you "which capability is available" ... and leave it to you to implement it.

→ [DeepSeek Harness ... 50 packages](/en/blog/deepseek-harness-architecture-overview) — architecture overview
→ [Cordis ... a deep dive](/en/blog/cordis-deep-dive) — plugin framework
→ [The fridge as a P2P server](/en/blog/the-fridge-as-a-p2p-server) — hardware for P2P nodes

## Sources

- [DeepSeek Harness](https://github.com/deepseek-ai/deepseek-harness) — open-source agent framework
- [Capability Seams design](https://github.com/deepseek-ai/deepseek-harness/blob/master/.agents/notes/implemented/architecture/2026-06-13-capability-seams.md) — architecture document
- [FS subsystem](https://github.com/deepseek-ai/deepseek-harness/blob/master/docs/subsystems/filesystem.md) — filesystem subsystem reference
