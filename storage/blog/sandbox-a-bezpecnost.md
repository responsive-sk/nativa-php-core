---
title: "Sandbox a bezpečnosť ... ako DSH obmedzuje procesy"
date: "2026-08-25"
version: "1.0.1"
tags: ["dsh-series", "deepseek", "sandbox", "security", "bwrap", "landlock", "seatbelt"]
excerpt: "Agent nemá prístup k celému disku. DSH používa tri sandbox backends: bwrap (Linux), Landlock (Linux kernel), Seatbelt (macOS). Fail-closed, per-call policy, private-PID namespaces."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Security"
translation: "sandbox-and-security"
published: true
---

Keď agent spustí `bash` príkaz, má prístup k celému disku. Bez sandboxu môže čítať citlivé súbory, prepísať konfiguráciu, spustiť škodlivé príkazy. DSH to rieši cez capability seam.

## Prečo sandbox?

Keď agent spustí `bash` príkaz, má potenciálne prístup k **celému disku**. Bez sandboxu by mohol:
- Čítať citlivé súbory (`/etc/passwd`, `~/.ssh/`)
- Prepísať konfiguráciu
- Spustiť škodlivé príkazy
- Vidieť procesy iných užívateľov

DSH rieši toto cez **capability seam** ... kontrakt (`sandbox/seam`) a implementácie (`sandbox-local/`).

## Tri backends

### 1. Bubblewrap (bwrap): Linux

Najprv skúsi `bwrap` ... filesystem isolation:

```bash
# Čo bwrap obmedzuje:
bwrap \
    --ro-bind / /           # Celý host filesystem = read-only
    --dev /dev              # Čerstvé /dev
    --proc /proc            # Private-PID /proc
    --tmpfs /tmp            # Dočasný /tmp
    --bind workspace /ws    # Workspace = writable
    -- echo "Hello from sandbox"
```

**Kľúčové vlastnosti:**
- **Read-only host root** ... agent nevidí reálne `/etc`, `/home`, atď.
- **Private-PID /proc** ... agent nevidí procesy hosta (žiadne magic links ako `root` alebo `fd`)
- **Workspace-write** ... len workspace a `/tmp` sú zapisovateľné

### 2. Landlock: Linux kernel

Ak `bwrap` nie je k dispozícii, Landlock je kernel-level sandboxing:

```typescript
// Landlock je novší, bezpečnejší
// Funguje na úrovni kernel API, nie userspace nástroja
// Obmedzuje filesystem access na úrovni syscalls
```

**Kľúčové vlastnosti:**
- Kernel-level ... žiadny userspace nástroj
- Od Linux 5.13
- Môže byť **partial** na starších kerneloch (obmedzuje len expose access classes)

### 3. Seatbelt: macOS

Na macOS sa používa Apple's Seatbelt (sandbox-exec):

```bash
# Seatbelt profile: allow-default + deny file-write*
# Povoľuje len:
#   - /dev/null (read-only)
#   - workspace root (write)
#   - /tmp (write)
#   - os.tmpdir() (write)
```

**Kľúčové vlastnosti:**
- Allow-default prístup ... všetko povolené, explicitne zakázané
- `deny file-write*` ... žiadny zápis mimo povolených ciest
- Apple označil `sandbox-exec` ako deprecated, ale stále ho shipuje

### 4. Windows ACL (bonus)

Windows má vlastný restricted-token runner s per-workspace SID a revocable ACE.

## Fail-closed ... žiadne tiché prepadnutie

```typescript
// Ak žiadny sandbox backend nie je dostupný:
// → SANDBOX_UNAVAILABLE error
// → Nikdy tiché prepadnutie do unconfined módu
```

Agent **nikdy** nebeží bez sandboxu. Ak nie je dostupný žiadny backend, operácia zlyhá.

## Per-call policy

Sandbox sa neaplikuje len raz ... **každý volanie** má svoju policy:

