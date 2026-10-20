<?php
// Aviso no WhatsApp do estúdio quando entra um agendamento.
// Configuração em config.php, bloco 'aviso_whatsapp'.

function mensagemNovoAgendamento(array $ag): string
{
    $tel = $ag['cliente_telefone'];
    $linhas = [
        '*Novo agendamento* ✅',
        '',
        '*Cliente:* ' . $ag['cliente_nome'],
        '*WhatsApp:* ' . telefoneBonito($tel) . ' (wa.me/55' . $tel . ')',
        '*Serviço:* ' . $ag['servico'] . ' (' . preco((float) $ag['preco']) . ')',
        '*Quando:* ' . dataExtenso($ag['data']) . ', às ' . $ag['hora'],
        '*Com:* ' . $ag['profissional'],
    ];
    if (!empty($ag['observacao'])) $linhas[] = '*Obs.:* ' . $ag['observacao'];
    return implode("\n", $linhas);
}

function mensagemCancelamento(array $ag): string
{
    return implode("\n", [
        '*Horário cancelado pela cliente* ❌',
        '',
        '*Cliente:* ' . $ag['cliente_nome'] . ' (' . telefoneBonito($ag['cliente_telefone']) . ')',
        '*Serviço:* ' . $ag['servico'],
        '*Era:* ' . dataExtenso($ag['data']) . ', às ' . $ag['hora'] . ', com ' . $ag['profissional'],
        '',
        'O horário já está livre no link.',
    ]);
}

/**
 * Envia uma mensagem pro WhatsApp do estúdio.
 * Retorna '' se deu certo, ou o motivo do erro (o agendamento nunca depende disso).
 */
function enviarWhatsappEstudio(string $texto): string
{
    $c = cfg('aviso_whatsapp') ?: [];
    $modo = $c['modo'] ?? 'desligado';
    $para = preg_replace('/\D/', '', (string) ($c['telefone'] ?? ''));

    if ($modo === 'desligado') return 'Aviso desligado no config.php.';
    if (!$para) return 'Falta o telefone que recebe o aviso no config.php.';
    if (!function_exists('curl_init')) return 'A extensão cURL do PHP está desligada.';

    if ($modo === 'callmebot') {
        if (empty($c['callmebot_apikey'])) return 'Falta a apikey do CallMeBot no config.php.';
        $url = 'https://api.callmebot.com/whatsapp.php?' . http_build_query([
            'phone' => '+' . $para, 'text' => $texto, 'apikey' => $c['callmebot_apikey'],
        ], '', '&', PHP_QUERY_RFC3986);
        [$cod, $corpo, $erro] = requisicao('GET', $url);
        if ($erro) return "Sem conexão com o CallMeBot ($erro).";
        if ($cod >= 400 || stripos($corpo, 'APIKey is invalid') !== false || stripos($corpo, 'not activated') !== false) {
            return 'O CallMeBot recusou: ' . mb_substr(trim(strip_tags($corpo)), 0, 160);
        }
        return '';
    }

    if ($modo === 'evolution') {
        foreach (['evolution_url', 'evolution_instancia', 'evolution_apikey'] as $k) {
            if (empty($c[$k])) return "Falta '$k' no config.php.";
        }
        $url = rtrim($c['evolution_url'], '/') . '/message/sendText/' . rawurlencode($c['evolution_instancia']);
        [$cod, $corpo, $erro] = requisicao('POST', $url, json_encode(['number' => $para, 'text' => $texto], JSON_UNESCAPED_UNICODE), [
            'apikey: ' . $c['evolution_apikey'],
            'Content-Type: application/json',
        ]);
        if ($erro) return "Sem conexão com a Evolution API ($erro).";
        if ($cod >= 400) return "A Evolution API respondeu $cod: " . mb_substr(trim($corpo), 0, 160);
        return '';
    }

    return "Modo '$modo' desconhecido. Use 'desligado', 'callmebot' ou 'evolution'.";
}

function requisicao(string $metodo, string $url, ?string $corpo = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($corpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
    $resp = curl_exec($ch);
    $erro = $resp === false ? curl_error($ch) : '';
    $cod = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$cod, (string) $resp, $erro];
}

/** Avisa o estúdio e registra o resultado no agendamento (aparece no painel). */
function avisarEstudio(array $ag): void
{
    $erro = enviarWhatsappEstudio(mensagemNovoAgendamento($ag));
    $status = $erro === '' ? 'enviado' : 'falhou';
    if (($c = cfg('aviso_whatsapp')) && ($c['modo'] ?? 'desligado') === 'desligado') $status = 'desligado';
    try {
        db()->prepare('UPDATE agendamentos SET aviso = ? WHERE id = ?')->execute([$status, $ag['id']]);
    } catch (Throwable $e) { /* coluna antiga: ignora */ }
    if ($erro !== '' && $status === 'falhou') error_log('[agenda] aviso WhatsApp falhou: ' . $erro);
}
