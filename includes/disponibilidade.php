<?php
// Semana de trabalho e dias específicos de cada colaboradora.
require_once __DIR__ . '/app.php';

const DIAS_SEMANA = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 0 => 'Domingo'];

function horaValida(?string $h): ?string
{
    return $h && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h) ? $h : null;
}

/** Semana atual da colaboradora pro formulário (se ela não configurou, mostra o horário do estúdio). */
function semanaDaColaboradora(int $prof): array
{
    $linhas = semanas()[$prof] ?? null;
    $alm = cfg('almoco');
    $saida = [];
    foreach (DIAS_SEMANA as $d => $_) {
        if ($linhas !== null) {
            $l = $linhas[$d] ?? null;
            $saida[$d] = $l
                ? ['trabalha' => true, 'inicio' => substr($l['inicio'], 0, 5), 'fim' => substr($l['fim'], 0, 5),
                   'pausa_inicio' => $l['pausa_inicio'] ? substr($l['pausa_inicio'], 0, 5) : '', 'pausa_fim' => $l['pausa_fim'] ? substr($l['pausa_fim'], 0, 5) : '']
                : ['trabalha' => false, 'inicio' => '09:00', 'fim' => '18:00', 'pausa_inicio' => '', 'pausa_fim' => ''];
        } else {
            $h = cfg('horarios')[$d] ?? null;
            $saida[$d] = ['trabalha' => (bool) $h, 'inicio' => $h[0] ?? '09:00', 'fim' => $h[1] ?? '18:00',
                          'pausa_inicio' => $h && $alm ? $alm[0] : '', 'pausa_fim' => $h && $alm ? $alm[1] : ''];
        }
    }
    return ['dias' => $saida, 'configurada' => $linhas !== null];
}

