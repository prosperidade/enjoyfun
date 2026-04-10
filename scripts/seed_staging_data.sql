-- =============================================================================
-- Seed data for staging environment
-- Purpose: simulate a 5000+ person event for load testing
-- Usage: psql -U postgres -d enjoyfun -f scripts/seed_staging_data.sql
-- Safe: uses ON CONFLICT DO NOTHING, can be re-run
-- WARNING: do NOT run in production
-- =============================================================================
--
-- Record counts by table:
--   users                  : 12 (1 organizer + 1 admin + 10 staff)
--   events                 : 1
--   event_days             : 3
--   event_shifts           : 6 (2 per day)
--   ticket_types           : 4 (VIP, Pista, Camarote, Staff)
--   tickets                : 5000 (500 VIP + 3500 Pista + 500 Camarote + 500 Staff)
--   people                 : 220 (workforce people)
--   participant_categories : 2 (workforce, guest)
--   event_participants     : 200 (workforce participants)
--   workforce_roles        : 5 (bar, food, portaria, estacionamento, limpeza)
--   workforce_event_roles  : 5
--   workforce_assignments  : 200
--   vendors                : 20
--   products               : 50 (bar, food, shop)
--   digital_cards          : 300
--   event_card_assignments : 300
--   sales                  : 500
--   sale_items             : 500
--   card_transactions      : 600 (300 credits + 300 debits)
--   parking_records        : 100 (60 parked + 40 exited)
--   guests                 : 50
--   event_meal_services    : 9 (3 per day: breakfast, lunch, dinner)
--   participant_meals      : 400
-- =============================================================================

BEGIN;

-- =============================================================================
-- 0. CONFIGURATION — all IDs are deterministic for idempotency
-- =============================================================================

-- We use fixed IDs starting at 90000 to avoid collisions with real data.
-- The organizer user's id IS the organizer_id (self-referencing pattern).

DO $$
BEGIN
    -- Ensure sequences are high enough to not collide with seed IDs
    PERFORM setval('users_id_seq', GREATEST(nextval('users_id_seq'), 90100), false);
    PERFORM setval('events_id_seq', GREATEST(nextval('events_id_seq'), 90010), false);
    PERFORM setval('event_days_id_seq', GREATEST(nextval('event_days_id_seq'), 90010), false);
    PERFORM setval('event_shifts_id_seq', GREATEST(nextval('event_shifts_id_seq'), 90010), false);
    PERFORM setval('ticket_types_id_seq', GREATEST(nextval('ticket_types_id_seq'), 90010), false);
    PERFORM setval('tickets_id_seq', GREATEST(nextval('tickets_id_seq'), 96000), false);
    PERFORM setval('people_id_seq', GREATEST(nextval('people_id_seq'), 91000), false);
    PERFORM setval('participant_categories_id_seq', GREATEST(nextval('participant_categories_id_seq'), 90010), false);
    PERFORM setval('event_participants_id_seq', GREATEST(nextval('event_participants_id_seq'), 91000), false);
    PERFORM setval('workforce_roles_id_seq', GREATEST(nextval('workforce_roles_id_seq'), 90010), false);
    PERFORM setval('workforce_event_roles_id_seq', GREATEST(nextval('workforce_event_roles_id_seq'), 90010), false);
    PERFORM setval('workforce_assignments_id_seq', GREATEST(nextval('workforce_assignments_id_seq'), 91000), false);
    PERFORM setval('vendors_id_seq', GREATEST(nextval('vendors_id_seq'), 90100), false);
    PERFORM setval('products_id_seq', GREATEST(nextval('products_id_seq'), 90100), false);
    PERFORM setval('sales_id_seq', GREATEST(nextval('sales_id_seq'), 91000), false);
    PERFORM setval('sale_items_id_seq', GREATEST(nextval('sale_items_id_seq'), 91000), false);
    PERFORM setval('card_transactions_id_seq', GREATEST(nextval('card_transactions_id_seq'), 91000), false);
    PERFORM setval('parking_records_id_seq', GREATEST(nextval('parking_records_id_seq'), 90200), false);
    PERFORM setval('guests_id_seq', GREATEST(nextval('guests_id_seq'), 90100), false);
    PERFORM setval('event_meal_services_id_seq', GREATEST(nextval('event_meal_services_id_seq'), 90020), false);
    PERFORM setval('participant_meals_id_seq', GREATEST(nextval('participant_meals_id_seq'), 91000), false);
    PERFORM setval('event_card_assignments_id_seq', GREATEST(nextval('event_card_assignments_id_seq'), 91000), false);
END $$;


-- =============================================================================
-- 1. USERS — organizer + admin + 10 staff
-- =============================================================================
-- Password hash = bcrypt of "staging123" (cost 10)

