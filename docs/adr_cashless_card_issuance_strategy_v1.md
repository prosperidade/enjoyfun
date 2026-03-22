# ADR — Estratégia Oficial de Emissão de Cartões Operacionais por Evento v1

## Status
Aceita — 2026-03-20

## Contexto
- A EnjoyFun já possui um módulo operacional de carteira/cartão digital em:
  - `frontend/src/pages/Cards.jsx`
  - `backend/src/Controllers/CardController.php`
  - `backend/src/Services/WalletSecurityService.php`
  - `database/schema_current.sql` (`digital_cards`, `card_transactions`)
- Hoje a emissão manual de cartão na UI de cartões é simples:
  - o operador informa nome e CPF
  - o sistema cria um cartão novo
- Porém a implementação real ainda é rasa para o caso de uso desejado:
  - o frontend envia `user_name`, `cpf` e `event_id`
  - o backend atual de criação (`createCard`) só persiste `user_id`, `organizer_id` e saldo inicial
  - a tabela `digital_cards` não guarda `event_id`, `participant_id`, `sector`, origem da emissão nem lote
- Em paralelo, o domínio de Workforce já possui estrutura rica de contexto operacional:
  - `event_participants`
  - `workforce_assignments`
  - `workforce_roles`
  - `workforce_event_roles`
  - importação CSV manager-first em `/workforce/import`
  - seleção em massa na UI de `Workforce Ops`
- O roadmap de produto também prevê um módulo futuro de logística dos artistas, com necessidade explícita de:
  - emitir cartões em massa para artistas contratados
  - segmentar por setor/equipe/liderança
  - preservar rastreabilidade operacional por evento

## Problema
Precisamos decidir **onde nasce a emissão estruturada de cartões** e **qual deve ser o modelo de dados correto** para suportar:

1. emissão avulsa manual
2. emissão em massa por setor
3. emissão em massa por liderança/árvore de workforce
4. emissão futura para artistas/logística
5. consulta operacional posterior por evento, setor, origem e lote

Sem essa decisão, a tendência seria empurrar toda a responsabilidade para a tela `/cards`, transformando o módulo de carteira em uma UI de cadastro genérico sem contexto de evento/equipe.

## Decisão
Adotar oficialmente uma arquitetura onde **a emissão de cartões nasce no módulo que conhece a pessoa e a estrutura operacional**, enquanto **a tela de cartões permanece como console operacional da carteira**.

### Síntese da decisão
1. `/cards` continua existindo, mas como superfície de operação e auditoria da carteira:
   - emitir cartão avulso manual
   - consultar saldo/extrato
   - recarregar
   - bloquear/reativar futuramente
   - filtrar cartões emitidos
2. A emissão estruturada por equipe **não deve nascer na tela genérica de cartões**.
3. A emissão em massa para equipe passa a nascer em `Participants > Workforce Ops`, porque esse módulo já conhece:
   - evento
   - setor
   - cargo
   - gerente/liderança
   - seleção em massa
   - importação CSV
4. O futuro módulo de logística dos artistas deve reutilizar o mesmo motor de emissão, iniciando o fluxo pela sua própria UI.
5. `digital_cards` continua sendo a carteira financeira.
6. O contexto operacional da emissão passa a viver em tabelas próprias de vínculo/lote, e não diretamente em `digital_cards`.

## Regras oficiais desta decisão
1. **Não criar tabela separada por setor para carteiras.**
2. **Não duplicar o conceito de saldo dentro de Workforce ou Logística.**
3. **`digital_cards` continua sendo a fonte oficial de saldo, ativação e identidade financeira do cartão.**
4. **Toda emissão operacional vinculada a evento deve gerar um vínculo explícito entre cartão e contexto do evento.**
5. **A emissão em massa deve operar sobre `people` + `event_participants`, nunca sobre listas soltas sem identidade persistida.**
6. **O fluxo padrão para equipe é “emitir para selecionados” dentro de Workforce Ops.**
7. **O fluxo padrão para artistas será “emitir para contratados/selecionados” dentro do futuro módulo de logística.**
8. **A tela `/cards` não é a origem principal da modelagem de equipe; ela é a central de operação da carteira emitida.**
9. **Deve existir regra operacional de unicidade: um participante não pode ter mais de um cartão ativo para o mesmo evento sem fluxo explícito de substituição.**
10. **A emissão avulsa manual em `/cards` continua permitida, mas passa a ser tratada como exceção operacional ou emissão não estruturada.**

