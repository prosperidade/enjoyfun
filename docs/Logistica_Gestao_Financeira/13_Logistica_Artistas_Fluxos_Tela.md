# 13 — Logística de Artistas · Fluxos de Tela

> Fluxos funcionais e estrutura de telas do Módulo 1.

---

## 1. Tela de lista de artistas

### Objetivo
Dar visão operacional por evento.

### Entradas
- filtro por `event_id`
- busca por artista
- filtro por status de booking
- filtro por severidade de alerta

### Colunas mínimas
- artista
- palco
- horário de show
- chegada operacional
- severidade atual
- status do booking

### Ações
- abrir detalhe
- editar booking
- abrir timeline
- abrir alertas

---

## 2. Tela de detalhe do artista

### Estrutura sugerida
Abas em `.jsx`:
- Booking
- Logística
- Timeline
- Alertas
- Equipe
- Arquivos

### Cards de topo
- artista
- evento
- show
- chegada
- severidade atual
- custo logístico acumulado

---

## 3. Tela de timeline operacional

### Blocos
- marcos horários
- trechos de transfer
- janelas calculadas
- alertas gerados

### Comportamentos
- editar horários-base
- recalcular timeline
- recalcular alertas
- exibir recomendação operacional

---

## 4. Tela de alertas

### Filtros
- `event_id`
- severidade
- status
- artista

### Ações
- acknowledge
- resolve
- dismiss
- recalcular lote do evento

---

## 5. Tela de equipe

### Campos por membro
- nome
- função
- documento
- hotel
- transfer
- observações

### Ações
- adicionar
- editar
- remover

---

## 6. Tela de arquivos

### Tipos esperados
- contrato
- rider
- rooming list
- passagem
- voucher
- documento diverso

### Ações
- upload
- listar
- remover

---

## 7. Fluxo de importação

### Passo 1
Selecionar tipo de importação.

### Passo 2
Enviar arquivo e gerar preview.

### Passo 3
Exibir:
- total de linhas
- válidas
- inválidas
- erros por linha
- impacto previsto

### Passo 4
Confirmar aplicação.

---

## 8. Diretriz de implementação frontend

- React `.jsx`
- aderir ao padrão visual de `WorkforceOpsTab.jsx`
- evitar criar componente visual desconectado do projeto existente
- chamadas para API com envelope padrão do sistema
