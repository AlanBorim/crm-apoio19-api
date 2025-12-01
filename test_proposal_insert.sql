-- INSERT de teste simplificado para propostas
-- Como agora só precisamos do lead_id

-- 1. Verificar leads disponíveis
SELECT id, name, company, email FROM leads WHERE active = '1' LIMIT 10;

-- 2. Verificar usuários disponíveis (para responsavel_id)
SELECT id, name, email FROM users WHERE active = '1' LIMIT 10;

-- 3. INSERT de teste simples (ajuste os IDs conforme seu banco)
INSERT INTO proposals (
    titulo, 
    lead_id, 
    contact_id,
    company_id,
    responsavel_id, 
    descricao, 
    condicoes, 
    valor_total, 
    status, 
    data_validade, 
    modelo_id
) VALUES (
    'Proposta Comercial - Teste',  -- titulo
    1,                              -- lead_id (OBRIGATÓRIO - ajuste conforme SELECT acima)
    NULL,                           -- contact_id (OPCIONAL - pode ser NULL)
    NULL,                           -- company_id (OPCIONAL - pode ser NULL)
    1,                              -- responsavel_id (ajuste conforme SELECT acima)
    'Descrição detalhada da proposta de teste', -- descricao
    'Prazo: 30 dias\nPagamento: À vista', -- condicoes
    5000.00,                        -- valor_total
    'rascunho',                     -- status
    '2025-12-31',                   -- data_validade
    NULL                            -- modelo_id (OPCIONAL - NULL até criar templates)
);

-- 4. Verificar se foi inserido
SELECT p.*, l.name as lead_name, u.name as responsavel_name
FROM proposals p
LEFT JOIN leads l ON p.lead_id = l.id
LEFT JOIN users u ON p.responsavel_id = u.id
ORDER BY p.id DESC LIMIT 1;

-- 5. Inserir items de teste para a proposta criada
-- (Substitua 999 pelo ID retornado acima)
INSERT INTO proposta_itens (proposal_id, description, quantity, unit_price, total_price) VALUES
(999, 'Item 1 - Serviço de Consultoria', 10, 500.00, 5000.00),
(999, 'Item 2 - Suporte Técnico', 5, 300.00, 1500.00);

-- 6. Verificar items inseridos
SELECT * FROM proposta_itens WHERE proposal_id = 999;
