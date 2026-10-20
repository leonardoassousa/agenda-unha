<?php
// Sessão e cabeçalho comuns às páginas do painel.
require_once __DIR__ . '/app.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_name('agenda_admin');
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

function logada(): bool
{
    return !empty($_SESSION['admin']);
}

function exigirLogin(): void
{
    if (!logada()) { header('Location: ./'); exit; }
}

function conferirCsrf(): void
{
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Sessão expirou. Volte e tente de novo.');
    }
}

function campoCsrf(): string
{
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}

function cabecalhoAdmin(string $ativa): void
{
    $abas = ['index.php' => 'Agenda', 'resumo.php' => 'Resumo', 'clientes.php' => 'Clientes'];
    ?>
  <header class="admin-topo">
    <nav class="abas" aria-label="Painel">
      <?php foreach ($abas as $arq => $rotulo): ?>
        <a href="<?= $arq === 'index.php' ? './' : $arq ?>" <?= $ativa === $arq ? 'aria-current="page"' : '' ?>><?= $rotulo ?></a>
      <?php endforeach; ?>
    </nav>
    <form method="post" action="./">
      <?= campoCsrf() ?>
      <button class="link" name="acao" value="sair">Sair</button>
    </form>
  </header>
    <?php
}

function headAdmin(string $titulo): void
{
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($titulo) ?> · <?= e(cfg('nome_estudio')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Gloock&family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/style.css">
<link rel="stylesheet" href="../assets/admin.css">
</head>
<body>
<main class="pagina admin">
    <?php
}
