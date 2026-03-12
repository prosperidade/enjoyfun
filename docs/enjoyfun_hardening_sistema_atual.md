# EnjoyFun — Hardening do Sistema Atual

## Objetivo deste documento

Este documento separa claramente a trilha de **hardening do sistema real atual** da trilha futura de **evolução arquitetural V4**.

A intenção é evitar mistura entre:
- problemas reais e imediatos do produto já em operação
- e melhorias estruturais de arquitetura-alvo que ainda não devem entrar antes da blindagem operacional

A diretriz principal é:

> Nenhuma nova feature relevante deve ser priorizada antes da correção dos bugs críticos de operação, segurança, sync offline, isolamento multi-tenant e defaults perigosos de contexto.

---

## Leitura honesta do sistema atual

O EnjoyFun hoje já é um produto operacional real, com cobertura funcional relevante em:
- autenticação
- eventos
- tickets
- scanner / validação
- POS / carteira
- participants / workforce
- financeiro básico
- dashboard híbrido
- dashboard analítico v1
- meals control

O sistema entrega valor real e já está além de um MVP simples.

Ao mesmo tempo, ele ainda não deve ser tratado como plataforma endurecida enterprise, porque persistem fragilidades importantes em:
- sessão web
- sync offline
- escopo de evento
- isolamento multi-tenant
- controllers grandes
- dependência de migrations e compatibilidades temporárias

---

## Regra de ouro

A partir deste ponto, a prioridade do produto deve seguir esta ordem:

1. blindagem operacional
2. segurança web e integridade
3. coerência de contexto (evento / organizer / tenant)
4. hardening arquitetural
5. só depois novas frentes de expansão

---

## Prioridade 1 — Crítico

### 1. Sessão web
#### Problema
O frontend ainda utiliza `localStorage` para armazenar `access_token` e `refresh_token`.

#### Risco
- exposição a exfiltração por XSS
- sessão web frágil
- superfície de ataque desnecessária

#### Direção recomendada
- migrar para fluxo com cookie `HttpOnly`
- revisar rotação segura do refresh token
- endurecer lifecycle de sessão

---

### 2. Sync offline
#### Problema
O sync offline é uma das áreas mais sensíveis do sistema.

Há risco de:
- placeholders SQL frágeis
- contexto default de evento
- persistência em evento errado
- falha silenciosa de replay

#### Risco
- venda gravada no evento errado
- quebra de integridade operacional
- divergência entre venda real e dashboard
- confiança baixa no modo offline

#### Direção recomendada
- remover qualquer fallback de `event_id = 1`
- exigir contexto explícito e válido
- revisar placeholders e execução SQL do SyncController
- validar replay ponta a ponta com cenários reais

---

### 3. Escopo de evento
#### Problema
Alguns fluxos operacionais ainda aceitam fallback de evento ou contexto implícito.

#### Risco
- operação silenciosa no evento errado
- bugs cross-event
- contaminação de dados
- quebra de confiança operacional

#### Direção recomendada
- remover defaults perigosos
- exigir `event_id` válido nas rotas operacionais críticas
- centralizar política de contexto

---

### 4. Multi-tenant / organizer_id
#### Problema
O sistema já usa `organizer_id` em muitas rotas, mas ainda há risco de deriva ou inconsistência.

#### Risco
- quebra de isolamento entre organizadores
- vazamento de contexto
- leitura/ação indevida entre tenants

#### Direção recomendada
- consolidar policy única por organizer/evento
- endurecer rotas críticas
- revisar controllers com maior risco de drift multi-tenant

---

### 5. Bugs críticos de operação
A auditoria executiva apontou bugs com potencial de bloqueio funcional ou quebra de isolamento.

Exemplos já destacados:
- criação de organizador com `password` no lugar de `password_hash`
- sync offline gravando em evento errado
- criação de produto sem `organizer_id`

#### Direção recomendada
- tratar esses bugs como fila prioritária de correção
- não abrir nova frente funcional antes de corrigir o núcleo crítico

---

## Prioridade 2 — Importante

### 1. Controllers críticos grandes demais
Os controllers ainda concentram:
- roteamento
- validação
- regra de negócio
- SQL
- resposta HTTP

#### Risco
- regressão
- manutenção cara
- drift entre documentação e implementação
- dificuldade de isolar segurança e política de acesso

#### Direção recomendada
Começar pelos domínios mais críticos:
- Workforce
- Ticket
- Event
- Sync
- controllers operacionais do POS

---

### 2. Observabilidade operacional
#### Problema
Ainda existem fluxos com fallback silencioso ou `catch` pouco explícito no frontend.

#### Risco
- erro operacional mascarado
- percepção falsa de funcionamento
- dificuldade de depuração em produção

#### Direção recomendada
- reduzir silêncio no frontend
- melhorar mensagens operacionais
- estruturar logs por domínio crítico
- separar indisponibilidade real de fallback

---

### 3. Dependência de migrations
#### Problema
Alguns módulos só funcionam corretamente com migrations aplicadas ou schema compatível.

#### Risco
- ambiente parcial parecer funcional
- módulos “meio ativos”
- custo de suporte e inconsistência por ambiente

#### Direção recomendada
- explicitar migrations obrigatórias por módulo
- endurecer readiness de ambiente
- separar “feature suportada” de “feature degradada por schema”

---

## Prioridade 3 — Evolução estruturada

### 1. Analytics
O Dashboard Analítico v1 foi encerrado como baseline estável da primeira frente da Fase 5.

#### Diretriz
- não abrir nova frente de analytics agora
- voltar depois para refinamento pontual
- só expandir filtros e leituras quando o hardening crítico estiver mais protegido

---

### 2. Meals
Meals está em andamento e deve ser concluído antes da abertura da próxima frente estrutural maior.

#### Diretriz
- terminar aderência ao workspace real
- validar com dados reais
- fechar camada financeira condicional com cuidado
- consolidar E2E

---

### 3. Antifraude além do relógio
O relógio antifraude hoje ajuda na integridade temporal, mas não é motor antifraude completo.

#### Direção futura
- replay detection
- sinais de risco
- anomalia
- correlação entre rotas críticas
- trilha antifraude mais robusta

---

## Prioridade 4 — Futuro planejado

Essas frentes devem esperar a blindagem do sistema atual:

- snapshots analíticos
- alertas operacionais e analíticos
- agentes automáticos
- financeiro premium
- logística operacional de artistas
- financeiro operacional do evento
- expansão V4 mais profunda
- otimizações de escala

---

## Ordem recomendada de ataque

### Bloco A — Blindagem imediata
- sessão web
- sync offline
- escopo de evento
- multi-tenant
- bugs críticos confirmados

### Bloco B — Hardening arquitetural
- controllers críticos
- policy layer
- services / repositories
- observabilidade
- readiness por migration

### Bloco C — Consolidação de módulos já em andamento
- Meals
- refinamentos futuros do Analytics
- alinhamentos operacionais restantes

### Bloco D — Expansão futura
- V4
- novos módulos
- premium financeiro
- antifraude expandido

---

## Conclusão

O EnjoyFun já é funcionalmente forte.
Mas o próximo ciclo deve priorizar:

- proteger o que já opera
- endurecer segurança e integridade
- fechar os módulos já em curso
- e só depois acelerar a evolução arquitetural futura

A diretriz oficial a partir deste documento é:

> Primeiro blindar o sistema real atual.  
> Depois crescer.