INSERT INTO users (id, name, email, password, role, sector, organizer_id, is_active, phone) VALUES
    -- Organizer (organizer_id = own id)
    (90001, 'Organizador Staging', 'organizer@staging.enjoyfun.com',
     '$2y$10$8KzVQ3RJlHd0.7QjYxK5QOqGz3rXwE4V5HvWnKuOvFbXjN1MqCJKy',
     'organizer', 'all', 90001, true, '11999990001'),
    -- Admin under this organizer
    (90002, 'Admin Staging', 'admin@staging.enjoyfun.com',
     '$2y$10$8KzVQ3RJlHd0.7QjYxK5QOqGz3rXwE4V5HvWnKuOvFbXjN1MqCJKy',
     'manager', 'all', 90001, true, '11999990002'),
    -- Staff members
    (90003, 'Bartender Maria', 'maria.bar@staging.enjoyfun.com',
     '$2y$10$8KzVQ3RJlHd0.7QjYxK5QOqGz3rXwE4V5HvWnKuOvFbXjN1MqCJKy',
     'bartender', 'bar', 90001, true, '11999990003'),
    (90004, 'Bartender Joao', 'joao.bar@staging.enjoyfun.com',
     '$2y$10$8KzVQ3RJlHd0.7QjYxK5QOqGz3rXwE4V5HvWnKuOvFbXjN1MqCJKy',
     'bartender', 'bar', 90001, true, '11999990004'),
    (90005, 'Cozinheira Ana', 'ana.food@staging.enjoyfun.com',
     '$2y$10$8KzVQ3RJlHd0.7QjYxK5QOqGz3rXwE4V5HvWnKuOvFbXjN1MqCJKy',
     'staff', 'food', 90001, true, '11999990005'),
    (90006, 'Porteiro Pedro', 'pedro.gate@staging.enjoyfun.com',
     '$2y$10$8KzVQ3RJlHd0.7QjYxK5QOqGz3rXwE4V5HvWnKuOvFbXjN1MqCJKy',
     'staff', 'portaria', 90001, true, '11999990006'),
    (90007, 'Porteira Lucia', 'lucia.gate@staging.enjoyfun.com',
     '$2y$10$8KzVQ3RJlHd0.7QjYxK5QOqGz3rXwE4V5HvWnKuOvFbXjN1MqCJKy',
     'staff', 'portaria', 90001, true, '11999990007'),
    (90008, 'Manobrista Carlos', 'carlos.park@staging.enjoyfun.com',
     '$2y$10$8KzVQ3RJlHd0.7QjYxK5QOqGz3rXwE4V5HvWnKuOvFbXjN1MqCJKy',
     'parking_staff', 'estacionamento', 90001, true, '11999990008'),
    (90009, 'Limpeza Rosa', 'rosa.clean@staging.enjoyfun.com',
     '$2y$10$8KzVQ3RJlHd0.7QjYxK5QOqGz3rXwE4V5HvWnKuOvFbXjN1MqCJKy',
     'staff', 'limpeza', 90001, true, '11999990009'),
    (90010, 'Supervisor Marcos', 'marcos.sup@staging.enjoyfun.com',
     '$2y$10$8KzVQ3RJlHd0.7QjYxK5QOqGz3rXwE4V5HvWnKuOvFbXjN1MqCJKy',
     'manager', 'all', 90001, true, '11999990010'),
    (90011, 'Scanner Felipe', 'felipe.scan@staging.enjoyfun.com',
     '$2y$10$8KzVQ3RJlHd0.7QjYxK5QOqGz3rXwE4V5HvWnKuOvFbXjN1MqCJKy',
     'staff', 'portaria', 90001, true, '11999990011'),
    (90012, 'Caixa Juliana', 'juliana.pos@staging.enjoyfun.com',
     '$2y$10$8KzVQ3RJlHd0.7QjYxK5QOqGz3rXwE4V5HvWnKuOvFbXjN1MqCJKy',
     'staff', 'bar', 90001, true, '11999990012')
ON CONFLICT (email) DO NOTHING;


-- =============================================================================
-- 2. EVENT — Festival Staging 2026 (3 days, starting in 2 weeks)
-- =============================================================================

INSERT INTO events (id, name, slug, description, venue_name, starts_at, ends_at, status, capacity, organizer_id, location, is_active, event_date) VALUES
    (90001,
     'Festival Staging 2026',
     'festival-staging-2026',
     'Evento de staging com 5000+ participantes para testes de carga e validacao completa do sistema.',
     'Arena Staging Park',
     (CURRENT_DATE + INTERVAL '14 days')::timestamp + TIME '14:00',
     (CURRENT_DATE + INTERVAL '16 days')::timestamp + TIME '04:00',
     'published',
     6000,
     90001,
     'Sao Paulo, SP',
     true,
     CURRENT_DATE + 14
    )
ON CONFLICT (slug) DO NOTHING;


-- =============================================================================
-- 3. EVENT DAYS — 3 days
-- =============================================================================

INSERT INTO event_days (id, event_id, date, starts_at, ends_at, organizer_id) VALUES
    (90001, 90001, CURRENT_DATE + 14,
     (CURRENT_DATE + INTERVAL '14 days')::timestamp + TIME '14:00',
     (CURRENT_DATE + INTERVAL '15 days')::timestamp + TIME '04:00',
     90001),
    (90002, 90001, CURRENT_DATE + 15,
     (CURRENT_DATE + INTERVAL '15 days')::timestamp + TIME '14:00',
     (CURRENT_DATE + INTERVAL '16 days')::timestamp + TIME '04:00',
     90001),
    (90003, 90001, CURRENT_DATE + 16,
     (CURRENT_DATE + INTERVAL '16 days')::timestamp + TIME '14:00',
     (CURRENT_DATE + INTERVAL '17 days')::timestamp + TIME '04:00',
     90001)
ON CONFLICT (id) DO NOTHING;


-- =============================================================================
-- 4. EVENT SHIFTS — 2 per day (Tarde 14-20h, Noite 20-04h)
-- =============================================================================

