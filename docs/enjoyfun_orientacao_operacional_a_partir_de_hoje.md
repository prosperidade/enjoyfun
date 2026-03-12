# EnjoyFun — Orientação Operacional a Partir de Hoje

## Objetivo deste documento

Este documento organiza a posição oficial de trabalho a partir de hoje, consolidando:

- o que já foi encerrado
- o que está em andamento
- o que deve ser priorizado
- o que fica explicitamente fora do foco imediato

---

## Estado atual consolidado

### 1. POS
A Fase 4 do POS foi encerrada formalmente.
O frontend foi modularizado e estabilizado dentro do escopo aprovado.

### 2. Fase 5 / Analytics
A primeira frente da Fase 5 foi encerrada formalmente como:
- `Dashboard Analítico v1`
- baseline estável
- `/analytics` separado do dashboard híbrido
- sem abrir snapshots, alertas, agentes ou financeiro premium

Também ficou registrado que:
- será necessário voltar ao Analytics depois
- alguns pontos ainda precisarão de refinamento e alinhamento
- isso não muda o fato de que a primeira frente já foi concluída no escopo aprovado

### 3. Meals
Meals está em andamento como frente própria.

A leitura oficial atual é:
- primeiro hardening de contrato
- depois leitura segura
- depois coerência de filtros
- depois aderência ao workspace real
- depois afinamento operacional
- só depois camada financeira condicional
- e, ao final, consolidação / validação E2E

Meals não deve ser misturado com Analytics.

### 4. Módulos futuros já reconhecidos
Ficaram reconhecidas, mas fora do escopo imediato:

- logística operacional de artistas
- financeiro operacional do evento

Também ficou alinhado que:
- esses módulos serão retomados depois
- o módulo de artistas deve operar por:
  - evento
  - palco
  - artista

---

## O que está em foco imediato

### Foco principal
Terminar Meals com honestidade operacional.

Isso significa:
- validar com dados reais
- corrigir o que ainda não refletir o workspace
- não maquiar ausência de base
- não inventar semântica que o backend não entrega
- separar saldo real, fallback, base complementar e custo indisponível

### Foco seguinte
Depois de Meals, abrir a trilha de hardening do sistema atual.

---

## O que não deve ser aberto agora

Neste momento, ficam fora de foco:

- snapshots analíticos
- alertas operacionais e analíticos
- agentes automáticos
- financeiro premium
- V4 profunda
- novos módulos de produto
- redesigns amplos
- expansão funcional paralela

---

## Regras de prioridade

### Regra 1
Nenhuma feature nova relevante antes de:
- bugs críticos
- sync offline
- segurança de sessão
- escopo de evento
- multi-tenant
- módulos em andamento concluídos

### Regra 2
Meals deve ser concluído antes da abertura da próxima grande frente estrutural.

### Regra 3
Analytics não está em expansão agora.
Analytics está em baseline estável e será refinado depois.

### Regra 4
A auditoria do sistema deve ser tratada em duas camadas:
- sistema real atual / hardening
- arquitetura-alvo V4

---

## Próximos passos oficiais

### Passo 1
Finalizar Meals:
- testes reais
- correções honestas
- ajuste do que ainda não reflete o workspace
- camada financeira condicional só se a base permitir
- consolidação E2E no momento correto

### Passo 2
Separar oficialmente a auditoria em:
- hardening do sistema atual
- trilha V4 / arquitetura-alvo

### Passo 3
Começar o trabalho pós-Meals pelo hardening do sistema atual:
- segurança web
- sync offline
- scoping de evento
- multi-tenant
- bugs críticos confirmados
- controllers críticos

### Passo 4
Deixar a trilha V4 como backlog arquitetural de próxima camada, não de execução imediata.

---

## Lembretes estratégicos

### Sobre Analytics
- a primeira frente foi concluída
- o módulo precisará de refinamentos depois
- isso já está reconhecido
- não reabrir agora sem necessidade

### Sobre Meals
- não tratar a tela como resolvida só porque houve PRs
- validar sempre contra o workspace real
- distinguir:
  - leitura real do Meals
  - leitura complementar do Workforce
  - fallback
  - projeção
  - custo indisponível

### Sobre novos módulos
- logística de artistas e financeiro operacional já estão reconhecidos
- mas não entram agora
- só retomar em etapa própria

---

## Conclusão

A orientação oficial a partir de hoje é:

1. terminar Meals com honestidade operacional
2. depois iniciar o hardening do sistema atual
3. manter a V4 como trilha separada
4. só depois abrir novas expansões

Diretriz final:

> Primeiro concluir o que já está em andamento e blindar o sistema real.  
> Depois crescer com segurança.