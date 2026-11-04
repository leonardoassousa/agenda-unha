<?php
// Marcação pelo painel (versão 1.9): a dona cadastra a cliente, marca o horário com o valor que combinou
// e, se quiser, deixa o mesmo horário fixo toda semana.
require_once __DIR__ . '/app.php';

const SERIE_SEMANAS_MAX = 26; // até meio ano de horário fixo de uma vez

/** Cria o que a versão 1.9 precisa no banco (roda uma vez; depois só confere rápido). */
function garantirEstruturaPainel(): void
{
    static $feito = false;
    if ($feito) {
        return;
    }
    $feito = true;
    $pdo = db();
    if ($pdo->query("SELECT valor FROM sistema WHERE chave = 'estrutura'")->fetchColumn() === '1.9') {
        return;
    }
    if (!$pdo->query("SHOW COLUMNS FROM agendamentos LIKE 'origem'")->fetch()) {
        $pdo->exec("ALTER TABLE agendamentos ADD COLUMN origem ENUM('site','painel') NOT NULL DEFAULT 'site' AFTER grupo");
    }
    if (!$pdo->query("SHOW COLUMNS FROM agendamentos LIKE 'serie'")->fetch()) {
        $pdo->exec('ALTER TABLE agendamentos ADD COLUMN serie INT UNSIGNED NULL AFTER origem, ADD KEY idx_serie (serie)');
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS series (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        dia_semana TINYINT UNSIGNED NOT NULL,
        hora TIME NOT NULL,
        ate DATE NOT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // Cliente cadastrada pelo estúdio pode não ter e-mail
    $col = $pdo->query("SHOW COLUMNS FROM clientes LIKE 'email'")->fetch();
    if ($col && strtoupper((string) $col['Null']) === 'NO') {
        $pdo->exec('ALTER TABLE clientes MODIFY email VARCHAR(150) NULL');
    }
    $pdo->exec("REPLACE INTO sistema (chave, valor) VALUES ('estrutura', '1.9')");
}

/** Clientes pra busca do painel (as mais recentes primeiro). */
function clientesParaBusca(): array
{
    return db()
        ->query('SELECT id, nome, telefone, email FROM clientes ORDER BY nome LIMIT 5000')
        ->fetchAll();
}

/**
 * Cadastro feito pela dona: nome e celular (e-mail opcional). Fica sem senha: quando a cliente
 * for "Criar conta" no site com esse celular, ela cria a senha e a ficha continua a mesma.
 * Se o celular já tem conta, devolve essa conta.
 */
function cadastrarClientePeloPainel(string $nome, string $telefone, string $email = ''): int
{
    garantirEstruturaPainel();
    $nome = trim(preg_replace('/\s+/', ' ', strip_tags($nome)));
    $tel = normalizarTelefone($telefone);
    $email = mb_strtolower(trim($email));
    if (mb_strlen($nome) < 2 || mb_strlen($nome) > 100) {
        throw new InvalidArgumentException('Digite o nome da cliente.');
    }
    if ($tel === '') {
        throw new InvalidArgumentException('Digite o celular da cliente com DDD, ex.: (73) 99999-9999.');
    }
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150)) {
        throw new InvalidArgumentException('O e-mail não parece certo. Pode deixar em branco.');
    }
    $st = db()->prepare('SELECT id FROM clientes WHERE telefone = ?');
    $st->execute([$tel]);
    if ($id = $st->fetchColumn()) {
        return (int) $id;
    }
    if ($email !== '') {
        $st = db()->prepare('SELECT 1 FROM clientes WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetchColumn()) {
            throw new InvalidArgumentException('Esse e-mail já está na ficha de outra cliente. Deixe em branco ou use outro.');
        }
    }
    db()
        ->prepare("INSERT INTO clientes (nome, telefone, email, senha_hash) VALUES (?, ?, ?, '')")
        ->execute([$nome, $tel, $email ?: null]);
    return (int) db()->lastInsertId();
}

/** Intervalo livre pra essa profissional? (horários marcados e bloqueios) */
function profLivre(int $prof, int $ini, int $fim): bool
{
    $data = date('Y-m-d', $ini);
    return livre(ocupacao($data, [$prof])[$prof], $ini, $fim);
}

/** "sexta" */
function nomeDiaSemana(int $dia): string
{
    return ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'][$dia];
}

