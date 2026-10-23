-- Versão 7: pé e mão com duas manicures ao mesmo tempo.
ALTER TABLE servicos ADD COLUMN dupla TINYINT(1) NOT NULL DEFAULT 0 AFTER ativo;
ALTER TABLE agendamentos ADD COLUMN grupo INT UNSIGNED NULL AFTER lembrete, ADD KEY idx_grupo (grupo);

UPDATE servicos SET descricao = 'Com uma manicure só' WHERE nome = 'Pé e mão' AND ativo = 1;
INSERT INTO servicos (nome, descricao, duracao_min, preco, ordem, dupla) VALUES
  ('Pé e mão com duas manicures', 'Uma faz o pé e a outra a mão, ao mesmo tempo', 60, 55.00, 1, 1);
