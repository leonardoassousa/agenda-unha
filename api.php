<?php
require __DIR__ . '/includes/app.php';
require __DIR__ . '/includes/whatsapp.php';
require __DIR__ . '/includes/cliente.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(array $dados, int $status = 200): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit();
}

/** Responde na hora pra cliente e só depois roda $depois (o aviso no WhatsApp não atrasa a tela dela). */
function responderEDepois(array $dados, callable $depois): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    } // não segura outras abas da cliente
    $json = json_encode($dados, JSON_UNESCAPED_UNICODE);
    ignore_user_abort(true);
    set_time_limit(60);
    if (function_exists('fastcgi_finish_request')) {
        echo $json;
        fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Connection: close');
        header('Content-Length: ' . strlen($json));
        echo $json;
        flush();
    }
    try {
        $depois();
    } catch (Throwable $e) {
        error_log('[agenda] depois da resposta: ' . $e);
    }
    exit();
}

$acao = $_GET['acao'] ?? '';
$ehPost = $_SERVER['REQUEST_METHOD'] === 'POST';

/** Corpo JSON do POST. Exigir JSON impede outro site de mandar formulário em nome da cliente. */
function corpoJson(): array
{
    if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
        responder(['erro' => 'Requisição inválida.'], 415);
    }
    $in = json_decode(file_get_contents('php://input'), true);
    return is_array($in) ? $in : [];
}

