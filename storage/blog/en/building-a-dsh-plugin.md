---
title: "Building a DSH plugin ... a practical tutorial"
date: "2026-08-25"
version: "1.0.0"
tags: ["dsh-series", "deepseek", "cordis", "plugins", "tutorial", "typescript"]
excerpt: "Step by step: how to create a plugin for DeepSeek Harness. Service Definition, Provider, Consumer. Effects, events, scoped services. From zero to a working plugin."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Tutorial"
translation: "ako-postavit-dsh-plugin"
published: true
---

A plugin is a service, a provider, a consumer, and cleanup. That's the pattern. Everything else is detail.

The problem with most plugin systems: too much ceremony, too little clarity.

We fixed this by defining a simple pattern: Service Definition, Provider, Consumer.

## What we'll learn

We'll build a simple plugin for DSH ... a **weather service** ... that provides weather forecasts to the agent.

Goals:
1. Service Definition (contract)
2. Service Provider (implementation)
3. Consumer (model-facing tool)
4. Effects (cleanup)
5. Events (notifications)

## Structure

```
packages/weather/
  weather-seam/          # Service Definition
  weather-local/         # Provider (open-meteo API)
  tool-weather/          # Consumer (model-facing tool)
```

## Step 1: Service Definition

```typescript
// packages/weather/weather-seam/src/index.ts

import { Context, Service } from '@deepseek-ai/cordis'

// Contract ... what can be done
export interface WeatherData {
    temperature: number
    condition: string
    humidity: number
    wind: number
}

export abstract class WeatherService extends Service {
    static provide = 'weather'
    
    abstract getForecast(city: string): Promise<WeatherData>
    abstract getAlerts(city: string): Promise<string[]>
}
```

Key points:
- `Service` is an abstract class from Cordis
- `static provide = 'weather'` ... automatic registration on `ctx.weather`
- `abstract` methods ... the provider must implement them

## Step 2: Service Provider

```typescript
// packages/weather/weather-local/src/index.ts

import { WeatherService, type WeatherData } from '@deepseek-ai/dsh-weather-seam'

export class LocalWeatherProvider extends WeatherService {
    private cache = new Map<string, { data: WeatherData, expiry: number }>()
    
    async getForecast(city: string): Promise<WeatherData> {
        // Check cache
        const cached = this.cache.get(city)
        if (cached && cached.expiry > Date.now()) {
            return cached.data
        }
        
        // Fetch from API
        const response = await fetch(
            `https://api.open-meteo.com/v1/forecast?city=${city}`
        )
        const data = await response.json()
        
        const weather: WeatherData = {
            temperature: data.temperature,
            condition: data.condition,
            humidity: data.humidity,
            wind: data.wind
        }
        
        // Cache for 10 minutes
        this.cache.set(city, {
            data: weather,
            expiry: Date.now() + 600_000
        })
        
        return weather
    }
    
    async getAlerts(city: string): Promise<string[]> {
        const response = await fetch(
            `https://api.open-meteo.com/v1/alerts?city=${city}`
        )
        return response.json()
    }
}
```

Key points:
- Implements `WeatherService` (Service Definition)
- Registration happens automatically via `static provide`
- Cache is scoped to the provider ... disappears when the provider is uninstalled

## Step 3: Consumer (Tool)

```typescript
// packages/weather/tool-weather/src/index.ts

import { Context, Service } from '@deepseek-ai/cordis'

export class WeatherTool extends Service {
    static provide = 'weatherTool'
    
    constructor(ctx: Context) {
        super(ctx, 'weatherTool')
        
        // Register the tool
        ctx.tools.register('weather', {
            description: 'Get weather forecast for a city',
            parameters: {
                city: { type: 'string', description: 'City name' }
            },
            execute: async (params) => {
                // Uses ctx.weather ... doesn't know if it's Local, Remote, or Mock
                const forecast = await this.ctx.weather.getForecast(params.city)
                return `Temperature: ${forecast.temperature}°C, ${forecast.condition}`
            }
        })
    }
}
```

Key points:
- Consumer uses `ctx.weather` ... doesn't know which provider is running
- Tool registers on `ctx.tools` ... the model can call it
- Cache in the provider is transparent to the consumer

## Step 4: Effects (Cleanup)

```typescript
// Add cleanup to the provider
export class LocalWeatherProvider extends WeatherService {
    private cache = new Map<string, { data: WeatherData, expiry: number }>()
    private intervalId?: NodeJS.Timeout
    
