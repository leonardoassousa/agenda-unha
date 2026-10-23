-- Versão 6: dados reais do Studio Carina Aquino (só pra quem já tinha instalado com os dados de exemplo).
-- Troca os nomes das 3 profissionais, coloca os dias de cada uma e a tabela de procedimentos.

-- Studio Carina Aquino
UPDATE profissionais SET nome = 'Carina Aquino', ordem = 1 WHERE id = 1;
UPDATE profissionais SET nome = 'Victoria Aquino', ordem = 2 WHERE id = 2;
UPDATE profissionais SET nome = 'Ana Oliveira', ordem = 3 WHERE id = 3;

-- Dias de cada uma (0 = domingo ... 6 = sábado). Cada uma pode mudar na área da equipe.
-- Carina: terça a sábado, 9h às 18h. Victoria: só sábado. Ana: quinta a sábado, 9h às 17h.
DELETE FROM disponibilidade_semana WHERE profissional_id IN (1, 2, 3);
INSERT INTO disponibilidade_semana (profissional_id, dia_semana, inicio, fim) VALUES
  (1, 2, '09:00', '18:00'), (1, 3, '09:00', '18:00'), (1, 4, '09:00', '18:00'), (1, 5, '09:00', '18:00'), (1, 6, '09:00', '18:00'),
  (2, 6, '09:00', '18:00'),
  (3, 4, '09:00', '17:00'), (3, 5, '09:00', '17:00'), (3, 6, '09:00', '17:00');

-- Os serviços antigos ficam inativos (o histórico de agendamentos continua certo).
UPDATE servicos SET ativo = 0;

-- Procedimentos e valores. A duração (min) define quanto tempo o horário ocupa na agenda.
INSERT INTO servicos (nome, descricao, duracao_min, preco, ordem) VALUES
  ('Pé e mão',                 'Os dois no mesmo horário',                       120,  55.00,  1),
  ('Pé',                       'Cutilagem e esmaltação',                         60,  35.00,  2),
  ('Mão',                      'Cutilagem e esmaltação',                         60,  35.00,  3),
  ('Esmaltação convencional',  'Só a esmaltação',                                10,   5.00,  4),
  ('Spa dos pés',              'Esfoliação, hidratação e cuidado completo',      60, 100.00,  5),
  ('Massagem nos pés',         '15 minutos de massagem relaxante',               15,  20.00,  6),
  ('Alongamento',              'Aplicação completa',                            120, 200.00,  7),
  ('Banho em gel',             'Gel sobre a unha natural',                       90, 150.00,  8),
  ('Esmaltação em gel',        'Dura até 3 semanas',                             60,  75.00,  9),
  ('Manutenção natural',       'Manutenção do banho em gel',                     90, 115.00, 10),
  ('Manutenção encapsulada',   'Com decoração. A partir de R$ 135, depende do caso', 120, 135.00, 11);