## Modelo de dados alvo

### 1. Manter `digital_cards` como carteira financeira
`digital_cards` permanece responsável por:
- `id`
- `user_id` quando existir vínculo com usuário da plataforma
- `balance`
- `is_active`
- `organizer_id`
- timestamps

Ela **não deve virar depósito de contexto operacional volátil** como setor, lote, gerente ou árvore de workforce.

### 2. Criar uma tabela de vínculo operacional por evento
Decisão: introduzir uma tabela do tipo `event_card_assignments`.

Campos mínimos esperados:
- `id`
- `card_id`
- `organizer_id`
- `event_id`
- `participant_id`
- `person_id`
- `sector`
- `source_module`
- `source_batch_id`
- `source_role_id`
- `source_event_role_id`
- `issued_by_user_id`
- `issued_at`
- `status`
- `holder_name_snapshot`
- `holder_document_snapshot`
- `notes`

### 3. Criar uma tabela de lote de emissão
Decisão: introduzir uma tabela do tipo `card_issue_batches`.

Objetivo:
- registrar emissões em massa
- permitir auditoria por lote
- reprocessar ou exportar resultados
- explicar “quem emitiu”, “de onde partiu” e “para qual setor”

Campos mínimos esperados:
- `id`
- `organizer_id`
- `event_id`
- `source_module`
- `sector`
- `requested_count`
- `issued_count`
- `skipped_count`
- `replaced_count`
- `created_by_user_id`
- `created_at`
- `metadata`

### 4. Não criar “uma tabela de cartões por setor”
Resposta oficial para essa dúvida:

- **não** criar tabelas separadas por setor
- **não** partir `digital_cards` em subconjuntos físicos por workforce/artistas/bar/etc.
- **sim** criar uma camada de vínculo operacional com coluna `sector`

O setor é atributo de contexto da emissão e da operação do evento, não da carteira financeira em si.

## Superfícies oficiais de UI

### 1. `/cards` — console operacional da carteira
Responsabilidades:
- ver todos os cartões do organizador
- filtrar por evento, setor, origem, status e lote
- recarregar
- consultar extrato
- emitir cartão avulso manual
- reemitir/substituir cartão futuramente

Não é a tela certa para:
- selecionar 30 artistas recém-importados
- emitir lote a partir de árvore de liderança
- decidir quem pertence a qual equipe

### 2. `Participants > Workforce Ops` — origem da emissão em massa de equipe
Responsabilidades:
- selecionar membros da equipe
- filtrar por setor
- filtrar por cargo
- filtrar por gerente/raiz da árvore
- emitir cartões para os selecionados
- emitir em lote logo após importação CSV

Fluxos oficiais sugeridos:
- ação em massa: `Emitir cartões`
- ação contextual na visão do gerente/setor: `Emitir cartões da equipe`
- CTA após importação CSV: `Emitir cartões para os importados agora`

### 3. Futuro módulo de logística dos artistas
Responsabilidades:
- listar artistas contratados
- segmentar por produção, palco, performance, circulação ou outra estrutura da operação
- emitir cartões para os artistas selecionados

Regra oficial:
- o módulo de logística dos artistas **reutiliza o mesmo serviço de emissão**
- ele **não cria um segundo sistema de carteira paralelo**

## Fluxos oficiais desta decisão

### Fluxo A — emissão avulsa manual
Origem:
- `/cards`

Uso esperado:
- exceção operacional
- visitante/staff pontual
- contingência

Regras:
- exigir evento selecionado
- gerar `event_card_assignments`
- marcar `source_module = cards_manual`

