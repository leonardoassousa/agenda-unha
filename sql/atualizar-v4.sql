-- Contas de cliente. Rode depois do atualizar-v3.sql (se ainda não rodou).
CREATE TABLE IF NOT EXISTS clientes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(100) NOT NULL,
  telefone VARCHAR(11) NOT NULL,
  email VARCHAR(150) NOT NULL,
  senha_hash VARCHAR(255) NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_login DATETIME NULL,
  UNIQUE KEY uq_cli_tel (telefone),
  UNIQUE KEY uq_cli_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_tentativas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chave VARCHAR(64) NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lt (chave, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE agendamentos
  ADD COLUMN cliente_id INT UNSIGNED NULL AFTER servico_id,
  ADD CONSTRAINT fk_ag_cli FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL;