INSERT INTO event_shifts (id, event_day_id, name, starts_at, ends_at, organizer_id) VALUES
    -- Day 1
    (90001, 90001, 'Dia 1 - Tarde',
     (CURRENT_DATE + INTERVAL '14 days')::timestamp + TIME '14:00',
     (CURRENT_DATE + INTERVAL '14 days')::timestamp + TIME '20:00',
     90001),
    (90002, 90001, 'Dia 1 - Noite',
     (CURRENT_DATE + INTERVAL '14 days')::timestamp + TIME '20:00',
     (CURRENT_DATE + INTERVAL '15 days')::timestamp + TIME '04:00',
     90001),
    -- Day 2
    (90003, 90002, 'Dia 2 - Tarde',
     (CURRENT_DATE + INTERVAL '15 days')::timestamp + TIME '14:00',
     (CURRENT_DATE + INTERVAL '15 days')::timestamp + TIME '20:00',
     90001),
    (90004, 90002, 'Dia 2 - Noite',
     (CURRENT_DATE + INTERVAL '15 days')::timestamp + TIME '20:00',
     (CURRENT_DATE + INTERVAL '16 days')::timestamp + TIME '04:00',
     90001),
    -- Day 3
    (90005, 90003, 'Dia 3 - Tarde',
     (CURRENT_DATE + INTERVAL '16 days')::timestamp + TIME '14:00',
     (CURRENT_DATE + INTERVAL '16 days')::timestamp + TIME '20:00',
     90001),
    (90006, 90003, 'Dia 3 - Noite',
     (CURRENT_DATE + INTERVAL '16 days')::timestamp + TIME '20:00',
     (CURRENT_DATE + INTERVAL '17 days')::timestamp + TIME '04:00',
     90001)
ON CONFLICT (id) DO NOTHING;


-- =============================================================================
-- 5. TICKET TYPES — 4 types
-- =============================================================================

INSERT INTO ticket_types (id, event_id, name, price, organizer_id) VALUES
    (90001, 90001, 'VIP',      350.00, 90001),
    (90002, 90001, 'Pista',    120.00, 90001),
    (90003, 90001, 'Camarote', 500.00, 90001),
    (90004, 90001, 'Staff',      0.00, 90001)
ON CONFLICT (id) DO NOTHING;


-- =============================================================================
-- 6. TICKETS — 5000 total (500 VIP + 3500 Pista + 500 Camarote + 500 Staff)
-- =============================================================================
-- Uses generate_series for volume. order_reference and qr_token are unique per ticket.

-- 500 VIP tickets (IDs 90001-90500)
INSERT INTO tickets (id, event_id, ticket_type_id, order_reference, status, price_paid, qr_token,
                     holder_name, holder_email, organizer_id, totp_secret, purchased_at)
SELECT
    90000 + s,
    90001,
    90001,
    'STG-VIP-' || LPAD(s::text, 5, '0'),
    CASE WHEN s <= 450 THEN 'paid' WHEN s <= 480 THEN 'used' ELSE 'cancelled' END,
    350.00,
    'QR-STG-VIP-' || LPAD(s::text, 5, '0') || '-' || md5('vip' || s::text),
    'VIP Participante ' || s,
    'vip' || s || '@staging.test',
    90001,
    upper(substring(md5('totp-vip-' || s::text), 1, 32)),
    CURRENT_TIMESTAMP - (random() * INTERVAL '30 days')
FROM generate_series(1, 500) AS s
ON CONFLICT (order_reference) DO NOTHING;

-- 3500 Pista tickets (IDs 90501-94000)
INSERT INTO tickets (id, event_id, ticket_type_id, order_reference, status, price_paid, qr_token,
                     holder_name, holder_email, organizer_id, totp_secret, purchased_at)
SELECT
    90500 + s,
    90001,
    90002,
    'STG-PIS-' || LPAD(s::text, 5, '0'),
    CASE WHEN s <= 3200 THEN 'paid' WHEN s <= 3400 THEN 'used' ELSE 'cancelled' END,
    120.00,
    'QR-STG-PIS-' || LPAD(s::text, 5, '0') || '-' || md5('pista' || s::text),
    'Participante Pista ' || s,
    'pista' || s || '@staging.test',
    90001,
    upper(substring(md5('totp-pis-' || s::text), 1, 32)),
    CURRENT_TIMESTAMP - (random() * INTERVAL '30 days')
FROM generate_series(1, 3500) AS s
ON CONFLICT (order_reference) DO NOTHING;

-- 500 Camarote tickets (IDs 94001-94500)
INSERT INTO tickets (id, event_id, ticket_type_id, order_reference, status, price_paid, qr_token,
                     holder_name, holder_email, organizer_id, totp_secret, purchased_at)
SELECT
    94000 + s,
    90001,
    90003,
    'STG-CAM-' || LPAD(s::text, 5, '0'),
    CASE WHEN s <= 420 THEN 'paid' WHEN s <= 470 THEN 'used' ELSE 'cancelled' END,
    500.00,
    'QR-STG-CAM-' || LPAD(s::text, 5, '0') || '-' || md5('camarote' || s::text),
    'Camarote Participante ' || s,
    'camarote' || s || '@staging.test',
    90001,
    upper(substring(md5('totp-cam-' || s::text), 1, 32)),
    CURRENT_TIMESTAMP - (random() * INTERVAL '30 days')
FROM generate_series(1, 500) AS s
ON CONFLICT (order_reference) DO NOTHING;

-- 500 Staff tickets (IDs 94501-95000)
INSERT INTO tickets (id, event_id, ticket_type_id, order_reference, status, price_paid, qr_token,
                     holder_name, holder_email, organizer_id, totp_secret, purchased_at)