/** Salva a semana. Retorna [ok, mensagem]. */
function salvarSemana(int $prof, array $post): array
{
    $novas = [];
    foreach (DIAS_SEMANA as $d => $nome) {
        if (empty($post['trabalha'][$d])) continue;
        $ini = horaValida($post['inicio'][$d] ?? null);
        $fim = horaValida($post['fim'][$d] ?? null);
        if (!$ini || !$fim || $ini >= $fim) return [false, "$nome: o horário de saída precisa ser depois do de entrada."];
        $pi = horaValida($post['pausa_inicio'][$d] ?? null);
        $pf = horaValida($post['pausa_fim'][$d] ?? null);
        if (($pi xor $pf) || ($pi && ($pi >= $pf || $pi < $ini || $pf > $fim))) {
            return [false, "$nome: a pausa precisa ter começo e fim, dentro do horário de trabalho."];
        }
        $novas[$d] = [$ini, $fim, $pi, $pf];
    }
    if (!$novas) return [false, 'Marque pelo menos um dia de trabalho. Pra folgas avulsas, use "Dias específicos".'];

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM disponibilidade_semana WHERE profissional_id = ?')->execute([$prof]);
    $ins = $pdo->prepare('INSERT INTO disponibilidade_semana (profissional_id, dia_semana, inicio, fim, pausa_inicio, pausa_fim) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($novas as $d => [$ini, $fim, $pi, $pf]) $ins->execute([$prof, $d, $ini, $fim, $pi, $pf]);
    $pdo->commit();
    return [true, 'Semana salva. Os horários do link de agendamento já seguem ela.' . avisoConflitos($prof)];
}

/** Salva um dia específico (folga ou horário diferente). */
function salvarDiaEspecifico(int $prof, array $post): array
{
    $data = (string) ($post['data'] ?? '');
    $d = DateTime::createFromFormat('!Y-m-d', $data);
    if (!$d || $d->format('Y-m-d') !== $data) return [false, 'Escolha o dia.'];
    if ($data < date('Y-m-d')) return [false, 'Escolha hoje ou um dia que ainda vai chegar.'];
    $trabalha = ($post['tipo'] ?? '') === 'horario';
    $ini = $fim = null;
    if ($trabalha) {
        $ini = horaValida($post['inicio'] ?? null);
        $fim = horaValida($post['fim'] ?? null);
        if (!$ini || !$fim || $ini >= $fim) return [false, 'O horário de saída precisa ser depois do de entrada.'];
    }
    db()->prepare('INSERT INTO disponibilidade_datas (profissional_id, data, trabalha, inicio, fim) VALUES (?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE trabalha = VALUES(trabalha), inicio = VALUES(inicio), fim = VALUES(fim)')
        ->execute([$prof, $data, $trabalha ? 1 : 0, $ini, $fim]);
    $quando = dataExtenso($data);
    return [true, ($trabalha ? "Horário de $quando salvo: $ini às $fim." : "Folga em $quando salva.") . avisoConflitos($prof, $data)];
}

function removerDiaEspecifico(int $prof, int $id): void
{
    db()->prepare('DELETE FROM disponibilidade_datas WHERE id = ? AND profissional_id = ?')->execute([$id, $prof]);
}

function diasEspecificos(int $prof): array
{
    $st = db()->prepare('SELECT * FROM disponibilidade_datas WHERE profissional_id = ? AND data >= CURDATE() ORDER BY data');
    $st->execute([$prof]);
    return $st->fetchAll();
}

/** Horários já marcados que ficaram fora da nova disponibilidade (não são cancelados, só avisados). */
function avisoConflitos(int $prof, ?string $soData = null): string
{
    $st = db()->prepare("SELECT inicio, fim, cliente_nome FROM agendamentos WHERE profissional_id = ? AND status = 'confirmado' AND inicio >= NOW()"
        . ($soData ? ' AND DATE(inicio) = ?' : '') . ' ORDER BY inicio LIMIT 200');
    $st->execute($soData ? [$prof, $soData] : [$prof]);
    $fora = [];
    foreach ($st as $a) {
        $d = substr($a['inicio'], 0, 10);
        // limpa o cache estático lendo direto
        $j = jornadaSemCache($prof, $d);
        $i = strtotime($a['inicio']); $f = strtotime($a['fim']);
        if (!$j || $i < $j['ini'] || $f > $j['fim']) $fora[] = date('d/m H:i', $i) . ' (' . $a['cliente_nome'] . ')';
    }
    if (!$fora) return '';
    return ' Atenção: ' . count($fora) . (count($fora) === 1 ? ' horário já marcado ficou' : ' horários já marcados ficaram')
        . ' fora do novo horário e continuam na agenda: ' . implode(', ', array_slice($fora, 0, 5)) . (count($fora) > 5 ? '…' : '')
        . '. Remarque ou cancele com a cliente.';
}

/** Igual a jornada(), mas lendo do banco na hora (depois de salvar). */
function jornadaSemCache(int $prof, string $data): ?array
{
    $st = db()->prepare('SELECT * FROM disponibilidade_datas WHERE profissional_id = ? AND data = ?');
    $st->execute([$prof, $data]);
    if ($esp = $st->fetch()) {
        if (!(int) $esp['trabalha']) return null;
        return ['ini' => strtotime("$data {$esp['inicio']}"), 'fim' => strtotime("$data {$esp['fim']}")];
    }
    $st = db()->prepare('SELECT COUNT(*) FROM disponibilidade_semana WHERE profissional_id = ?');
    $st->execute([$prof]);
    if ((int) $st->fetchColumn()) {
        $st = db()->prepare('SELECT * FROM disponibilidade_semana WHERE profissional_id = ? AND dia_semana = ?');
        $st->execute([$prof, (int) date('w', strtotime($data))]);
        $l = $st->fetch();
        return $l ? ['ini' => strtotime("$data {$l['inicio']}"), 'fim' => strtotime("$data {$l['fim']}")] : null;
    }
    $exp = expediente($data);
    return $exp ? ['ini' => $exp[0], 'fim' => $exp[1]] : null;
}

/** Formulários de semana + dias específicos. $campos: campos escondidos extras (ex.: prof no painel). */
function renderDisponibilidade(int $prof, string $nome, string $campos): void
{
    $semana = semanaDaColaboradora($prof);
    $datas = diasEspecificos($prof);
    ?>
  <section class="bloco" id="semana">
    <h2>Semana de trabalho</h2>
    <p class="topo-info">
      <?= $semana['configurada']
        ? 'Os dias e horários em que ' . e($nome) . ' atende. A cliente só vê horários dentro deles.'
        : 'Ainda está usando o horário do estúdio. Ajuste e salve pra valer o horário de ' . e($nome) . '.' ?>
    </p>
    <form method="post" class="semana">
      <?= $campos ?>
      <input type="hidden" name="acao" value="semana">
      <?php foreach (DIAS_SEMANA as $d => $rotulo): $l = $semana['dias'][$d]; ?>
        <fieldset class="semana-dia<?= $l['trabalha'] ? '' : ' folga' ?>">
          <legend class="sr">Horário de <?= $rotulo ?></legend>
          <label class="semana-check">
            <input type="checkbox" name="trabalha[<?= $d ?>]" value="1" <?= $l['trabalha'] ? 'checked' : '' ?> onchange="this.closest('fieldset').classList.toggle('folga', !this.checked)">
            <span><?= $rotulo ?></span>
          </label>
          <div class="semana-horas">
            <label><span>Entra</span><input type="time" name="inicio[<?= $d ?>]" value="<?= e($l['inicio']) ?>"></label>
            <label><span>Sai</span><input type="time" name="fim[<?= $d ?>]" value="<?= e($l['fim']) ?>"></label>
            <label><span>Pausa de</span><input type="time" name="pausa_inicio[<?= $d ?>]" value="<?= e($l['pausa_inicio']) ?>"></label>
            <label><span>até</span><input type="time" name="pausa_fim[<?= $d ?>]" value="<?= e($l['pausa_fim']) ?>"></label>
          </div>
          <p class="semana-folga-txt">Folga</p>
        </fieldset>
      <?php endforeach; ?>
      <button class="botao">Salvar semana</button>
    </form>
  </section>

  <section class="bloco" id="dias">
    <h2>Dias específicos</h2>
    <p class="topo-info">Folga num dia, ou um horário diferente só naquela data. Vale mais que a semana.</p>
    <form method="post" class="campos form-bloq dia-especifico">
      <?= $campos ?>
      <input type="hidden" name="acao" value="dia">
      <label class="campo campo-largo"><span>Dia</span><input type="date" name="data" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" required></label>
      <fieldset class="campo-largo tipo-dia">
        <legend>Nesse dia</legend>
        <label><input type="radio" name="tipo" value="folga" checked onchange="this.form.classList.remove('com-horario')"> Folga</label>
        <label><input type="radio" name="tipo" value="horario" onchange="this.form.classList.add('com-horario')"> Trabalho em outro horário</label>
      </fieldset>
      <label class="campo so-horario"><span>Entra</span><input type="time" name="inicio" value="09:00"></label>
      <label class="campo so-horario"><span>Sai</span><input type="time" name="fim" value="18:00"></label>
      <button class="botao campo-largo">Salvar dia</button>
    </form>

    <?php if ($datas): ?>
      <ul class="lista-bloq">
        <?php foreach ($datas as $r): ?>
          <li>
            <span>
              <strong><?= e(ucfirst(dataExtenso($r['data']))) ?></strong><br>
              <?= (int) $r['trabalha'] ? 'Trabalha das ' . substr($r['inicio'], 0, 5) . ' às ' . substr($r['fim'], 0, 5) : 'Folga' ?>
            </span>
            <form method="post">
              <?= $campos ?>
              <input type="hidden" name="acao" value="remover_dia">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="link link-perigo">Remover</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
    <?php
}

/** Trata os POSTs da disponibilidade. Retorna a mensagem. */
function acaoDisponibilidade(int $prof, array $post): string
{
    switch ($post['acao'] ?? '') {
        case 'semana': return salvarSemana($prof, $post)[1];
        case 'dia': return salvarDiaEspecifico($prof, $post)[1];
        case 'remover_dia': removerDiaEspecifico($prof, (int) ($post['id'] ?? 0)); return 'Dia removido. Volta a valer a semana.';
    }
    return '';
}
