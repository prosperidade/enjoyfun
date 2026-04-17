# PROMPT DIRECIONADOR PARA AGENTES — EnjoyFun v1.0
## UI Hub Changes + Auditoria + Arquitetura Multi-Evento

**Data:** 14/04/2026  
**Escopo:** Todas as mudanças de UI por hub, correções de auditoria, e nova arquitetura de configuração de eventos para todos os tipos e tamanhos.  
**Regra geral:** Todo código novo deve ser ADITIVO, FEATURE-FLAGGED, FIRE-AND-FORGET e FALLBACK-FIRST.

---

# PARTE 1 — MUDANÇAS DE UI POR HUB

---

## 1. DASHBOARD (Dashboard.jsx)

### 1.1 Assistente de IA — Reposicionamento
- **MOVER** o chat de IA para o TOPO do dashboard, logo abaixo da seção "Resumo Geral"
- Remover qualquer instância duplicada do chat de IA no rodapé
- O assistente de IA no topo deve ser o padrão em TODAS as páginas do sistema (regra global)

### 1.2 Card "Vendas no Evento"
- Ao CLICAR no card, redirecionar para uma sub-view com 4 abas/cards internos:
  - **PDV** (vendas do ponto de venda geral)
  - **Bar** (vendas específicas dos bares)
  - **Alimentação** (vendas de alimentação)
  - **Loja** (vendas das lojas)
- Cada aba deve mostrar os indicadores de venda daquele setor
- O link deve usar `buildScopedPath()` com `event_id`

### 1.3 Card "Saldo Disponível"
- Renomear para **"Saldo Disponível por Evento"**
- O valor exibido deve ser o saldo disponível DAQUELE evento selecionado no filtro global
- Não mais saldo agregado/geral

### 1.4 Cards de Estoque Crítico (são 2)
- **Card superior:** Panorama GERAL de estoque crítico (todos os setores)
- **Card inferior:** Estoque dos BARES distribuídos pelo evento/festival
  - Deve listar cada bar individualmente (ex: Bar Palco 1, Bar Área VIP, Bar Entrada)
  - A configuração dos bares vem do PDV (configurado pelo organizador)
  - Suportar múltiplos bares (festivais geralmente têm 5+ bares)

### 1.5 Card "Pagamentos Estimados por Setor"
- **ACRESCENTAR** a linha "Operacional" com o valor total da equipe operacional
- **ACRESCENTAR** rodapé com **TOTAL** = soma de todos os setores (incluindo operacional)
- Setores: Bar + Alimentação + Loja + Artistas + Operacional = TOTAL

### 1.6 Card "Pagamento Estimado por Cargo" → SUBSTITUIR
- **DELETAR** o card "Pagamento Estimado por Cargo"
- **SUBSTITUIR** por card **"Lideranças do Evento"**
  - Listar todos os cargos de liderança: Gerente, Supervisor, Coordenador
  - Mostrar nome + cargo + valor/custo de cada líder
  - **Rodapé:** TOTAL com soma dos valores de todas as lideranças

### 1.7 Card "Membros Operacionais (primeiros 20)"
- **DELETAR** completamente

### 1.8 Card "Orçamento Total"
- Ao CLICAR → redirecionar para o **Painel Financeiro** (EventFinanceDashboard)
- Usar `buildScopedPath('/finance', eventId)`

### 1.9 Card "Comprometido" → Renomear
- Renomear para **"Contas a Pagar"**
- Ao CLICAR → redirecionar para a tela de **Contas a Pagar** do Painel Financeiro
- Usar `buildScopedPath('/finance/payables', eventId)`

### 1.10 Card "Pago" → Renomear
- Renomear para **"Contas Pagas"**
- Ao CLICAR → redirecionar para **Contas Pagas** no Painel Financeiro (filtro status=paid)
- Usar `buildScopedPath('/finance/payables?status=paid', eventId)`

### 1.11 Card "Contas Vencidas"
- Ao CLICAR → redirecionar para **tabela de contas vencidas** (filtro status=overdue)
- Usar `buildScopedPath('/finance/payables?status=overdue', eventId)`

### 1.12 Bugs da Auditoria a Corrigir (Dashboard)
- **D1 (BUG):** `FinancialHealthConnector` usa `summary?.overage` mas backend não retorna. Corrigir backend para retornar campo `overage` OU remover referência no frontend. "Estouro" sempre mostra R$ 0,00.
- **D2 (BUG):** `cars_inside` e `users_total` sem fallback — adicionar `?? 0` ou `|| 0`
- **D3 (UX):** `StatCard` renderiza `<Link to="#">` sem destino — remover `cursor: pointer` quando `to="#"` ou sem destino configurado
- **D4 (UX):** `RevenueBySectorPanel` mostra tudo R$ 0,00 com seed data — exibir mensagem "Sem dados de vendas neste evento" quando todos os valores forem zero

---

## 2. SUPER ADMIN (SuperAdmin.jsx)

### 2.1 Cadastro de Organizadores — Modelo Revisado
O Super Admin NÃO cadastra organizadores manualmente. Organizadores se cadastram na plataforma de forma individual. O Super Admin VISUALIZA e GERENCIA os organizadores cadastrados.

### 2.2 Informações que o Super Admin deve VER por organizador:
- Dados cadastrais: nome, telefone, CPF ou CNPJ, e-mail pessoal, e-mail da organização
- Evento(s) do organizador
- Público estimado por evento
- Plataforma de pagamento escolhida
- Plano escolhido (3 planos disponíveis)
- Porcentagem da plataforma (definida automaticamente por plano)

### 2.3 Cards do Super Admin:
- **Organizadores Cadastrados** (total)
- **Organizadores Ativos** (com eventos ativos)
- **Organizadores Inativos** (sem eventos ou suspensos)
- **Vendas Brutas Totais** (soma de todos os organizadores)
- **Comissão da Plataforma** (1% configurada automaticamente)

### 2.4 Área de APIs (venda de acesso)
- Card de **Vendas de API Consumadas**
- **Espaços de Trabalho** por organizador
- **Usuários por Organizador**
- **Custos de Tokens por Usuário** (separado por organizer)
- **API Escolhida** pelo organizador
- **Pagamentos** (sempre ANTES de usar — modelo pré-pago)
- **Avisos de Alto Consumo** (alertas automáticos)
- **Cartão de Crédito** do organizador atrelado à API da plataforma

