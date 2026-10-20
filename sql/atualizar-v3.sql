-- Só pra quem já tinha a versão 1 ou 2 instalada.
-- (Se veio da versão 1, rode antes o atualizar-v2.sql.)
ALTER TABLE agendamentos
  ADD COLUMN preco DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER observacao,
  MODIFY status ENUM('confirmado','cancelado','faltou') NOT NULL DEFAULT 'confirmado',
  ADD COLUMN aviso ENUM('pendente','enviado','falhou','desligado') NOT NULL DEFAULT 'pendente' AFTER status,
  ADD KEY idx_inicio (inicio),
  ADD KEY idx_tel (cliente_telefone, inicio);

-- Preenche o valor dos agendamentos antigos com o preço atual do serviço
UPDATE agendamentos a JOIN servicos s ON s.id = a.servico_id SET a.preco = s.preco WHERE a.preco = 0;