```typescript
// read-only: agent môže čítať, nie písať
await sandbox.wrap(['cat', 'file.txt'], { mode: 'read-only' })

// workspace-write: agent môže písať len do workspace
await sandbox.wrap(['edit', 'file.txt'], { mode: 'workspace-write' })
```

### Enforcement completeness

Každý wrap reportuje **enforcement level**:

| Backend | Enforcement | Dôvod |
|---------|------------|-------|
| bwrap | full | Complete filesystem isolation |
| Landlock | full alebo partial | Závisí od kernel ABI |
| Seatbelt | full | Allow-default + explicit deny |
| Windows | partial | Everyone SID retained for process init |

## Cross-family integration

Sandbox sa integruje s filesystem seams:

```typescript
// fs-sandbox ... filesystem provider s sandbox fencing
await root.plugin(FSLocal)
await root.plugin(FSSandbox)     // fences writes podľa sandbox mode
await root.plugin(ObservationPolicy)  // read-before-edit
```

Write/edit operácie sa fence-ujú podľa sandbox mode:
- `read-only` → write/edit zamietnuté
- `workspace-write` → len workspace + temp roots

## Runner selection

Backend sa vyberá automaticky:

```
1. Skúsiť bwrap → ak OK, použiť bwrap
2. Skúsiť Landlock → ak OK, použiť Landlock
3. Ak macOS → použiť Seatbelt
4. Ak Windows → použiť ACL runner
5. Ak nič → SANDBOX_UNAVAILABLE
```

Výber sa **cacheuje** na život provideru. Inštalácia/odinštalovanie runneru si vyžaduje reload pluginu.

## Runner failure rules

Každý wrap má **structured runner-failure rules** ... pravidlá na rozlíšenie:

| Typ | Čo znamená |
|-----|-----------|
| Broken sandbox | Runner nefunguje (chýba, nie je spustiteľný) |
| Command failure | Príkaz zlyhal (exit code ≠ 0, ale sandbox funguje) |

```typescript
// Landlock: exit 125 + 'landlock-run:' fatal line = sandbox failure
// Landlock: exit 1, 2, 125 bez 'landlock-run:' = child outcome
// bwrap/Seatbelt: signature-based detection
```

## Custom runner

Môžete použiť vlastný runner:

```yaml
# config
sandbox:
  runnerCommand: /usr/local/bin/my-sandbox-runner
  runnerFailureSignatures:
    - "my-runner: fatal"
    - "sandbox-error:"
```

`runnerCommand` preskočí sondy a vyžaduje položku `runnerFailureSignatures`.

| Aspekt | DSH prístup |
|--------|-----------|
| Základný princíp | Fail-closed (nikdy unconfined) |
| Backends | bwrap, Landlock, Seatbelt, Windows ACL |
| Policy | Per-call (read-only, workspace-write) |
| Enforcement | Reportovaný (full/partial) |
| Integration | Cross-family s filesystem seams |
| Custom | runnerCommand + runnerFailureSignatures |

## Zdroje

- [DeepSeek Harness](https://github.com/deepseek-ai/deepseek-harness) ... open-source agentný framework
- [Sandbox subsystem](https://github.com/deepseek-ai/deepseek-harness/blob/master/docs/subsystems/sandbox.md) ... sandbox subsystem reference
- [Sandbox decision](https://github.com/deepseek-ai/deepseek-harness/blob/master/.agents/notes/implemented/feature/2026-07-06-sandbox.md) ... architektonický dokument
- [Cross-family fs sandbox](https://github.com/deepseek-ai/deepseek-harness/blob/master/.agents/notes/implemented/feature/2026-07-14-cross-family-fs-sandbox.md) ... filesystem integration

→ [Capability seams](/blog/capability-seams): Service Definition / Provider / Consumer
→ [DeepSeek Harness ... 50 balíkov](/blog/deepseek-harness-architektura): architektonický prehľad
→ [DSH a P2P](/blog/dsh-a-p2p): distribuovaní agenti v sieti