### 2.5 Saúde do Sistema
- Alertas de manutenção
- Avisos de bugs na plataforma
- **Agente especializado** em varrer o sistema e trazer auditorias com classificação:
  - 🔴 Urgente
  - 🟡 Crítico
  - 🟢 Saudável

### 2.6 Financeiro do Admin
- Faturamento total
- Contas a pagar
- Custo de tokens
- Banco de dados (custos)
- Custos fixos
- Custos variáveis
- **Financeiro organizado** com categorias:
  - Contas a Pagar
  - Contas Pagas
  - Contas Vencidas

---

## 3. SIDEBAR (Sidebar.jsx)

### 3.1 Mudanças de nomenclatura:
| Item Atual | Novo Nome | Ícone |
|---|---|---|
| Scanner | **Scanner** (mantém, mas inclui sub-itens) | `ScanLine` |
| Artistas | **Atrações / Logística** | `Music` |

### 3.2 Scanner — Sub-itens inclusos:
- Scanner de Ingressos (portaria)
- Scanner de Estacionamento
- Scanner de Refeições
- (Setores dinâmicos do Workforce continuam funcionando)

---

## 4. PDV — BAR, ALIMENTAÇÃO, LOJA (POS.jsx)

### 4.1 Novo input: Custo do Produto
- Adicionar campo **"Custo do Produto"** em cada item do PDV (bar, alimentação, loja)
- Campo `cost_price` no StockForm
- Validar: `cost_price > 0` e `cost_price < price`

### 4.2 Assistente de IA — Reposicionamento
- Existem 2 inputs de assistente de IA — REMOVER o primeiro (superior)
- MOVER o chat que está no rodapé para o TOPO, logo abaixo dos indicadores do bar/loja/alimentação
- Padrão global: IA sempre no topo, abaixo dos indicadores

### 4.3 Card de Custos (NOVO)
- Criar card específico **"Custos"** no PDV contendo:
  - Custos dos produtos (calculado pelo `cost_price` de cada item)
  - Custos por categoria
  - Custos de equipes (alimentados pelo Workforce):
    - Membros operacionais
    - Estoquistas
    - Gerentes
    - Supervisores/Coordenadores
  - Total geral de custos

### 4.4 Bugs da Auditoria a Corrigir (POS)
- **P1 (BUG):** Cart quantity não remove item ao decrementar para 0 — corrigir para remover do carrinho quando qty chega a 0
- **P2 (BUG):** `HMAC_KEY_MISSING` não pede re-login — implementar redirect para `/login` com toast explicativo
- **P3 (MEDIUM):** Tolerância de checkout `R$ 0,01` muito apertada para floating point — aumentar para `R$ 0,05` ou usar `Math.round(value * 100) / 100`
- **P4 (MEDIUM):** Sem paginação em products — implementar paginação com 50 items/página ou virtualização (react-window)
- **P5 (MEDIUM):** Cart não persiste — implementar `localStorage` com TTL de 4h para o carrinho
- **P6 (MINOR):** StockForm aceita `price <= 0` e `threshold` negativo — validar no frontend e backend
- **P7 (MINOR):** Sem debounce no input de card reference — adicionar 300ms debounce

---

## 5. TICKETS / INGRESSOS (Tickets.jsx)

### 5.1 Filtro de Setores (NOVO)
- Adicionar filtros por SETOR no topo da página:
  - Todos | Premium | Frontstage | Backstage | Lounges | VIP | Pista | [Setores customizados]
- Os setores devem ser criados pelo organizador no modal de ingressos

### 5.2 Modal de Ingressos — Definição de Setores (NOVO)
- No modal de criação/edição de tipo de ingresso, adicionar:
  - Campo **"Setor"** (select ou criação dinâmica)
  - Botão **"+ Criar Setor"** para novos setores
  - Exemplos pré-definidos: Premium (cadeiras/mesas), Frontstage, Backstage, Lounges, Pista, Camarote

### 5.3 Tabelas por Setor
- Ao selecionar um setor no filtro, exibir tabela específica com vendas daquele setor

### 5.4 Assistente de IA
- Deve estar no TOPO da página, abaixo dos filtros (padrão global)

### 5.5 Bugs da Auditoria a Corrigir (Tickets)
- **T1 (CRITICAL):** `totp_secret` NÃO é retornado no `GET /tickets` → modal QR é estático, derrotando anti-print TOTP. **CORRIGIR URGENTE:** Backend deve retornar `totp_secret` para QR dinâmico, ou implementar TOTP real via endpoint dedicado
- **T2 (MEDIUM):** Transfer button aparece para qualquer ticket `paid`, sem checar `ownership (user_id)` — adicionar verificação de propriedade
- **T3 (UX):** Transfer usa `prompt()` do browser — substituir por modal styled
- **T4 (MINOR):** Cache offline (localStorage) sem TTL — implementar TTL de 2h

---

## 6. ESTACIONAMENTO (Parking.jsx)

### 6.1 Bugs da Auditoria a Corrigir
- **K1 (BUG):** `entry_at` é NULL no registro manual → `new Date(null)` crash/"Invalid Date" — adicionar fallback `entry_at || new Date().toISOString()`
- **K2 (BUG):** Vehicle type mapeia apenas `car→CARRO`, todo o resto→`MOTO` (truck e bus mostram "MOTO") — adicionar mapeamento completo: `truck→CAMINHÃO`, `bus→ÔNIBUS`, `van→VAN`, `motorcycle→MOTO`
- **K3 (MEDIUM):** Scanner entry assume `status='parked'` offline, mas backend retorna `'pending'` — alinhar status offline/online
- **K4 (MEDIUM):** Validation result usa `current_status` online mas `status` offline — unificar fonte
- **K5 (UX):** Sem capacidade/limite — implementar warning quando estacionamento lotou (capacidade no form do evento)
- **K6 (UX):** Sem cálculo de duração ou fee tracking — implementar cálculo de permanência e valor

---

## 7. PARTICIPANTS HUB / WORKFORCE (ParticipantsHub.jsx, WorkforceOpsTab.jsx, MealsControl.jsx)