SELECT
    94500 + s,
    90001,
    90004,
    'STG-STF-' || LPAD(s::text, 5, '0'),
    'paid',
    0.00,
    'QR-STG-STF-' || LPAD(s::text, 5, '0') || '-' || md5('staff' || s::text),
    'Staff ' || s,
    'staff' || s || '@staging.test',
    90001,
    upper(substring(md5('totp-stf-' || s::text), 1, 32)),
    CURRENT_TIMESTAMP - (random() * INTERVAL '14 days')
FROM generate_series(1, 500) AS s
ON CONFLICT (order_reference) DO NOTHING;


-- =============================================================================
-- 7. VENDORS — 20 vendors across sectors
-- =============================================================================

INSERT INTO vendors (id, name, sector, commission_rate, organizer_id) VALUES
    (90001, 'Bar Central',        'bar',            10.00, 90001),
    (90002, 'Bar Lateral A',      'bar',            10.00, 90001),
    (90003, 'Bar Lateral B',      'bar',            10.00, 90001),
    (90004, 'Bar VIP',            'bar',            12.00, 90001),
    (90005, 'Bar Camarote',       'bar',            12.00, 90001),
    (90006, 'Food Truck Burguer', 'food',           15.00, 90001),
    (90007, 'Food Truck Pizza',   'food',           15.00, 90001),
    (90008, 'Food Truck Acai',    'food',           15.00, 90001),
    (90009, 'Espetinhos do Zeca', 'food',           12.00, 90001),
    (90010, 'Pastelaria Maria',   'food',           12.00, 90001),
    (90011, 'Loja Oficial',       'shop',           20.00, 90001),
    (90012, 'Loja Merch VIP',     'shop',           20.00, 90001),
    (90013, 'Barraca Drinks',     'bar',            10.00, 90001),
    (90014, 'Barraca Agua/Refri', 'bar',             8.00, 90001),
    (90015, 'Food Court A',       'food',           14.00, 90001),
    (90016, 'Food Court B',       'food',           14.00, 90001),
    (90017, 'Sorveteria Gelato',  'food',           15.00, 90001),
    (90018, 'Loja Acessorios',    'shop',           22.00, 90001),
    (90019, 'Bar Piscina',        'bar',            10.00, 90001),
    (90020, 'Food Truck Crepe',   'food',           15.00, 90001)
ON CONFLICT (id) DO NOTHING;


-- =============================================================================
-- 8. PRODUCTS — 50 products (bar/food/shop)
-- =============================================================================

INSERT INTO products (id, event_id, name, price, stock_qty, vendor_id, sector, low_stock_threshold, organizer_id) VALUES
    -- Bar products (20)
    (90001, 90001, 'Cerveja Lata',          12.00, 5000, 90001, 'bar', 100, 90001),
    (90002, 90001, 'Cerveja Long Neck',     15.00, 3000, 90001, 'bar', 50,  90001),
    (90003, 90001, 'Cerveja Premium',       20.00, 1000, 90004, 'bar', 30,  90001),
    (90004, 90001, 'Chopp 300ml',           14.00, 2000, 90002, 'bar', 50,  90001),
    (90005, 90001, 'Chopp 500ml',           22.00, 1500, 90002, 'bar', 50,  90001),
    (90006, 90001, 'Caipirinha',            25.00, 800,  90003, 'bar', 20,  90001),
    (90007, 90001, 'Caipiroska',            28.00, 800,  90003, 'bar', 20,  90001),
    (90008, 90001, 'Whisky Dose',           35.00, 500,  90005, 'bar', 10,  90001),
    (90009, 90001, 'Vodka com Energetico',  30.00, 600,  90005, 'bar', 15,  90001),
    (90010, 90001, 'Gin Tonico',            32.00, 400,  90004, 'bar', 10,  90001),
    (90011, 90001, 'Agua Mineral 500ml',     8.00, 8000, 90014, 'bar', 200, 90001),
    (90012, 90001, 'Refrigerante Lata',     10.00, 4000, 90014, 'bar', 100, 90001),
    (90013, 90001, 'Energetico',            18.00, 2000, 90014, 'bar', 50,  90001),
    (90014, 90001, 'Suco Natural',          15.00, 1000, 90013, 'bar', 30,  90001),
    (90015, 90001, 'Agua Tonica',           10.00, 1000, 90013, 'bar', 30,  90001),
    (90016, 90001, 'Cerveja Sem Alcool',    12.00, 500,  90001, 'bar', 20,  90001),
    (90017, 90001, 'Drink Especial Casa',   38.00, 300,  90019, 'bar', 10,  90001),
    (90018, 90001, 'Shot Tequila',          20.00, 400,  90005, 'bar', 10,  90001),
    (90019, 90001, 'Combo Balde 5 Cerv',    50.00, 500,  90001, 'bar', 15,  90001),
    (90020, 90001, 'Jarra Sangria',         45.00, 200,  90019, 'bar', 5,   90001),

    -- Food products (20)
    (90021, 90001, 'Hamburguer Artesanal',  32.00, 1000, 90006, 'food', 20, 90001),
    (90022, 90001, 'Hamburguer Duplo',      42.00, 800,  90006, 'food', 15, 90001),
    (90023, 90001, 'Pizza Fatia Margherita', 18.00, 1500, 90007, 'food', 30, 90001),
    (90024, 90001, 'Pizza Fatia Pepperoni', 20.00, 1200, 90007, 'food', 30, 90001),
    (90025, 90001, 'Acai 300ml',            22.00, 800,  90008, 'food', 20, 90001),
    (90026, 90001, 'Acai 500ml',            30.00, 600,  90008, 'food', 15, 90001),
    (90027, 90001, 'Espetinho Carne',       15.00, 2000, 90009, 'food', 50, 90001),
    (90028, 90001, 'Espetinho Frango',      12.00, 2000, 90009, 'food', 50, 90001),
    (90029, 90001, 'Pastel Carne',          12.00, 1500, 90010, 'food', 30, 90001),
    (90030, 90001, 'Pastel Queijo',         12.00, 1500, 90010, 'food', 30, 90001),
    (90031, 90001, 'Porcao Batata Frita',   20.00, 800,  90015, 'food', 20, 90001),
    (90032, 90001, 'Porcao Mandioca',       18.00, 600,  90015, 'food', 15, 90001),
    (90033, 90001, 'Crepe Nutella',         20.00, 500,  90020, 'food', 10, 90001),
    (90034, 90001, 'Crepe Romeu e Julieta', 18.00, 500,  90020, 'food', 10, 90001),
    (90035, 90001, 'Yakisoba',              28.00, 400,  90016, 'food', 10, 90001),
    (90036, 90001, 'Hotdog Completo',       16.00, 1000, 90016, 'food', 20, 90001),
    (90037, 90001, 'Sorvete 1 Bola',        12.00, 800,  90017, 'food', 20, 90001),
    (90038, 90001, 'Sorvete 2 Bolas',       18.00, 600,  90017, 'food', 15, 90001),
    (90039, 90001, 'Milho Cozido',           8.00, 500,  90015, 'food', 10, 90001),
    (90040, 90001, 'Pipoca Grande',         15.00, 800,  90016, 'food', 20, 90001),

    -- Shop products (10)
    (90041, 90001, 'Camiseta Oficial M',     89.90, 300, 90011, 'shop', 10, 90001),
    (90042, 90001, 'Camiseta Oficial G',     89.90, 300, 90011, 'shop', 10, 90001),
    (90043, 90001, 'Bone Oficial',           59.90, 200, 90011, 'shop', 10, 90001),
    (90044, 90001, 'Copo Personalizado',     25.00, 500, 90011, 'shop', 20, 90001),
    (90045, 90001, 'Poster Oficial',         35.00, 150, 90012, 'shop', 5,  90001),
    (90046, 90001, 'Ecobag Festival',        30.00, 300, 90012, 'shop', 10, 90001),
    (90047, 90001, 'Pulseira Neon',          15.00, 1000,90018, 'shop', 30, 90001),
    (90048, 90001, 'Oculos LED',             25.00, 500, 90018, 'shop', 15, 90001),
    (90049, 90001, 'Kit Festival (camiseta+bone)', 129.90, 100, 90011, 'shop', 5, 90001),
    (90050, 90001, 'Mochila Oficial',        120.00, 80, 90012, 'shop', 5, 90001)
