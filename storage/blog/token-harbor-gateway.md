---
title: "Token Harbor — OpenAI-kompatibilná AI brána pre vývojárov"
date: "2026-09-13"
version: "0.1.0"
tags: ["ai-gateway", "openai-compatible", "token-harbor", "llm", "api"]
excerpt: "Token Harbor je jednotný API endpoint pre prístup k viacerým LLM modelom (Claude, GPT, Gemini, DeepSeek, Qwen) cez jeden OpenAI-kompatibilný kontrakt. Článek popisuje architektúru, integráciu do OMP, bezpečnosť API kľúčov a postupy pre produkciu."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Architecture"
translation: "token-harbor-gateway"
published: false
---

# Token Harbor — OpenAI-kompatibilná AI brána pre vývojárov

**Token Harbor** je gateway služba, ktorá poskytuje jeden OpenAI-kompatibilný endpoint pre prístup k/modelom: Anthropic Claude, OpenAI GPT, Google Gemini, DeepSeek, Qwen a ďalším. Vývojár používa jeden API kľúč, jednu base URL a standardný OpenAI SDK / HTTP klient.

---

## Prečo Token Harbor?

Tradičný prístup: pre každý poskytovateľa (OpenAI, Anthropic, Google) potrebujete samostatný SDK, kľúč, base URL, rate-limiting logiku, retry politiku, aonitoring. Pri 5+ modeloch to znamena 5 integrácií.

Token Harbor zjednotí toto do **jedného OpenAI-compatible endpointu**:

- Jeden API kľúč (`sk-th-...`)
- Jedna base URL (`https://api.tokenharbor.ai/v1` alebo vlastná)
- Standardné OpenAI SDK / `openai` Python balík / `httpx` / `curl`
- Per-token billing, transparentné ceny, free tier (DeepSeek V4 Flash, MiMo V2.5)

---

## Architektúra

```
Client (OpenAI SDK)
        │
        ▼
┌───────────────────┐
│  Token Harbor     │  ←── OpenAI-compatible REST API
│  Gateway          │      /v1/chat/completions
│  (Load balancer)  │      /v1/models
└─────────┬─────────┘
          │
    ┌─────┼─────┐
    ▼     ▼     ▼
Claude  GPT  Gemini  DeepSeek  Qwen ...
```

- **Routing**: `model` parameter v requeste určuje cieľový model
- **Failover**: ak jeden provider zlyhá, automatický fallback na iného
- **Observability**: per-request logy, token usage, latency metrics

---

## Integračný príklad — OMP (Oh My Pi)

OMP je coding harness s AI asistenciou. Token Harbor sa nastaví ako **OpenAI provider**:

```bash
# 1. Prihlásenie do OMP
omp login openai

# 2. V dialógu zadajte:
#    API Key:  váš_TokenHarbor_kľúč
#    Base URL: https://api.tokenharbor.ai/v1

# 3. Vyberte model z Token Harbor dashboardu, napr.:
#    deepseek-v4-flash
#    qwen-2.5-72b
#    claude-3.5-sonnet
```

### Premenné prostredia (pre skripty/CI):

```bash
export OPENAI_API_KEY="sk-th-xxxxxxxxxxxx"
export OPENAI_BASE_URL="https://api.tokenharbor.ai/v1"
```

### Python príklad (bez SDK):

```python
import os, httpx

resp = httpx.post(
    f"{os.environ['OPENAI_BASE_URL']}/chat/completions",
    headers={"Authorization": f"Bearer {os.environ['OPENAI_API_KEY']}"},
    json={
        "model": "deepseek-v4-flash",
        "messages": [{"role": "user", "content": "Ahoj, vysvetli mi capability seams."}],
    },
    timeout=60,
)
print(resp.json()["choices"][0]["message"]["content"])
```

---

## Bezpečnosť API kľúčov

| Pravidlo | Popis |
|----------|-------|
| **Nikdy nekomitujte kľúč do gitu** | Použite `.env`, `~/.omp/agent/.env`, GitHub Secrets, Vault |
| **Omezte scope** | Token Harbor umožňuje vytvoriť kľúče s obmedzeným modelom/denným limitom |
| **Rotujte kľúče** | Pravidelne generujte nové, staré odvolajte |
| **Monitorujte usage** | Dashboard ukazuje per-model spotrebu, detekuje anomaly |

```bash
# Správne uloženie v .env (pridajte do .gitignore)
OPENAI_API_KEY=sk-th-xxxxxxxxxxxx
OPENAI_BASE_URL=https://api.tokenharbor.ai/v1
```

---

## Produkčné osvedčené postupy

1. **Timeouts**: nastavte rozumné timeouty (30–60 s) — niektoré modely (Claude, GPT-4) trvajú dlhšie.
2. **Retry + backoff**: exponenciálne backoff pre 429/5xx, max 3 pokusy.
3. **Streaming**: pre dlhé odpovede použite `stream: true` a spracúvajte chunky.
4. **Model pinning**: v kóde uvádzajte presný model (nie `auto`), aby ste mali kontrolu nad cenou/latencou.
5. **Cost alerts**: Token Harbor dashboard umožňuje nastaviť denný/mesačný limit.

---

## Porovnanie s alternatívami

| Vlastnosť | Token Harbor | OpenRouter | LiteLLM Proxy | Vlastný proxy |
|-----------|--------------|------------|---------------|---------------|
| OpenAI-compat | áno | áno | áno | manuálne |
| Free tier | áno (DeepSeek V4 Flash) | áno | nie | nie |
| Failover | áno — automatický | áno | áno | manuálne |
| Per-token billing | áno | áno | nie | manuálne |
| Self-hosted | nie | nie | áno | áno |
| SDK required | nie (standard OpenAI) | nie | nie | manuálne |

---

## Záver

Token Harbor eliminuje integráčnú zložitosť pri viacerých LLM poskytovateľoch. Pre vývojárov používajúcich OMP, Python, TypeScript alebo čistý HTTP je to **drop-in replacement** za OpenAI API — zmeníte len `base_url` a `api_key`, zvyšok kódu zostane nezmenený.

Pre produkciu: rotujte kľúče, monitorujte usage, nastavte cost alerts, a vždy testujte failover scenáre v stagingu.

---

## Zdroje

- [Token Harbor — oficiálna stránka](https://tokenharbor.ai)
- [Token Harbor FAQ](https://tokenharbor.ai/faq)
- [OpenAI API Reference](https://platform.openai.com/docs/api-reference)
- [OMP — provider konfigurácia](https://github.com/can1357/oh-my-pi/tree/main/docs/adding-a-provider.md)