### 7.1 Bugs da Auditoria a Corrigir
- **W1 (BUG):** `normalizeCostBucket` no frontend override `cost_bucket` do backend por heurística de nome — remover override do frontend, usar sempre valor do backend
- **W2 (BUG / SECURITY):** Assignment JOIN no ParticipantController não filtra por `event_id` → possível data leak cross-event — **ADICIONAR filtro obrigatório por event_id em TODOS os JOINs**
- **W3 (MEDIUM):** `meal_unit_cost` vem de 7 fontes com prioridade diferente — consolidar para 1 fonte autoritativa (event config > workforce config > fallback)
- **W4 (MEDIUM):** Device time errado → refeição registrada no dia errado — backend deve validar `consumed_at` vs `event_day_id`
- **W5 (MEDIUM):** Sector normalization case-sensitive — normalizar sempre com `.toLowerCase().trim()`

---

## 8. ARTISTAS → ATRAÇÕES/LOGÍSTICA (ArtistsCatalog.jsx, ArtistDetail.jsx)

### 8.1 Renomear no Sidebar
- "Artistas" → **"Atrações / Logística"**

### 8.2 Bugs da Auditoria a Corrigir
- **A1 (BUG):** Computed windows mostra "undefined min" quando timeline não retorna `computed_windows` — adicionar check `computed_windows?.length > 0`
- **A2 (MEDIUM):** Create artist + booking não é atômico — se booking falha, artist fica órfão. Usar transação única no backend
- **A3 (MEDIUM):** Logistics items loop não é atômico — mid-failure deixa items órfãos. Envolver em transação
- **A4 (MEDIUM):** CSV import aceita delimitadores inconsistentes sem erro — validar delimitador e retornar erro claro

---

## 9. AGENTES DE IA (AI.jsx)

### 9.1 Card de Custos de API (NOVO)
- Criar card dedicado para custos de API contendo:
  - Tempo de uso por sessão
  - Custo acumulado
  - Tokens consumidos (total)
  - **Separação por tipo de usuário:**
    - Tokens do usuário comum (público do evento)
    - Tokens do organizador (dentro da plataforma)
  - Custo por modelo utilizado

---

## 10. ORGANIZER FILES (OrganizerFiles.jsx)

### 10.1 Bugs da Auditoria a Corrigir
- **F1 (HIGH):** Search UI NÃO EXISTE — backend tem `/organizer-files/search` mas frontend nunca chama. **IMPLEMENTAR UI de busca** com input + resultados
- **F2 (MEDIUM):** `parsed_error` nunca exibido — exibir toast/banner quando parsing falhou
- **F3 (MEDIUM):** Search endpoint não filtra por `event_id` — adicionar filtro obrigatório

---

## 11. SETTINGS (Configurações)

### 11.1 Bugs da Auditoria a Corrigir
- **S1 (SECURITY):** Backend aceita placeholder `"(Configurada)"` como API key real se frontend bypass — validar no backend que a key tem formato real
- **S2 (BUG):** Default model Claude é `claude-3-5-sonnet-latest` (inválido). **CORRIGIR para `claude-sonnet-4-6`**
- **S3 (MEDIUM):** Logo URL usa `$_SERVER['HTTP_HOST']` — spoofável. Usar variável de ambiente `APP_URL`
- **S4 (MEDIUM):** SVG aceito como logo — risco XSS. Servir com header `Content-Type: image/svg+xml` e `X-Content-Type-Options: nosniff`
- **S5 (MEDIUM):** Branding e Messaging sem audit log — adicionar trilha de auditoria
- **S6 (MEDIUM):** Admin sem `organizer_id` usa `user id` como `organizer_id` — corrigir isolamento

---

## 12. REGRA GLOBAL — ASSISTENTE DE IA EM TODAS AS PÁGINAS

**PADRÃO OBRIGATÓRIO:** Em TODAS as páginas do sistema, o assistente de IA deve estar posicionado no TOPO da página, logo abaixo dos filtros/indicadores principais. Nunca no rodapé. Nunca duplicado.

Páginas afetadas:
- Dashboard → abaixo do Resumo Geral
- Tickets → abaixo dos filtros de setor
- PDV (Bar/Food/Shop) → abaixo dos indicadores
- Artistas/Logística → abaixo dos filtros
- Financeiro → abaixo dos indicadores
- Participants Hub → abaixo dos filtros
- Todas as demais páginas

---

# PARTE 2 — ARQUITETURA DE CONFIGURAÇÃO MULTI-EVENTO

---

## CONCEITO CENTRAL

A EnjoyFun deve ser uma plataforma COMPLETA para TODOS os tipos de eventos. O organizador, ao criar um evento, escolhe o TIPO de evento em cards visuais. Cada tipo de evento ativa um formulário de configuração ESPECÍFICO com os campos e módulos necessários para aquele tipo.

---

## 13. TELA DE EVENTOS (Events.jsx) — REESTRUTURAÇÃO

### 13.1 Fluxo de Criação: Seleção de Tipo por Cards

Ao clicar em "Novo Evento", o primeiro passo é uma tela de seleção com CARDS visuais:

```
┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌──────────────┐
│  🎵 Festival  │ │  🎤 Show     │ │  💼 Corporat. │ │  🎓 Formatura │
│  de Música   │ │  Avulso      │ │              │ │              │
└──────────────┘ └──────────────┘ └──────────────┘ └──────────────┘
┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌──────────────┐
│  💒 Casamento │ │  🏟️ Esportivo │ │  🎪 Feira /   │ │  🎓 Congresso │
│              │ │              │ │  Exposição   │ │  / Palestra  │
└──────────────┘ └──────────────┘ └──────────────┘ └──────────────┘
┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌──────────────┐
│  🎭 Teatro   │ │  🏀 Ginásio   │ │  🏆 Rodeio   │ │  ⚡ Evento    │
│              │ │              │ │              │ │  Customizado │
└──────────────┘ └──────────────┘ └──────────────┘ └──────────────┘
```

Ao selecionar o tipo, o formulário se adapta com os campos específicos daquele tipo.

### 13.2 Campos COMUNS a todos os tipos de evento:
- Nome do Evento
- Descrição
- Data de início / Data de término
- Local (nome do venue)
- Endereço completo
- Cidade / Estado / País
- **Coordenadas GPS** (latitude/longitude) — para geolocalização no app
- Capacidade total estimada
- Status (rascunho/publicado)
- Logo/Banner do evento
- Público estimado
- Classificação etária

