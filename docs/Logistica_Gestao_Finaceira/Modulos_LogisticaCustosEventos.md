Plano estruturado — Logística de Artistas + Financeiro Operacional do Evento
1. Escopo geral
Frente A — Logística operacional de artistas

Controlar:

artista / atração
equipe ligada ao artista
passagem
hospedagem
traslado
camarim / hospitalidade
agenda operacional
cache
consumação
documentos e anexos
custos operacionais por artista
Frente B — Financeiro operacional do evento

Controlar:

fornecedores
contratos
centro de custo
contas a pagar
contas pagas
custos por evento
custos por artista
custos por setor
previsões vs realizado
integração com cartões / consumação
consolidação financeira operacional do evento
2. Objetivo do módulo

Criar uma camada operacional e financeira que permita:

planejar o custo real do evento
controlar gasto por artista
controlar gasto por fornecedor
acompanhar pagamento e pendências
emitir cartões de consumação quando necessário
importar dados em lote por CSV
consolidar tudo por evento
3. Arquitetura funcional
3.1 Entidades principais
Evento
Artista
Booking / apresentação
Logística do artista
Fornecedor
Contrato
Pagamento
Cartão / consumação
Arquivo / anexo
Centro de custo
Categoria de custo
3.2 Relações principais
um evento tem muitos artistas
um artista pode participar de muitos eventos
um artista pode ter vários custos logísticos
um artista pode ter um ou mais cartões/consumações
um evento tem muitos fornecedores
um fornecedor tem muitos pagamentos
cada custo/pagamento pertence a um centro de custo e categoria
4. Banco de dados
4.1 Tabelas principais — artistas
artists

Campos sugeridos:

id
organizer_id
stage_name
legal_name
document_type
document_number
phone
email
manager_name
manager_phone
manager_email
notes
created_at
updated_at
event_artists

Vínculo entre artista e evento.

id
organizer_id
event_id
artist_id
performance_date
performance_time
stage
status
cache_amount
currency
payment_status
contract_number
notes
created_at
updated_at
artist_logistics

Bloco operacional do artista no evento.

id
organizer_id
event_id
artist_id
arrival_date
arrival_time
departure_date
departure_time
hotel_name
hotel_checkin
hotel_checkout
rooming_notes
dressing_room_notes
hospitality_notes
local_transport_notes
airport_transfer_notes
created_at
updated_at
artist_logistics_items

Itens detalhados da logística.

id
organizer_id
event_id
artist_id
logistics_type
airfare
bus
hotel
transfer
local_transport
dressing_room
hospitality
rider
other
supplier_id
description
quantity
unit_amount
total_amount
due_date
paid_at
status
notes
created_at
updated_at
4.2 Tabelas principais — financeiro operacional
event_cost_categories
id
organizer_id
name
code
is_active

Exemplos:

artístico
logística
hospedagem
transporte
alimentação
produção
segurança
estrutura
marketing
fornecedor
consumação
event_cost_centers
id
organizer_id
event_id
name
code
notes

Exemplos:

palco
bar
backstage
artistas
camarim
produção
credenciamento
suppliers
id
organizer_id
legal_name
trade_name
document_type
document_number
phone
email
pix_key
bank_name
bank_branch
bank_account
notes
created_at
updated_at
event_payables

Contas a pagar do evento.

id
organizer_id
event_id
supplier_id nullable
artist_id nullable
category_id
cost_center_id
source_type
supplier
artist
logistics
internal
source_id nullable
description
amount
due_date
paid_amount
paid_at
payment_method
status
pending
partial
paid
canceled
notes
attachment_count
created_at
updated_at
event_payments

Baixas/pagamentos realizados.

id
organizer_id
payable_id
payment_date
amount
payment_method
reference_number
notes
created_at
updated_at
4.3 Tabelas para consumação/cartões
artist_benefits

Benefícios do artista por evento.

id
organizer_id
event_id
artist_id
benefit_type
consumption_card
meal_credit
backstage_credit
guest_list
parking
other
amount
quantity
notes
created_at
updated_at
artist_cards

Cartões emitidos para artista/equipe.