ON CONFLICT ON CONSTRAINT uq_products_organizer_event_name DO NOTHING;


-- =============================================================================
-- 9. DIGITAL CARDS — 300 cashless cards with balance
-- =============================================================================

-- We use deterministic UUIDs based on a namespace to be idempotent
INSERT INTO digital_cards (id, user_id, balance, is_active, organizer_id)
SELECT
    ('a0000000-0000-4000-8000-' || LPAD(s::text, 12, '0'))::uuid,
    NULL,
    CASE
        WHEN s <= 100 THEN (50 + random() * 200)::numeric(10,2)
        WHEN s <= 250 THEN (20 + random() * 100)::numeric(10,2)
        ELSE 0.00
    END,
    CASE WHEN s <= 280 THEN true ELSE false END,
    90001
FROM generate_series(1, 300) AS s
ON CONFLICT (id) DO NOTHING;


-- =============================================================================
-- 10. EVENT CARD ASSIGNMENTS — link cards to the event
-- =============================================================================

INSERT INTO event_card_assignments (card_id, organizer_id, event_id, holder_name_snapshot,
                                     status, source_module, created_at)
SELECT
    ('a0000000-0000-4000-8000-' || LPAD(s::text, 12, '0'))::uuid,
    90001,
    90001,
    'Portador Cartao ' || s,
    CASE WHEN s <= 280 THEN 'active' ELSE 'inactive' END,
    'seed',
    CURRENT_TIMESTAMP
FROM generate_series(1, 300) AS s
ON CONFLICT DO NOTHING;


-- =============================================================================
-- 11. CARD TRANSACTIONS — 300 credits (recharges) + 300 debits (purchases)
-- =============================================================================

-- Credits (recharges)
INSERT INTO card_transactions (card_id, event_id, amount, balance_before, balance_after,
                               type, description, created_at)
SELECT
    ('a0000000-0000-4000-8000-' || LPAD(s::text, 12, '0'))::uuid,
    90001,
    (50 + (random() * 200))::numeric(10,2),
    0.00,
    (50 + (random() * 200))::numeric(10,2),
    'credit',
    'Recarga staging - cartao ' || s,
    CURRENT_TIMESTAMP - (random() * INTERVAL '7 days')
FROM generate_series(1, 300) AS s
ON CONFLICT DO NOTHING;

-- Debits (purchases) - only for cards with balance (first 250)
INSERT INTO card_transactions (card_id, event_id, amount, balance_before, balance_after,
                               type, description, created_at)
SELECT
    ('a0000000-0000-4000-8000-' || LPAD(s::text, 12, '0'))::uuid,
    90001,
    (10 + (random() * 50))::numeric(10,2),
    (80 + (random() * 150))::numeric(10,2),
    (20 + (random() * 100))::numeric(10,2),
    'debit',
    'Compra staging - cartao ' || s,
    CURRENT_TIMESTAMP - (random() * INTERVAL '3 days')