### 13.3 Campos de LOCALIZAÇÃO (novos para todos):
- Nome do local/venue
- Endereço completo com CEP
- Coordenadas GPS (auto-preenchidas por endereço ou input manual)
- Tipo de local: Indoor / Outdoor / Híbrido
- Mapa 3D (upload) — para interação do usuário no app e WhatsApp
- Mapa do local (upload de imagem ou PDF)

---

## 14. MODELOS DE CONFIGURAÇÃO POR TIPO DE EVENTO

---

### 14.1 🎵 FESTIVAL DE MÚSICA (multi-dia, multi-palco)

**Dores específicas:** Logística de múltiplos palcos, bares distribuídos, fluxo de público entre áreas, controle de equipes 24h, gestão de atrações com riders técnicos complexos, cashless em volume.

**Configurações específicas:**

#### Palcos
- Botão **"+ Criar Novo Palco"**
- Para cada palco: Nome, Capacidade, Tipo (principal/secundário/alternativo)
- Atrações vinculadas a cada palco (lineup por palco)
- Horário de cada atração por palco (grade horária)

#### PDV / Bares Distribuídos
- Configuração de MÚLTIPLOS bares por evento
- Para cada bar: Nome, Localização (palco associado), Cardápio específico
- Configuração de pontos de alimentação (nome, cardápio, preços)
- Configuração de lojas (nome, produtos, preços)
- Upload de CSV para produtos (bar, alimentação, lojas)

#### Estacionamento
- Preços por categoria (carro, moto, van, ônibus)
- Quantidade de vagas por categoria
- Mapa de estacionamento (upload)
- Vagas VIP reserváveis

#### Setores/Áreas
- Criação de setores: Pista, VIP, Camarote, Backstage, Frontstage, Lounges, Área Premium
- Capacidade por setor
- Tipo de ingresso vinculado ao setor

#### Hospedagem/Camping (para festivais multi-dia)
- Área de camping: localização, capacidade, preço
- Parcerias de hospedagem: hotéis, pousadas (links/informações)

#### Mapas
- Mapa 3D interativo (upload para o app)
- Mapa dos palcos com localização
- Mapa dos bares/alimentação
- Mapa geral do festival

---

### 14.2 🎤 SHOW AVULSO / CASA DE SHOW

**Dores específicas:** Velocidade de setup, controle de bilheteria com poucos lotes, operação enxuta, um único palco com timeline linear.

**Configurações específicas:**

#### Palco
- Palco único (nome, capacidade)
- Timeline de atrações (ordem linear)
- Abertura de portas (horário)

#### PDV Simplificado
- Bares: 1-3 (configuração rápida)
- Cardápio único para todos os bares
- Alimentação: opcional

#### Setores Simplificados
- Pista, Camarote/VIP (opcional), Área Premium (opcional)
- Mesas numeradas (se aplicável)

---

### 14.3 💼 EVENTO CORPORATIVO

**Dores específicas:** Controle rígido de convidados por empresa, credenciamento por empresa/departamento, salas simultâneas, agendas paralelas, networking controlado, catering por cota, relatórios de participação para RH.

**Configurações específicas:**

#### Credenciamento Corporativo
- Lista de empresas participantes
- Cotas por empresa (quantidade de participantes)
- Categorias: Palestrante, Patrocinador, Convidado, Funcionário, Imprensa
- Crachás com QR: nome, empresa, cargo, categoria
- Check-in por empresa com relatório de presença

#### Salas/Auditórios
- Botão **"+ Criar Sala"**
- Para cada sala: Nome, Capacidade, Tipo (auditório, sala de reunião, workshop)
- Agenda por sala (palestras, workshops com horários)
- Palestrantes por sessão

#### Agenda/Programação
- Timeline multi-track (agenda paralela em várias salas)
- Sessões com título, descrição, palestrante, horário, sala
- O participante pode favoritar sessões no app

#### Catering
- Configuração de coffee breaks (horários, menu)
- Almoço/jantar: menu por evento, opções dietéticas
- Controle de refeições por participante (QR no crachá)

#### Networking
- Rodadas de networking agendadas
- Matchmaking de participantes por interesse/setor
- Troca de contatos digital (via QR)

#### Patrocinadores/Expositores
- Cotas de patrocínio: Master, Gold, Silver, Bronze
- Espaço de exposição por patrocinador
- Logo em materiais (configurável)

#### Certificados
- Emissão automática de certificado de participação
- Validação por check-in real (não só por inscrição)

#### Mapa
- Mapa de auditórios e salas
- Mapa de stands de patrocinadores
- Mapa de áreas de catering/coffee

---

### 14.4 🎓 FORMATURA

**Dores específicas:** Múltiplas cerimônias (colação + festa), listas enormes de convidados por formando, mesa cativa, protocolo de cerimônia (fila indiana, entrega de diplomas), controle de convites limitados por formando.

**Configurações específicas:**

#### Cerimônia de Colação
- Lista de formandos (nome, curso, foto)
- Convites por formando (limite configurável, ex: 5 convites)
- Ordem da cerimônia (sequência de entrada)
- Paraninfo, homenageados, patrono
- Juramento e discursos (timeline)

#### Festa de Formatura
- Mapa de mesas (drag-and-drop)
- Mesas por formando com seus convidados
- Cardápio do jantar/evento
- Atrações musicais (banda, DJ)
- Configuração de open bar / bar limitado

#### Convites
- Convite digital com QR por convidado
- Vinculação: convidado → formando → mesa
- RSVP digital (confirmação/recusa)
- Controle de plus-one (acompanhante)

#### Financeiro por Formando
- Valor por formando (mensalidade ou valor único)
- Controle de pagamento individual
- Divisão de custos (rateio)

#### Seating Chart
- Mapa visual de mesas
- Drag-and-drop de convidados entre mesas
- Capacidade por mesa
- Impressão de mapa de mesas

---

### 14.5 💒 CASAMENTO

**Dores específicas:** Gestão de convidados com RSVP, conflitos familiares na disposição de mesas, múltiplos momentos (cerimônia + recepção), controle fino de dietas e restrições alimentares, fornecedores diversos.

**Configurações específicas:**

