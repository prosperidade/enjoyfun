# ADR — Platform Guide Agent v1

**Status:** Aceito — 2026-04-11
**Contexto:** Princípio §0.3 do [execucaobacklogtripla.md](../execucaobacklogtripla.md) e decisão #6 do [adr_emas_architecture_v1.md](adr_emas_architecture_v1.md).
**Implementação:** Sprint 1 (foundation) + Sprint 3 (completo).

---

## Contexto

O bot global flutuante do EnjoyFun hoje tenta ser tudo ao mesmo tempo:

- Responder sobre dados de evento (vendas, ingressos, workforce)
- Explicar como configurar coisas da plataforma
- Diagnosticar problemas de setup
- Servir de FAQ estático

Resultado da auditoria 2026-04-11: o bot global responde mal sobre tudo e ainda **vaza dados de outros eventos** quando o usuário pergunta algo cross-surface. Misturar "ajuda da plataforma" com "dados operacionais" é a raiz do problema.

A solução não é mais um prompt de guardrail. É **separar fisicamente** o agente que conhece a plataforma do agente que enxerga dados.

---

## Decisão

Criar um novo agente no `ai_agent_registry` com `agent_key = platform_guide`, com escopo, persona e tool set **completamente separados** dos 12 agentes operacionais.

### 1. Persona

Especialista oficial da plataforma EnjoyFun. Didático, paciente, conhece todos os módulos, fluxos, configurações e atalhos. Fala como um onboarding lead, não como um chatbot. Sempre PT-BR. Sempre cita o caminho exato (`Configurações → Branding → Cores`).

### 2. Conhecimento

Indexado por `PlatformKnowledgeService.php` (novo, S1-C2). Fonte: documentação interna da plataforma estruturada por módulo (Bar, Food, Shop, POS, Parking, Artists, Workforce, Tickets, Cards, Meals, Branding, Channels, Finance, Files, AI Agents, SuperAdmin). Cada módulo entrega: descrição, fluxos principais, configurações disponíveis, troubleshooting comum.

No S3 esse conhecimento ganha RAG pragmático: chunks indexados em `pgvector`, recall por similaridade, citação de fonte obrigatória.

### 3. Tools exclusivas

| Skill | Função |
|---|---|
| `get_module_help` | Retorna descrição e fluxos do módulo X |
| `get_configuration_steps` | Passo-a-passo para configurar feature Y |
| `navigate_to_screen` | Devolve um bloco `actions` que leva o usuário pra tela X |
| `diagnose_organizer_setup` | Roda checagens no organizador atual e devolve gaps de configuração |

Implementadas via `AISkillRegistryService` no S1 (foundation) e completadas no S3.

### 4. Isolamento de dados

**O `platform_guide` NÃO tem acesso a tools de dados operacionais.** Não pode ler vendas, ingressos, workforce, parking, meals, finance ou qualquer entidade de evento. Se o usuário pedir dados, o agente devolve uma resposta padrão: *"Pra ver dados do seu evento, abra o módulo X e use o chat embarcado de lá. Quer que eu te leve pra lá?"* + bloco `actions` com `navigate_to_screen`.

Isolamento garantido em três camadas:

1. **Registry:** `platform_guide` tem `allowed_skills` restrito ao set acima
2. **Runtime:** `AIToolRuntimeService` valida `agent_key` × `skill_key` antes de cada tool call
3. **RLS:** as queries que o agente faz via skills passam por `app_user` com `app.current_organizer_id`, mas as skills permitidas não tocam tabelas operacionais

### 5. Rendering

Resposta usa blocos `text`, `tutorial_steps` (novo bloco no S3), `actions` e `card_grid`. **Nunca** retorna `chart`, `table`, `lineup`, `map` — esses são exclusivos dos agentes operacionais.

---

## Consequências

### Positivas
- Bot global passa a responder bem sobre a plataforma — vira diferencial de UX
- Vazamento de dados cross-surface eliminado por construção
- Onboarding de novo organizador acelera (guia + diagnóstico de setup)
- Embedded chats das superfícies ficam livres pra focar em dados
- Versionamento da base de conhecimento independente do código (S4)

### Negativas
- Mais um agente para manter no `ai_agent_registry` (13 no total)
- `PlatformKnowledgeService` precisa ser atualizado a cada feature nova da plataforma
- Cria expectativa de que o bot "sabe tudo da plataforma" — gap de cobertura vira bug

### Riscos
- **Conhecimento desatualizado:** mitigado por revisão a cada release + ownership do squad de produto
- **Usuário insiste em pedir dados:** mitigado pela resposta padrão + redirect com `navigate_to_screen`

---

## Alternativas consideradas

1. **Manter o bot global híbrido com guardrails de prompt** — rejeitado: não resolve vazamento, só esconde
2. **Eliminar o bot global** — rejeitado: perde o canal de descoberta e onboarding
3. **Bot global = só FAQ estático em markdown** — rejeitado: estagna, não diagnostica
4. **Reusar um dos 12 agentes existentes** — rejeitado: conflito de persona, dados e tools

---

## Critérios de aceite

- `agent_key = platform_guide` existe no `ai_agent_registry` (S1-C1)
- `PlatformKnowledgeService.php` responde com módulos indexados (S1-C2)
- 4 skills exclusivas registradas no `ai_skill_registry` (S1-C3)
- Tentativa de chamar tool de dados operacionais com `agent_key=platform_guide` retorna erro 403 + log
- 5 fluxos de tutorial testados manualmente (gateway Asaas, branding, emissão de cartões, cadastro de evento, configuração de canais)
- Bot global flutuante usa `platform_guide` quando flag `FEATURE_AI_PLATFORM_GUIDE` está on (S3)
- Resposta padrão de redirect quando usuário pede dados de evento (S3)
- RAG pragmático com citação de fonte obrigatória (S3)
