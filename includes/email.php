<?php
// Envio de e-mail por SMTP (Gmail, Hostinger, Brevo, Resend...). Sem biblioteca: fala o protocolo direto.
// Configuração em config.php, bloco 'email'.

function emailAtivo(): bool
{
    $c = cfg('email') ?: [];
    return ($c['modo'] ?? 'desligado') === 'smtp' && !empty($c['smtp_host']) && !empty($c['remetente_email']);
}

/**
 * Manda um e-mail. Retorna '' se deu certo, ou o motivo do erro.
 * $html é a versão bonita; $texto é a versão simples (pra quem não abre HTML).
 */
function enviarEmail(string $para, string $nomePara, string $assunto, string $html, string $texto): string
{
    if (!emailAtivo()) {
        return 'e-mail desligado no config.php';
    }
    $c = cfg('email');
    $host = (string) $c['smtp_host'];
    $porta = (int) ($c['smtp_porta'] ?? 465);
    $seg = (string) ($c['smtp_seguranca'] ?? 'ssl'); // 'ssl' (porta 465), 'tls' (porta 587) ou '' (sem criptografia)
    $de = (string) $c['remetente_email'];
    $deNome = (string) ($c['remetente_nome'] ?? cfg('nome_estudio'));

    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $alvo = ($seg === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $porta;
    $s = @stream_socket_client($alvo, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) {
        return "não conectou no servidor de e-mail ($errstr)";
    }
    stream_set_timeout($s, 15);

    // Lê a resposta do servidor (pode ter várias linhas) e confere o código
    $ler = function (array $esperado) use ($s): string {
        $resp = '';
        while (($linha = fgets($s, 515)) !== false) {
            $resp .= $linha;
            if (strlen($linha) < 4 || $linha[3] === ' ') {
                break;
            }
        }
        $cod = (int) substr($resp, 0, 3);
        if (!in_array($cod, $esperado, true)) {
            throw new RuntimeException('servidor de e-mail respondeu: ' . trim($resp));
        }
        return $resp;
    };
    $cmd = function (string $linha, array $esperado) use ($s, $ler): string {
        fwrite($s, $linha . "\r\n");
        return $ler($esperado);
    };

    try {
        $ler([220]);
        $eu = preg_replace('/[^a-z0-9.-]/i', '', (string) ($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
        $cmd("EHLO $eu", [250]);
        if ($seg === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('não deu pra ativar a criptografia (STARTTLS)');
            }
            $cmd("EHLO $eu", [250]);
        }
        if (!empty($c['smtp_usuario'])) {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode((string) $c['smtp_usuario']), [334]);
            $cmd(base64_encode((string) ($c['smtp_senha'] ?? '')), [235]);
        }
        $cmd("MAIL FROM:<$de>", [250]);
        $cmd("RCPT TO:<$para>", [250, 251]);
        $cmd('DATA', [354]);

        $cab = fn(string $t) => '=?UTF-8?B?' . base64_encode($t) . '?=';
        $limite = 'b' . bin2hex(random_bytes(12));
        $dominio = substr(strrchr($de, '@') ?: '@localhost', 1);
        $msg = implode("\r\n", [
            'Date: ' . date('r'),
            'From: ' . $cab($deNome) . " <$de>",
            'To: ' . ($nomePara !== '' ? $cab($nomePara) . ' ' : '') . "<$para>",
            'Subject: ' . $cab($assunto),
            'Message-ID: <' . bin2hex(random_bytes(16)) . "@$dominio>",
            'MIME-Version: 1.0',
            "Content-Type: multipart/alternative; boundary=\"$limite\"",
            '',
            "--$limite",
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($texto)),
            "--$limite",
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($html)),
            "--$limite--",
            '',
        ]);
        fwrite($s, $msg . "\r\n.\r\n");
        $ler([250]);
        $cmd('QUIT', [221]);
        return '';
    } catch (Throwable $e) {
        return $e->getMessage();
    } finally {
        fclose($s);
    }
}

/** Endereço do site (pra montar links nos e-mails). Usa 'url_site' do config; se vazio, descobre pelo acesso. */
function urlSite(): string
{
    $url = rtrim((string) (cfg('url_site') ?? ''), '/');
    if ($url !== '') {
        return $url;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = preg_replace('/[^a-z0-9.:-]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $pasta = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    $pasta = preg_replace('#/(admin|equipe|cron)$#', '', $pasta); // chamado de dentro do painel ou da equipe
    return ($https ? 'https' : 'http') . '://' . $host . $pasta;
}

/** E-mail com o link pra criar senha nova. */
function emailRedefinirSenha(string $nome, string $link): array
{
    $estudio = (string) cfg('nome_estudio');
    $primeiro = explode(' ', trim($nome))[0];
    $assunto = "Crie sua senha nova · $estudio";
    $texto = "Oi, $primeiro!\n\nRecebemos um pedido pra trocar a senha da sua conta de agendamento no $estudio.\n\n"
        . "Pra criar a senha nova, abra este link (vale por 1 hora):\n$link\n\n"
        . "Se não foi você, é só ignorar este e-mail: sua senha continua a mesma.\n\n$estudio";
    $h = fn(string $t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    $html = '<!doctype html><html lang="pt-BR"><body style="margin:0;padding:0;background:#fbf7f6;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fbf7f6;padding:32px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border:1px solid #eedfe1;border-radius:18px;overflow:hidden;font-family:Helvetica,Arial,sans-serif;color:#26121b;">'
        . '<tr><td style="background:#3b0a1c;padding:26px 28px;font-family:Georgia,\'Times New Roman\',serif;font-size:26px;color:#ffffff;">' . $h($estudio) . '</td></tr>'
        . '<tr><td style="padding:28px 28px 8px;font-size:16px;line-height:1.55;">'
        . '<p style="margin:0 0 14px;">Oi, ' . $h($primeiro) . '!</p>'
        . '<p style="margin:0 0 22px;">Recebemos um pedido pra trocar a senha da sua conta de agendamento. Toque no botão pra criar a senha nova:</p>'
        . '<p style="margin:0 0 22px;"><a href="' . $h($link) . '" style="display:inline-block;background:#8e1238;color:#ffffff;text-decoration:none;font-weight:bold;padding:14px 26px;border-radius:999px;">Criar senha nova</a></p>'
        . '<p style="margin:0 0 8px;font-size:14px;color:#7a6670;">O link vale por 1 hora e só funciona uma vez.</p>'
        . '<p style="margin:0 0 24px;font-size:14px;color:#7a6670;">Se não foi você, é só ignorar este e-mail: sua senha continua a mesma.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:16px 28px 24px;border-top:1px solid #eedfe1;font-size:12px;color:#7a6670;word-break:break-all;">Se o botão não abrir, copie este endereço no navegador:<br>' . $h($link) . '</td></tr>'
        . '</table></td></tr></table></body></html>';
    return [$assunto, $html, $texto];
}
