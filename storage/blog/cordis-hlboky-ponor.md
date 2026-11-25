---
title: "Cordis ... hlboký ponor do modulového plugin frameworku"
date: "2026-08-25"
version: "1.0.1"
tags: ["dsh-series", "cordis", "typescript", "plugins", "lifecycle", "events"]
excerpt: "Cordis je TypeScript plugin framework, na ktorom je postavený DeepSeek Harness. Fiber lifecycle, scoped services, event dispatch, intercept config. Poďme sa pozrieť pod pokrievku."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Architecture"
translation: "cordis-deep-dive"
published: true
---

Cordis je TypeScript plugin framework. Nie abstraktná myšlienka, ale konkrétny nástroj s ~2700 riadkami kódu. DeepSeek Harness ho vendloval a pridal vlastné patche.

## Čo je Cordis

Cordis je **TypeScript plugin framework** ... nie abstraktná myšlienka, ale konkrétny nástroj s ~2700 riadkami kódu. DeepSeek Harness ho vendloval do `@deepseek-ai/*` scope a pridal vlastné patche.

Jadro má 9 súborov:

```
cordis/src/
  context.ts    # Root a child dependency containers (146 riadkov)
  fiber.ts      # Plugin lifecycle manager (754 riadkov)
  events.ts     # Event dispatch system (352 riadkov)
  service.ts    # Base class for named services (115 riadkov)
  registry.ts   # Plugin registration (337 riadkov)
  reflect.ts    # Service resolution via proxy (418 riadkov)
  logger.ts     # Named loggers (270 riadkov)
  utils.ts      # Shared utilities (287 riadkov)
  index.ts      # Public API (14 riadkov)
```

## Context ... koreňový kontajner

`Context` je **proxy**. Normálne čítanie vlastností prechádza cez service resolver. Keď napíšete `ctx.logger`, Cordis nájde `LoggerService` a vráti ho.

```typescript
// Context je root dependency container
const root = new Context()

// Plugin sa zaregistroval, ctx teraz má nové služby
await root.plugin(MyPlugin)

// Čítanie cez proxy ... nájde zaregistrovanú službu
console.log(ctx.myService)  // ← resolves via ReflectService
```

### Scoped contexts

`extend()`, `isolate()` a `intercept()` vytvárajú **child contexts** bez mutácie parenta:

```typescript
// Child context s extra metadata
const child = root.extend({ locale: 'sk' })

// Isolated scope pre konkrétnu službu
const isolated = root.isolate('database')

// Intercept config pre pluginy pod týmto kontextom
const intercepted = root.intercept('llm', { model: 'deepseek-chat' })
```

Každý child zdedí všetko z parenta, ale vlastné properties ho shadowujú. Parent nie je mutovaný.

## Fiber ... životný cyklus pluginu

`Fiber` je **správca životného cyklu** každého pluginu. Je to 754 riadkov kódu, ktoré riešia:

### Stavový automat

```
PENDING → LOADING → ACTIVE → UNLOADING → (disposal)
   ↑         ↓          ↓
   └─────────┴──────────┘ (reentrant restart)
```

- **PENDING** ... plugin čaká na závislosti
- **LOADING** ... plugin sa načítava
- **ACTIVE** ... plugin beží
- **UNLOADING** ... plugin sa odinštaluje, disposal prebieha

### Effects ... čistenie zdrojov

Každý plugin môže registrovať **effects** ... kúsky kódu, ktoré sa spustia pri odinštalovaní:

```typescript
// Plugin registrácia effect
ctx.effect(() => {
    const db = new DatabaseConnection()
    
    // Vráti disposer ... spustí sa pri UNLOADING
    return () => {
        db.close()  // ← cleanup
    }
})

// Alebo cez generator
ctx.effect(function* () {
    const server = startServer()
    yield () => server.stop()  // ← cleanup
})
```

Effects sa spúšťajú v **opačnom poradí** (LIFO) ... posledný registrovaný, prvý uvoľnený.

### DeepSeek patche na fiber

DeepSeek pridal tri kľúčové hardeningy:

