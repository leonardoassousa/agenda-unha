<?php
// WhatsApp: aviso de agendamento e cancelamento no grupo da equipe e lembrete pra cliente.
// Configuração em config.php, bloco 'aviso_whatsapp' (e 'lembrete_cliente').

require_once __DIR__ . '/email.php'; // urlSite()

const LINHA_WA = '━━━━━━━━━━━━━━━';

/** Quantos atendimentos concluídos a cliente já teve (serviço em dupla conta uma vez). */
function visitasAnteriores(string $telefone, string $antesDe): int
{
    $st = db()->prepare(
        "SELECT COUNT(DISTINCT COALESCE(grupo, id)) FROM agendamentos WHERE cliente_telefone = ? AND status = 'concluido' AND inicio < ?",
    );
    $st->execute([$telefone, $antesDe]);
    return (int) $st->fetchColumn();
}

function linkPainelDia(string $data): string
{
    return urlSite() . '/admin/?' . http_build_query(['mes' => substr($data, 0, 7), 'dia' => $data]) . '#detalhe';
}

/** Linha do horário fixo: "🔁 Toda sexta, até 18/12 · 7 horários" */
function linhaSerie(?int $serie): ?string
{
    if (!$serie || !function_exists('dadosSerie')) {
        return null;
    }
    $s = dadosSerie($serie);
    return $s ? '🔁 ' . $s['texto'] . ' · ' . plural($s['restantes'], 'horário', 'horários') : null;
}

/** Aviso de agendamento novo (vai pro grupo da equipe ou pro WhatsApp do estúdio). */
function mensagemNovoAgendamento(array $ag): string
{
    $tel = $ag['cliente_telefone'];
    $antes = visitasAnteriores($tel, $ag['data'] . ' ' . $ag['hora'] . ':00');
    $dupla = !empty($ag['grupo']);
    $linhas = [
        '💅 *Novo agendamento*',
        cfg('nome_estudio'),
        LINHA_WA,
        '',
        '👤 *' . $ag['cliente_nome'] . '*',
        '📱 ' . telefoneBonito($tel),
        'wa.me/55' . $tel,
        $antes ? '⭐ Cliente da casa · ' . ($antes + 1) . 'ª visita' : '🆕 Primeira vez no estúdio',
        '',
        '✨ *' . $ag['servico'] . '*',
        '💰 ' . preco((float) $ag['preco']) . ' · ⏱ ' . duracaoTexto((int) ($ag['duracao_min'] ?? 0)),
        '',
        '📅 *' . ucfirst(dataExtenso($ag['data'])) . '*',
        '🕑 ' . $ag['hora'] . (!empty($ag['hora_fim']) ? ' às ' . $ag['hora_fim'] : ''),
        ($dupla ? '👩👩 Com *' : '👩 Com *') . $ag['profissional'] . '*',
    ];
    if ($serie = linhaSerie($ag['serie'] ?? null)) {
        $linhas[] = $serie;
    }
    if (!empty($ag['observacao'])) {
        $linhas[] = '';
        $linhas[] = '📝 _"' . $ag['observacao'] . '"_';
    }
    $quando = !empty($ag['criado_em']) ? ' às ' . date('H:i', strtotime($ag['criado_em'])) : '';
    $linhas[] = '';
    $linhas[] = LINHA_WA;
    $linhas[] = (($ag['origem'] ?? 'site') === 'painel' ? 'Marcado pelo painel' : 'Marcado pelo site') . $quando;
    $linhas[] = 'Ver no painel: ' . linkPainelDia($ag['data']);
    return implode("\n", $linhas);
}

/**
 * Aviso de horário desmarcado. $quem: "pela cliente, pelo site", "pelo painel", "pela Ana"...
 * $serieCancelados > 1: desmarcou o horário fixo (este e os próximos).
 */