try {
    // Só abre sessão quando precisa (e não cria cookie pra quem só está olhando horários)
    $temCookie = isset($_COOKIE['agenda_cliente']);
    if (
        in_array($acao, ['entrar', 'cadastrar'], true) ||
        ($temCookie && in_array($acao, ['eu', 'sair', 'agendar', 'cancelar', 'senha'], true))
    ) {
        iniciarSessaoCliente();
    }

    if ($acao === 'eu') {
        $c = clienteAtual();
        responder(['cliente' => clientePublico($c), 'horarios' => $c ? horariosDaCliente((int) $c['id']) : []]);
    }

    if ($acao === 'entrar' && $ehPost) {
        $in = corpoJson();
        $c = entrarCliente((string) ($in['telefone'] ?? ''), (string) ($in['senha'] ?? ''));
        responder(['ok' => true, 'cliente' => clientePublico($c), 'horarios' => horariosDaCliente((int) $c['id'])]);
    }

    if ($acao === 'cadastrar' && $ehPost) {
        $in = corpoJson();
        $c = cadastrarCliente(
            (string) ($in['nome'] ?? ''),
            (string) ($in['telefone'] ?? ''),
            (string) ($in['email'] ?? ''),
            (string) ($in['senha'] ?? ''),
        );
        responder(['ok' => true, 'cliente' => clientePublico($c), 'horarios' => horariosDaCliente((int) $c['id'])]);
    }

    if ($acao === 'sair' && $ehPost) {
        corpoJson();
        sairCliente();
        responder(['ok' => true]);
    }

    if ($acao === 'senha' && $ehPost) {
        $in = corpoJson();
        $c = clienteAtual();
        if (!$c) {
            responder(['erro' => 'Entre na sua conta de novo.', 'precisa_login' => true], 401);
        }
        trocarSenhaCliente((int) $c['id'], (string) ($in['atual'] ?? ''), (string) ($in['nova'] ?? ''));
        responder(['ok' => true]);
    }

    if ($acao === 'cancelar' && $ehPost) {
        $in = corpoJson();
        $c = clienteAtual();
        if (!$c) {
            responder(['erro' => 'Entre na sua conta pra cancelar.', 'precisa_login' => true], 401);
        }
        $ag = cancelarPelaCliente((int) $c['id'], (int) ($in['id'] ?? 0));
        responderEDepois(
            ['ok' => true, 'horarios' => horariosDaCliente((int) $c['id'])],
            fn() => enviarWhatsappEstudio(mensagemCancelamento($ag)),
        );
    }

    if ($acao === 'horarios') {
        $serv = servicoPorId((int) ($_GET['servico'] ?? 0));
        if (!$serv) {
            responder(['erro' => 'Serviço inválido.'], 400);
        }

        $ids = array_map('intval', array_column(profissionaisAtivas(), 'id'));
        $data = (string) ($_GET['data'] ?? '');
        $lista = [];
        if ($serv['dupla']) {
            // Duas manicures juntas: um "cartão" só (id 0) com os horários em que duas estão livres
            $lista[] = [
                'id' => 0,
                'horarios' => array_column(horariosDupla($data, (int) $serv['duracao_min'], $ids), 'hora'),
            ];
        } else {
            foreach (horariosPorProfissional($data, (int) $serv['duracao_min'], $ids) as $id => $horas) {
                $lista[] = ['id' => $id, 'horarios' => $horas];
            }
        }
        // Aproveita a visita pra mandar lembretes que estão na hora (sem atrasar a resposta)
        if (lembreteAutomaticoAtivo()) {
            responderEDepois(['data' => $data, 'profissionais' => $lista], 'lembretesSeHora');
        }
        responder(['data' => $data, 'profissionais' => $lista]);
    }

    // Próximo dia com horário livre (pra quem não achou vaga no dia escolhido)
    if ($acao === 'proximo') {
        $serv = servicoPorId((int) ($_GET['servico'] ?? 0));
        if (!$serv) {
            responder(['erro' => 'Serviço inválido.'], 400);
        }
        $ids = array_map('intval', array_column(profissionaisAtivas(), 'id'));
        $prof = (int) ($_GET['profissional'] ?? 0);
        if ($prof && !in_array($prof, $ids, true)) {
            responder(['erro' => 'Profissional inválida.'], 400);
        }
        $d = (string) ($_GET['depois'] ?? date('Y-m-d'));
        if (!DateTime::createFromFormat('!Y-m-d', $d)) {
            $d = date('Y-m-d');
        }
        for ($i = 1; $i <= (int) cfg('dias_a_frente'); $i++) {
            $dia = date('Y-m-d', strtotime("$d +$i day"));
            if (!dataPermitida($dia)) {
                break;
            }
            if (horariosDoServico($serv, $dia, $prof && !$serv['dupla'] ? [$prof] : $ids)) {
                responder(['data' => $dia]);
            }
        }
        responder(['data' => null]);
    }

    if ($acao === 'agendar' && $ehPost) {
        $in = corpoJson();

        // Campo isca: robô preenche, gente não vê
        if (!empty($in['site'])) {
            responder(['ok' => true, 'agendamento' => null]);
        }

        $c = clienteAtual();
        if (!$c) {
            responder(
                ['erro' => 'Entre na sua conta ou crie uma pra confirmar o horário.', 'precisa_login' => true],
                401,
            );
        }
        $obs = mb_substr(trim(strip_tags((string) ($in['observacao'] ?? ''))), 0, 255);

        $ag = agendar([
            'servico' => $in['servico'] ?? 0,
            'profissional' => $in['profissional'] ?? 0,
            'data' => (string) ($in['data'] ?? ''),
            'hora' => (string) ($in['hora'] ?? ''),
            'nome' => $c['nome'],
            'telefone' => $c['telefone'],
            'cliente_id' => (int) $c['id'],
            'observacao' => $obs,
        ]);
        $resposta = [
            'ok' => true,
            'agendamento' => [
                'servico' => $ag['servico'],
                'profissional' => $ag['profissional'],
                'profissional_id' => $ag['profissional_id'],
                'data' => $ag['data'],
                'hora' => $ag['hora'],
                'data_extenso' => dataExtenso($ag['data']),
                'preco_fmt' => preco($ag['preco']),
            ],
        ];
        if ($ag['novo']) {
            responderEDepois($resposta, fn() => avisarEstudio($ag));
        }
        responder($resposta);
    }

    responder(['erro' => 'Ação inválida.'], 404);
} catch (PDOException $ex) {
    error_log('[agenda] ' . $ex);
    responder(['erro' => 'Não deu pra agendar agora. Tente de novo em instantes.'], 500);
} catch (InvalidArgumentException $ex) {
    responder(['erro' => $ex->getMessage()], 422);
} catch (RuntimeException $ex) {
    responder(['erro' => $ex->getMessage()], 409);
} catch (Throwable $ex) {
    error_log('[agenda] ' . $ex);
    responder(['erro' => 'Não deu pra agendar agora. Tente de novo em instantes.'], 500);
}