#### Lista de Convidados
- Convidados separados por lado (noivo/noiva)
- RSVP digital (confirmação + escolha de menu + restrições alimentares)
- Controle de acompanhantes/plus-one
- Status: Convidado / Confirmado / Recusou / Pendente
- Tags: Família, Amigo, Trabalho, VIP, Criança, PCD

#### Cerimônia
- Local da cerimônia (pode ser diferente da recepção)
- Layout da cerimônia (assentos, altar)
- Timeline: entrada, votos, benção, saída

#### Recepção
- Mapa de mesas (drag-and-drop)
- Disposição de mesas: redonda, retangular, imperial
- Convidados por mesa
- Menu por convidado (carne/peixe/vegetariano/infantil)
- Restrições alimentares por convidado

#### Fornecedores
- Cadastro de fornecedores: Buffet, Decoração, Fotógrafo, Vídeo, Banda/DJ, Florista, Bolo, Doces, Convites
- Contrato e pagamento por fornecedor
- Checklist por fornecedor

#### Momentos / Timeline do Dia
- Preparação → Cerimônia → Fotos → Recepção → Festa → Saída
- Horários de cada momento
- Responsável por momento

#### Presentes
- Lista de presentes (opcional — pode linkar com loja externa)

---

### 14.6 🏟️ EVENTO ESPORTIVO (Estádio)

**Dores específicas:** Capacidade massiva (10k-100k), múltiplos setores com preços diferentes, controle de acesso por zona, segurança reforçada, proibição de re-entry em certos setores, venda de alimentos/bebidas distribuída, áreas VIP/camarotes.

**Configurações específicas:**

#### Mapa do Estádio
- Upload do mapa de assentos (com setores numerados)
- Zonas: Arquibancada, Cadeira Inferior, Cadeira Superior, Camarote, VIP, Imprensa, Visitante
- Capacidade por zona
- Preço por zona

#### Controle de Acesso por Zona
- Ingresso vinculado a zona específica
- Catracas/gates por zona
- Regra de re-entry (permitido/proibido por zona)
- Credencial de imprensa separada

#### Temporada / Jogos
- Configuração de temporada (múltiplos jogos)
- Ingresso por jogo ou pacote de temporada (season ticket)
- Adversário por jogo
- Calendário de jogos

#### PDV Distribuído
- Pontos de venda por setor do estádio
- Cardápio por setor (pode ser diferente: camarote tem menu premium)
- Cashless integrado por zona

#### Estacionamento
- Vagas por setor (próximo ao gate de entrada do torcedor)
- Estacionamento VIP separado
- Controle de entrada/saída por placa

#### Segurança
- Checklist de segurança por gate
- Proibições (lista de itens proibidos)
- Protocolo de evacuação por setor

---

### 14.7 🎪 FEIRA DE EXPOSIÇÃO / TRADE SHOW

**Dores específicas:** Gestão de expositores (não público), venda de espaço de stand, montagem/desmontagem, agenda de palestras paralela, credenciamento por tipo (expositor, visitante, imprensa, VIP), networking B2B.

**Configurações específicas:**

#### Expositores
- Cadastro de empresas expositoras
- Para cada expositor: Nome da empresa, CNPJ, Contato, Stand designado
- Portal self-service do expositor (configurar perfil, produtos, equipe)
- Crachás por expositor (múltiplas credenciais por empresa)

#### Planta de Stands (Floor Plan)
- Editor visual de planta baixa
- Tipos de stand: Standard, Premium, Corner, Island
- Preço por m² ou por tipo de stand
- Status: Disponível / Reservado / Pago / Montado
- Mapa interativo (web + app)

#### Agenda de Palestras
- Auditórios/salas de palestra
- Agenda multi-track (palestras simultâneas)
- Palestrantes: bio, foto, empresa
- Inscrição por palestra (limite de vagas)

#### Credenciamento
- Categorias: Expositor, Visitante, Comprador, Imprensa, VIP, Organizador
- Crachá com QR diferenciado por categoria
- Controle de acesso por área (área de expositores, auditórios, VIP)

#### Networking / Lead Capture
- Troca de contatos via QR (lead scanning)
- Agendamento de reuniões entre expositor e visitante
- Matchmaking por setor/interesse

#### Montagem/Desmontagem
- Período de montagem (datas/horários)
- Período de desmontagem
- Checklist por expositor
- Controle de entrada de carga (dock management)

#### Patrocínio
- Cotas de patrocínio com benefícios configuráveis
- Espaços de mídia: banners, totens, naming rights
- Relatório de exposição de marca

---

### 14.8 🎓 CONGRESSO / CONFERÊNCIA ACADÊMICA

**Dores específicas:** Call for papers, revisão por pares, emissão de certificados com horas, múltiplas trilhas temáticas, submissão de trabalhos, keynote speakers internacionais, tradução simultânea.

**Configurações específicas:**

#### Call for Papers / Submissão
- Formulário de submissão de trabalhos (título, resumo, autores, arquivo)
- Categorias/trilhas temáticas
- Prazos de submissão
- Processo de revisão (aprovado/rejeitado/revisão)
- Notificação automática aos autores

#### Programação Científica
- Trilhas temáticas paralelas
- Sessões: Keynote, Mesa Redonda, Apresentação Oral, Pôster, Workshop
- Palestrantes/Moderadores por sessão
- Horários por sala

#### Certificados
- Certificado de participação (com carga horária)
- Certificado de apresentação de trabalho
- Certificado por workshop (horas diferenciadas)
- Validação por check-in real nas sessões

#### Auditórios / Salas
- Configuração de múltiplos auditórios
- Capacidade por auditório
- Equipamentos: projetor, tradução simultânea, gravação

#### Tradução Simultânea
- Idiomas disponíveis por sessão
- Canais de tradução (distribuição de receptores)

#### Pôsteres
- Espaço de pôsteres: localização, horário de apresentação
- Upload de pôster digital (PDF/imagem)
- Votação de melhor pôster (opcional)

#### Credenciamento Acadêmico
- Categorias: Participante, Palestrante, Autor, Moderador, Comitê Organizador, Estudante
- Desconto para estudante (verificação)
- Controle de presença por sessão

---

### 14.9 🎭 TEATRO / ESPETÁCULO EM AUDITÓRIO