    constructor(ctx: Context) {
        super(ctx, 'weather')
        
        // Effect: cleanup cache interval
        this.ctx.effect(() => {
            // Runs on UNLOADING
            this.intervalId = setInterval(() => {
                this.cache.clear()
            }, 600_000)
            
            // Returns disposer
            return () => {
                clearInterval(this.intervalId)
                this.cache.clear()
            }
        })
    }
}
```

Key points:
- `ctx.effect()` registers cleanup
- Disposer runs in **reverse order** (LIFO)
- No memory leak ... Cordis handles it

## Step 5: Events (Notifications)

```typescript
// Provider emits event when a new forecast arrives
async getForecast(city: string): Promise<WeatherData> {
    const weather = await this.fetchFromAPI(city)
    
    // Emit event ... other plugins can listen
    this.ctx.emit('weather/updated', {
        city,
        temperature: weather.temperature,
        condition: weather.condition
    })
    
    return weather
}

// Another plugin listens
ctx.on('weather/updated', (data) => {
    console.log(`Weather in ${data.city}: ${data.temperature}°C`)
})
```

## Step 6: Plugin Registration

```typescript
// packages/weather/src/index.ts

import { LocalWeatherProvider } from '@deepseek-ai/dsh-weather-local'
import { WeatherTool } from '@deepseek-ai/dsh-tool-weather'

// Plugin function
export const weatherPlugin = (ctx: Context) => {
    // Load provider
    ctx.plugin(LocalWeatherProvider)
    
    // Load tool
    ctx.plugin(WeatherTool)
}

// Cordis YAML configuration
// cordis.yml
// plugins:
//   - id: weather
//     name: '@deepseek-ai/dsh-weather'
```

## Step 7: Testing

```typescript
// In tests: mock provider
import { WeatherService } from '@deepseek-ai/dsh-weather-seam'

class MockWeatherProvider extends WeatherService {
    async getForecast(city: string): Promise<WeatherData> {
        return { temperature: 20, condition: 'sunny', humidity: 50, wind: 5 }
    }
    async getAlerts(city: string): Promise<string[]> {
        return []
    }
}

// Test
const ctx = new Context()
ctx.plugin(MockWeatherProvider)
ctx.plugin(WeatherTool)

const result = await ctx.tools.execute('weather', { city: 'Bratislava' })
// result: "Temperature: 20°C, sunny"
```

## Finished plugin

```
packages/weather/
  weather-seam/
    src/index.ts         # WeatherService (abstract class)
    package.json
  weather-local/
    src/index.ts         # LocalWeatherProvider (open-meteo API)
    package.json
  tool-weather/
    src/index.ts         # WeatherTool (model-facing tool)
    package.json
```

## What we learned

| Step | What | Cordis mechanism |
|------|------|-----------------|
| 1. Definition | Abstract class | `Service` + `static provide` |
| 2. Provider | API implementation | `extends Service` |
| 3. Consumer | Model-facing tool | `ctx.tools.register` |
| 4. Cleanup | Resource disposal | `ctx.effect()` |
| 5. Events | Inter-plugin communication | `ctx.emit()` / `ctx.on()` |
| 6. Registration | Plugin composition | `ctx.plugin()` |
| 7. Testing | Mock provider | New plugin in test |

## Related articles

- [Cordis ... a deep dive](/en/blog/cordis-deep-dive) ... plugin framework
- [Capability seams](/en/blog/capability-seams) ... Service Definition / Provider / Consumer
- [DeepSeek Harness ... 50 packages](/en/blog/deepseek-harness-architecture-overview) ... architecture overview

## Sources

- [DeepSeek Harness](https://github.com/deepseek-ai/deepseek-harness) ... open-source agent framework
- [Adding a package](https://github.com/deepseek-ai/deepseek-harness/blob/master/docs/cookbook/adding-a-package.md) ... official guide
- [Examples](https://github.com/deepseek-ai/deepseek-harness/tree/master/examples) ... runnable demonstrations

The plugin pattern remains the same regardless of the framework: define, implement, register, clean up. Cordis makes it explicit. The pattern works without it.

---

A plugin is not a configuration. It's a contract between your service and the agent.

---

A plugin should be read, not just registered.

---

→ [Cordis deep dive](/blog/cordis-deep-dive) — plugin framework internals
→ [Capability seams](/blog/capability-seams) — Service Definition / Provider / Consumer