FROM generate_series(1, 300) AS s
ON CONFLICT DO NOTHING;


-- =============================================================================
-- 12. SALES + SALE_ITEMS — 500 completed sales
-- =============================================================================

-- Sales across different sectors and vendors
INSERT INTO sales (id, event_id, total_amount, status, sector, vendor_id, organizer_id,
                   operator_id, app_commission, vendor_payout, created_at)
SELECT
    90000 + s,
    90001,
    CASE
        WHEN s <= 200 THEN (12 + random() * 60)::numeric(10,2)   -- bar
        WHEN s <= 380 THEN (15 + random() * 50)::numeric(10,2)   -- food
        ELSE (25 + random() * 120)::numeric(10,2)                -- shop
    END AS total,
    'completed',
    CASE
        WHEN s <= 200 THEN 'bar'
        WHEN s <= 380 THEN 'food'
        ELSE 'shop'
    END,
    CASE
        WHEN s <= 200 THEN 90001 + (s % 8)     -- bar vendors 90001-90008
        WHEN s <= 380 THEN 90006 + (s % 12)    -- food vendors
        ELSE 90011 + (s % 2)                    -- shop vendors
    END,
    90001,
    90003 + (s % 10),  -- various operators
    -- commission = 1% of total
    CASE
        WHEN s <= 200 THEN ((12 + random() * 60) * 0.01)::numeric(10,2)
        WHEN s <= 380 THEN ((15 + random() * 50) * 0.01)::numeric(10,2)
        ELSE ((25 + random() * 120) * 0.01)::numeric(10,2)
    END,
    CASE
        WHEN s <= 200 THEN ((12 + random() * 60) * 0.99)::numeric(10,2)
        WHEN s <= 380 THEN ((15 + random() * 50) * 0.99)::numeric(10,2)
        ELSE ((25 + random() * 120) * 0.99)::numeric(10,2)
    END,
    CURRENT_TIMESTAMP - (random() * INTERVAL '5 days')
FROM generate_series(1, 500) AS s
ON CONFLICT (id) DO NOTHING;

-- One sale_item per sale (simplified: 1 product per sale)
INSERT INTO sale_items (id, sale_id, product_id, quantity, unit_price, subtotal)
SELECT
    90000 + s,
    90000 + s,
    CASE
        WHEN s <= 200 THEN 90001 + (s % 20)     -- bar products 90001-90020
        WHEN s <= 380 THEN 90021 + (s % 20)     -- food products 90021-90040
        ELSE 90041 + (s % 10)                    -- shop products 90041-90050
    END,
    1 + (s % 3),  -- qty 1-3
    CASE
        WHEN s <= 200 THEN (12 + random() * 30)::numeric(10,2)
        WHEN s <= 380 THEN (12 + random() * 25)::numeric(10,2)
        ELSE (25 + random() * 100)::numeric(10,2)
    END AS price,
    CASE
        WHEN s <= 200 THEN ((1 + (s % 3)) * (12 + random() * 30))::numeric(10,2)
        WHEN s <= 380 THEN ((1 + (s % 3)) * (12 + random() * 25))::numeric(10,2)
        ELSE ((1 + (s % 3)) * (25 + random() * 100))::numeric(10,2)
    END
FROM generate_series(1, 500) AS s
ON CONFLICT (id) DO NOTHING;


-- =============================================================================
-- 13. PARKING RECORDS — 100 records (60 parked, 40 exited)
-- =============================================================================

INSERT INTO parking_records (id, event_id, license_plate, vehicle_type, entry_at, exit_at,
                             status, fee_paid, entry_gate, organizer_id)
SELECT
    90000 + s,
    90001,
    'STG' || LPAD((1000 + s)::text, 4, '0') || chr(65 + (s % 26)),
    CASE WHEN s % 5 = 0 THEN 'motorcycle' WHEN s % 7 = 0 THEN 'van' ELSE 'car' END,
    CURRENT_TIMESTAMP - (random() * INTERVAL '3 days'),
    CASE
        WHEN s <= 60 THEN NULL  -- still parked
        ELSE CURRENT_TIMESTAMP - (random() * INTERVAL '1 day')
    END,
    CASE WHEN s <= 60 THEN 'parked' ELSE 'exited' END,
    CASE
        WHEN s % 5 = 0 THEN 15.00   -- motorcycle
        WHEN s % 7 = 0 THEN 40.00   -- van
        ELSE 30.00                    -- car
    END,
    'Portao ' || (1 + (s % 3)),
    90001
FROM generate_series(1, 100) AS s
ON CONFLICT (id) DO NOTHING;


-- =============================================================================
-- 14. PEOPLE — 220 people for workforce participants
-- =============================================================================

INSERT INTO people (id, name, email, document, phone, organizer_id)
SELECT
    90000 + s,
    'Colaborador ' || s,
    'colab' || s || '@staging.test',
    LPAD((10000000000 + s)::text, 11, '0'),
    '1199900' || LPAD(s::text, 4, '0'),
    90001
FROM generate_series(1, 220) AS s
ON CONFLICT (id) DO NOTHING;


-- =============================================================================
-- 15. PARTICIPANT CATEGORIES — workforce + guest
-- =============================================================================

INSERT INTO participant_categories (id, organizer_id, name, type) VALUES
    (90001, 90001, 'Equipe de Producao', 'workforce'),
    (90002, 90001, 'Convidado VIP',      'guest')