**Dores específicas:** Mapa de assentos numerados, vendas por sessão (múltiplas apresentações do mesmo espetáculo), controle de lotação por sessão, meia-entrada.

**Configurações específicas:**

#### Mapa de Assentos
- Upload ou configuração do mapa de assentos do teatro/auditório
- Setores: Plateia, Mezanino, Balcão, Frisa, Camarote
- Numeração de poltronas
- Preço por setor/poltrona
- Assentos PCD reservados

#### Sessões / Apresentações
- Múltiplas sessões do mesmo espetáculo
- Calendário de sessões (datas e horários)
- Capacidade por sessão
- Status por sessão: Disponível / Esgotado / Cancelado

#### Elenco / Ficha Técnica
- Diretor, Elenco, Produção
- Sinopse do espetáculo
- Duração / Classificação etária
- Intervalo (sim/não, duração)

#### Meia-Entrada
- Configuração de categorias de meia-entrada
- Verificação no check-in (documento)

---

### 14.10 🏀 EVENTO EM GINÁSIO (Esportes Indoor, Shows)

**Dores específicas:** Similar ao estádio mas em escala menor (5k-20k), configuração flexível (quadra pode virar palco), setores adaptáveis.

**Configurações específicas:**

#### Layout Flexível
- Pré-sets: Modo Quadra (esporte) / Modo Palco (show) / Modo Arena (luta)
- Assentos numerados ou área livre (por setor)
- Floor seats / Cadeiras removíveis

#### Setores
- Anel inferior, Anel superior, VIP, Floor, Backstage
- Preço por setor

#### Esporte
- Equipes/atletas participantes
- Calendário de jogos/lutas
- Placar ao vivo (integração futura)

---

### 14.11 🏆 RODEIO / EXPOSIÇÃO AGROPECUÁRIA

**Dores específicas:** Evento multi-dia com agenda diversa (rodeio + shows + feira + gastronomia), múltiplos espaços, grande área aberta, estacionamento massivo, pecuária.

**Configurações específicas:**

#### Arena de Rodeio
- Programação de provas
- Competidores (peão, cavalo/boi)
- Placar/classificação

#### Parque de Exposições
- Pavilhões de exposição agropecuária
- Stands de vendas (máquinas agrícolas, etc.)
- Praça de alimentação

#### Shows Noturnos
- Palco de shows (reutiliza modelo festival)
- Lineup por noite

#### Leilão
- Configuração de leilão (lotes, lance mínimo)
- Controle de arrematantes

---

### 14.12 ⚡ EVENTO CUSTOMIZADO

Para tipos que não se encaixam nos anteriores. O organizador monta o evento com módulos à la carte:

- [ ] Palcos
- [ ] Mapa de assentos
- [ ] PDV / Bares
- [ ] Estacionamento
- [ ] Credenciamento
- [ ] Agenda de palestras
- [ ] Expositores/Stands
- [ ] Mapa de mesas
- [ ] Certificados
- [ ] RSVP
- [ ] Catering
- [ ] Hospedagem/Camping
- [ ] Cashless
- [ ] Scanner multi-setor
- [ ] Networking
- [ ] Leilão

---

## 15. COMPONENTES DE UI TRANSVERSAIS (NOVOS)

### 15.1 MapBuilder Component
Componente reutilizável para criação de mapas visuais:
- Drag-and-drop de elementos (mesas, palcos, stands, assentos)
- Zoom e pan
- Grid com snap
- Exportação para imagem
- Usado em: Casamento (mesas), Feira (stands), Teatro (assentos), Festival (palcos)

### 15.2 AgendaBuilder Component
Componente de agenda multi-track:
- Timeline visual
- Sessões/atrações arrastáveis
- Múltiplas tracks/salas/palcos simultâneas
- Conflito de horário (alerta)
- Usado em: Congresso, Corporativo, Festival, Feira

### 15.3 GuestManager Component
Componente de gestão de convidados:
- Import CSV
- RSVP tracking
- Tags e categorias
- Filtros avançados
- Controle de plus-one
- Exportação
- Usado em: Casamento, Formatura, Corporativo

### 15.4 SeatingChart Component
Componente de mapa de assentos:
- Tipos de mesa: redonda, retangular, imperial
- Numeração de assentos
- Drag-and-drop de convidados
- Capacidade por mesa
- Preferências alimentares por assento
- Usado em: Casamento, Formatura, Teatro, Corporativo

### 15.5 CredentialBuilder Component
Componente de credenciamento:
- Templates de crachá por categoria
- QR code individual
- Foto do credenciado
- Dados variáveis: nome, empresa, cargo, categoria
- Impressão em lote
- Usado em: Corporativo, Feira, Congresso, Festival

---

## 16. MODELO DE DADOS — NOVOS CAMPOS PARA EVENT

### 16.1 Tabela `events` — Campos novos:

```sql
ALTER TABLE events ADD COLUMN IF NOT EXISTS event_type VARCHAR(50) DEFAULT 'festival';
-- Tipos: festival, show, corporate, graduation, wedding, sports_stadium, 
--        sports_gym, trade_show, congress, theater, rodeo, custom

ALTER TABLE events ADD COLUMN IF NOT EXISTS event_subtype VARCHAR(50);
-- Subtipos livres para refinamento

ALTER TABLE events ADD COLUMN IF NOT EXISTS latitude DECIMAL(10, 8);
ALTER TABLE events ADD COLUMN IF NOT EXISTS longitude DECIMAL(11, 8);
ALTER TABLE events ADD COLUMN IF NOT EXISTS city VARCHAR(100);
ALTER TABLE events ADD COLUMN IF NOT EXISTS state VARCHAR(50);
ALTER TABLE events ADD COLUMN IF NOT EXISTS country VARCHAR(50) DEFAULT 'BR';
ALTER TABLE events ADD COLUMN IF NOT EXISTS zip_code VARCHAR(20);
ALTER TABLE events ADD COLUMN IF NOT EXISTS venue_type VARCHAR(20) DEFAULT 'outdoor';
-- indoor / outdoor / hybrid

ALTER TABLE events ADD COLUMN IF NOT EXISTS age_rating VARCHAR(20);
ALTER TABLE events ADD COLUMN IF NOT EXISTS banner_url TEXT;
ALTER TABLE events ADD COLUMN IF NOT EXISTS map_3d_url TEXT;
ALTER TABLE events ADD COLUMN IF NOT EXISTS map_image_url TEXT;
ALTER TABLE events ADD COLUMN IF NOT EXISTS modules_config JSONB DEFAULT '{}';
-- Armazena quais módulos estão ativados para este evento
-- Ex: {"stages": true, "seating_map": true, "pdv": true, "parking": true}
```