### Fluxo B — emissão em massa por workforce
Origem:
- `Participants > Workforce Ops`

Uso esperado:
- gerente, supervisor, coordenador e membros operacionais
- lotes vindos de CSV
- emissão por setor

Regras:
- operar sobre participantes já existentes no evento
- respeitar contexto de setor e liderança
- permitir preview antes de emitir
- registrar lote
- marcar `source_module = workforce_bulk`

### Fluxo C — emissão a partir da logística dos artistas
Origem:
- módulo futuro de logística

Uso esperado:
- artistas contratados
- equipe artística por produção/setor

Regras:
- artista deve estar ancorado no mesmo eixo de identidade (`people` + `event_participants`)
- emissão usa o mesmo serviço de lote
- marcar `source_module = artist_logistics`

## Regras de negócio recomendadas
1. Um participante pode ter no máximo **um cartão ativo por evento**.
2. Se já existir cartão ativo:
   - o default é **não emitir outro**
   - o sistema deve marcar como `skipped`
3. Reemissão só ocorre por fluxo explícito:
   - `substituir cartão`
   - `desativar anterior e emitir novo`
4. Emissão em massa deve ter preview com pelo menos:
   - aptos para emissão
   - já possuem cartão ativo
   - precisam de substituição
   - estão sem identidade suficiente
5. O filtro por setor deve operar sobre o vínculo operacional, não sobre `digital_cards`.

## Artistas: interpretação oficial
O futuro módulo de logística dos artistas **não deve criar um silo novo de identidade**.

Direção oficial:
- artista contratado continua pertencendo ao eixo canônico:
  - `people`
  - `event_participants`
- a logística dos artistas adiciona contexto operacional próprio
- a emissão do cartão reutiliza o domínio de `event_card_assignments`

Isso evita:
- duplicação de cadastros
- carteira paralela
- duas verdades para a mesma pessoa

## Implicações arquiteturais futuras
Quando esta frente for implementada, a arquitetura-alvo deverá considerar pelo menos:

### Backend
- `CardIssuanceService`
- endpoint de preview de emissão
- endpoint de emissão em lote
- endpoint de reemissão/substituição
- consultas por evento/setor/origem/lote

### Banco
- `event_card_assignments`
- `card_issue_batches`
- índices por:
  - `event_id`
  - `participant_id`
  - `sector`
  - `source_module`
  - `source_batch_id`

### Frontend
- ação em massa de emissão dentro de `Workforce Ops`
- CTA de emissão após importação CSV
- filtros estruturados em `/cards`
- visão agrupada por setor/lote/origem

## Fases sugeridas

### Fase 1 — fundação do domínio
- criar `event_card_assignments`
- criar `card_issue_batches`
- criar `CardIssuanceService`
- ajustar emissão manual de `/cards` para gravar vínculo operacional

### Fase 2 — Workforce
- adicionar preview de emissão em massa
- adicionar ação `Emitir cartões` para selecionados
- adicionar emissão por setor/gerente

### Fase 3 — Cards como console real
- filtros por evento, setor, origem e lote
- agrupamento visual por setor
- status operacional de emissão

### Fase 4 — Logística dos artistas
- reaproveitar o mesmo serviço de emissão
- expor a ação no módulo de logística

## Fora do escopo desta decisão
- não define ainda o nome final das tabelas
- não define ainda o contrato HTTP final dos endpoints
- não implementa ainda substituição física de cartão
- não decide ainda se a carteira continuará reutilizável entre múltiplos eventos do mesmo organizador
- não define ainda a modelagem completa do módulo de logística dos artistas

## Resultado esperado
Esta decisão congela a direção oficial:

- a emissão de cartões **nasce no módulo que conhece a pessoa e a estrutura**
- a tela `/cards` **opera e audita a carteira emitida**
- o contexto de evento/setor/lote **vive em tabelas de vínculo**
- workforce e logística dos artistas **reutilizam o mesmo motor de emissão**
- não haverá fragmentação do domínio de carteira em tabelas separadas por setor
