<?php
// "Instalar" o site na tela inicial do celular (Adicionar à tela inicial).
//   manifest.php              -> app das clientes (abre na página de agendar)
//   manifest.php?app=equipe   -> app da equipe (abre no login; a dona cai direto no painel)
require __DIR__ . '/includes/app.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$equipe = ($_GET['app'] ?? '') === 'equipe';
$nome = (string) cfg('nome_estudio');
$curto = mb_strlen($nome) > 12 ? trim((string) preg_replace('/^(studio|estúdio|espaço)\s+/iu', '', $nome)) : $nome;
$icone = fn(int $t, string $q) => ['src' => "assets/icones/$q-$t.png", 'sizes' => "{$t}x$t", 'type' => 'image/png', 'purpose' => 'any maskable'];

$app = $equipe
    ? [
        'id' => 'equipe/',
        'name' => "Agenda · $nome",
        'short_name' => 'Agenda',
        'description' => 'Agenda, horários e caixa da equipe do estúdio.',
        'start_url' => 'equipe/?app=1',
        'background_color' => '#fbf3f4',
        'theme_color' => '#3a0e1e',
        'icons' => [$icone(192, 'equipe'), $icone(512, 'equipe')],
    ]
    : [
        'id' => './',
        'name' => $nome,
        'short_name' => $curto, // nome embaixo do ícone (sem o "Studio" quando é comprido)
        'description' => 'Agende seu horário no estúdio.',
        'start_url' => './',
        'background_color' => '#fbf7f6',
        'theme_color' => '#3b0a1c',
        'icons' => [$icone(192, 'cliente'), $icone(512, 'cliente')],
    ];

echo json_encode(
    $app + ['scope' => './', 'display' => 'standalone', 'orientation' => 'portrait', 'lang' => 'pt-BR'],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
);