### 16.2 Novas tabelas necessárias:

```sql
-- Palcos/Salas por evento
CREATE TABLE IF NOT EXISTS event_stages (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id UUID NOT NULL REFERENCES events(id),
  name VARCHAR(200) NOT NULL,
  stage_type VARCHAR(50), -- main, secondary, alternative, auditorium, room
  capacity INTEGER,
  location_description TEXT,
  sort_order INTEGER DEFAULT 0,
  created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Setores por evento
CREATE TABLE IF NOT EXISTS event_sectors (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id UUID NOT NULL REFERENCES events(id),
  name VARCHAR(200) NOT NULL,
  sector_type VARCHAR(50), -- pista, vip, camarote, backstage, frontstage, lounge, premium
  capacity INTEGER,
  price_modifier DECIMAL(10,2) DEFAULT 0,
  created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Configuração de estacionamento
CREATE TABLE IF NOT EXISTS event_parking_config (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id UUID NOT NULL REFERENCES events(id),
  vehicle_type VARCHAR(20) NOT NULL, -- car, motorcycle, van, bus
  price DECIMAL(10,2) NOT NULL,
  total_spots INTEGER NOT NULL,
  vip_spots INTEGER DEFAULT 0,
  map_url TEXT,
  created_at TIMESTAMPTZ DEFAULT NOW()
);

-- PDV Points por evento (bares/lojas distribuídos)
CREATE TABLE IF NOT EXISTS event_pdv_points (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id UUID NOT NULL REFERENCES events(id),
  name VARCHAR(200) NOT NULL,
  pdv_type VARCHAR(20) NOT NULL, -- bar, food, shop
  stage_id UUID REFERENCES event_stages(id), -- palco associado
  location_description TEXT,
  created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Expositores (feiras)
CREATE TABLE IF NOT EXISTS event_exhibitors (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id UUID NOT NULL REFERENCES events(id),
  company_name VARCHAR(300) NOT NULL,
  cnpj VARCHAR(20),
  contact_name VARCHAR(200),
  contact_email VARCHAR(200),
  contact_phone VARCHAR(30),
  stand_number VARCHAR(50),
  stand_type VARCHAR(50), -- standard, premium, corner, island
  stand_size_m2 DECIMAL(8,2),
  status VARCHAR(20) DEFAULT 'pending', -- pending, confirmed, paid, cancelled
  created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Agenda/Sessões (congressos, corporativos)
CREATE TABLE IF NOT EXISTS event_sessions (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id UUID NOT NULL REFERENCES events(id),
  stage_id UUID REFERENCES event_stages(id),
  title VARCHAR(500) NOT NULL,
  description TEXT,
  session_type VARCHAR(50), -- keynote, panel, workshop, poster, oral
  speaker_name VARCHAR(200),
  speaker_bio TEXT,
  starts_at TIMESTAMPTZ NOT NULL,
  ends_at TIMESTAMPTZ NOT NULL,
  max_capacity INTEGER,
  requires_registration BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Convidados com RSVP (casamentos, formaturas)
CREATE TABLE IF NOT EXISTS event_guests (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id UUID NOT NULL REFERENCES events(id),
  name VARCHAR(200) NOT NULL,
  email VARCHAR(200),
  phone VARCHAR(30),
  guest_side VARCHAR(20), -- bride, groom, student, company
  rsvp_status VARCHAR(20) DEFAULT 'pending', -- pending, confirmed, declined
  meal_choice VARCHAR(50), -- meat, fish, vegetarian, vegan, kids
  dietary_restrictions TEXT,
  table_id UUID, -- referência para a mesa
  seat_number INTEGER,
  plus_one_name VARCHAR(200),
  tags TEXT[], -- ['family', 'vip', 'child', 'pcd']
  invited_by VARCHAR(200), -- nome do formando/noivo(a)
  created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Mesas (casamentos, formaturas, corporativos)
CREATE TABLE IF NOT EXISTS event_tables (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id UUID NOT NULL REFERENCES events(id),
  table_number INTEGER NOT NULL,
  table_name VARCHAR(100),
  table_type VARCHAR(20) DEFAULT 'round', -- round, rectangular, imperial
  capacity INTEGER NOT NULL,
  position_x DECIMAL(8,2), -- posição no mapa
  position_y DECIMAL(8,2),
  section VARCHAR(100), -- setor onde a mesa está
  created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Certificados (congressos, corporativos)
CREATE TABLE IF NOT EXISTS event_certificates (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  event_id UUID NOT NULL REFERENCES events(id),
  participant_name VARCHAR(200) NOT NULL,
  participant_email VARCHAR(200),
  certificate_type VARCHAR(50), -- participation, presentation, workshop
  hours INTEGER,
  session_id UUID REFERENCES event_sessions(id),
  issued_at TIMESTAMPTZ DEFAULT NOW(),
  validation_code VARCHAR(50) UNIQUE
);
```

---

## 17. ENDPOINTS DE API NECESSÁRIOS (NOVOS)

```
# Palcos/Salas
GET    /events/:id/stages
POST   /events/:id/stages
PUT    /events/:id/stages/:stageId
DELETE /events/:id/stages/:stageId

# Setores
GET    /events/:id/sectors
POST   /events/:id/sectors
PUT    /events/:id/sectors/:sectorId
DELETE /events/:id/sectors/:sectorId

# Estacionamento Config
GET    /events/:id/parking-config
POST   /events/:id/parking-config
PUT    /events/:id/parking-config/:configId

# PDV Points
GET    /events/:id/pdv-points
POST   /events/:id/pdv-points
PUT    /events/:id/pdv-points/:pointId
DELETE /events/:id/pdv-points/:pointId

# Expositores
GET    /events/:id/exhibitors
POST   /events/:id/exhibitors
PUT    /events/:id/exhibitors/:exhibitorId
DELETE /events/:id/exhibitors/:exhibitorId

# Sessões/Agenda
GET    /events/:id/sessions
POST   /events/:id/sessions
PUT    /events/:id/sessions/:sessionId
DELETE /events/:id/sessions/:sessionId

# Convidados
GET    /events/:id/guests
POST   /events/:id/guests
PUT    /events/:id/guests/:guestId
DELETE /events/:id/guests/:guestId
POST   /events/:id/guests/import-csv
POST   /events/:id/guests/:guestId/rsvp

# Mesas
GET    /events/:id/tables
POST   /events/:id/tables
PUT    /events/:id/tables/:tableId
DELETE /events/:id/tables/:tableId
POST   /events/:id/tables/:tableId/assign-guest

# Certificados
GET    /events/:id/certificates
POST   /events/:id/certificates/generate
GET    /certificates/validate/:code
```

