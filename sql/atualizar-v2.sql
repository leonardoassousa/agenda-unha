-- Só pra quem já importou a versão 1: adiciona a coluna da foto
ALTER TABLE profissionais ADD COLUMN foto VARCHAR(255) NULL AFTER cor;