ON CONFLICT (id) DO NOTHING;


-- =============================================================================
-- 16. EVENT PARTICIPANTS — 200 workforce participants linked to people
-- =============================================================================

INSERT INTO event_participants (id, event_id, person_id, category_id, status,
                                qr_token, organizer_id)
SELECT
    90000 + s,
    90001,
    90000 + s,  -- matches people.id
    90001,      -- workforce category
    CASE WHEN s <= 180 THEN 'confirmed' ELSE 'expected' END,
    'QR-PART-STG-' || LPAD(s::text, 5, '0') || '-' || md5('participant' || s::text),
    90001
FROM generate_series(1, 200) AS s
ON CONFLICT (id) DO NOTHING;


-- =============================================================================
-- 17. WORKFORCE ROLES — 5 operational roles
-- =============================================================================

INSERT INTO workforce_roles (id, organizer_id, name, sector) VALUES
    (90001, 90001, 'Barman',              'bar'),
    (90002, 90001, 'Cozinheiro',          'food'),
    (90003, 90001, 'Porteiro/Seguranca',  'portaria'),
    (90004, 90001, 'Manobrista',          'estacionamento'),
    (90005, 90001, 'Auxiliar de Limpeza', 'limpeza')
ON CONFLICT (id) DO NOTHING;


-- =============================================================================
-- 18. WORKFORCE EVENT ROLES — 5 roles scoped to the event
-- =============================================================================

INSERT INTO workforce_event_roles (id, organizer_id, event_id, role_id, sector, role_class,
                                    authority_level, cost_bucket, max_shifts_event,
                                    shift_hours, meals_per_day, payment_amount, sort_order, is_active)
VALUES
    (90001, 90001, 90001, 90001, 'bar',            'operational', 'none', 'operational', 3, 8.00, 3, 150.00, 1, true),
    (90002, 90001, 90001, 90002, 'food',           'operational', 'none', 'operational', 3, 8.00, 3, 150.00, 2, true),
    (90003, 90001, 90001, 90003, 'portaria',       'operational', 'none', 'operational', 3, 8.00, 3, 120.00, 3, true),
    (90004, 90001, 90001, 90004, 'estacionamento', 'operational', 'none', 'operational', 3, 8.00, 3, 120.00, 4, true),
    (90005, 90001, 90001, 90005, 'limpeza',        'operational', 'none', 'operational', 3, 8.00, 3, 100.00, 5, true)
ON CONFLICT (id) DO NOTHING;


-- =============================================================================
-- 19. WORKFORCE ASSIGNMENTS — 200 people across 5 sectors
-- =============================================================================
-- Distribution: 60 bar, 50 food, 40 portaria, 25 estacionamento, 25 limpeza

INSERT INTO workforce_assignments (id, participant_id, role_id, sector, event_shift_id,
                                    event_role_id, organizer_id)
SELECT
    90000 + s,
    90000 + s,  -- matches event_participants.id
    CASE
        WHEN s <= 60  THEN 90001  -- bar
        WHEN s <= 110 THEN 90002  -- food
        WHEN s <= 150 THEN 90003  -- portaria
        WHEN s <= 175 THEN 90004  -- estacionamento
        ELSE 90005                -- limpeza
    END,
    CASE
        WHEN s <= 60  THEN 'bar'
        WHEN s <= 110 THEN 'food'
        WHEN s <= 150 THEN 'portaria'
        WHEN s <= 175 THEN 'estacionamento'
        ELSE 'limpeza'
    END,
    90001 + (s % 6),  -- rotate across all 6 shifts
    CASE
        WHEN s <= 60  THEN 90001
        WHEN s <= 110 THEN 90002
        WHEN s <= 150 THEN 90003
        WHEN s <= 175 THEN 90004
        ELSE 90005
    END,
    90001
FROM generate_series(1, 200) AS s
ON CONFLICT (id) DO NOTHING;


-- =============================================================================
-- 20. GUESTS — 50 VIP guests
-- =============================================================================

INSERT INTO guests (id, organizer_id, event_id, name, email, phone, document, status, qr_code_token)
SELECT
    90000 + s,
    90001,
    90001,
    'Convidado VIP ' || s,
    'convidado' || s || '@staging.test',
    '1198800' || LPAD(s::text, 4, '0'),
    LPAD((20000000000 + s)::text, 11, '0'),
    CASE WHEN s <= 30 THEN 'confirmado' WHEN s <= 45 THEN 'esperado' ELSE 'cancelado' END,
    'GUEST-STG-' || LPAD(s::text, 5, '0') || '-' || md5('guest' || s::text)
FROM generate_series(1, 50) AS s
ON CONFLICT (qr_code_token) DO NOTHING;


-- =============================================================================
-- 21. EVENT MEAL SERVICES — 3 meals per day x 3 days = 9 services
-- =============================================================================

INSERT INTO event_meal_services (id, event_id, service_code, label, sort_order, starts_at, ends_at,
                                  unit_cost, is_active, organizer_id) VALUES
    -- Day 1 meals (the service is event-scoped, not day-scoped, but we create 3 services total)
    (90001, 90001, 'breakfast',       'Cafe da Manha',  1, '06:00', '09:00', 15.00, true, 90001),
    (90002, 90001, 'lunch',           'Almoco',         2, '11:30', '14:00', 25.00, true, 90001),
    (90003, 90001, 'dinner',          'Jantar',         3, '18:00', '21:00', 30.00, true, 90001)
ON CONFLICT ON CONSTRAINT uq_ems_event_service_code DO NOTHING;


