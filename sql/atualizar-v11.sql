-- Versão 1.9: marcação pelo painel, horário fixo semanal e ficha de cliente sem e-mail.
-- Não precisa rodar: o sistema faz isso sozinho na primeira vez que o painel abre.
ALTER TABLE agendamentos
  ADD COLUMN origem ENUM('site','painel') NOT NULL DEFAULT 'site' AFTER grupo,
  ADD COLUMN serie INT UNSIGNED NULL AFTER origem,
  ADD KEY idx_serie (serie);
CREATE TABLE IF NOT EXISTS series (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dia_semana TINYINT UNSIGNED NOT NULL,
  hora TIME NOT NULL,
  ate DATE NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE clientes MODIFY email VARCHAR(150) NULL;
REPLACE INTO sistema (chave, valor) VALUES ('estrutura', '1.9');