id
organizer_id
event_id
artist_id
beneficiary_name
beneficiary_role
card_type
consumacao
refeicao
backstage
card_number
qr_token
credit_amount
consumed_amount
status
issued_at
expires_at
notes
created_at
updated_at
artist_card_transactions
id
organizer_id
event_id
artist_card_id
transaction_type
issue
consume
adjust
cancel
amount
reference
notes
created_at
4.4 Tabelas para arquivos e importação
artist_files
id
organizer_id
event_id
artist_id
file_type
contract
rider
rooming_list
ticket
voucher
invoice
other
file_name
file_url
mime_type
size_bytes
notes
created_at
artist_import_batches
id
organizer_id
event_id
file_name
import_type
artists
logistics
cache
cards
status
total_rows
success_rows
failed_rows
created_by
created_at
artist_import_rows
id
batch_id
row_number
raw_payload_json
resolved_entity_id
status
error_message
created_at
5. UI / UX
5.1 Módulo “Artistas”

Abas sugeridas:

Lista de artistas do evento
Agenda / apresentações
Logística
Cache e pagamentos
Consumação / cartões
Arquivos
Custos consolidados
Tela: Lista de artistas

Colunas:

artista
apresentação
horário
cache
logística
consumação
status financeiro
pendências

Ações:

ver detalhe
editar
anexar arquivo
emitir cartão
lançar custo
registrar pagamento
Tela: Detalhe do artista

Blocos:

dados gerais
apresentação / agenda
logística
custos logísticos
cache
consumação/cartões
arquivos
histórico / observações
5.2 Módulo “Financeiro Operacional”

Abas sugeridas:

Resumo
Contas a pagar
Pagamentos realizados
Fornecedores
Custos por categoria
Custos por centro de custo
Custos por artista
Exportação
Dashboard financeiro

Cards:

total previsto
total pago
total pendente
custo artístico
custo logístico
custo de fornecedores
custo por setor
custo por evento

Gráficos:

previsto vs realizado
custo por categoria
custo por artista
pendências por vencimento
5.3 Fluxo de cartões / consumação

Tela simples dentro do detalhe do artista:

emitir novo cartão
definir valor
definir beneficiário
gerar QR/token
ver saldo
ver consumo
bloquear/cancelar
5.4 Fluxo de importação CSV

Uploads previstos:

artistas do evento
cache por artista
logística de viagem/hospedagem
benefícios/consumação
lista de equipe do artista
UX do import
upload do CSV
preview das colunas
mapeamento de campos
validação
importação
relatório de erros

Campos típicos:

nome artístico
nome legal
CPF/CNPJ
telefone
email
data de apresentação
horário
cache
hotel
voo
traslado
valor de consumação
quantidade de cartões
6. Backend / API
6.1 Endpoints principais — artistas
GET /artists
POST /artists
PUT /artists/{id}
GET /events/{event_id}/artists
POST /events/{event_id}/artists
PUT /event-artists/{id}
6.2 Endpoints — logística
GET /events/{event_id}/artist-logistics
POST /events/{event_id}/artist-logistics
PUT /artist-logistics/{id}
POST /artist-logistics/import
6.3 Endpoints — financeiro
GET /events/{event_id}/payables
POST /events/{event_id}/payables
POST /payables/{id}/payments
GET /events/{event_id}/financial-summary
GET /events/{event_id}/costs/by-category
GET /events/{event_id}/costs/by-artist
6.4 Endpoints — cartões
GET /events/{event_id}/artist-cards
POST /events/{event_id}/artist-cards
POST /artist-cards/{id}/consume
POST /artist-cards/{id}/adjust
POST /artist-cards/{id}/cancel
6.5 Endpoints — importação
POST /events/{event_id}/artists/import
POST /events/{event_id}/artist-logistics/import
POST /events/{event_id}/artist-cards/import
7. Frontend
7.1 Componentes principais
ArtistsModule
ArtistsList
ArtistDetailDrawer
ArtistLogisticsTab
ArtistFinanceTab
ArtistCardsTab
ArtistFilesTab
ArtistImportModal
FinancialOperationsTab
SupplierModal
PayableModal
PaymentModal
7.2 Estados importantes
artista selecionado
evento selecionado
lote de importação
pendências financeiras
saldo dos cartões
anexos do artista
8. Consumidores / usuários do módulo
8.1 Produção / backstage

Usa para:

agenda
logística
traslado
hotel
rider
camarim
8.2 Financeiro

Usa para:

cache
pagamentos
fornecedores
pendências
fechamento do evento
8.3 Operação de bar / consumação

Usa para:

emissão de cartões
controle de saldo
baixa de consumação
8.4 Coordenação geral

Usa para:

visão consolidada do artista
custo total por artista
custo total do evento
pendências críticas
9. Integrações possíveis
9.1 Cartões / consumo

Aproveitar infraestrutura já existente de:

