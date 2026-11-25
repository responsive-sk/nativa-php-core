---
title: "DSH a P2P ... distribuovaní agenti v sieti"
date: "2026-08-25"
version: "1.0.1"
tags: ["dsh-series", "deepseek", "p2p", "distributed", "agents", "cordis"]
excerpt: "DeepSeek Harness môže byť základom pre distribuovaných agentov. Capability seams pre P2P komunikáciu, event-sourced sessions pre replikáciu, sandbox pre bezpečnosť uzlov. Chladnička ako agentný server."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Vision"
translation: "dsh-and-p2p"
published: true
---

Agent A potrebuje poslať správu agentovi B. V tradičnom svete ide cez cloud — Google, AWS, nejaký server uprostred. Ale čo keď cloud spadne? Čo keď firma zmení API? Čo keď agent B je v inej krajine s inými pravidlami?

Cloud je centrálny. Jeden server, jedna politika, jedno miesto, kde všetko zlyhá.

```
Tradične:  [Agent A] ←→ [Cloud API] ←→ [Agent B]
```

## P2P ako capability seam

DSH rieši P2P rovnako ako filesystem, shell, alebo sandbox — cez **capability seam**. Kontrakt, implementácia a spotrebiteľ sú oddelené.

```
DSH:       [Agent A] ←→ [P2P Seam] ←→ [Agent B]
                       ↓
                   [Capability Seam]
                   [Sandbox] [Session] [Tools]
```

### Service Definition

```typescript
export interface P2PMessage {
    type: 'chunk' | 'heartbeat' | 'request' | 'response'
    from: string
    to?: string
    payload: unknown
    timestamp: number
}

export abstract class P2PService extends Service {
    static provide = 'p2p'
    
    abstract broadcast(message: P2PMessage): Promise<void>
    abstract send(to: string, message: P2PMessage): Promise<void>
    abstract onMessage(handler: (msg: P2PMessage) => void): void
    abstract getPeers(): Promise<string[]>
}
```

### Provider: libp2p

```typescript
export class LibP2PProvider extends P2PService {
    private node: any
    
    constructor(ctx: Context) {
        super(ctx, 'p2p')
        
        this.ctx.effect(async () => {
            this.node = await createLibp2p({
                addresses: { listen: ['/ip4/0.0.0.0/tcp/0'] },
                transports: [/* ... */],
                discovery: [/* ... */]
            })
            await this.node.start()
            
            return async () => { await this.node.stop() }
        })
    }
    
    async broadcast(message: P2PMessage): Promise<void> {
        await this.node.services.pubsub.publish(
            'nativa-messages',
            new TextEncoder().encode(JSON.stringify(message))
        )
    }
}
```

Agent na Node B nemôže čítať súbory Node A, prepísať konfiguráciu, spustiť škodlivé príkazy, alebo vidieť procesy Node A. Sandbox je fence, nie návrh.

## Event-sourced sessions pre replikáciu

Keď má každý agent **event-sourced session**, replikácia je prirodzená:

```
Node A: [1] user: "Analyzuj súbor" → [2] agent: tool_call → [3] result
                ↓ P2P broadcast
Node B: [1] user: "Analyzuj súbor" → [2] agent: tool_call → [3] result
```

Session sync prebieha cez P2P — každý nový entry sa rozosiela sa všetkým peerom. Žiadny centrálny server. Žiadny single point of failure.

## Čo bráni realite

| Prekážka | Status | Riešenie |
|----------|--------|---------|
| LLM veľkosť | Mistral 7B beží na Pi | Kvantizácia, edge models |
| P2P protokoly | libp2p existuje | Overený, používa IPFS |
| Hardvér | Raspberry Pi 5 = $80 | Lacný, dostupný |
| DSH zrelosť | Developer preview | Rýchlo sa vyvíja |

## Prečo to záleží

P2P nie je len technológia — je to filozofia. Keď odstránite cloud, odstránite aj závislosť. Agent nemusí dôverovať firme, serveru, ani protokolu. Dôveruje len susedom v sieti.

A to je presne tá istá myšlienka, ako keď sme odstránili Composer z produkcie. Zero-dependency. Plná kontrola.

→ [Chladnička ako P2P server](/blog/chladnicka-ako-p2p-server) — hardvér pre P2P uzly
→ [Capability seams](/blog/capability-seams) — Service Definition / Provider / Consumer
→ [DeepSeek Harness ... 50 balíkov](/blog/deepseek-harness-architektura) — architektonický prehľad

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — bezpečnostné best practices
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
- [W3C — Web Standards](https://www.w3.org/standards/) — web štandardy
