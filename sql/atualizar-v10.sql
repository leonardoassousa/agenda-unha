-- Versão 1.8: link de "esqueci a senha" por e-mail.
-- Não precisa rodar: o sistema cria as colunas sozinho na primeira vez que alguém usa "Esqueci a senha".
ALTER TABLE clientes
  ADD COLUMN reset_hash CHAR(64) NULL AFTER senha_hash,
  ADD COLUMN reset_expira DATETIME NULL AFTER reset_hash;
