# EnjoyFun — Trilha V4 / Arquitetura-Alvo

## Objetivo deste documento

Este documento organiza a **arquitetura-alvo V4** como trilha futura e separada do hardening do sistema atual.

A função deste documento não é priorizar execução imediata.
A função é:

- preservar o norte arquitetural
- evitar perda de visão de longo prazo
- impedir que melhorias estruturais futuras sejam confundidas com urgências do sistema atual

---

## Diretriz principal

A trilha V4 **não deve competir com o hardening do sistema atual**.

Ela só deve ganhar prioridade prática depois de:
- blindagem operacional
- segurança web mínima endurecida
- sync offline estabilizado
- escopo de evento protegido
- módulos em andamento concluídos (ex.: Meals)
- redução dos riscos mais graves de regressão

---

## Leitura da V4

A V4 representa a visão de evolução do EnjoyFun para uma arquitetura mais robusta, previsível, segura e escalável.

Ela é útil como:
- blueprint de longo prazo
- referência de desenho técnico
- alvo de organização por domínio
- guia para evitar crescimento torto

Ela não deve ser tratada como backlog imediato integral.

---

## Eixos da arquitetura-alvo V4

### 1. Segurança e identidade
- autenticação mais robusta
- endurecimento da sessão web
- gestão de segredos mais madura
- redução de superfície de ataque
- separação mais clara entre autenticação, autorização e contexto operacional

### 2. Isolamento por domínio
- domínios mais explicitamente separados
- menos controllers monolíticos
- services e políticas por responsabilidade
- crescimento mais previsível por módulo

### 3. Antifraude expandido
Além do relógio antifraude atual, a V4 deve prever:
- replay detection
- correlação de eventos suspeitos
- sinais de risco
- trilhas operacionais anômalas
- motor antifraude mais completo

### 4. Resiliência operacional
- sync offline mais blindado
- observabilidade melhor
- readiness real por ambiente
- menos compatibilidade temporária silenciosa

### 5. Escalabilidade analítica
- snapshots
- recortes analíticos mais robustos
- filtros avançados
- desempenho melhor em leituras históricas

### 6. Expansão modular
A V4 também deve abrir espaço, sem antecipar execução imediata, para:
- logística operacional de artistas
- financeiro operacional do evento
- premium financeiro
- agentes e automações futuras

---

## O que faz parte da V4, mas não entra agora

Exemplos de itens que pertencem à trilha V4, mas não devem entrar antes do hardening do sistema atual:

- RS256 pleno
- Vault / gestão mais robusta de segredos
- WAF / rate limiting mais agressivo
- antifraude além do relógio
- apps mais segregados por contexto
- audit trail mais expandido
- trilha premium mais profunda
- otimizações de escala e desempenho de longo prazo

---

## Como usar este documento

Sempre que surgir uma iniciativa nova, ela deve ser classificada em uma destas duas categorias:

### A. Hardening do sistema atual
Pergunta:
> isso protege operação, segurança, integridade ou aderência do sistema atual?

Se sim, deve ir para a trilha de hardening.

### B. Evolução V4
Pergunta:
> isso melhora a arquitetura-alvo, mas não resolve um risco imediato do produto atual?

Se sim, deve permanecer nesta trilha V4.

---

## Guardrails de decisão

### Não antecipar V4 quando:
- houver bug crítico aberto
- houver risco de sync offline
- houver risco de evento errado
- houver risco multi-tenant
- Meals ainda não estiver estabilizado
- módulos operacionais ainda estiverem em aderência parcial ao workspace real

### Considerar execução V4 quando:
- núcleo operacional estiver protegido
- sessão web estiver endurecida
- sync offline estiver validado
- módulos em andamento estiverem concluídos
- o time puder crescer sem risco de colapso de contexto

---

## Macrotemas da V4

### V4.1 — Segurança mais madura
- sessão
- segredo
- autenticação mais forte
- política por contexto

### V4.2 — Domínio e arquitetura
- controller fino
- service
- repository/query layer
- policy layer
- menos acoplamento

### V4.3 — Antifraude expandido
- replay
- sinais de risco
- anomalia
- correlação temporal e operacional

### V4.4 — Analytics e performance
- snapshots
- filtros avançados
- estabilidade de recortes
- leitura histórica mais forte

### V4.5 — Expansões operacionais futuras
- Artist Logistics
- Event Finance
- premium financeiro
- agentes
- automações futuras

---

## Relação entre V4 e o sistema atual

A V4 não substitui o sistema atual.
Ela deve ser construída em cima dele, de forma progressiva.

Isso exige:
- separar o que é urgência
- separar o que é evolução
- proteger o produto atual antes de redesenhar o futuro

---

## Conclusão

A V4 continua válida como visão estratégica.
Mas sua execução deve ser disciplinada.

A ordem correta é:

1. proteger o sistema atual
2. estabilizar o que já está em andamento
3. reduzir dívida estrutural mais perigosa
4. só depois avançar com a arquitetura-alvo

Diretriz final:

> A V4 é o norte.  
> O hardening do sistema atual é a prioridade.