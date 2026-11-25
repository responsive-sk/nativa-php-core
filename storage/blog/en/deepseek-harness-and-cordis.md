---
title: "DeepSeek Harness and Cordis ... the plugin framework for tomorrow"
date: "2026-08-25"
version: "1.0.0"
tags: ["security-series", "deepseek", "cordis", "plugins", "agents", "architecture", "vision"]
excerpt: "Everything is a plugin. No hard core. Agent changes the environment at runtime. Hot-swap without restart. DeepSeek Harness built on Cordis is exactly the kind of modular system you need for P2P freeNet."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Vision"
translation: "deepseek-harness-a-cordis"
published: true
---

## What is DeepSeek Harness

DeepSeek Harness (dsh) isn't another LLM API wrapper. It's an **agent framework** ... an environment where agents run, and every agent is a plugin.

The core is built on **Cordis** ... a plugin framework where:
- **Nothing is fixed** ... all components are replaceable
- **Agent changes the environment** ... can load/uninstall plugins at runtime
- **Effects are tracked** ... when a plugin is uninstalled, resources are released

## DeepSeek owns the framework layer

DeepSeek didn't just use Cordis ... they **vendored the entire framework layer** into their own `@deepseek-ai/*` scope:

| Package | Upstream | What it does |
|---------|----------|-------------|
| `@deepseek-ai/cordis` | cordiverse/cordis 4.0.0-rc.7 | Core plugin framework |
| `@deepseek-ai/cosmokit` | deepseek-harness fork | Utility library |
| `@deepseek-ai/schemastery` | deepseek-harness fork | Schema validation |
| `@deepseek-ai/cordis-plugin-loader` | @cordisjs/plugin-loader | Runtime plugin loading |
| `@deepseek-ai/cordis-plugin-hmr` | @cordisjs/plugin-hmr | Hot module replacement |
| `@deepseek-ai/cordis-plugin-timer` | @cordisjs/plugin-timer | Disposal-aware timers |
| `@deepseek-ai/cordis-plugin-include` | @cordisjs/plugin-include | YAML/JSON config |

Why? Because they publish Cordis as **part of Harness**, not as a dependency. Every harness package declares `cordis` as a peer dependency ... and when you publish the harness, you publish the framework layer too.

### Local patches

DeepSeek didn't take Cordis as-is from upstream. They added:

- **Fiber lifecycle hardening** ... closes reentrant disposal gaps, cleanup-time registration is rejected, teardown-notification failures are isolated per observer
- **Transactional Loader** ... imports a new entry name BEFORE disposal, restores the previous plugin if application fails
- **JSDoc enrichment** ... `@param/@returns` tags for the API reference generator

This isn't just "we use Cordis" ... this is **we own the framework layer**.

## Cordis: No hard core

Traditional frameworks have a hard core and plugins are added around it:

```
Traditional: [Hard Core] + [Plugin 1] + [Plugin 2] + [Plugin 3]
Cordis:      [Plugin 1] ↔ [Plugin 2] ↔ [Plugin 3]
             No core. Everything is a plugin.
```

In Cordis, **even the agent-loop is a plugin**. You can replace, modify, or uninstall it without breaking the rest of the system.

```yaml
# cordis.yml ... plugin configuration
plugins:
  - dsh-agent-loop       # Basic agent loop
  - dsh-llm-adapter      # LLM provider adapter
  - dsh-tool-registry    # Tool registration
  - dsh-p2p-sync         # P2P communication
  - dsh-energy-monitor   # Resource tracking
  - dsh-outbox           # Async processing
```

Every line is a plugin. None is "the important one." All can be replaced.

## Hot-swap: Change at runtime

Imagine your P2P node (fridge) is running and you want to change the communication method. Traditional approach: restart. With Cordis:

```php
// Agent changes plugin at runtime
$ctx->uninstall('dsh-http-transport');
$ctx->install('dsh-websocket-transport');

// No restart. No downtime. Network keeps running.
```

The plugin is properly uninstalled:
1. Its lifecycle is tracked
2. Resources are released (DB connections, file handles)
3. The new plugin takes over its duties

## Scoped services: Resources with lifecycle

Every plugin has **scoped services** ... resources that live only during the plugin's lifetime:

