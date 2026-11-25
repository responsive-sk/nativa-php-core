---
title: "DSH and P2P ... distributed agents in the network"
date: "2026-08-25"
version: "1.0.0"
tags: ["dsh-series", "deepseek", "p2p", "distributed", "agents", "cordis"]
excerpt: "DeepSeek Harness can be the foundation for distributed agents. Capability seams for P2P communication, event-sourced sessions for replication, sandbox for node security. The fridge as an agent server."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Vision"
translation: "dsh-a-p2p"
published: true
---

## How DSH fits into P2P

In the [previous article](/en/blog/the-fridge-as-a-p2p-server) we described the fridge as a P2P server. DSH isn't just a framework for one agent ... it's an **operating system** for distributed agents.

```
Traditionally: [Agent A] ←→ [Cloud API] ←→ [Agent B]
DSH:           [Agent A] ←→ [P2P Seam] ←→ [Agent B]
                           ↓
                       [Capability Seam]
                       [Sandbox] [Session] [Tools]
```

## P2P as a capability seam

In DSH, P2P communication would be another **capability seam**:

```
packages/p2p/
  p2p-seam/          # Service Definition
  p2p-libp2p/        # Provider (libp2p)
  p2p-websocket/     # Provider (WebSocket)
  p2p-gossip/        # Provider (gossip protocol)
  tool-p2p/          # Consumer (model-facing tools)
```

### Service Definition

```typescript
// p2p-seam/src/index.ts

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
// p2p-libp2p/src/index.ts

import { P2PService, type P2PMessage } from '@deepseek-ai/dsh-p2p-seam'
import { createLibp2p } from 'libp2p'

export class LibP2PProvider extends P2PService {
    private node: any
    
    constructor(ctx: Context) {
        super(ctx, 'p2p')
        
        this.ctx.effect(async () => {
            // Create P2P node
            this.node = await createLibp2p({
                addresses: { listen: ['/ip4/0.0.0.0/tcp/0'] },
                transports: [/* ... */],
                discovery: [/* ... */]
            })
            
            await this.node.start()
            
            // Cleanup on UNLOADING
            return async () => {
                await this.node.stop()
            }
        })
    }
    
    async broadcast(message: P2PMessage): Promise<void> {
        await this.node.services.pubsub.publish(
            'nativa-messages',
            new TextEncoder().encode(JSON.stringify(message))
        )
    }
    
    async send(to: string, message: P2PMessage): Promise<void> {
        // Direct message via libp2p
        await this.node.dial(to)
        // ... send message
    }
    
    onMessage(handler: (msg: P2PMessage) => void): void {
        this.node.services.pubsub.subscribe('nativa-messages')
        this.node.services.pubsub.addEventListener('message', (evt) => {
            const msg = JSON.parse(new TextDecoder().decode(evt.detail.data))
            handler(msg)
        })
    }
    
    async getPeers(): Promise<string[]> {
        return this.node.getPeers().map((p: any) => p.toString())
    }
}
```

## Event-sourced sessions for replication

When every agent has an **event-sourced session**, replication is natural:

```
Node A: [1] user: "Analyze file" → [2] agent: tool_call → [3] result
                ↓ P2P broadcast
Node B: [1] user: "Analyze file" → [2] agent: tool_call → [3] result
```

### Session sync

```typescript
// P2P plugin syncs session logs
ctx.on('p2p/session-sync', async (data) => {
    // Receive session entries from another node
    const entries = data.entries
    
    // Append to local session log
    for (const entry of entries) {
        await ctx.sessions.append(data.sessionId, entry)
    }
})

// On new entry → broadcast
ctx.on('session/entry-added', async (entry) => {
    const peers = await ctx.p2p.getPeers()
    for (const peer of peers) {
        await ctx.p2p.send(peer, {
            type: 'chunk',
            from: ctx.nodeId,
            payload: { sessionId: entry.sessionId, entries: [entry] },
            timestamp: Date.now()
        })
    }
})
```

## Sandbox for node security

Every P2P node runs in a **sandbox**:

