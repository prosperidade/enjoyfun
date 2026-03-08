# EnjoyFun — Especificação Oficial do Tenant Settings Hub v1

## Objetivo
Definir oficialmente o hub de configurações do organizador dentro da EnjoyFun.

O Tenant Settings Hub será o centro onde cada organizador configura o seu ambiente white label, seus canais, sua IA e sua operação financeira.

---

## 1. Papel do Tenant Settings Hub

O Tenant Settings Hub é o espaço onde o organizador transforma a infraestrutura da EnjoyFun em um produto próprio.

Ele deve permitir configurar:
- identidade visual
- dados institucionais
- canais de comunicação
- provedores de IA
- gateways financeiros
- preferências operacionais do tenant

---

## 2. Estrutura oficial do Settings Hub

O Settings Hub deve ser dividido em 4 seções principais.

## 2.1 Branding
### Objetivo
Controlar a identidade visual e institucional do app do organizador.

### Campos principais
- app_name
- logo_url
- favicon_url
- primary_color
- secondary_color
- support_email
- support_whatsapp
- subdomain

### Regras
- branding deve ser carregado no login
- branding deve refletir na sidebar, header, PWA e experiência do participante

---

## 2.2 Channels
### Objetivo
Permitir que o organizador conecte seus próprios canais de comunicação.

### Providers prioritários
- Resend
- Z-API
- Evolution
- futuros providers

### Configurações por provider
- status ativo/inativo
- credenciais/token
- ambiente (quando aplicável)
- webhook
- remetente/instância
- teste de conexão

### Resultados esperados
- o organizador envia e recebe em seus próprios canais
- a EnjoyFun apenas orquestra a infraestrutura

---

## 2.3 AI Config
### Objetivo
Permitir que o organizador configure seus agentes com suas próprias credenciais.

### Configurações principais
- provider de IA
- api key
- modelo
- agentes habilitados
- contexto do organizador
- contexto do evento
- limite de uso
- teste de conexão

### Tipos de agentes previstos
- atendimento ao participante
- copiloto do organizador
- insights operacionais
- resumo executivo

---

## 2.4 Financeiro
### Objetivo
Permitir que o organizador opere seus pagamentos com seus próprios gateways.

### Gateways prioritários
- Mercado Pago
- PagSeguro
- Asaas
- Pagar.me
- InfinityPay

### Configurações principais
- provider
- status ativo/inativo
- gateway principal
- ambiente
- credenciais/tokens
- teste de conexão

### Configurações financeiras complementares
- comissão da EnjoyFun
- preferências de repasse
- dados de liquidação
- política padrão do tenant

---

## 3. Estrutura visual recomendada do frontend

### Aba 1 — Branding
Bloco com preview visual e edição de:
- nome
- logo
- cores
- favicon
- suporte

### Aba 2 — Channels
Lista de cards por provider:
- Resend
- Z-API
- Evolution
- adicionar futuro provider

Cada card deve exibir:
- status
- botão conectar/desconectar
- botão testar
- botão editar

### Aba 3 — AI Config
Bloco com:
- provider
- api key
- modelo
- agentes ativados
- contexto
- teste

### Aba 4 — Financeiro
Lista de gateways com:
- status
- principal/secundário
- botão testar
- botão editar
- visão rápida da comissão

---

## 4. Regras oficiais do produto

1. Branding, Channels, AI e Financeiro não devem ficar misturados numa mesma tela sem separação clara.
2. Cada seção deve ter seu próprio service e sua própria modelagem.
3. Toda credencial sensível deve ser armazenada de forma segura.
4. O organizador deve conseguir testar conexões antes de ativar.
5. A experiência precisa ser simples, mesmo com muita potência por trás.

---

## 5. Backend oficial recomendado

### Controllers
- `OrganizerSettingsController` → Branding
- `OrganizerChannelsController` → Channels
- `OrganizerAIConfigController` → AI Config
- `OrganizerFinanceController` → Financeiro

### Services
- `BrandingService`
- `OrganizerChannelService`
- `AIConfigService`
- `PaymentGatewayService`
- `FinancialSettingsService`

---

## 6. Modelagem conectada ao Settings Hub

### Branding
- `organizer_settings`

### Channels
- `organizer_channels`

### AI Config
- `organizer_ai_config`

### Financeiro
- `organizer_payment_gateways`
- `organizer_financial_settings`

---

## 7. Ordem recomendada de implementação

### Etapa 1
- Branding estabilizado
- separação conceitual das abas

### Etapa 2
- Channels v1
- AI Config v1
- Financeiro v1

### Etapa 3
- testes de conexão
- UX refinada
- logs e status mais claros

---

## 8. Resultado esperado

Ao final desta especificação, o organizador terá dentro da EnjoyFun um hub completo para transformar a plataforma em um produto com:
- identidade própria
- canais próprios
- IA própria
- financeiro próprio