```php
// Plugin registers scoped service
$ctx->effect(function () use ($pdo) {
    // This runs when plugin is installed
    $repository = new SqliteOutboxRepository($pdo);

    // Returns service that will be released on uninstall
    return $repository;
});

// When plugin is uninstalled, $repository is automatically released
```

No memory leak. No orphan connections. Cordis takes care of it.

## Event-driven: Small-talk between plugins

Plugins communicate through **typed events** ... exactly like small-talk:

```php
// Plugin A: emits event
$ctx->emit('p2p/chunk-received', [
    'chunk_id' => $chunk->id(),
    'hash' => $chunk->hash(),
]);

// Plugin B: listens and reacts
$ctx->on('p2p/chunk-received', function (array $data) {
    $this->chunkIndexer->index($data);
});

// Plugin C: also listens, but differently
$ctx->on('p2p/chunk-received', function (array $data) {
    $this->energyMonitor->trackTransfer($data);
});
```

Plugin A doesn't know Plugin B or C. It just "says" something and whoever wants to, listens. Decoupled, testable, extensible.

## How it fits P2P

In the [previous article](/en/blog/the-fridge-as-a-p2p-server) we described the fridge as a P2P server. Cordis is the **operating system** for such a node:

| Need | Cordis solution |
|------|----------------|
| P2P communication | `dsh-p2p-sync` plugin |
| Energy tracking | `dsh-energy-monitor` plugin |
| Local storage | `dsh-chunk-store` plugin |
| Async processing | `dsh-outbox` plugin |
| Updates | Hot-swap without restart |
| Audit | Trajectory (append-only log) |

### Trajectory: Audit trail

Harness automatically records all events in an **append-only log**:

```php
// Every action is recorded
$trajectory->record('p2p/chunk-shared', [
    'chunk_id' => $chunk->id(),
    'peers' => ['192.168.1.10', '192.168.1.11'],
    'timestamp' => time(),
]);

// Full audit trail ... who, what, when, why
```

For P2P networks this is crucial: you know exactly which chunks were shared, when, and through which nodes.

## Cordis and Outbox

Cordis plugins can use the Outbox pattern for async processing:

```php
// dsh-outbox plugin
$ctx->effect(function () use ($processor) {
    // Registers handlers for P2P events
    $processor->registerHandler('ChunkShared', function (OutboxMessage $msg) {
        $this->p2pNetwork->broadcast($msg->payload());
    });

    // Background process processes outbox
    $processor->process(100);

    return $processor; // Released on uninstall
});
```

Outbox ensures P2P broadcasts survive plugin crashes. Cordis ensures plugins can be fixed without restarting the entire node.

## OpenSpec and Cordis

OpenSpec defines **what** should happen. Cordis ensures **how**:

```yaml
# OpenSpec: behavior definition
distribution:
  strategy: gossip
  chunk_size: 4KB
  retry: 3

# Cordis: implementation as plugin
plugins:
  - dsh-p2p-gossip       # Gossip protocol
  - dsh-chunk-splitter   # Split into 4KB chunks
  - dsh-retry-queue      # Retry mechanism
```

OpenSpec can generate Cordis configuration. Cordis can report back to OpenSpec. A bidirectional bridge.

## The lesson

**Cordis is a TypeScript plugin framework ... not an abstract idea, but a concrete tool.**

A framework that follows these principles:
- No hard core ... everything can be replaced
- No restart ... hot-swap at runtime
- No memory leak ... scoped services
- No silent failure ... trajectory logging

For P2P freeNet, it's exactly what you need: a node that can update, fix, and extend itself without someone physically coming to restart the fridge.

And when you combine it with OpenSpec (orchestrator), Outbox (async distribution), and ACID (consistency), you have the complete stack for a decentralized cognitive network.

→ [The fridge as a P2P server](/en/blog/the-fridge-as-a-p2p-server) — hardware for P2P nodes
→ [Outbox pattern ... when cache isn't enough](/en/blog/outbox-pattern-when-cache-isnt-enough) — async distribution
→ [OpenSpec vs tickets ... agility in the AI era](/en/blog/openspec-vs-tickets-agility-in-the-ai-era) — orchestrator for Cordis

## Sources

- [DeepSeek Harness](https://github.com/deepseek-ai/deepseek-harness) — open-source agent framework
- [Cordis](https://github.com/deepseek-ai/deepseek-harness/tree/master/vendor/cordis) — TypeScript plugin framework (part of Harness)
