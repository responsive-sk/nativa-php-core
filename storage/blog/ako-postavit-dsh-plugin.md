---
title: "Ako postaviť DSH plugin ... praktický tutoriál"
date: "2026-08-25"
version: "1.0.2"
tags: ["dsh-series", "deepseek", "cordis", "plugins", "tutorial", "typescript"]
excerpt: "Krok za krokom: ako vytvoriť plugin pre DeepSeek Harness. Service Definition, Provider, Consumer. Effects, events, scoped services. Od nuly po funkčný plugin."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Tutorial"
translation: "building-a-dsh-plugin"
published: true
---

Agent potrebuje vedieť, aké je počasie v Bratislave. Nemá na to nástroj. V DSH sa to dá vyriešiť za hodinu — jedným pluginom, ktorý sa skladá z troch súborov.

## Čo postavíme

Weather plugin — predpoveď počasia pre agenta. Tri roly:

1. **Service Definition** — kontrakt: čo sa dá robiť
2. **Service Provider** — implementácia: open-meteo API
3. **Consumer** — model-facing tool: agent ho môže zavolať

```
packages/weather/
  weather-seam/          # Service Definition
  weather-local/         # Provider (open-meteo API)
  tool-weather/          # Consumer (model-facing tool)
```

## Krok 1: Service Definition

```typescript
// packages/weather/weather-seam/src/index.ts
import { Context, Service } from '@deepseek-ai/cordis'

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

`Service` je abstraktná trieda z Cordis. `static provide = 'weather'` znamená automatickú registráciu na `ctx.weather`. Abstract metódy musí provider implementovať, `static provide` povie Cordis, kam sa služba zavesí.

## Krok 2: Service Provider

```typescript
// packages/weather/weather-local/src/index.ts
import { WeatherService, type WeatherData } from '@deepseek-ai/dsh-weather-seam'

export class LocalWeatherProvider extends WeatherService {
    private cache = new Map<string, { data: WeatherData, expiry: number }>()
    
    async getForecast(city: string): Promise<WeatherData> {
        const cached = this.cache.get(city)
        if (cached && cached.expiry > Date.now()) {
            return cached.data
        }
        
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

Cache je scoped na provider — zmizne, keď sa provider odinštaluje. Žiadny memory leak.

## Krok 3: Consumer (Tool)

```typescript
// packages/weather/tool-weather/src/index.ts
import { Context, Service } from '@deepseek-ai/cordis'

export class WeatherTool extends Service {
    static provide = 'weatherTool'
    
    constructor(ctx: Context) {
        super(ctx, 'weatherTool')
        
        ctx.tools.register('weather', {
            description: 'Get weather forecast for a city',
            parameters: {
                city: { type: 'string', description: 'City name' }
            },
            execute: async (params) => {
                // Používa ctx.weather — nevieme, či je to Local, Remote alebo Mock
                const forecast = await this.ctx.weather.getForecast(params.city)
                return `Temperature: ${forecast.temperature}°C, ${forecast.condition}`
            }
        })
    }
}
```

Consumer používa `ctx.weather` — nevie, ktorý provider beží. A ani nemusí.

## Krok 4: Effects (Cleanup)

```typescript
export class LocalWeatherProvider extends WeatherService {
    private cache = new Map<string, { data: WeatherData, expiry: number }>()
    private intervalId?: NodeJS.Timeout
    
    constructor(ctx: Context) {
        super(ctx, 'weather')
        
        this.ctx.effect(() => {
            this.intervalId = setInterval(() => {
                this.cache.clear()
            }, 600_000)
            
            return () => {
                clearInterval(this.intervalId)
                this.cache.clear()
            }
        })
    }
}
```

`ctx.effect()` zaregistruje cleanup. Disposer sa spustí v opačnom poradí (LIFO).

## Krok 5: Events (Notifikácie)

```typescript
// Provider emitne event keď príde nová predpoveď
async getForecast(city: string): Promise<WeatherData> {
    const weather = await this.fetchFromAPI(city)
    
    this.ctx.emit('weather/updated', {
        city,
        temperature: weather.temperature,
        condition: weather.condition
    })
    
    return weather
}

// Iný plugin počúva
ctx.on('weather/updated', (data) => {
    console.log(`Weather in ${data.city}: ${data.temperature}°C`)
})
```

## Krok 6: Plugin Registration

```typescript
// packages/weather/src/index.ts
import { LocalWeatherProvider } from '@deepseek-ai/dsh-weather-local'
import { WeatherTool } from '@deepseek-ai/dsh-tool-weather'

export const weatherPlugin = (ctx: Context) => {
    ctx.plugin(LocalWeatherProvider)
    ctx.plugin(WeatherTool)
}
```

## Krok 7: Testing

```typescript
class MockWeatherProvider extends WeatherService {
    async getForecast(city: string): Promise<WeatherData> {
        return { temperature: 20, condition: 'sunny', humidity: 50, wind: 5 }
    }
    async getAlerts(city: string): Promise<string[]> {
        return []
    }
}

const ctx = new Context()
ctx.plugin(MockWeatherProvider)
ctx.plugin(WeatherTool)

const result = await ctx.tools.execute('weather', { city: 'Bratislava' })
// result: "Temperature: 20°C, sunny"
```

| Krok | Čo | Cordis mechanizmus |
|------|----|-------------------|
| 1. Definition | Abstraktná trieda | `Service` + `static provide` |
| 2. Provider | Implementácia API | `extends Service` |
| 3. Consumer | Model-facing tool | `ctx.tools.register` |
| 4. Cleanup | Resource disposal | `ctx.effect()` |
| 5. Events | Inter-plugin communication | `ctx.emit()` / `ctx.on()` |
| 6. Registration | Plugin composition | `ctx.plugin()` |
| 7. Testing | Mock provider | Nový plugin v teste |

Za hodinu máte funkčný plugin. Agent nevie, či dáta prichádzajú z API, z cache, alebo z mocku. A to je presne pointa.

→ [Cordis ... hlboký ponor](/blog/cordis-hlboky-ponor) — plugin framework
→ [Capability seams](/blog/capability-seams) — Service Definition / Provider / Consumer
→ [DeepSeek Harness ... 50 balíkov](/blog/deepseek-harness-architektura) — architektonický prehľad

## Zdroje

- [Cordis — @deepseek-ai/cordis](https://github.com/deepseek-ai/cordis) — Service/Context jadro plugin frameworku
- [DeepSeek Harness](https://github.com/deepseek-ai/deepseek-harness) — repozitár DSH, štruktúra `packages/weather`
- [TypeScript Handbook — Modules](https://www.typescriptlang.org/docs/handbook/2/modules.html) — `export`/`import` konvencie
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