digital cards
QR/token
transactions
consumo em bar/POS
9.2 Financeiro

Integração futura com:

organizer finance
payment gateways
exportação CSV/XLSX
contas pagas / a pagar
9.3 Arquivos

Upload de:

contrato
rider
rooming list
passagens
voucher de hotel
nota fiscal
10. Regras de negócio principais
artista pode ter vários custos no mesmo evento
cache e logística são separados
consumação é benefício, não pagamento
cartão pode ser emitido para artista ou equipe do artista
pagamento pode ser parcial
custo sempre deve ter categoria e centro de custo
importação CSV nunca deve inserir cegamente sem preview/validação
evento é o filtro principal
tudo deve respeitar organizer_id
custo do artista deve aparecer no consolidado do evento
11. Ordem sugerida de implementação
Fase 1 — Base de artistas + vínculo com evento
artists
event_artists
listagem e detalhe básico
Fase 2 — Logística operacional
artist_logistics
artist_logistics_items
anexos
agenda e transporte
Fase 3 — Financeiro do artista
cache
custos logísticos
contas a pagar
pagamentos
Fase 4 — Consumação / cartões
benefícios
emissão de cartões
saldo e transações
Fase 5 — Importação CSV
artistas
logística
cache
consumação
Fase 6 — Dashboard consolidado do evento
custos por artista
custos por categoria
pendências
previsto vs realizado
12. Entrega esperada dessa frente

Quando isso estiver pronto, o sistema passa a ter:

operação real de artistas
custo real por artista
custo real do evento
fornecedores e pagamentos organizados
consumação emitida por cartão
importação em lote
visão consolidada para produção e financeiro

Se você quiser, no próximo passo eu posso transformar isso em estrutura pronta para entrar no progresso6.md, com formato de roadmap executivo + técnico.

mas são 2 mudlos separados, onde esta o que conversamos depois sobre logistica apertada dos artistas, com o horario q ele chega e a hora q ele toca e vai embora porque vem de outro evento e vai para outro evento, sinalizando alerta de horario apertado, com o tempo estimado da losgistica, ente aerooporto e evento atc
Pensou por 7s

Você tem razão. São 2 módulos separados e eu misturei demais no bloco anterior.

E também faltou justamente a parte mais crítica que vocês já tinham definido depois: logística apertada do artista, com janela real entre chegada, passagem por outro evento, horário do show e saída.

Então a correção é esta:

Estrutura correta
Módulo 1 — Logística Operacional de Artistas

Esse módulo cuida de:

agenda real do artista no evento
chegada
saída
origem e destino
aeroporto / hotel / evento / próximo evento
deslocamentos
janela operacional
alertas de conflito
risco de atraso
rider / hospitalidade / camarim
equipe do artista
cartões/consumação do artista e equipe, se isso fizer parte da operação de backstage
Módulo 2 — Financeiro Operacional do Evento

Esse módulo cuida de:

fornecedores
pagamentos
contas a pagar
contas pagas
custo por evento
custo por artista
custo por categoria
custo por centro de custo
previsto vs realizado
Onde entra a “logística apertada” dos artistas

Isso fica no Módulo 1 — Logística Operacional de Artistas, como um núcleo próprio:

Submódulo — Janela Operacional / Logística Apertada

Objetivo:
medir se o artista consegue cumprir a operação com segurança entre:

chegada
deslocamento
passagem por hotel/backstage
horário de palco
saída
próximo compromisso
O que esse submódulo precisa controlar
1. Linha do tempo do artista

Para cada artista no evento:

origem anterior
cidade
aeroporto
outro evento
hotel anterior
horário estimado de chegada na cidade
horário estimado de chegada no aeroporto
horário estimado de chegada no hotel
horário estimado de chegada no evento
horário de passagem de som
horário de abertura de camarim
horário do show
duração do show
horário previsto de saída do evento
destino seguinte
hotel
aeroporto
outro evento
outra cidade
horário limite de saída
horário real de deslocamento para o próximo destino
2. Estimativas de deslocamento

Precisamos registrar tempos estimados entre pontos, por exemplo:

aeroporto → hotel
aeroporto → evento
hotel → evento
evento → aeroporto
evento → próximo evento
hotel → próximo evento
Campos necessários
origem
destino
distância estimada
tempo estimado sem trânsito
tempo estimado com trânsito
margem operacional
tempo total considerado para o planejamento
3. Indicador de janela apertada

O sistema precisa calcular automaticamente:

Janela de chegada para apresentação

Exemplo:

