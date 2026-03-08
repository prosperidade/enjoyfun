# EnjoyFun — Especificação Oficial do Participants Hub + Workforce Ops v1

## Objetivo
Definir oficialmente a arquitetura funcional e operacional do Participants Hub e do Workforce Ops dentro da EnjoyFun.

Este documento existe para resolver uma dor central do produto:
- não deixar Guest crescer de forma errada
- separar claramente convidados de operação de equipe
- preparar a plataforma para eventos de 24h, multi-day, festivais e credenciamento operacional robusto

---

## 1. Tese oficial

A EnjoyFun precisa tratar pessoas do evento como um domínio central.

Mas nem todas as pessoas devem ser tratadas da mesma forma.

### Por isso, a estrutura oficial é:

## Participants Hub
núcleo unificado de participantes do evento

com dois submódulos principais:

### Guest Management
voltado para hospitalidade, acesso e listas especiais

### Workforce Ops
voltado para operação, turnos, presença e alimentação

---

## 2. Diferença oficial entre Guest e Workforce

## 2.1 Guest Management
Destinado a:
- convidados
- artistas
- DJs
- permutas
- listas especiais
- categorias VIP ou de acesso social/hospitalidade

### Regras principais
- foco em credenciamento e entrada
- foco em presença/no-show
- pouco ou nenhum controle de turno
- normalmente não envolve escala operacional

---

## 2.2 Workforce Ops
Destinado a:
- staff
- produção
- praça de alimentação
- fornecedores operacionais
- seguranças
- operadores por setor
- equipes técnicas

### Regras principais
- foco em jornada operacional
- entrada e saída por turno
- validade por dia/horário
- refeições por dia/turno
- presença, ausência e permanência

---

## 3. Estrutura oficial do Participants Hub

## 3.1 Categorias iniciais sugeridas
- guest
- artist
- dj
- staff
- permuta
- food_staff
- production
- parking
- vendor_staff

---

## 3.2 Entidade base
A base do domínio deve evoluir para uma estrutura unificada:
- `event_participants`

Ela deve representar qualquer pessoa vinculada ao evento.

### Campos centrais
- organizer_id
- event_id
- category_id
- name
- email
- phone
- document
- status
- qr_code_token
- metadata
- created_at
- updated_at

---

## 4. Guest Management — escopo oficial

## 4.1 Objetivo
Gerenciar todas as listas especiais e credenciais de acesso não operacionais.

## 4.2 Funcionalidades oficiais
- cadastro manual
- importação via CSV
- edição e remoção
- geração de QR code
- check-in
- consulta pública de ticket/convite
- filtros por categoria
- presença / no-show

## 4.3 Abas sugeridas no frontend
- Convidados
- Artistas
- DJs
- Permutas
- Listas especiais

## 4.4 KPIs principais
- total por categoria
- presentes por categoria
- ausentes por categoria
- taxa de presença
- no-show

---

## 5. Workforce Ops — escopo oficial

## 5.1 Objetivo
Gerenciar o credenciamento e a operação de equipes do evento.

## 5.2 Funcionalidades oficiais
- importação CSV de equipe
- cadastro por cargo
- cadastro por setor
- vínculo com turno
- validade por dia/horário
- geração de QR code
- check-in e check-out
- controle de refeições
- presença por turno
- ausências e atrasos
- histórico operacional da pessoa

## 5.3 Abas sugeridas no frontend
- Staff
- Produção
- Praça de Alimentação
- Operadores por Setor
- Turnos
- Refeições

## 5.4 KPIs principais
- total previsto por turno
- total presente
- ausentes
- atrasados
- por setor
- por cargo
- refeições previstas
- refeições consumidas
- saldo de refeição

---

## 6. Eventos multi-dia e turnos

## 6.1 Regra oficial
Eventos multi-day não podem ser tratados só por `starts_at` e `ends_at` no evento.

É obrigatório usar:
- `event_days`
- `event_shifts`