1. **Reentrant disposal gaps** ... effect's owner-list wrapper sa registuje PRED setup body, takže unload začatý zo setup počká na dokončenie
2. **Cleanup-time registration rejection** ... effect creation je zamietnutá počas UNLOADING
3. **Teardown-notification failure isolation** ... jeden callback nemôže prerušiť peers

## Events ... small-talk medzi pluginmi

Cordis má **5 dispatch stratégií**:

| Stratégia | Čo robí | Kedy použiť |
|-----------|---------|-------------|
| `emit` | Synchronous, ignoruje return values | Fire-and-forget notifikácie |
| `parallel` | Await všetkých listenerov súčasne | Nezávislé paralelné operácie |
| `serial` | Await postupne, kým jeden nezbailne | Reťazenie závislých operácií |
| `bail` | Synchronous, stop na prvom bail value | Guard chain (overenie oprávnení) |
| `waterfall` | Každý listener wrapne next() | Middleware chain |

```typescript
// emit ... fire-and-forget
ctx.emit('p2p/chunk-received', { chunk_id: 'abc' })

// parallel ... všetci súčasne
await ctx.parallel('data/processed', result)

// bail ... guard chain
const allowed = ctx.bail('auth/check', { user, action })
if (!allowed) return  // ← niekto zamietol

// waterfall ... middleware chain
const html = await ctx.waterfall('render/page', content)
```

## Service ... pomenované API na ctx

`Service` je abstraktná trieda pre služby, ktoré sa registrujú na `ctx`:

```typescript
class DatabaseService extends Service {
    // automatická registrácia ako 'database'
    static provide = 'database'
    
    constructor(ctx: Context) {
        super(ctx, 'database')
    }
    
    // Callable service ... ctx.database() funguje ako funkcia
    [Service.invoke]() {
        return this.query.bind(this)
    }
}
```

### Scoped services

Každý plugin má svoje **scoped services** ... zdroje, ktoré žijú len počas života pluginu:

```typescript
// Plugin A má svoju DB
await root.plugin(PluginA)  // ctx.database = PluginA's DB

// Plugin B má svoju DB (izolovaná)
await root.isolate('database').plugin(PluginB)  // ctx.database = PluginB's DB

// Keď sa Plugin A odinštaluje, jeho DB sa automaticky uvoľní
```

Žiadny memory leak. Žiadne orphan connections. Cordis sa postará.

## Intercept config ... konfigurácia cez kontext

`intercept()` umožňuje meniť konfiguráciu služieb pre pluginy pod daným kontextom:

```typescript
// Nastaviť model pre všetky pluginy pod týmto kontextom
const withModel = root.intercept('llm', { model: 'deepseek-chat' })

// Plugin A vidí model: 'deepseek-chat'
await withModel.plugin(PluginA)

// Plugin B vidí model: 'gpt-4' (bez interceptu)
await root.plugin(PluginB)
```

Config sa merguje z ancestor entries ... čím bližšie k root, tým skôr sa aplikuje.

## DeepSeek Harness používa Cordis na

| Potreba | Cordis mechanizmus |
|---------|-------------------|
| Plugin lifecycle | Fiber (PENDING→LOADING→ACTIVE→UNLOADING) |
| Resource cleanup | Effects (LIFO disposal) |
| Inter-plugin communication | Events (5 dispatch strategies) |
| Scoped isolation | Context (extend/isolate/intercept) |
| Named services | Service class (auto-registration) |
| Config management | Intercept config (ancestor merge) |



## Zdroje

- [Cordis](https://github.com/deepseek-ai/deepseek-harness/tree/master/vendor/cordis) ... TypeScript plugin framework
- [DeepSeek Harness](https://github.com/deepseek-ai/deepseek-harness) ... agent framework postavený na Cordis

→ [Capability seams](/blog/capability-seams): Service Definition / Provider / Consumer
→ [Ako postaviť DSH plugin](/blog/ako-postavit-dsh-plugin): praktický tutoriál
→ [DeepSeek Harness ... 50 balíkov](/blog/deepseek-harness-architektura): architektonický prehľad