---

# PARTE 3 — PRIORIZAÇÃO DE EXECUÇÃO

---

## SPRINT 1 — CRÍTICO (Semana 1-2)
1. T1: Corrigir TOTP estático em tickets (CRITICAL)
2. W2: Corrigir data leak cross-event em ParticipantController (SECURITY)
3. S1: Corrigir placeholder credentials (SECURITY)
4. S2: Corrigir modelo Claude para `claude-sonnet-4-6` (BUG)
5. Regra global: IA no topo de todas as páginas

## SPRINT 2 — BUGS E NOMENCLATURA (Semana 2-3)
6. D1-D4: Bugs do Dashboard
7. P1-P2: Bugs do POS (cart e HMAC)
8. K1-K2: Bugs do Parking
9. W1: Bug do normalizeCostBucket
10. A1: Bug computed windows
11. Renomear sidebar: Scanner sub-itens, Artistas → Atrações/Logística

## SPRINT 3 — UI DASHBOARD (Semana 3-4)
12. Cards do Dashboard (vendas, saldo, estoque, pagamentos, lideranças)
13. Links dos cards com buildScopedPath
14. Deletar card Membros Operacionais

## SPRINT 4 — TICKETS E PDV (Semana 4-5)
15. Modal de setores em Tickets
16. Filtros de setor em Tickets
17. Input de custo do produto no PDV
18. Card de custos no PDV
19. P3-P7: Medium/Minor do POS

## SPRINT 5 — SUPER ADMIN (Semana 5-6)
20. Reestruturação do Super Admin completa
21. Cards de organizadores, vendas, comissão
22. Área de APIs
23. Saúde do sistema
24. Financeiro do admin

## SPRINT 6 — ARQUITETURA MULTI-EVENTO (Semana 6-8)
25. Cards de seleção de tipo de evento
26. Formulário base com campos comuns
27. Model/Migration: event_type, coordenadas, modules_config
28. Tabelas: event_stages, event_sectors, event_parking_config, event_pdv_points
29. Endpoints de stages, sectors, parking-config, pdv-points

## SPRINT 7 — TIPOS ESPECÍFICOS 1 (Semana 8-10)
30. Formulário Festival de Música (palcos, bares, setores)
31. Formulário Corporativo (credenciamento, salas, agenda)
32. Formulário Feira/Exposição (expositores, stands, floor plan)
33. Tabelas: event_exhibitors, event_sessions
34. Endpoints: exhibitors, sessions

## SPRINT 8 — TIPOS ESPECÍFICOS 2 (Semana 10-12)
35. Formulário Casamento (convidados, RSVP, mesas)
36. Formulário Formatura (formandos, convites, mesas)
37. Formulário Congresso (call for papers, certificados)
38. Tabelas: event_guests, event_tables, event_certificates
39. Endpoints: guests, tables, certificates

## SPRINT 9 — TIPOS ESPECÍFICOS 3 (Semana 12-14)
40. Formulário Teatro/Auditório (mapa de assentos, sessões)
41. Formulário Esportivo Estádio (zonas, temporada)
42. Formulário Esportivo Ginásio (layout flexível)
43. Formulário Rodeio (arena, pavilhões, leilão)
44. Formulário Evento Customizado (módulos à la carte)

## SPRINT 10 — COMPONENTES TRANSVERSAIS (Semana 14-16)
45. MapBuilder Component
46. AgendaBuilder Component
47. GuestManager Component
48. SeatingChart Component
49. CredentialBuilder Component

---

# PARTE 4 — REGRAS PARA OS AGENTES

---

## Regras de Código

1. **Multi-tenant obrigatório:** Todo endpoint DEVE filtrar por `organizer_id` e `event_id`. Sem exceção.
2. **Transações atômicas:** Operações compostas (criar palco + atrações) devem usar `beginTransaction/commit/rollback`
3. **Validação dupla:** Frontend valida para UX, backend valida para segurança. Nunca confiar só no frontend.
4. **buildScopedPath:** Todo link interno deve usar `buildScopedPath()` para manter contexto do evento
5. **Fallback offline:** Novos componentes devem funcionar em modo degradado quando sem conexão
6. **Audit log:** Toda criação/edição/deleção de configuração de evento deve ser auditada
7. **Internacionalização:** Textos em português BR, mas com chaves i18n preparadas para expansão futura
8. **Responsivo:** Todos os novos componentes devem funcionar em mobile (mínimo 375px)

## Regras de UI

1. IA sempre no topo, abaixo dos indicadores/filtros
2. Cards clicáveis devem ter `cursor: pointer` e destino real (nunca `#`)
3. Tabelas com mais de 20 linhas devem ter paginação
4. Formulários longos devem usar steps/wizard (não scroll infinito)
5. Toast para feedback de sucesso/erro (nunca `alert()` ou `prompt()`)
6. Loading states em toda operação assíncrona
7. Empty states com mensagem orientativa (nunca tela vazia)

## Regras de Nomenclatura

| Termo Antigo | Termo Novo |
|---|---|
| Artistas | Atrações / Logística |
| Comprometido | Contas a Pagar |
| Pago | Contas Pagas |
| Participants Hub | Equipe & Pessoas |
| Meals Control | Refeições |
| Scanner | Scanner (com sub-itens) |
| Agentes de IA | Assistente Inteligente |
| OrganizerFiles | Arquivos do Evento |

---

**FIM DO DOCUMENTO — Versão 1.0**
**Autor: Claude + André (EnjoyFun)**
**Data: 14/04/2026**
