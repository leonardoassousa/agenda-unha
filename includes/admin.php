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
    $abas = ['index.php' => 'Calendário', 'resumo.php' => 'Resumo', 'clientes.php' => 'Clientes', 'ajustes.php' => 'Ajustes'];
    ?>
  <header class="admin-topo">
    <nav class="abas" aria-label="Painel">
      <?php foreach ($abas as $arq => $rotulo): ?>
        <a href="<?= $arq === 'index.php' ? './' : $arq ?>" <?= $ativa === $arq ? 'aria-current="page"' : '' ?>><?= $rotulo ?></a>
      <?php endforeach; ?>
    </nav>
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

function voltarPara(string $arquivo, array $params = [], string $ancora = ''): void
{
    $q = http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0'));
    header('Location: ' . $arquivo . ($q ? '?' . $q : '') . ($ancora ? '#' . $ancora : ''));
    exit;
}

const PASTA_FOTOS = 'uploads/profissionais';

function removerFoto(int $id): void
{
    $st = db()->prepare('SELECT foto FROM profissionais WHERE id = ?');
    $st->execute([$id]);
    $atual = $st->fetchColumn();
    if ($atual && strpos($atual, PASTA_FOTOS . '/') === 0) @unlink(__DIR__ . '/../' . $atual);
    db()->prepare('UPDATE profissionais SET foto = NULL WHERE id = ?')->execute([$id]);
}

/** Salva a foto quadrada (400x400). Retorna mensagem de erro ou '' se deu certo. */
function salvarFoto(int $id, ?array $arq): string
{
    if (!$arq || $arq['error'] === UPLOAD_ERR_NO_FILE) return 'Escolha uma foto.';
    if ($arq['error'] !== UPLOAD_ERR_OK) return 'A foto não chegou. Tente uma imagem menor que 5 MB.';
    if ($arq['size'] > 5 * 1024 * 1024) return 'A foto passou de 5 MB. Escolha uma menor.';
    $info = @getimagesize($arq['tmp_name']);
    $tipos = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($tipos[$info[2]])) return 'Use uma foto JPG, PNG ou WEBP.';

    $st = db()->prepare('SELECT id FROM profissionais WHERE id = ?');
    $st->execute([$id]);
    if (!$st->fetchColumn()) return 'Profissional não encontrada.';

    $dir = __DIR__ . '/../' . PASTA_FOTOS;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $nome = $id . '-' . bin2hex(random_bytes(4));

    if (function_exists('imagecreatetruecolor')) {
        // Recorta no centro e reduz pra 400x400: carrega rápido no celular
        $abrir = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'][$info[2]];
        $src = function_exists($abrir) ? @$abrir($arq['tmp_name']) : false;
        if (!$src) return 'Não deu pra abrir essa foto. Tente outra.';
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($arq['tmp_name']);
            $giro = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
            if ($giro) $src = imagerotate($src, $giro, 0);
        }
        $w = imagesx($src); $h = imagesy($src); $lado = min($w, $h);
        $dst = imagecreatetruecolor(400, 400);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // fundo branco pra PNG transparente
        // corta no centro na horizontal e um pouco pra cima na vertical (onde fica o rosto)
        imagecopyresampled($dst, $src, 0, 0, (int) (($w - $lado) / 2), (int) (($h - $lado) / 3), 400, 400, $lado, $lado);
        $arquivo = "$nome.jpg";
        imagejpeg($dst, "$dir/$arquivo", 85);
    } else {
        $arquivo = "$nome." . $tipos[$info[2]];
        if (!move_uploaded_file($arq['tmp_name'], "$dir/$arquivo")) return 'Não deu pra salvar a foto.';
    }

    removerFoto($id);
    db()->prepare('UPDATE profissionais SET foto = ? WHERE id = ?')->execute([PASTA_FOTOS . '/' . $arquivo, $id]);
    return '';
}