/**
 * Marca pelo painel. $d: cliente_id, servico, profs ([id] ou [id, id] em dupla), data, hora, valor (total),
 * observacao, repetir (bool), ate (Y-m-d, último dia da repetição).
 * Retorna ['ids' => [primeiro id de cada dia marcado], 'pulados' => ['d/m' => motivo], 'serie' => id|null,
 *          'fora_do_horario' => bool].
 */
function agendarPeloPainel(array $d): array
{
    garantirEstruturaPainel();
    $serv = servicoPorId((int) ($d['servico'] ?? 0));
    if (!$serv) {
        throw new InvalidArgumentException('Escolha o serviço.');
    }
    $st = db()->prepare('SELECT id, nome, telefone FROM clientes WHERE id = ?');
    $st->execute([(int) ($d['cliente_id'] ?? 0)]);
    $cli = $st->fetch();
    if (!$cli) {
        throw new InvalidArgumentException('Escolha a cliente (ou cadastre uma nova).');
    }

    $todas = array_map('intval', array_column(profissionaisAtivas(), 'id'));
    $profs = array_values(array_unique(array_map('intval', (array) ($d['profs'] ?? []))));
    $profs = array_values(array_intersect($profs, $todas));
    $dupla = !empty($serv['dupla']);
    if ($dupla && count($profs) !== 2) {
        throw new InvalidArgumentException('Esse serviço é em dupla: marque as duas manicures.');
    }
    if (!$dupla && count($profs) !== 1) {
        throw new InvalidArgumentException('Escolha a manicure.');
    }

    $data = (string) ($d['data'] ?? '');
    $hora = (string) ($d['hora'] ?? '');
    $dt = DateTime::createFromFormat('!Y-m-d', $data);
    if (!$dt || $dt->format('Y-m-d') !== $data) {
        throw new InvalidArgumentException('Escolha o dia.');
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
        throw new InvalidArgumentException('Escolha o horário.');
    }
    if (strtotime("$data $hora") < time() - 12 * 3600) {
        throw new InvalidArgumentException('Esse dia já passou. Escolha uma data de hoje em diante.');
    }

    $valor = lerValor((string) ($d['valor'] ?? ''));
    if ($valor < 0 || $valor > 10000) {
        throw new InvalidArgumentException('Confira o valor: ele precisa ficar entre R$ 0 e R$ 10.000.');
    }
    $obs = mb_substr(trim(strip_tags((string) ($d['observacao'] ?? ''))), 0, 255);

    // Dias a marcar: só esse, ou toda semana até a data escolhida
    $datas = [$data];
    $serie = null;
    if (!empty($d['repetir'])) {
        $ate = (string) ($d['ate'] ?? '');
        $dtAte = DateTime::createFromFormat('!Y-m-d', $ate);
        if (!$dtAte || $dtAte->format('Y-m-d') !== $ate || $ate <= $data) {
            throw new InvalidArgumentException('Escolha até quando o horário se repete (uma data depois do primeiro dia).');
        }
        for ($i = 1; $i <= SERIE_SEMANAS_MAX; $i++) {
            $prox = date('Y-m-d', strtotime("$data +$i week"));
            if ($prox > $ate) {
                break;
            }
            $datas[] = $prox;
        }
        if (count($datas) < 2) {
            throw new InvalidArgumentException('Até essa data não cabe nenhuma semana a mais. Escolha uma data mais pra frente.');
        }
    }

    $dur = (int) $serv['duracao_min'] * 60;
    $metade = round($valor / 2, 2);
    $partes = $dupla ? [[$profs[0], $valor - $metade], [$profs[1], $metade]] : [[$profs[0], $valor]];
    $pdo = db();
    $ids = [];
    $pulados = [];
    $fora = false;

    $pdo->beginTransaction();
    try {
        $in = implode(',', array_fill(0, count($profs), '?'));
        $pdo->prepare("SELECT id FROM profissionais WHERE id IN ($in) FOR UPDATE")->execute($profs);

        if (count($datas) > 1) {
            $pdo->prepare('INSERT INTO series (dia_semana, hora, ate) VALUES (?, ?, ?)')->execute([
                (int) $dt->format('w'),
                $hora,
                end($datas),
            ]);
            $serie = (int) $pdo->lastInsertId();
        }

        $ins = $pdo->prepare("INSERT INTO agendamentos (profissional_id, servico_id, cliente_id, inicio, fim, cliente_nome, cliente_telefone,
                observacao, preco, lembrete, origem, serie) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'painel', ?)");
        foreach ($datas as $dia) {
            $ini = strtotime("$dia $hora");
            $fim = $ini + $dur;
            $ocupadas = [];
            foreach ($profs as $p) {
                if (!profLivre($p, $ini, $fim)) {
                    $ocupadas[] = $p;
                }
                $j = jornada($p, $dia);
                if (!$j || $ini < $j['ini'] || $fim > $j['fim']) {
                    $fora = true;
                }
            }
            if ($ocupadas) {
                $nomes = array_map(fn($id) => explode(' ', profissionalNome($id))[0], $ocupadas);
                $pulados[date('d/m', $ini)] = nomesJuntos($nomes) . (count($ocupadas) > 1 ? ' já têm' : ' já tem') . ' horário ou bloqueio';
                continue;
            }
            $primeiro = 0;
            foreach ($partes as $i => [$pid, $v]) {
                $ins->execute([
                    $pid,
                    $serv['id'],
                    $cli['id'],
                    date('Y-m-d H:i:s', $ini),
                    date('Y-m-d H:i:s', $fim),
                    $cli['nome'],
                    $cli['telefone'],
                    $obs ?: null,
                    $v,
                    $i ? 'pulado' : 'pendente',
                    $serie,
                ]);
                $novo = (int) $pdo->lastInsertId();
                if (!$primeiro) {
                    $primeiro = $novo;
                }
                if ($dupla) {
                    $pdo->prepare('UPDATE agendamentos SET grupo = ? WHERE id = ?')->execute([$primeiro, $novo]);
                }
            }
            $ids[] = $primeiro;
        }
        if (!$ids) {
            throw new RuntimeException(
                count($datas) > 1
                    ? 'Nenhuma semana ficou livre nesse horário. Escolha outro horário.'
                    : 'Esse horário já está ocupado (' . reset($pulados) . '). Escolha outro.',
            );
        }
        if ($serie && count($ids) < 2) {
            // Só sobrou um dia: não é horário fixo
            $pdo->prepare('UPDATE agendamentos SET serie = NULL WHERE serie = ?')->execute([$serie]);
            $pdo->prepare('DELETE FROM series WHERE id = ?')->execute([$serie]);
            $serie = null;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['ids' => $ids, 'pulados' => $pulados, 'serie' => $serie, 'fora_do_horario' => $fora];
}

function profissionalNome(int $id): string
{
    foreach (profissionaisAtivas() as $p) {
        if ((int) $p['id'] === $id) {
            return $p['nome'];
        }
    }
    return '';
}

/** Dados do horário fixo: ['texto' => 'Toda sexta, às 14:00, até 18/12', 'restantes' => n] ou null. */
function dadosSerie(?int $serie): ?array
{
    if (!$serie) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM series WHERE id = ?');
    $st->execute([$serie]);
    $s = $st->fetch();
    if (!$s) {
        return null;
    }
    $st = db()->prepare(
        "SELECT COUNT(DISTINCT COALESCE(grupo, id)) FROM agendamentos WHERE serie = ? AND status = 'confirmado' AND inicio > NOW()",
    );
    $st->execute([$serie]);
    $dia = nomeDiaSemana((int) $s['dia_semana']);
    return [
        'texto' => ($dia === 'sábado' || $dia === 'domingo' ? 'Todo ' : 'Toda ') . $dia . ', até ' . date('d/m', strtotime($s['ate'])),
        'restantes' => (int) $st->fetchColumn(),
    ];
}

/** Cancela este e todos os próximos horários do horário fixo. Retorna quantos dias foram cancelados. */
function cancelarSerieAPartir(int $agId): int
{
    $st = db()->prepare('SELECT serie, inicio FROM agendamentos WHERE id = ?');
    $st->execute([$agId]);
    $a = $st->fetch();
    if (!$a || !$a['serie']) {
        return 0;
    }
    $st = db()->prepare(
        "SELECT COUNT(DISTINCT COALESCE(grupo, id)) FROM agendamentos WHERE serie = ? AND status = 'confirmado' AND inicio >= ?",
    );
    $st->execute([$a['serie'], $a['inicio']]);
    $n = (int) $st->fetchColumn();
    db()
        ->prepare("UPDATE agendamentos SET status = 'cancelado' WHERE serie = ? AND status = 'confirmado' AND inicio >= ?")
        ->execute([$a['serie'], $a['inicio']]);
    return $n;
}
