-- Versão 1.5: categoria dos serviços (grupos na tela da cliente).
-- Não precisa rodar: o sistema cria a coluna e preenche sozinho na primeira vez que a tela da cliente abre.
ALTER TABLE servicos ADD COLUMN categoria VARCHAR(40) NULL AFTER descricao;
UPDATE servicos SET categoria = 'Mãos e pés' WHERE nome IN ('Pé e mão', 'Pé e mão com duas manicures', 'Pé', 'Mão', 'Esmaltação convencional');
UPDATE servicos SET categoria = 'Gel e alongamento' WHERE nome IN ('Alongamento', 'Banho em gel', 'Esmaltação em gel', 'Manutenção natural', 'Manutenção encapsulada');
UPDATE servicos SET categoria = 'Spa e relaxamento' WHERE nome IN ('Spa dos pés', 'Massagem nos pés');