pouso: 18:10
aeroporto → evento: 50 min
margem: 20 min
horário do palco: 19:00

Resultado:

chegada operacional prevista: 19:20
show: 19:00
status: risco crítico
Janela de saída para outro compromisso

Exemplo:

fim do show: 23:10
desmontagem mínima / saída: 20 min
evento → aeroporto: 45 min
voo: 00:10

Resultado:

saída operacional prevista: 00:15
voo: 00:10
status: impraticável
Regras automáticas de alerta
Alertas possíveis
logística apertada
risco de atraso
janela crítica
incompatível com passagem de som
incompatível com horário de palco
incompatível com saída para próximo evento
conflito entre chegada e performance
conflito entre performance e deslocamento seguinte
Classificação sugerida
verde = confortável
amarelo = atenção
laranja = apertado
vermelho = crítico
cinza = dados insuficientes
Banco de dados — parte específica da logística apertada
artist_operational_timeline
id
organizer_id
event_id
artist_id
previous_commitment_type
previous_commitment_label
previous_city
arrival_mode
arrival_airport
arrival_datetime
hotel_checkin_datetime
venue_arrival_datetime
soundcheck_datetime
dressing_room_ready_datetime
performance_start_datetime
performance_end_datetime
venue_departure_datetime
next_commitment_type
next_commitment_label
next_city
next_destination
next_departure_deadline
notes
created_at
updated_at
artist_transfer_estimations
id
organizer_id
event_id
artist_id
route_type
airport_to_hotel
airport_to_venue
hotel_to_venue
venue_to_airport
venue_to_next_event
hotel_to_next_event
custom
origin_label
destination_label
distance_km
eta_minutes_base
eta_minutes_peak
safety_buffer_minutes
planned_eta_minutes
notes
created_at
updated_at
artist_operational_alerts
id
organizer_id
event_id
artist_id
alert_type
tight_arrival
tight_departure
soundcheck_conflict
stage_conflict
transfer_risk
insufficient_data
severity
low
medium
high
critical
message
recommended_action
is_resolved
resolved_by
resolved_at
created_at
UI / UX desse módulo
Tela: Agenda Operacional do Artista

Blocos:

chegada
deslocamentos
palco
saída
próximo compromisso
alertas
Tela: Linha do tempo operacional

Uma timeline visual:

pouso
hotel
venue arrival
soundcheck
palco
saída
aeroporto / próximo evento
Tela: Alertas logísticos

Lista de artistas com:

artista
horário do show
chegada prevista
saída prevista
próximo destino
nível de risco
recomendação
Tela: Mapa / rotas resumidas

Sem precisar virar app de mapas completo:

origem
destino
ETA
buffer
status
Ações operacionais sugeridas

Quando houver alerta, o sistema pode sugerir:

antecipar transfer
pular hotel
ir direto do aeroporto ao evento
reduzir soundcheck
alterar horário de palco
reservar helicóptero/transfer especial
remarcar saída
marcar operação como crítica
Importação CSV para esse submódulo

Você pediu CSV, então isso cabe também aqui.

CSV de logística operacional

Campos possíveis:

artista
voo / origem
aeroporto chegada
horário chegada
hotel
horário check-in
horário soundcheck
horário show
duração show
destino seguinte
horário limite de saída
aeroporto saída
observações

Com isso o sistema calcula:

janela real
risco
alerta
Separação final correta
Módulo 1 — Logística Operacional de Artistas

Subáreas:

cadastro do artista no evento
agenda operacional
logística de chegada e saída
hospedagem / traslado / camarim / rider
janela apertada / alertas de conflito
equipe do artista
cartões / consumação do artista e equipe
anexos / documentos
Módulo 2 — Financeiro Operacional do Evento

Subáreas:

fornecedores
contratos
contas a pagar
pagamentos
custos por artista
custos por categoria
custos por centro de custo
fechamento financeiro operacional do evento
Resposta objetiva à sua pergunta

A parte de:

horário que o artista chega
hora que toca
hora que vai embora
porque vem de outro evento
porque vai para outro evento
alerta de horário apertado
tempo estimado entre aeroporto e evento
tempo estimado entre evento e próximo destino

fica no Módulo 1 — Logística Operacional de Artistas, como um submódulo próprio de janela operacional / logística apertada.

Se você quiser, eu reorganizo tudo agora em 2 blocos separados e prontos para entrar no progresso6.md, já com:

banco de dados
backend
frontend
UI/UX
integrações
CSV
alertas
ordem de implementação.