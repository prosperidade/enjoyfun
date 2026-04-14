---
name: mermaid-diagrams
description: >
  Diagramas Mermaid para ADRs e documentação EnjoyFun. Use ao criar
  diagramas de fluxo, sequência, arquitetura, ou ER para documentação.
  Trigger: diagrama, mermaid, fluxo, sequência, ER, arquitetura visual.
---

# Mermaid Diagrams — EnjoyFun

## Tipos Mais Usados

### Fluxo de Agente IA
```mermaid
flowchart TD
    A[Pergunta do Organizador] --> B{IntentRouter}
    B --> C[Agente Selecionado]
    C --> D[Provider: OpenAI/Gemini/Claude]
    D --> E{tool_calls?}
    E -->|Sim, read| F[Runtime executa]
    F --> G[Síntese com tool_results]
    G --> H[Resposta Final]
    E -->|Sim, write| I[Aguarda aprovação]
    E -->|Não| H
```

### Sequência de Auth
```mermaid
sequenceDiagram
    participant U as Usuário
    participant API as Backend
    participant DB as PostgreSQL
    U->>API: POST /auth/login
    API->>DB: Verifica credenciais
    DB-->>API: organizer_id
    API->>API: Gera JWT (HS256)
    API-->>U: { token }
    U->>API: GET /events (Authorization: Bearer)
    API->>DB: SET app.current_organizer_id
    Note over DB: RLS filtra automaticamente
    DB-->>API: Dados do tenant
```

## Regras
- Incluir em ADRs quando o fluxo tem 3+ passos
- Labels em português na UI, inglês técnico nos nomes internos
- Manter simples — max 15 nós por diagrama
- Usar `flowchart TD` (top-down) como padrão
