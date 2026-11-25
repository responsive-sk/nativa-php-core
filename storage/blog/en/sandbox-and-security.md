---
title: "Sandbox and security ... how DSH confines processes"
date: "2026-08-25"
version: "1.0.0"
tags: ["dsh-series", "deepseek", "sandbox", "security", "bwrap", "landlock", "seatbelt"]
excerpt: "The agent doesn't have access to the entire disk. DSH uses three sandbox backends: bwrap (Linux), Landlock (Linux kernel), Seatbelt (macOS). Fail-closed, per-call policy, private-PID namespaces."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Security"
translation: "sandbox-a-bezpecnost"
published: true
---

A `bash` command runs. The agent reads `/etc/passwd`. No sandbox, no check, no error.

## Why sandbox?

When an agent runs a `bash` command, it potentially has access to the **entire disk**. Without a sandbox, it could:
- Read sensitive files (`/etc/passwd`, `~/.ssh/`)
- Overwrite configuration
- Run malicious commands
- See other users' processes

DSH solves this through a **capability seam** ... a contract (`sandbox/seam`) and implementations (`sandbox-local/`).

## Three backends

### 1. Bubblewrap (bwrap): Linux

First it tries `bwrap` ... filesystem isolation:

```bash
# What bwrap confines:
bwrap \
    --ro-bind / /           # Entire host filesystem = read-only
    --dev /dev              # Fresh /dev
    --proc /proc            # Private-PID /proc
    --tmpfs /tmp            # Temporary /tmp
    --bind workspace /ws    # Workspace = writable
    -- echo "Hello from sandbox"
```

**Key properties:**
- **Read-only host root** ... agent can't see real `/etc`, `/home`, etc.
- **Private-PID /proc** ... agent can't see host processes (no magic links like `root` or `fd`)
- **Workspace-write** ... only workspace and `/tmp` are writable

### 2. Landlock: Linux kernel

If `bwrap` isn't available, Landlock provides kernel-level sandboxing:

```typescript
// Landlock is newer, more secure
// Works at kernel API level, not userspace tool
// Confines filesystem access at syscall level
```

**Key properties:**
- Kernel-level ... no userspace tool
- Available since Linux 5.13
- May be **partial** on older kernels (confines only exposed access classes)

### 3. Seatbelt: macOS

On macOS, Apple's Seatbelt (sandbox-exec) is used:

```bash
# Seatbelt profile: allow-default + deny file-write*
# Only allows:
#   - /dev/null (read-only)
#   - workspace root (write)
#   - /tmp (write)
#   - os.tmpdir() (write)
```

**Key properties:**
- Allow-default approach ... everything denied, explicitly allowed
- `deny file-write*` ... no writes outside allowed paths
- Apple marked `sandbox-exec` as deprecated but still ships it

### 4. Windows ACL (bonus)

Windows has its own restricted-token runner with per-workspace SID and revocable ACE.

## Fail-closed ... no silent fallback

```typescript
// If no sandbox backend is available:
// → SANDBOX_UNAVAILABLE error
// → Never silently falls through to unconfined mode
```

The agent **never** runs without a sandbox. If no backend is available, the operation fails.

## Per-call policy

The sandbox isn't applied once ... **every call** has its own policy:

```typescript
// read-only: agent can read, not write
await sandbox.wrap(['cat', 'file.txt'], { mode: 'read-only' })

// workspace-write: agent can only write to workspace
await sandbox.wrap(['edit', 'file.txt'], { mode: 'workspace-write' })
```

### Enforcement completeness

Every wrap reports an **enforcement level**:

| Backend | Enforcement | Reason |
|---------|------------|--------|
| bwrap | full | Complete filesystem isolation |
| Landlock | full or partial | Depends on kernel ABI |
| Seatbelt | full | Allow-default + explicit deny |
| Windows | partial | Everyone SID retained for process init |

## Cross-family integration

The sandbox integrates with filesystem seams:

```typescript
// fs-sandbox ... filesystem provider with sandbox fencing
await root.plugin(FSLocal)
await root.plugin(FSSandbox)     // fences writes per sandbox mode
await root.plugin(ObservationPolicy)  // read-before-edit
```

Write/edit operations are fenced per sandbox mode:
- `read-only` → write/edit rejected
- `workspace-write` → only workspace + temp roots allowed

## Runner selection

The backend is selected automatically:

```
1. Try bwrap → if OK, use bwrap
2. Try Landlock → if OK, use Landlock
3. If macOS → use Seatbelt
4. If Windows → use ACL runner
5. If nothing → SANDBOX_UNAVAILABLE
```

Selection is **cached** for the provider lifetime. Installing/removing a runner requires plugin reload.

## Runner failure rules

Every wrap has **structured runner-failure rules** ... rules to distinguish:

| Type | What it means |
|------|-------------|
| Broken sandbox | Runner doesn't work (missing, not executable) |
| Command failure | Command failed (exit code ≠ 0, but sandbox works) |

```typescript
// Landlock: exit 125 + 'landlock-run:' fatal line = sandbox failure
// Landlock: exit 1, 2, 125 without 'landlock-run:' = child outcome
// bwrap/Seatbelt: signature-based detection
```

## Custom runner

You can use your own runner:

```yaml
# config
sandbox:
  runnerCommand: /usr/local/bin/my-sandbox-runner
  runnerFailureSignatures:
    - "my-runner: fatal"
    - "sandbox-error:"
```

`runnerCommand` skips probes and requires `runnerFailureSignatures` entries.

The solution was three backends with a shared contract. We replaced the monolithic sandbox with a seam pattern: `sandbox/seam` defines the interface, `sandbox-local/` implements it. Fail-closed by default.

Sandbox remains the simplest security boundary, just not one you should skip. When you know what the agent can access, the policy writes itself. The next person who runs a command should not have to guess whether it's confined.

## Summary

| Aspect | DSH approach |
|--------|-------------|
| Core principle | Fail-closed (never unconfined) |
| Backends | bwrap, Landlock, Seatbelt, Windows ACL |
| Policy | Per-call (read-only, workspace-write) |
| Enforcement | Reported (full/partial) |
| Integration | Cross-family with filesystem seams |
| Custom | runnerCommand + runnerFailureSignatures |

→ [Capability seams](/en/blog/capability-seams): how DSH abstracts abilities
→ [DeepSeek Harness ... 50 packages](/en/blog/deepseek-harness-architecture-overview): architecture overview
→ [Cordis ... a deep dive](/en/blog/cordis-deep-dive): plugin framework

## Sources

- [DeepSeek Harness](https://github.com/deepseek-ai/deepseek-harness): open-source agent framework
- [Sandbox subsystem](https://github.com/deepseek-ai/deepseek-harness/blob/master/docs/subsystems/sandbox.md): sandbox subsystem reference
- [Sandbox decision](https://github.com/deepseek-ai/deepseek-harness/blob/master/.agents/notes/implemented/feature/2026-07-06-sandbox.md): architecture document
- [Cross-family fs sandbox](https://github.com/deepseek-ai/deepseek-harness/blob/master/.agents/notes/implemented/feature/2026-07-14-cross-family-fs-sandbox.md): filesystem integration
