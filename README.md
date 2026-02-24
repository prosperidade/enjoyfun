# EnjoyFun 2.0

Plataforma de gestão inteligente para eventos, englobando ingressos, controle de portaria, bar/PDV (offline-first) e estacionamento.

## Estrutura Atual do Banco de Dados (PostgreSQL)

O sistema foi desenhado para atuar sobre PostgreSQL. As tabelas fundamentais já implementadas e ativas são:

1. **Auth & Usuários**
   - `users`: Armazena dados de acesso, senhas (bcrypt) e status (`is_active`).
   - `roles`: Cargos disponíveis (`admin`, `staff`, `participant`, etc).
   - `user_roles`: Relacionamento N:N entre usuários e cargos.
   - `refresh_tokens`: Controle de sessões via tokens opacos e JWT de curta duração.

2. **Eventos & Ingressos**
   - `events`: Dados centrais do evento (nome, data, local, ativação de modo offline).
   - `ticket_types`: Lotes e tipos de ingressos disponíveis à venda.
   - `tickets`: Ingressos gerados/comprados vinculados com QR Code exclusivo e Status.
   
3. **Estacionamento & Cartão Digital (RFID)**
   - `digital_cards`: Cartões cashless emitidos no evento, com carteira e saldo.
   - `parking_records`: Registros de entrada e saída de veículos vinculados a um evento.

## Status das Rotas (Backend API)

As rotas são centralizadas em `public/index.php` através de um dispatcher nativo:

✅ **Funcionando / Finalizado (Fase 1)**
- `POST /api/auth/login` (Login, verificação de Bcrypt, geração de JWT)
- `POST /api/auth/register` (Criação de contas)
- `GET /api/auth/me` (Busca os dados do próprio usuário com base no JWT)
- `GET /api/events` (Listagem geral de eventos — `EventController.php`)
- `GET /api/tickets` (Listagem de bilhetes do usuário — `TicketController.php`)
- `GET /api/parking` (Listagem de registros de veículos — `ParkingController.php`)

⏳ **Pendências / Não Implementado (Fases 2 e 3)**
- **Fase 2 (PDV & Cashless):**
  - Rotas de Bar (`BarController.php`) para produtos e estoque.
  - Vendas, recarga de cartão via RFID (`CardController.php`).
- **Fase 3 (Offline Sync):**
  - API de Sincronização em Lote (`SyncController.php`).
  - Filas IndexedDB no frontend com liberação periódica na nuvem.

## Como Iniciar o Back-End

1. Crie um banco de dados no PostgreSQL chamado `enjoyfun`.
2. Execute o conteúdo de `database/schema.sql` no banco para erguer as tabelas.
3. Configure o arquivo `.env` conectando-o ao banco.
4. O Apache precisa apontar o `DocumentRoot` para a pasta `/backend/public`.
