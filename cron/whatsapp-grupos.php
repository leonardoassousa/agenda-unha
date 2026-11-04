<?php
// Lista os grupos do WhatsApp conectado na Evolution API, com o código de cada um.
// Uso (no servidor):  php cron/whatsapp-grupos.php
// Copie o código do grupo da equipe (termina em @g.us) pro 'grupo' do aviso_whatsapp no config.php.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Só pela linha de comando.');
}
require __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/whatsapp.php';

$c = cfg('aviso_whatsapp') ?: [];
foreach (['evolution_url', 'evolution_instancia', 'evolution_apikey'] as $k) {
    if (empty($c[$k])) {
        exit("Falta '$k' no aviso_whatsapp do config.php.\n");
    }
}
$url = rtrim($c['evolution_url'], '/') . '/group/fetchAllGroups/' . rawurlencode($c['evolution_instancia']) . '?getParticipants=false';
[$cod, $corpo, $erro] = requisicao('GET', $url, null, ['apikey: ' . $c['evolution_apikey']]);
if ($erro || $cod >= 400) {
    exit('Não deu pra listar os grupos: ' . ($erro ?: "resposta $cod: " . mb_substr($corpo, 0, 200)) . "\n");
}
$grupos = json_decode($corpo, true) ?: [];
if (!$grupos) {
    exit("Nenhum grupo encontrado. O número do estúdio precisa estar no grupo da equipe.\n");
}
foreach ($grupos as $g) {
    printf("%-40s %s\n", mb_substr((string) ($g['subject'] ?? '(sem nome)'), 0, 40), $g['id'] ?? '?');
}