```typescript
// Each remote agent is confined
await root.plugin(SandboxFS)
await root.plugin(SandboxShell)

// Remote command is fenced
await sandbox.wrap(['bash', '-c', 'analyze-data.sh'], {
    mode: 'workspace-write'
})
```

Agent on Node B cannot:
- Read Node A's files
- Overwrite configuration
- Run malicious commands
- See Node A's processes

## Heartbeat and discovery

```typescript
// Heartbeat ... every node reports its availability
setInterval(async () => {
    const peers = await ctx.p2p.getPeers()
    ctx.emit('p2p/heartbeat', {
        nodeId: ctx.nodeId,
        peers: peers.length,
        load: process.loadavg()[0],
        uptime: process.uptime()
    })
}, 30_000)

// Discovery ... new nodes join
ctx.on('p2p/peer-joined', async (data) => {
    console.log(`New peer: ${data.nodeId}`)
    
    // Send session history to new peer
    const sessions = await ctx.sessions.list()
    for (const session of sessions) {
        const entries = await ctx.sessions.getEntries(session.id)
        await ctx.p2p.send(data.nodeId, {
            type: 'chunk',
            from: ctx.nodeId,
            payload: { sessionId: session.id, entries },
            timestamp: Date.now()
        })
    }
})
```

## Gossip protocol

Instead of direct broadcast, DSH can use **gossip**:

```typescript
// Gossip ... small-talk between nodes
ctx.on('p2p/gossip', async (data) => {
    // Ignore messages from self
    if (data.from === ctx.nodeId) return
    
    // Process
    await processGossip(data)
    
    // Forward (with TTL)
    if (data.ttl > 0) {
        await ctx.p2p.broadcast({
            ...data,
            ttl: data.ttl - 1,
            from: ctx.nodeId
        })
    }
})
```

## What stands in the way

| Obstacle | Status | Solution |
|----------|--------|----------|
| LLM size | Mistral 7B runs on Pi | Quantization, edge models |
| P2P protocols | libp2p exists | Proven, used by IPFS |
| Hardware | Raspberry Pi 5 = $80 | Cheap, available |
| DSH maturity | Developer preview | Evolving rapidly |

## Distributed node architecture

```
┌─────────────────────────────────────┐
│         DSH Node (fridge)           │
│                                     │
│  ┌──────────┐  ┌──────────────┐    │
│  │ Agent    │  │ Session      │    │
│  │ Loop     │  │ (event-sourced)│  │
│  └────┬─────┘  └──────┬───────┘    │
│       │               │            │
│  ┌────┴───────────────┴────┐       │
│  │     Capability Seams     │       │
│  │  ┌─────┐ ┌──────┐ ┌───┐│       │
│  │  │ FS  │ │Shell │ │P2P││       │
│  │  └─────┘ └──────┘ └───┘│       │
│  └─────────────────────────┘       │
│                                     │
│  ┌──────────┐  ┌──────────────┐    │
│  │ Sandbox  │  │ Tools        │    │
│  │ (bwrap)  │  │ (model-facing)│   │
│  └──────────┘  └──────────────┘    │
└─────────────────────────────────────┘
         ↕ P2P (gossip)
┌─────────────────────────────────────┐
│         DSH Node (another node)     │
└─────────────────────────────────────┘
```

→ [The fridge as a P2P server](/en/blog/the-fridge-as-a-p2p-server): hardware for P2P nodes
→ [DeepSeek Harness ... 50 packages](/en/blog/deepseek-harness-architecture-overview): architecture overview
→ [Capability seams](/en/blog/capability-seams): how DSH abstracts abilities
→ [Outbox pattern ... when cache isn't enough](/en/blog/outbox-pattern-when-cache-isnt-enough): async distribution

## Sources

- [DeepSeek Harness](https://github.com/deepseek-ai/deepseek-harness): open-source agent framework
- [libp2p](https://github.com/libp2p/go-libp2p): modular P2P framework
- [IPFS / Kubo](https://github.com/ipfs/kubo): content-addressed storage