function mensagemCancelamento(array $ag, string $quem = 'pela cliente, pelo site', int $serieCancelados = 0): string
{
    $linhas = [
        '❌ *Horário cancelado*',
        cfg('nome_estudio'),
        LINHA_WA,
        '',
        '👤 *' . $ag['cliente_nome'] . '*',
        '📱 ' . telefoneBonito($ag['cliente_telefone']),
        '',
        '✨ ' . $ag['servico'],
        '📅 ' . ucfirst(dataExtenso($ag['data'])) . ', às ' . $ag['hora'],
        (!empty($ag['grupo']) ? '👩👩 Com ' : '👩 Com ') . $ag['profissional'],
    ];
    if ($serieCancelados > 1) {
        $linhas[] = '🔁 Horário fixo desmarcado: esse e os próximos (' . $serieCancelados . ' horários)';
    }
    $linhas[] = '';
    $linhas[] = LINHA_WA;
    $linhas[] = 'Cancelado ' . $quem;
    $linhas[] = $serieCancelados > 1 ? '✅ Os horários já estão livres no link.' : '✅ O horário já está livre no link.';
    return implode("\n", $linhas);
}

/** Lembrete que a cliente recebe antes do horário. $a: linha do agendamento + servico + profissional. */
function mensagemLembreteCliente(array $a): string
{
    $nome = preg_split('/\s+/', trim($a['cliente_nome']))[0];
    $data = substr($a['inicio'], 0, 10);
    $quando = $data === date('Y-m-d') ? '*hoje*' : ($data === date('Y-m-d', strtotime('+1 day')) ? '*amanhã*' : '*' . dataExtenso($data) . '*');
    $linhas = [
        "Oi, $nome! 💅",
        '',
        "Passando pra lembrar do seu horário $quando no *" . cfg('nome_estudio') . '*:',
        '',
        '✨ ' . $a['servico'],
        '🕑 ' . substr($a['inicio'], 11, 5) . ', com ' . $a['profissional'],
    ];
    if ($end = trim((string) cfg('endereco'))) {
        $linhas[] = '📍 ' . $end;
    }
    $linhas[] = '';
    $linhas[] = 'Te esperamos! Se não puder vir, avisa por aqui pra gente liberar o horário pra outra cliente 💕';
    return implode("\n", $linhas);
}

/** Roda $fn depois de mandar a resposta (o WhatsApp não atrasa a tela). */
function depoisDaResposta(callable $fn): void
{
    register_shutdown_function(function () use ($fn) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ignore_user_abort(true);
        try {
            $fn();
        } catch (Throwable $e) {
            error_log('[agenda] depois da resposta: ' . $e);
        }
    });
}

/** Avisa o grupo sobre um cancelamento (não faz nada se o aviso estiver desligado). */
function avisarCancelamento(array $ag, string $quem, int $serieCancelados = 0): void
{
    $erro = enviarWhatsappEstudio(mensagemCancelamento($ag, $quem, $serieCancelados));
    if ($erro !== '' && (cfg('aviso_whatsapp')['modo'] ?? 'desligado') !== 'desligado') {
        error_log('[agenda] aviso de cancelamento falhou: ' . $erro);
    }
}

/**
 * Envia uma mensagem pro WhatsApp do estúdio.
 * Retorna '' se deu certo, ou o motivo do erro (o agendamento nunca depende disso).
 */
function enviarWhatsappEstudio(string $texto): string
{
    $c = cfg('aviso_whatsapp') ?: [];
    // Grupo da equipe (Evolution API) ou, sem grupo, o número do estúdio
    $destino = ($c['modo'] ?? '') === 'evolution' && !empty($c['grupo']) ? (string) $c['grupo'] : (string) ($c['telefone'] ?? '');
    return enviarWhatsapp($destino, $texto);
}

/**
 * Envia mensagem pra qualquer número (com 55 + DDD).
 * O CallMeBot só entrega no número que ativou ele (o do estúdio); pra cliente precisa da Evolution API.
 * Retorna '' se deu certo, ou o motivo do erro.
 */
