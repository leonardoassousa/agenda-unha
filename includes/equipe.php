<?php
// Login de cada colaboradora (celular + senha criada pela dona no painel).
// A mesma sessão vale pra área da equipe e pro painel: quem tem "acesso ao painel" (a dona)
// entra uma vez só e usa os dois.
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/sessao.php';

session_set_cookie_params(['lifetime' => 30 * 86400, 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
ini_set('session.gc_maxlifetime', (string) (30 * 86400));
session_name('agenda_equipe');
session_start();
prepararCsrf();
garantirColunaPainel();

/** Banco de antes da versão 1.2: cria a coluna de acesso ao painel sozinho (mesmo que o atualizar-v8.sql). */
function garantirColunaPainel(): void
{
    if (db()->query("SHOW COLUMNS FROM profissionais LIKE 'admin'")->fetch()) return;
    db()->exec('ALTER TABLE profissionais ADD COLUMN admin TINYINT(1) NOT NULL DEFAULT 0 AFTER senha_hash');
}

/** Colaboradora logada (ou null). */
function colaboradora(): ?array
{
    static $c = false;
    if ($c !== false) return $c;
    $id = (int) ($_SESSION['prof_id'] ?? 0);
    if (!$id) return $c = null;
    $st = db()->prepare('SELECT id, nome, cor, foto, telefone, admin FROM profissionais WHERE id = ? AND ativo = 1 AND senha_hash IS NOT NULL');
    $st->execute([$id]);
    $c = $st->fetch() ?: null;
    if (!$c) unset($_SESSION['prof_id']);
    return $c;
}

function exigirColaboradora(): array
{
    $c = colaboradora();
    if (!$c) { header('Location: ./'); exit; }
    return $c;
}

/** Retorna '' se entrou, ou a mensagem de erro. */
function entrarEquipe(string $telefone, string $senha): string
{
    $tel = normalizarTelefone($telefone);
    if ($tel === '' || $senha === '') return 'Digite seu celular com DDD e a senha.';
    $chave = 'eq:' . $tel;
    db()->exec('DELETE FROM login_tentativas WHERE criado_em < NOW() - INTERVAL 1 DAY');
    $st = db()->prepare('SELECT COUNT(*) FROM login_tentativas WHERE chave = ? AND criado_em > NOW() - INTERVAL 15 MINUTE');
    $st->execute([$chave]);
    if ((int) $st->fetchColumn() >= 5) return 'Muitas tentativas erradas. Espere 15 minutos ou peça uma senha nova pra dona do estúdio.';

    $st = db()->prepare('SELECT id, senha_hash FROM profissionais WHERE telefone = ? AND ativo = 1');
    $st->execute([$tel]);
    $p = $st->fetch();
    if (!$p || !$p['senha_hash'] || !password_verify($senha, $p['senha_hash'])) {
        db()->prepare('INSERT INTO login_tentativas (chave) VALUES (?)')->execute([$chave]);
        sleep(1);
        return 'Celular ou senha incorretos. Se esqueceu, peça uma senha nova pra dona do estúdio.';
    }
    session_regenerate_id(true);
    $_SESSION['prof_id'] = (int) $p['id'];
    return '';
}

/** Troca a senha da própria colaboradora. Retorna a mensagem pra mostrar. */
function trocarSenhaPropria(int $id, string $atual, string $nova): string
{
    $st = db()->prepare('SELECT senha_hash FROM profissionais WHERE id = ?');
    $st->execute([$id]);
    if (!password_verify($atual, (string) $st->fetchColumn())) return 'A senha atual está errada.';
    if (mb_strlen($nova) < 6) return 'A nova senha precisa ter pelo menos 6 caracteres.';
    db()->prepare('UPDATE profissionais SET senha_hash = ? WHERE id = ?')->execute([password_hash($nova, PASSWORD_DEFAULT), $id]);
    session_regenerate_id(true);
    return 'Senha trocada.';
}

function fotoColab(array $p, string $classe = ''): string
{
    return $p['foto']
        ? '<img class="foto ' . $classe . '" src="../' . e($p['foto']) . '" alt="" style="--cor:' . e($p['cor']) . '">'
        : '<span class="foto ' . $classe . '" style="--cor:' . e($p['cor']) . '" aria-hidden="true">' . e(iniciais($p['nome'])) . '</span>';
}

function headEquipe(string $titulo): void
{
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#3A0E1E">
<title><?= e($titulo) ?> · Equipe · <?= e(cfg('nome_estudio')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Gloock&family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/style.css">
<link rel="stylesheet" href="../assets/admin.css">
</head>
<body class="equipe">
<main class="pagina admin">
    <?php
}

function cabecalhoEquipe(string $ativa, array $c): void
{
    $abas = ['index.php' => 'Minha agenda', 'disponibilidade.php' => 'Meus horários', 'caixa.php' => 'Meu caixa'];
    ?>
  <header class="admin-topo topo-equipe" style="--cor: <?= e($c['cor']) ?>">
    <div class="equipe-topo">
      <?= fotoColab($c) ?>
      <div>
        <p class="equipe-ola">Olá, <?= e(preg_split('/\s+/', $c['nome'])[0]) ?></p>
        <p class="equipe-estudio"><?= e(cfg('nome_estudio')) ?></p>
      </div>
      <div class="equipe-acoes">
        <form method="post" action="./" class="equipe-sair">
          <?= campoCsrf() ?>
          <button class="link" name="acao" value="sair">Sair</button>
        </form>
      </div>
    </div>
    <div class="lateral-marca">
      <p class="lateral-estudio"><?= e(cfg('nome_estudio')) ?></p>
      <p class="lateral-sub">Área da equipe</p>
    </div>
    <nav class="abas abas-equipe" aria-label="Área da equipe">
      <?php foreach ($abas as $arq => $rotulo): ?>
        <a href="<?= $arq === 'index.php' ? './' : $arq ?>" <?= $ativa === $arq ? 'aria-current="page"' : '' ?>><?= $rotulo ?></a>
      <?php endforeach; ?>
      <?php if (!empty($c['admin'])): ?><a class="aba-outra" href="../admin/">Painel do estúdio</a><?php endif; ?>
    </nav>
    <div class="lateral-conta">
      <p class="lateral-nome"><?= fotoColab($c) ?><span><?= e($c['nome']) ?></span></p>
      <form method="post" action="./">
        <?= campoCsrf() ?>
        <button class="link lateral-link" name="acao" value="sair">Sair</button>
      </form>
    </div>
  </header>
    <?php
}
