-- Script de Seed Opcional (Produtos Mocks e Cartão Cashless)
-- Pode rodar este script para popular a base e testar o POS.jsx e a baixa de saldo

-- Inserindo Produtos no evento 1
INSERT INTO products (event_id, name, price, stock_qty) VALUES 
(1, 'Água Mineral 500ml', 5.00, 100),
(1, 'Cerveja Pilsen 350ml', 12.00, 200),
(1, 'Refrigerante Lata', 8.00, 150),
(1, 'Energético 250ml', 15.00, 80),
(1, 'Combo Vodka', 45.00, 30),
(1, 'Porção Fritas', 25.00, 50);

-- Criando um Cartão Digital para Teste (Token: 12345) com saldo R$ 150,00
INSERT INTO digital_cards (event_id, card_token, qr_token, balance, status, is_anonymous)
VALUES (1, '12345', 'qr-12345-demo', 150.00, 'active', true);

-- Registrando o histórico dessa recarga
INSERT INTO card_transactions (card_id, event_id, amount, balance_before, balance_after, type, description)
SELECT id, 1, 150.00, 0.00, 150.00, 'credit', 'Carga Inicial (Seed)' 
FROM digital_cards WHERE card_token = '12345';