## 6.2 O que isso permite
- controle por dia do festival
- credenciais válidas só em certos dias
- turnos diferentes no mesmo evento
- refeições por dia/turno
- relatórios por dia e por equipe

---

## 7. Fluxo operacional oficial do Workforce

### Etapa 1 — Importação
O organizador importa um CSV com dados base da equipe.

### Etapa 2 — Enriquecimento
O sistema associa:
- categoria
- cargo
- setor
- turno
- refeições permitidas
- validade por dia/horário

### Etapa 3 — Geração de credencial
É gerado um QR code único por pessoa.

### Etapa 4 — Operação
A pessoa usa o mesmo QR para:
- entrar
- sair
- consumir refeição

### Etapa 5 — Monitoramento
O dashboard mostra:
- quem já entrou
- quem ainda não entrou
- quem saiu
- refeições consumidas
- saldo operacional da equipe

---

## 8. Regras oficiais de QR code

1. Cada participante deve ter um QR único.
2. O QR pode carregar regras diferentes conforme a categoria.
3. Para Guest, o foco é acesso.
4. Para Workforce, o QR deve suportar:
   - entrada
   - saída
   - refeição
   - validade temporal
5. O mesmo QR pode ser reutilizado em múltiplos dias, desde que controlado pelas regras do evento/turno.

---

## 9. Regras oficiais de status

## Guest
Status sugeridos:
- esperado
- presente
- ausente
- cancelado

## Workforce
Status sugeridos:
- previsto
- presente
- ausente
- atrasado
- em_turno
- fora_do_turno
- desligado_do_evento

---

## 10. Backend oficial recomendado

### Controllers de transição
- `GuestController`

### Novos controllers recomendados
- `ParticipantController`
- `WorkforceController`
- `ParticipantCheckinController`
- `MealController`

### Services recomendados
- `ParticipantService`
- `GuestService`
- `WorkforceService`
- `ShiftAssignmentService`
- `PresenceService`
- `MealControlService`

---

## 11. Frontend oficial recomendado

### Módulo `participants`
Responsável por:
- listas
- filtros
- categorias
- check-ins
- visão consolidada

### Módulo `workforce`
Responsável por:
- turnos
- equipe
- refeições
- presença
- operação por setor/cargo

### Componentes sugeridos
- `ParticipantTabs`
- `ParticipantTable`
- `CheckinActionPanel`
- `ShiftSummaryCard`
- `MealSummaryCard`
- `WorkforceFilters`
- `ParticipantStatsGrid`

---

## 12. Modelagem conectada

### Tabelas-base
- `participant_categories`
- `event_participants`
- `event_days`
- `event_shifts`
- `workforce_assignments`
- `participant_checkins`
- `participant_meals`

### Regra de transição
- `guests` continua existindo temporariamente
- novo modelo nasce em paralelo
- migração deve ser gradual

---

## 13. Ordem recomendada de implementação

### Etapa 1
- manter Guest atual funcionando
- criar categorias e base de participantes
- criar dias e turnos

### Etapa 2
- criar Workforce Ops v1
- CSV de staff
- check-in e check-out
- vínculo por turno

### Etapa 3
- criar Meals Control v1
- dashboards operacionais de equipe
- consolidar KPIs de presença

---

## 14. O que não fazer

1. Não colocar toda a lógica de workforce dentro do Guest atual.
2. Não tratar equipe como simples convidado com nome diferente.
3. Não tentar controlar evento multi-day sem `event_days` e `event_shifts`.
4. Não criar dashboard de presença sem base de check-ins e turnos.
5. Não criar refeição apenas por contagem manual sem rastreamento por pessoa.

---

## 15. Resultado esperado

Ao seguir esta especificação, a EnjoyFun passa a ter uma base sólida para:
- convidados e listas especiais
- credenciamento operacional real
- controle de turnos
- alimentação de equipe
- presença e no-show por categoria
- eventos longos e festivais
- dashboards operacionais muito mais úteis

