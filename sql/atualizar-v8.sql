-- Versão 8 (sistema 1.2): cada uma com a própria conta, e a dona entra no painel com o mesmo celular e senha.
-- O sistema já cria essa coluna sozinho na primeira vez que alguém abre o painel ou a área da equipe;
-- este arquivo é só pra quem preferir rodar pelo phpMyAdmin.
ALTER TABLE profissionais ADD COLUMN admin TINYINT(1) NOT NULL DEFAULT 0 AFTER senha_hash;