function enviarWhatsapp(string $numero, string $texto): string
{
    $c = cfg('aviso_whatsapp') ?: [];
    $modo = $c['modo'] ?? 'desligado';
    // Grupo do WhatsApp (ex.: 120363012345678901@g.us) vai do jeito que está; número fica só com dígitos
    $para = str_ends_with(trim($numero), '@g.us') ? trim($numero) : preg_replace('/\D/', '', $numero);
    $estudio = preg_replace('/\D/', '', (string) ($c['telefone'] ?? ''));

    if ($modo === 'desligado') {
        return 'Aviso desligado no config.php.';
    }
    if (!$para) {
        return 'Falta o número de destino.';
    }
    if (!function_exists('curl_init')) {
        return 'A extensão cURL do PHP está desligada.';
    }

    if ($modo === 'callmebot') {
        if ($para !== $estudio) {
            return 'O CallMeBot só manda mensagem pro número do estúdio. Pra mandar pra cliente, use a Evolution API.';
        }
        if (empty($c['callmebot_apikey'])) {
            return 'Falta a apikey do CallMeBot no config.php.';
        }
        $url =
            'https://api.callmebot.com/whatsapp.php?' .
            http_build_query(
                [
                    'phone' => '+' . $para,
                    'text' => $texto,
                    'apikey' => $c['callmebot_apikey'],
                ],
                '',
                '&',
                PHP_QUERY_RFC3986,
            );
        [$cod, $corpo, $erro] = requisicao('GET', $url);
        if ($erro) {
            return "Sem conexão com o CallMeBot ($erro).";
        }
        if (
            $cod >= 400 ||
            stripos($corpo, 'APIKey is invalid') !== false ||
            stripos($corpo, 'not activated') !== false
        ) {
            return 'O CallMeBot recusou: ' . mb_substr(trim(strip_tags($corpo)), 0, 160);
        }
        return '';
    }

    if ($modo === 'evolution') {
        foreach (['evolution_url', 'evolution_instancia', 'evolution_apikey'] as $k) {
            if (empty($c[$k])) {
                return "Falta '$k' no config.php.";
            }
        }
        $url = rtrim($c['evolution_url'], '/') . '/message/sendText/' . rawurlencode($c['evolution_instancia']);
        [$cod, $corpo, $erro] = requisicao(
            'POST',
            $url,
            json_encode(['number' => $para, 'text' => $texto], JSON_UNESCAPED_UNICODE),
            ['apikey: ' . $c['evolution_apikey'], 'Content-Type: application/json'],
        );
        if ($erro) {
            return "Sem conexão com a Evolution API ($erro).";
        }
        if ($cod >= 400) {
            return "A Evolution API respondeu $cod: " . mb_substr(trim($corpo), 0, 160);
        }
        return '';
    }

    return "Modo '$modo' desconhecido. Use 'desligado', 'callmebot' ou 'evolution'.";
}

/** Lembrete da cliente pode ser enviado automaticamente? */
function lembreteAutomaticoAtivo(): bool
{
    $l = cfg('lembrete_cliente') ?: [];
    $modo = (cfg('aviso_whatsapp') ?: [])['modo'] ?? 'desligado';
    return !empty($l['ativo']) && $modo === 'evolution';
}

/**
 * Manda os lembretes que estão na hora. Cada horário recebe no máximo um.
 * Retorna quantos foram enviados.
 */