-- =============================================================================
-- 22. PARTICIPANT MEALS — 400 meal records across workforce
-- =============================================================================
-- Each of the 200 participants eats ~2 meals on average, spread across days

-- Day 1 breakfast (first 80 participants)
INSERT INTO participant_meals (participant_id, event_day_id, meal_service_id, unit_cost_applied, organizer_id, consumed_at)
SELECT
    90000 + s,
    90001,        -- day 1
    90001,        -- breakfast
    15.00,
    90001,
    (CURRENT_DATE + INTERVAL '14 days')::timestamp + TIME '07:30' + (random() * INTERVAL '1 hour')
FROM generate_series(1, 80) AS s
ON CONFLICT DO NOTHING;

-- Day 1 lunch (first 120 participants)
INSERT INTO participant_meals (participant_id, event_day_id, meal_service_id, unit_cost_applied, organizer_id, consumed_at)
SELECT
    90000 + s,
    90001,
    90002,        -- lunch
    25.00,
    90001,
    (CURRENT_DATE + INTERVAL '14 days')::timestamp + TIME '12:00' + (random() * INTERVAL '2 hours')
FROM generate_series(1, 120) AS s
ON CONFLICT DO NOTHING;

-- Day 2 lunch (participants 50-170)
INSERT INTO participant_meals (participant_id, event_day_id, meal_service_id, unit_cost_applied, organizer_id, consumed_at)
SELECT
    90050 + s,
    90002,        -- day 2
    90002,        -- lunch
    25.00,
    90001,
    (CURRENT_DATE + INTERVAL '15 days')::timestamp + TIME '12:00' + (random() * INTERVAL '2 hours')
FROM generate_series(1, 120) AS s
ON CONFLICT DO NOTHING;

-- Day 3 dinner (participants 1-80)
INSERT INTO participant_meals (participant_id, event_day_id, meal_service_id, unit_cost_applied, organizer_id, consumed_at)
SELECT
    90000 + s,
    90003,        -- day 3
    90003,        -- dinner
    30.00,
    90001,
    (CURRENT_DATE + INTERVAL '16 days')::timestamp + TIME '19:00' + (random() * INTERVAL '2 hours')
FROM generate_series(1, 80) AS s
ON CONFLICT DO NOTHING;


-- =============================================================================
-- 23. ORGANIZER SETTINGS — branding for white label
-- =============================================================================

INSERT INTO organizer_settings (organizer_id, primary_color, secondary_color, logo_url, subdomain)
VALUES (
    90001,
    '#FF6B00',
    '#1A1A2E',
    'https://staging.enjoyfun.com/logo-staging.png',
    'staging'
)
ON CONFLICT (organizer_id) DO NOTHING;


-- =============================================================================
-- VERIFICATION COUNTS (run after seed to verify)
-- =============================================================================
-- Uncomment below to verify counts:
--
-- SELECT 'users' AS tbl, count(*) FROM users WHERE organizer_id = 90001
-- UNION ALL SELECT 'events', count(*) FROM events WHERE organizer_id = 90001
-- UNION ALL SELECT 'event_days', count(*) FROM event_days WHERE organizer_id = 90001
-- UNION ALL SELECT 'event_shifts', count(*) FROM event_shifts WHERE organizer_id = 90001
-- UNION ALL SELECT 'ticket_types', count(*) FROM ticket_types WHERE organizer_id = 90001
-- UNION ALL SELECT 'tickets', count(*) FROM tickets WHERE organizer_id = 90001
-- UNION ALL SELECT 'vendors', count(*) FROM vendors WHERE organizer_id = 90001
-- UNION ALL SELECT 'products', count(*) FROM products WHERE organizer_id = 90001
-- UNION ALL SELECT 'digital_cards', count(*) FROM digital_cards WHERE organizer_id = 90001
-- UNION ALL SELECT 'event_card_assignments', count(*) FROM event_card_assignments WHERE organizer_id = 90001
-- UNION ALL SELECT 'card_transactions', count(*) FROM card_transactions WHERE event_id = 90001
-- UNION ALL SELECT 'sales', count(*) FROM sales WHERE organizer_id = 90001
-- UNION ALL SELECT 'sale_items', count(*) FROM sale_items WHERE sale_id BETWEEN 90001 AND 90500
-- UNION ALL SELECT 'parking_records', count(*) FROM parking_records WHERE organizer_id = 90001
-- UNION ALL SELECT 'people', count(*) FROM people WHERE organizer_id = 90001
-- UNION ALL SELECT 'participant_categories', count(*) FROM participant_categories WHERE organizer_id = 90001
-- UNION ALL SELECT 'event_participants', count(*) FROM event_participants WHERE organizer_id = 90001
-- UNION ALL SELECT 'workforce_roles', count(*) FROM workforce_roles WHERE organizer_id = 90001
-- UNION ALL SELECT 'workforce_event_roles', count(*) FROM workforce_event_roles WHERE organizer_id = 90001
-- UNION ALL SELECT 'workforce_assignments', count(*) FROM workforce_assignments WHERE organizer_id = 90001
-- UNION ALL SELECT 'guests', count(*) FROM guests WHERE organizer_id = 90001
-- UNION ALL SELECT 'event_meal_services', count(*) FROM event_meal_services WHERE organizer_id = 90001
-- UNION ALL SELECT 'participant_meals', count(*) FROM participant_meals WHERE organizer_id = 90001
-- UNION ALL SELECT 'organizer_settings', count(*) FROM organizer_settings WHERE organizer_id = 90001
-- ORDER BY tbl;

COMMIT;
