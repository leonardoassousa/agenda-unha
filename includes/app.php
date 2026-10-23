<?php
// Núcleo: configuração, conexão e regras da agenda.

if (!is_file(__DIR__ . '/../config.php')) {
    http_response_code(500);
    exit('Falta o config.php. Copie o config.exemplo.php com o nome config.php e preencha.');
}
$CFG = require __DIR__ . '/../config.php';
date_default_timezone_set($CFG['fuso']);

function cfg(string $chave)
{
    global $CFG;
    return $CFG[$chave] ?? null;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $d = cfg('db');
        $pdo = new PDO("mysql:host={$d['host']};dbname={$d['nome']};charset=utf8mb4", $d['usuario'], $d['senha'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function preco(float $v): string
{
    return 'R$ ' . number_format($v, 2, ',', '.');
}

function servicosAtivos(): array
{
    return db()
        ->query(
            'SELECT id, nome, descricao, duracao_min, preco, dupla FROM servicos WHERE ativo = 1 ORDER BY ordem, nome',
        )
        ->fetchAll();
}

function profissionaisAtivas(): array
{
    return db()
        ->query('SELECT id, nome, cor, foto FROM profissionais WHERE ativo = 1 ORDER BY ordem, nome')
        ->fetchAll();
}

/** Horários livres de cada profissional: [id => ['09:00', ...]] */
function horariosPorProfissional(string $data, int $duracaoMin, array $profIds): array
{
    $saida = array_fill_keys($profIds, []);
    foreach (horariosDisponiveis($data, $duracaoMin, $profIds) as $slot) {
        foreach ($slot['profissionais'] as $id) {
            $saida[$id][] = $slot['hora'];
        }
    }
    return $saida;
}

function iniciais(string $nome): string
{
    $partes = preg_split('/\s+/', trim($nome));
    $ini = mb_substr($partes[0], 0, 1);
    if (count($partes) > 1) {
        $ini .= mb_substr(end($partes), 0, 1);
    }
    return mb_strtoupper($ini);
}

function servicoPorId(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM servicos WHERE id = ? AND ativo = 1');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Data válida no formato Y-m-d, dentro da janela permitida. */
function dataPermitida(string $data): bool
{
    $d = DateTime::createFromFormat('!Y-m-d', $data);
    if (!$d || $d->format('Y-m-d') !== $data) {
        return false;
    }
    $hoje = new DateTime('today');
    $limite = (clone $hoje)->modify('+' . (int) cfg('dias_a_frente') . ' days');
    return $d >= $hoje && $d <= $limite;
}

/** Status que ocupam a agenda e contam como atendimento. */
const STATUS_ATIVOS = "('confirmado','concluido')";

/** Horário padrão do estúdio (config.php): [abre, fecha] em timestamp, ou null se fechado. */
function expediente(string $data): ?array
{
    $dia = (int) date('w', strtotime($data));
    $h = cfg('horarios')[$dia] ?? null;
    if (!$h) {
        return null;
    }
    return [strtotime("$data {$h[0]}"), strtotime("$data {$h[1]}")];
}

/** Semana de trabalho de todas as colaboradoras: [profId => [diaSemana => linha]] (cache por requisição). */
function semanas(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT * FROM disponibilidade_semana') as $r) {
            $cache[(int) $r['profissional_id']][(int) $r['dia_semana']] = $r;
        }
    }
    return $cache;
}

/** Dias específicos (folga ou horário diferente) num intervalo: [profId => [data => linha]]. */
function datasEspeciais(string $de, string $ate): array
{
    static $cache = [];
    $k = "$de|$ate";
    if (!isset($cache[$k])) {
        $cache[$k] = [];
        $st = db()->prepare('SELECT * FROM disponibilidade_datas WHERE data BETWEEN ? AND ?');
        $st->execute([$de, $ate]);
        foreach ($st as $r) {
            $cache[$k][(int) $r['profissional_id']][$r['data']] = $r;
        }
    }
    return $cache[$k];
}

/**
 * Jornada da colaboradora num dia: ['ini' => ts, 'fim' => ts, 'pausa' => [ts, ts] | null], ou null se não trabalha.
 * Ordem: dia específico > semana dela > horário do estúdio (se ela não configurou nada).
 */
function jornada(int $profId, string $data): ?array
{
    $esp = datasEspeciais($data, $data)[$profId][$data] ?? null;
    $semana = semanas()[$profId] ?? null;
    $dia = (int) date('w', strtotime($data));

    if ($esp) {
        if (!(int) $esp['trabalha'] || !$esp['inicio'] || !$esp['fim']) {
            return null;
        }
        $ini = strtotime("$data {$esp['inicio']}");
        $fim = strtotime("$data {$esp['fim']}");
        $pausa = null;
        $linha = $semana[$dia] ?? null; // usa a pausa da semana, se couber
        if ($linha && $linha['pausa_inicio'] && $linha['pausa_fim']) {
            $pausa = [strtotime("$data {$linha['pausa_inicio']}"), strtotime("$data {$linha['pausa_fim']}")];
        } elseif (!$semana && ($alm = cfg('almoco'))) {
            $pausa = [strtotime("$data {$alm[0]}"), strtotime("$data {$alm[1]}")];
        }
        return $fim > $ini ? ['ini' => $ini, 'fim' => $fim, 'pausa' => $pausa] : null;
    }

    if ($semana) {
        $linha = $semana[$dia] ?? null;
        if (!$linha) {
            return null;
        }
        $pausa =
            $linha['pausa_inicio'] && $linha['pausa_fim']
                ? [strtotime("$data {$linha['pausa_inicio']}"), strtotime("$data {$linha['pausa_fim']}")]
                : null;
        return [
            'ini' => strtotime("$data {$linha['inicio']}"),
            'fim' => strtotime("$data {$linha['fim']}"),
            'pausa' => $pausa,
        ];
    }

    $exp = expediente($data);
    if (!$exp) {
        return null;
    }
    $alm = cfg('almoco');
    return [
        'ini' => $exp[0],
        'fim' => $exp[1],
        'pausa' => $alm ? [strtotime("$data {$alm[0]}"), strtotime("$data {$alm[1]}")] : null,
    ];
}

/** Minutos de trabalho da jornada (sem a pausa). */
function minutosJornada(?array $j): int
{
    if (!$j) {
        return 0;
    }
    $m = ($j['fim'] - $j['ini']) / 60;
    if ($j['pausa']) {
        $m -= max(0, (min($j['fim'], $j['pausa'][1]) - max($j['ini'], $j['pausa'][0])) / 60);
    }
    return (int) max(0, $m);
}

/** Datas (Y-m-d) da janela de agendamento em que pelo menos uma colaboradora trabalha. */
function diasDisponiveis(): array
{
    $ids = array_map('intval', array_column(profissionaisAtivas(), 'id'));
    $hoje = date('Y-m-d');
    $ate = date('Y-m-d', strtotime('+' . (int) cfg('dias_a_frente') . ' days'));
    datasEspeciais($hoje, $ate); // aquece o cache
    $saida = [];
    for ($t = strtotime($hoje); date('Y-m-d', $t) <= $ate; $t = strtotime('+1 day', $t)) {
        $d = date('Y-m-d', $t);
        foreach ($ids as $id) {
            if (jornada($id, $d)) {
                $saida[] = $d;
                break;
            }
        }
    }
    return $saida;
}

/**
 * Intervalos ocupados no dia, por profissional: [id => [[inicio, fim], ...]].
 * Junta agendamentos ativos e bloqueios (bloqueio sem profissional vale pra todas).
 */
function ocupacao(string $data, array $profIds): array
{
    $ocup = array_fill_keys($profIds, []);
    if (!$profIds) {
        return $ocup;
    }

    $ini = "$data 00:00:00";
    $fim = date('Y-m-d', strtotime("$data +1 day")) . ' 00:00:00';

    $st = db()->prepare(
        "SELECT profissional_id, inicio, fim FROM agendamentos
                          WHERE status IN " .
            STATUS_ATIVOS .
            ' AND inicio < ? AND fim > ?',
    );
    $st->execute([$fim, $ini]);
    foreach ($st as $r) {
        if (isset($ocup[$r['profissional_id']])) {
            $ocup[$r['profissional_id']][] = [strtotime($r['inicio']), strtotime($r['fim'])];
        }
    }

    $st = db()->prepare('SELECT profissional_id, inicio, fim FROM bloqueios WHERE inicio < ? AND fim > ?');
    $st->execute([$fim, $ini]);
    foreach ($st as $r) {
        $intervalo = [strtotime($r['inicio']), strtotime($r['fim'])];
        if ($r['profissional_id'] === null) {
            foreach ($ocup as $id => $_) {
                $ocup[$id][] = $intervalo;
            }
        } elseif (isset($ocup[$r['profissional_id']])) {
            $ocup[$r['profissional_id']][] = $intervalo;
        }
    }
    return $ocup;
}

function sobrepoe(int $a1, int $a2, int $b1, int $b2): bool
{
    return $a1 < $b2 && $b1 < $a2;
}

function livre(array $intervalos, int $ini, int $fim): bool
{
    foreach ($intervalos as [$a, $b]) {
        if (sobrepoe($ini, $fim, $a, $b)) {
            return false;
        }
    }
    return true;
}

/**
 * Horários disponíveis num dia pra um serviço, respeitando a jornada de cada colaboradora.
 * Retorna [['hora' => '09:00', 'profissionais' => [ids livres]], ...]
 */
function horariosDisponiveis(string $data, int $duracaoMin, array $profIds): array
{
    if (!dataPermitida($data) || !$profIds) {
        return [];
    }
    $jornadas = [];
    foreach ($profIds as $id) {
        if ($j = jornada($id, $data)) {
            $jornadas[$id] = $j;
        }
    }
    if (!$jornadas) {
        return [];
    }

    $passo = (int) cfg('intervalo_slots_min') * 60;
    $dur = $duracaoMin * 60;
    $minimo = time() + (int) cfg('antecedencia_min') * 60;
    $ocup = ocupacao($data, array_keys($jornadas));

    // Os horários partem de uma grade única (00:00 + passo) pra todas ficarem alinhadas
    $base = strtotime("$data 00:00");
    $comeco = min(array_column($jornadas, 'ini'));
    $comeco = $base + (int) ceil(($comeco - $base) / $passo) * $passo;
    $termino = max(array_column($jornadas, 'fim'));

    $saida = [];
    for ($t = $comeco; $t + $dur <= $termino; $t += $passo) {
        if ($t < $minimo) {
            continue;
        }
        $livres = [];
        foreach ($jornadas as $id => $j) {
            if ($t < $j['ini'] || $t + $dur > $j['fim']) {
                continue;
            }
            if ($j['pausa'] && sobrepoe($t, $t + $dur, $j['pausa'][0], $j['pausa'][1])) {
                continue;
            }
            if (livre($ocup[$id], $t, $t + $dur)) {
                $livres[] = $id;
            }
        }
        if ($livres) {
            $saida[] = ['hora' => date('H:i', $t), 'profissionais' => $livres];
        }
    }
    return $saida;
}

/** Serviço em dupla: só os horários em que pelo menos duas estão livres juntas. */
function horariosDupla(string $data, int $duracaoMin, array $profIds): array
{
    $saida = [];
    foreach (horariosDisponiveis($data, $duracaoMin, $profIds) as $s) {
        if (count($s['profissionais']) >= 2) {
            $saida[] = $s;
        }
    }
    return $saida;
}

/** Horários do serviço: em dupla ou normal. */
function horariosDoServico(array $serv, string $data, array $profIds): array
{
    return !empty($serv['dupla'])
        ? horariosDupla($data, (int) $serv['duracao_min'], $profIds)
        : horariosDisponiveis($data, (int) $serv['duracao_min'], $profIds);
}

/** "Ana", "Ana e Bia" */
function nomesJuntos(array $nomes): string
{
    $ult = array_pop($nomes);
    return $nomes ? implode(', ', $nomes) . ' e ' . $ult : (string) $ult;
}

/** Converte "R$ 15,50", "15.5", "15" em número. */
function lerValor(string $v): float
{
    $v = preg_replace('/[^\d,.\-]/', '', $v);
    if ($v === '' || $v === '-') {
        return 0.0;
    }
    if (strpos($v, ',') !== false) {
        $v = str_replace('.', '', $v);
    } // 1.234,56
    return round((float) str_replace(',', '.', $v), 2);
}

/** Deixa o telefone só com DDD + número (10 ou 11 dígitos). Retorna '' se inválido. */
function normalizarTelefone(string $tel): string
{
    $t = ltrim(preg_replace('/\D/', '', $tel), '0'); // tira o 0 de "0 73 ..."
    if (strlen($t) > 11 && strpos($t, '55') === 0) {
        $t = substr($t, 2);
    } // veio com +55
    if (strlen($t) < 10 || strlen($t) > 11) {
        return '';
    }
    if ((int) substr($t, 0, 2) < 11) {
        return '';
    } // DDD não existe
    return $t;
}

function telefoneBonito(string $t): string
{
    if (strlen($t) === 11) {
        return sprintf('(%s) %s-%s', substr($t, 0, 2), substr($t, 2, 5), substr($t, 7));
    }
    if (strlen($t) === 10) {
        return sprintf('(%s) %s-%s', substr($t, 0, 2), substr($t, 2, 4), substr($t, 6));
    }
    return $t;
}

function dadosDoAgendamento(int $id): array
{
    $st = db()->prepare('SELECT a.*, s.nome servico, p.nome profissional FROM agendamentos a
                         JOIN servicos s ON s.id = a.servico_id JOIN profissionais p ON p.id = a.profissional_id WHERE a.id = ?');
    $st->execute([$id]);
    $a = $st->fetch();
    $nome = $a['profissional'];
    $profId = (int) $a['profissional_id'];
    $preco = (float) $a['preco'];
    if ($a['grupo']) {
        // Serviço em dupla: junta as duas profissionais e o valor das duas partes
        $st = db()->prepare(
            'SELECT p.nome, a.preco FROM agendamentos a JOIN profissionais p ON p.id = a.profissional_id WHERE a.grupo = ? ORDER BY a.id',
        );
        $st->execute([$a['grupo']]);
        $partes = $st->fetchAll();
        $nome = nomesJuntos(array_column($partes, 'nome'));
        $preco = array_sum(array_map('floatval', array_column($partes, 'preco')));
        $profId = 0;
    }
    return [
        'id' => (int) $a['id'],
        'grupo' => $a['grupo'] ? (int) $a['grupo'] : null,
        'servico' => $a['servico'],
        'profissional' => $nome,
        'profissional_id' => $profId,
        'data' => substr($a['inicio'], 0, 10),
        'hora' => substr($a['inicio'], 11, 5),
        'preco' => $preco,
        'cliente_nome' => $a['cliente_nome'],
        'cliente_telefone' => $a['cliente_telefone'],
        'observacao' => $a['observacao'],
    ];
}

/**
 * Cria o agendamento. Protegido contra:
 * - duas clientes no mesmo horário da mesma profissional (trava no banco);
 * - a mesma cliente tocar duas vezes / reenviar depois de cair a internet (devolve o mesmo agendamento);
 * - a mesma cliente em dois horários que se sobrepõem, ou marcando demais.
 * Retorna os dados do agendamento + 'novo' => false quando já existia.
 */
function agendar(array $d): array
{
    $serv = servicoPorId((int) $d['servico']);
    if (!$serv) {
        throw new InvalidArgumentException('Escolha um serviço da lista.');
    }

    $data = (string) $d['data'];
    $hora = (string) $d['hora'];
    if (!dataPermitida($data)) {
        throw new InvalidArgumentException('Esse dia não está disponível. Escolha outro dia.');
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
        throw new InvalidArgumentException('Horário inválido.');
    }

    $tel = normalizarTelefone((string) $d['telefone']);
    if ($tel === '') {
        throw new InvalidArgumentException('Digite o WhatsApp com DDD, ex.: (73) 99999-9999.');
    }

    $todas = array_map('intval', array_column(profissionaisAtivas(), 'id'));
    $pedida = (int) $d['profissional'];
    if ($pedida && !in_array($pedida, $todas, true)) {
        throw new InvalidArgumentException('Profissional não encontrada.');
    }
    $dupla = !empty($serv['dupla']);
    if ($dupla) {
        $pedida = 0;
    } // em dupla o sistema escolhe as duas
    $candidatas = $pedida ? [$pedida] : $todas;
    if (!$candidatas) {
        throw new InvalidArgumentException('Nenhuma profissional disponível no momento.');
    }

    $ini = strtotime("$data $hora");
    $fim = $ini + (int) $serv['duracao_min'] * 60;
    $iniSql = date('Y-m-d H:i:s', $ini);
    $fimSql = date('Y-m-d H:i:s', $fim);

    $pdo = db();
    // Trava por telefone: dois envios da mesma cliente ao mesmo tempo viram um só
    $chave = 'agenda_tel_' . $tel;
    $pdo->prepare('SELECT GET_LOCK(?, 10)')->execute([$chave]);
    try {
        // Reenvio do mesmo pedido (toque duplo, internet caiu na hora da resposta)
        $st = $pdo->prepare("SELECT id FROM agendamentos WHERE status = 'confirmado' AND cliente_telefone = ?
                              AND servico_id = ? AND inicio = ? AND (? = 0 OR profissional_id = ?) ORDER BY id LIMIT 1");
        $st->execute([$tel, $serv['id'], $iniSql, $pedida, $pedida]);
        if ($idExistente = $st->fetchColumn()) {
            return dadosDoAgendamento((int) $idExistente) + ['novo' => false];
        }

        // Mesma cliente com outro horário que bate com esse
        $st = $pdo->prepare(
            'SELECT inicio FROM agendamentos WHERE status IN ' .
                STATUS_ATIVOS .
                " AND cliente_telefone = ?
                              AND inicio < ? AND fim > ? LIMIT 1",
        );
        $st->execute([$tel, $fimSql, $iniSql]);
        if ($conflito = $st->fetchColumn()) {
            throw new RuntimeException(
                'Você já tem um horário marcado às ' .
                    substr($conflito, 11, 5) .
                    ' desse dia. Escolha um horário depois que ele terminar, ou fale com o estúdio pelo WhatsApp.',
            );
        }

        // Limite de horários futuros por cliente (evita brincadeira e agenda travada)
        $max = (int) (cfg('max_agendamentos_por_cliente') ?? 3);
        if ($max > 0) {
            $st = $pdo->prepare(
                "SELECT COUNT(DISTINCT COALESCE(grupo, id)) FROM agendamentos WHERE status = 'confirmado' AND cliente_telefone = ? AND fim > NOW()",
            );
            $st->execute([$tel]);
            if ((int) $st->fetchColumn() >= $max) {
                throw new RuntimeException(
                    "Você já tem $max horários marcados. Pra marcar mais, fale com o estúdio pelo WhatsApp.",
                );
            }
        }

        $pdo->beginTransaction();
        try {
            // Trava as profissionais: duas clientes no mesmo horário esperam uma pela outra
            $in = implode(',', array_fill(0, count($candidatas), '?'));
            $pdo->prepare("SELECT id FROM profissionais WHERE id IN ($in) FOR UPDATE")->execute($candidatas);

            $livres = null;
            foreach (horariosDoServico($serv, $data, $candidatas) as $s) {
                if ($s['hora'] === $hora) {
                    $livres = $s['profissionais'];
                    break;
                }
            }
            if (!$livres) {
                throw new RuntimeException(
                    $dupla
                        ? 'Nesse horário não tem mais duas manicures livres juntas. Escolha outro.'
                        : 'Esse horário não está mais disponível. Escolha outro.',
                );
            }

            // Sem preferência: fica com quem tem menos atendimentos no dia
            $escolhida = $livres[0];
            $ordem = $livres;
            if (!$pedida && count($livres) > 1) {
                $in2 = implode(',', array_fill(0, count($livres), '?'));
                $st = $pdo->prepare(
                    "SELECT profissional_id, COUNT(*) n FROM agendamentos
                                      WHERE status IN " .
                        STATUS_ATIVOS .
                        " AND inicio >= ? AND inicio < ? AND profissional_id IN ($in2)
                                      GROUP BY profissional_id",
                );
                $st->execute(
                    array_merge(["$data 00:00:00", date('Y-m-d', strtotime("$data +1 day")) . ' 00:00:00'], $livres),
                );
                $carga = array_fill_keys($livres, 0);
                foreach ($st as $r) {
                    $carga[(int) $r['profissional_id']] = (int) $r['n'];
                }
                asort($carga);
                $ordem = array_map('intval', array_keys($carga));
                $escolhida = $ordem[0];
            }

            $ins = $pdo->prepare('INSERT INTO agendamentos (profissional_id, servico_id, cliente_id, inicio, fim, cliente_nome, cliente_telefone, observacao, preco, lembrete)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            if ($dupla) {
                // Uma linha pra cada profissional; o valor fica dividido entre as duas (vai pro caixa de cada uma)
                $metade = round((float) $serv['preco'] / 2, 2);
                $partes = [[$ordem[0], (float) $serv['preco'] - $metade], [$ordem[1], $metade]];
                $id = 0;
                foreach ($partes as $i => [$pid, $valor]) {
                    // Lembrete pra cliente sai uma vez só (pela primeira linha)
                    $ins->execute([
                        $pid,
                        $serv['id'],
                        $d['cliente_id'] ?? null,
                        $iniSql,
                        $fimSql,
                        $d['nome'],
                        $tel,
                        $d['observacao'] ?: null,
                        $valor,
                        $i ? 'pulado' : 'pendente',
                    ]);
                    if (!$id) {
                        $id = (int) $pdo->lastInsertId();
                    }
                    $pdo->prepare('UPDATE agendamentos SET grupo = ? WHERE id = ?')->execute([
                        $id,
                        (int) $pdo->lastInsertId(),
                    ]);
                }
            } else {
                $ins->execute([
                    $escolhida,
                    $serv['id'],
                    $d['cliente_id'] ?? null,
                    $iniSql,
                    $fimSql,
                    $d['nome'],
                    $tel,
                    $d['observacao'] ?: null,
                    $serv['preco'],
                    'pendente',
                ]);
                $id = (int) $pdo->lastInsertId();
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ex;
        }
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$chave]);
    }

    return dadosDoAgendamento($id) + ['novo' => true];
}

/** "1 serviço" / "2 serviços" */
function plural(int $n, string $um, string $varios): string
{
    return $n . ' ' . ($n === 1 ? $um : $varios);
}

function diaSemana(string $data): string
{
    return ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'][(int) date('w', strtotime($data))];
}

function dataExtenso(string $data): string
{
    $meses = [
        'janeiro',
        'fevereiro',
        'março',
        'abril',
        'maio',
        'junho',
        'julho',
        'agosto',
        'setembro',
        'outubro',
        'novembro',
        'dezembro',
    ];
    $t = strtotime($data);
    return diaSemana($data) . ', ' . date('j', $t) . ' de ' . $meses[(int) date('n', $t) - 1];
}