function rodarLembretes(): int
{
    if (!lembreteAutomaticoAtivo()) {
        return 0;
    }
    require_once __DIR__ . '/atendimento.php';
    $horas = max(1, (int) ((cfg('lembrete_cliente') ?: [])['horas_antes'] ?? 2));

    // Horário marcado já dentro da janela (ex.: marcou 1 h antes) não precisa de lembrete
    db()
        ->prepare(
            "UPDATE agendamentos SET lembrete = 'pulado' WHERE lembrete = 'pendente' AND status = 'confirmado'
                   AND criado_em > inicio - INTERVAL ? HOUR AND inicio <= NOW() + INTERVAL ? HOUR",
        )
        ->execute([$horas, $horas]);
    db()->exec(
        "UPDATE agendamentos SET lembrete = 'pulado' WHERE lembrete = 'pendente' AND (inicio <= NOW() OR status <> 'confirmado')",
    );

    $st = db()->prepare("SELECT a.*, s.nome servico,
            COALESCE((SELECT GROUP_CONCAT(p2.nome ORDER BY a2.id SEPARATOR ' e ') FROM agendamentos a2
                      JOIN profissionais p2 ON p2.id = a2.profissional_id WHERE a.grupo IS NOT NULL AND a2.grupo = a.grupo), p.nome) profissional
        FROM agendamentos a
        JOIN servicos s ON s.id = a.servico_id JOIN profissionais p ON p.id = a.profissional_id
        WHERE a.lembrete = 'pendente' AND a.status = 'confirmado' AND a.inicio > NOW() AND a.inicio <= NOW() + INTERVAL ? HOUR
        ORDER BY a.inicio LIMIT " . (PHP_SAPI === 'cli' ? 30 : 8));
    $st->execute([$horas]);
    $enviados = 0;
    foreach ($st->fetchAll() as $a) {
        // "Reserva" o horário antes de mandar: dois disparos ao mesmo tempo não mandam duas vezes
        $res = db()->prepare("UPDATE agendamentos SET lembrete = 'enviado' WHERE id = ? AND lembrete = 'pendente'");
        $res->execute([$a['id']]);
        if ($res->rowCount() !== 1) {
            continue;
        }
        if (isset($pausar)) {
            // Um de cada vez, com pausa: envio em rajada é o que faz o WhatsApp desconfiar
            sleep(PHP_SAPI === 'cli' ? random_int(4, 8) : 2);
        }
        $pausar = true;
        $erro = enviarWhatsapp('55' . $a['cliente_telefone'], mensagemLembreteCliente($a));
        if ($erro !== '') {
            db()
                ->prepare("UPDATE agendamentos SET lembrete = 'falhou' WHERE id = ?")
                ->execute([$a['id']]);
            error_log('[agenda] lembrete ' . $a['id'] . ' falhou: ' . $erro);
        } else {
            $enviados++;
        }
    }
    return $enviados;
}

/** Roda os lembretes no máximo a cada 5 minutos (dispensa agendador no servidor). */
function lembretesSeHora(): void
{
    if (!lembreteAutomaticoAtivo()) {
        return;
    }
    $pdo = db();
    $pdo->exec("INSERT IGNORE INTO sistema (chave, valor) VALUES ('lembretes_em', '0')");
    $st = $pdo->prepare("UPDATE sistema SET valor = ? WHERE chave = 'lembretes_em' AND CAST(valor AS UNSIGNED) < ?");
    $st->execute([(string) time(), time() - 300]);
    if ($st->rowCount() === 1) {
        rodarLembretes();
    }
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
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
    }
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
    if (($c = cfg('aviso_whatsapp')) && ($c['modo'] ?? 'desligado') === 'desligado') {
        $status = 'desligado';
    }
    try {
        db()
            ->prepare('UPDATE agendamentos SET aviso = ? WHERE id = ? OR grupo = ?' . (!empty($ag['serie']) ? ' OR serie = ?' : ''))
            ->execute(array_merge([$status, $ag['id'], $ag['grupo'] ?? $ag['id']], !empty($ag['serie']) ? [$ag['serie']] : []));
    } catch (Throwable $e) {
        /* coluna antiga: ignora */
    }
    if ($erro !== '' && $status === 'falhou') {
        error_log('[agenda] aviso WhatsApp falhou: ' . $erro);
    }
}
