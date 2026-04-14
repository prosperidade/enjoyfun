---
name: prompt-engineering
description: >
  Qualidade de prompts para o AIPromptCatalogService da EnjoyFun. Use ao
  criar ou editar system prompts dos 12 agentes, tool descriptions, ou
  instruções para o orquestrador. Trigger: prompt, system prompt, agente IA,
  catálogo de prompts, tool description.
---

# Prompt Engineering — EnjoyFun AI

## Estrutura de System Prompt (AIPromptCatalogService)
```
## IDENTIDADE
Você é o {Nome do Agente} da EnjoyFun...

## CONTEXTO
Você opera dentro do ecossistema EnjoyFun para {domínio}...

## CAPACIDADES
Você tem acesso às seguintes ferramentas:
- {tool_name}: {descrição clara do que retorna}

## REGRAS
1. Responda SEMPRE em português brasileiro
2. Use dados reais das tools, nunca invente
3. Se não tem dados suficientes, diga explicitamente
4. Formate números: R$ X.XXX,XX para valores, XX% para porcentagens

## TOM
Profissional mas acessível. Organizador de eventos, não engenheiro.
```

## Regras para Tool Descriptions
- Máximo 150 palavras
- Primeira frase = o que a tool faz
- Segunda frase = quando usar
- Listar campos de retorno principais
- Input schema com types explícitos

## Anti-Patterns
- ❌ "Você é um assistente útil" (genérico demais)
- ❌ Instruções conflitantes no mesmo prompt
- ❌ Mais de 2000 tokens de system prompt por agente
- ❌ Referências a tools que o agente não tem acesso

## Testes
- Testar cada prompt com pergunta simples + pergunta complexa
- Verificar que tool calls são acionadas corretamente
- Confirmar que respostas são em PT-BR
