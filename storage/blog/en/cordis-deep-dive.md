---
title: "Cordis ... a deep dive into the plugin framework"
date: "2026-08-25"
version: "1.0.0"
tags: ["dsh-series", "cordis", "typescript", "plugins", "lifecycle", "events"]
excerpt: "Cordis is the TypeScript plugin framework powering DeepSeek Harness. Fiber lifecycle, scoped services, event dispatch, intercept config. Let's look under the hood."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Architecture"
translation: "cordis-hlboky-ponor"
published: true
---

## What is Cordis

Cordis is a **TypeScript plugin framework** ... not an abstract idea, but a concrete tool with ~2700 lines of code. DeepSeek Harness vendored it into the `@deepseek-ai/*` scope and added their own patches.

The core has 9 files:

```
cordis/src/
  context.ts    # Root and child dependency containers (146 lines)
  fiber.ts      # Plugin lifecycle manager (754 lines)
  events.ts     # Event dispatch system (352 lines)
  service.ts    # Base class for named services (115 lines)
  registry.ts   # Plugin registration (337 lines)
  reflect.ts    # Service resolution via proxy (418 lines)
  logger.ts     # Named loggers (270 lines)
  utils.ts      # Shared utilities (287 lines)
  index.ts      # Public API (14 lines)
```

## Context ... the root container

`Context` is a **proxy**. Normal property reads go through the service resolver. When you write `ctx.logger`, Cordis finds the `LoggerService` and returns it.

```typescript
// Context is the root dependency container
const root = new Context()

// Plugin registered, ctx now has new services
await root.plugin(MyPlugin)

// Reading through proxy ... finds the registered service
console.log(ctx.myService)  // ← resolves via ReflectService
```

### Scoped contexts

`extend()`, `isolate()`, and `intercept()` create **child contexts** without mutating the parent:

```typescript
// Child context with extra metadata
const child = root.extend({ locale: 'sk' })

// Isolated scope for a specific service
const isolated = root.isolate('database')

// Intercept config for plugins under this context
const intercepted = root.intercept('llm', { model: 'deepseek-chat' })
```

Every child inherits everything from the parent, but own properties shadow the inherited ones. The parent is not mutated.

## Fiber ... the plugin lifecycle

`Fiber` is the **lifecycle manager** for every plugin. It's 754 lines of code that handle:

### State machine

```
PENDING → LOADING → ACTIVE → UNLOADING → (disposal)
   ↑         ↓          ↓
   └─────────┴──────────┘ (reentrant restart)
```

- **PENDING** ... plugin waiting for dependencies
- **LOADING** ... plugin is loading
- **ACTIVE** ... plugin is running
- **UNLOADING** ... plugin is uninstalling, disposal in progress

### Effects ... cleaning up resources

Every plugin can register **effects** ... pieces of code that run during uninstallation:

```typescript
// Plugin registering an effect
ctx.effect(() => {
    const db = new DatabaseConnection()
    
    // Returns a disposer ... runs on UNLOADING
    return () => {
        db.close()  // ← cleanup
    }
})

// Or via generator
ctx.effect(function* () {
    const server = startServer()
    yield () => server.stop()  // ← cleanup
})
```

Effects run in **reverse registration order** (LIFO) ... last registered, first released.

### DeepSeek patches to fiber

DeepSeek added three key hardenings:

1. **Reentrant disposal gaps** ... an effect's owner-list wrapper is registered BEFORE its setup body, so an unload started from setup awaits setup completion
2. **Cleanup-time registration rejection** ... effect creation is rejected during UNLOADING
3. **Teardown-notification failure isolation** ... one callback cannot interrupt peers

## Events ... small-talk between plugins

Cordis has **5 dispatch strategies**:

| Strategy | What it does | When to use |
|----------|-------------|-------------|
| `emit` | Synchronous, ignores return values | Fire-and-forget notifications |
| `parallel` | Awaits all listeners concurrently | Independent parallel operations |
| `serial` | Awaits in order until one bails | Chaining dependent operations |
| `bail` | Synchronous, stops on first bail value | Guard chains (permission checks) |
| `waterfall` | Each listener wraps next() | Middleware chains |

```typescript
// emit ... fire-and-forget
ctx.emit('p2p/chunk-received', { chunk_id: 'abc' })

// parallel ... all at once
await ctx.parallel('data/processed', result)

// bail ... guard chain
const allowed = ctx.bail('auth/check', { user, action })
if (!allowed) return  // ← someone denied

// waterfall ... middleware chain
const html = await ctx.waterfall('render/page', content)
```

## Service ... named API on ctx

`Service` is an abstract class for services registered on `ctx`:

```typescript
class DatabaseService extends Service {
    // automatic registration as 'database'
    static provide = 'database'
    
    constructor(ctx: Context) {
        super(ctx, 'database')
    }
    
    // Callable service ... ctx.database() works as a function
    [Service.invoke]() {
        return this.query.bind(this)
    }
}
```

### Scoped services

Every plugin has its own **scoped services** ... resources that live only during the plugin's lifetime:

```typescript
// Plugin A has its own DB
await root.plugin(PluginA)  // ctx.database = PluginA's DB

// Plugin B has its own DB (isolated)
await root.isolate('database').plugin(PluginB)  // ctx.database = PluginB's DB

// When Plugin A is uninstalled, its DB is automatically released
```

No memory leaks. No orphan connections. Cordis takes care of it.

## Intercept config ... configuration through context

`intercept()` allows changing service configurations for plugins under a given context:

```typescript
// Set model for all plugins under this context
const withModel = root.intercept('llm', { model: 'deepseek-chat' })

// Plugin A sees model: 'deepseek-chat'
await withModel.plugin(PluginA)

// Plugin B sees model: 'gpt-4' (no intercept)
await root.plugin(PluginB)
```

Config is merged from ancestor entries ... the closer to root, the earlier it applies.

## How DeepSeek Harness uses Cordis

| Need | Cordis mechanism |
|------|-----------------|
| Plugin lifecycle | Fiber (PENDING→LOADING→ACTIVE→UNLOADING) |
| Resource cleanup | Effects (LIFO disposal) |
| Inter-plugin communication | Events (5 dispatch strategies) |
| Scoped isolation | Context (extend/isolate/intercept) |
| Named services | Service class (auto-registration) |
| Config management | Intercept config (ancestor merge) |

→ [DeepSeek Harness and Cordis](/en/blog/deepseek-harness-and-cordis): the plugin framework for tomorrow
→ [DeepSeek Harness ... 50 packages](/en/blog/deepseek-harness-architecture-overview): architecture overview
→ [ACID for beginners](/en/blog/acid-for-beginners): why transactions aren't boring

## Sources

- [Cordis](https://github.com/deepseek-ai/deepseek-harness/tree/master/vendor/cordis): TypeScript plugin framework
- [DeepSeek Harness](https://github.com/deepseek-ai/deepseek-harness): agent framework built on Cordis
