-- Agenda de unhas: estrutura do banco (MySQL 5.7+ / MariaDB 10.3+)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS profissionais (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(80) NOT NULL,
  cor CHAR(7) NOT NULL DEFAULT '#9B1B3A',   -- cor dela na agenda
  foto VARCHAR(255) NULL,                     -- caminho da foto (enviada pelo painel)
  telefone VARCHAR(11) NULL,                  -- login da colaboradora na área da equipe
  senha_hash VARCHAR(255) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  ordem INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_prof_tel (telefone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS servicos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(100) NOT NULL,
  descricao VARCHAR(255) NULL,
  duracao_min SMALLINT UNSIGNED NOT NULL,
  preco DECIMAL(8,2) NOT NULL DEFAULT 0,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  ordem INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Contas das clientes (login com celular + senha)
CREATE TABLE IF NOT EXISTS clientes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(100) NOT NULL,
  telefone VARCHAR(11) NOT NULL,            -- só DDD + número
  email VARCHAR(150) NOT NULL,
  senha_hash VARCHAR(255) NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_login DATETIME NULL,
  UNIQUE KEY uq_cli_tel (telefone),
  UNIQUE KEY uq_cli_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tentativas de login erradas (trava por 15 min depois de 5 erros)
CREATE TABLE IF NOT EXISTS login_tentativas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chave VARCHAR(64) NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lt (chave, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS agendamentos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  profissional_id INT UNSIGNED NOT NULL,
  servico_id INT UNSIGNED NOT NULL,
  cliente_id INT UNSIGNED NULL,
  inicio DATETIME NOT NULL,
  fim DATETIME NOT NULL,
  cliente_nome VARCHAR(100) NOT NULL,
  cliente_telefone VARCHAR(20) NOT NULL,
  observacao VARCHAR(255) NULL,
  preco DECIMAL(8,2) NOT NULL DEFAULT 0,     -- valor do serviço no dia em que marcou
  extra_valor DECIMAL(8,2) NOT NULL DEFAULT 0, -- valor a mais lançado pela colaboradora
  extra_obs VARCHAR(255) NULL,                 -- o que foi feito a mais
  status ENUM('confirmado','concluido','cancelado','faltou') NOT NULL DEFAULT 'confirmado',
  concluido_em DATETIME NULL,
  aviso ENUM('pendente','enviado','falhou','desligado') NOT NULL DEFAULT 'pendente', -- aviso no WhatsApp do estúdio
  lembrete ENUM('pendente','enviado','falhou','pulado') NOT NULL DEFAULT 'pendente', -- lembrete no WhatsApp da cliente
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_prof_inicio (profissional_id, inicio),
  KEY idx_inicio (inicio),
  KEY idx_tel (cliente_telefone, inicio),
  CONSTRAINT fk_ag_prof FOREIGN KEY (profissional_id) REFERENCES profissionais(id),
  CONSTRAINT fk_ag_serv FOREIGN KEY (servico_id) REFERENCES servicos(id),
  CONSTRAINT fk_ag_cli FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Semana de trabalho de cada colaboradora (sem linha no dia = não trabalha nesse dia).
-- Se ela não tiver nenhuma linha, vale o horário do estúdio do config.php.
CREATE TABLE IF NOT EXISTS disponibilidade_semana (
  profissional_id INT UNSIGNED NOT NULL,
  dia_semana TINYINT UNSIGNED NOT NULL,       -- 0 = domingo ... 6 = sábado
  inicio TIME NOT NULL,
  fim TIME NOT NULL,
  pausa_inicio TIME NULL,
  pausa_fim TIME NULL,
  PRIMARY KEY (profissional_id, dia_semana),
  CONSTRAINT fk_ds_prof FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dias específicos: folga ou horário diferente naquela data (vale mais que a semana)
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

-- Controles internos (ex.: última vez que rodou o lembrete)
CREATE TABLE IF NOT EXISTS sistema (
  chave VARCHAR(50) PRIMARY KEY,
  valor VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Folgas, consultas, horário de almoço diferente etc.
-- profissional_id NULL = bloqueia o salão inteiro
CREATE TABLE IF NOT EXISTS bloqueios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  profissional_id INT UNSIGNED NULL,
  inicio DATETIME NOT NULL,
  fim DATETIME NOT NULL,
  motivo VARCHAR(120) NULL,
  KEY idx_bl_inicio (inicio),
  CONSTRAINT fk_bl_prof FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dados de exemplo: troque pelos nomes, serviços e preços reais
INSERT INTO profissionais (nome, cor, ordem) VALUES
  ('Ana',     '#9B1B3A', 1),
  ('Bruna',   '#C2577A', 2),
  ('Camila',  '#6E3B5C', 3);

INSERT INTO servicos (nome, descricao, duracao_min, preco, ordem) VALUES
  ('Mão',                    'Cutilagem e esmaltação',            45, 25.00, 1),
  ('Pé',                     'Cutilagem e esmaltação',            45, 30.00, 2),
  ('Pé e mão',               'Os dois no mesmo horário',          90, 50.00, 3),
  ('Esmaltação em gel',      'Dura até 3 semanas',                60, 60.00, 4),
  ('Alongamento em fibra',   'Aplicação completa',               120, 130.00, 5),
  ('Manutenção',             'Manutenção de alongamento',         90, 80.00, 6);
