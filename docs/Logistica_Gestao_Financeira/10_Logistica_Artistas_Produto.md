# 10 — Logística de Artistas · Produto

> Escopo funcional do Módulo 1 no modelo já fechado para implementação.

---

## 1. Objetivo

Centralizar a operação do artista no evento em um único módulo, cobrindo cadastro, vínculo com evento, logística, timeline operacional, alertas, equipe, arquivos e importação.

---

## 2. Usuários principais

- produção
- coordenação de backstage
- operação logística
- coordenação geral

---

## 3. Casos de uso principais

### 3.1 Cadastro do artista
Cadastro mestre reutilizável do artista/atração.

### 3.2 Booking no evento
Vínculo do artista com o evento com informações operacionais, show, horário e cachê acordado.

### 3.3 Logística
Registro de:
- origem
- destino
- voo
- hospedagem
- transportes locais
- necessidades operacionais

### 3.4 Timeline operacional
Linha do tempo do artista no evento com marcos importantes.

### 3.5 Estimativas de deslocamento
Cálculo de ETA base, pico e buffer operacional.

### 3.6 Alertas
Geração de risco operacional a partir de conflitos de janela e inconsistência de dados.

### 3.7 Equipe do artista
Membros, função, documento e necessidades vinculadas.

### 3.8 Arquivos
Gestão de contrato, rider, rooming list, voucher, passagem e documentos auxiliares.

### 3.9 Importação
Carga em lote com preview e confirmação.

---

## 4. Recurso raiz e subrecursos oficiais

### Recurso raiz
`/api/artists`

### Subrecursos oficiais
- `/api/artists/bookings`
- `/api/artists/logistics`
- `/api/artists/logistics-items`
- `/api/artists/timelines`
- `/api/artists/transfers`
- `/api/artists/alerts`
- `/api/artists/team`
- `/api/artists/files`
- `/api/artists/imports`

---

## 5. Entidades de produto

- artista
- booking do artista no evento
- logística do artista
- item de logística
- timeline operacional
- estimativa de transfer
- alerta operacional
- membro da equipe
- arquivo do artista
- lote de importação

---

## 6. Regras funcionais fechadas

- `event_id` obrigatório em operações contextuais do evento
- alertas são calculados pelo backend
- timeline é operacional, não financeira
- custos logísticos podem originar lançamento no financeiro, mas não são pagos aqui
- cartões não ganham motor novo nem tabelas paralelas
- cartões existentes do sistema são apenas vinculados ao contexto do artista/equipe

---

## 7. O que este módulo não faz

- contas a pagar
- pagamentos
- orçamento
- centros de custo
- fornecedores como domínio principal

---

## 8. Telas mínimas esperadas

- lista de artistas por evento
- detalhe do artista com abas
- timeline operacional
- central de alertas
- equipe
- arquivos
- importação

Todas em React `.jsx`, aderentes ao padrão visual já usado no frontend atual.
