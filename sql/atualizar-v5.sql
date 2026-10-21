-- Área da equipe, concluir serviço, valor a mais, lembrete da cliente e disponibilidade.
-- Rode depois do atualizar-v4.sql.
ALTER TABLE profissionais
  ADD COLUMN telefone VARCHAR(11) NULL AFTER foto,
  ADD COLUMN senha_hash VARCHAR(255) NULL AFTER telefone,
  ADD UNIQUE KEY uq_prof_tel (telefone);

ALTER TABLE agendamentos
  ADD COLUMN extra_valor DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER preco,
  ADD COLUMN extra_obs VARCHAR(255) NULL AFTER extra_valor,
  MODIFY status ENUM('confirmado','concluido','cancelado','faltou') NOT NULL DEFAULT 'confirmado',
  ADD COLUMN concluido_em DATETIME NULL AFTER status,
  ADD COLUMN lembrete ENUM('pendente','enviado','falhou','pulado') NOT NULL DEFAULT 'pendente' AFTER aviso;

-- Horários que já passaram não recebem lembrete
UPDATE agendamentos SET lembrete = 'pulado' WHERE inicio < NOW();

CREATE TABLE IF NOT EXISTS disponibilidade_semana (
  profissional_id INT UNSIGNED NOT NULL,
  dia_semana TINYINT UNSIGNED NOT NULL,
  inicio TIME NOT NULL,
  fim TIME NOT NULL,
  pausa_inicio TIME NULL,
  pausa_fim TIME NULL,
  PRIMARY KEY (profissional_id, dia_semana),
  CONSTRAINT fk_ds_prof FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS disponibilidade_datas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  profissional_id INT UNSIGNED NOT NULL,
  data DATE NOT NULL,
  trabalha TINYINT(1) NOT NULL DEFAULT 0,
  inicio TIME NULL,
  fim TIME NULL,
  UNIQUE KEY uq_dd (profissional_id, data),
  CONSTRAINT fk_dd_prof FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sistema (
  chave VARCHAR(50) PRIMARY KEY,
  valor VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
