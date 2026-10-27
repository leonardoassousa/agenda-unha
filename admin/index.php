<?php
require __DIR__ . '/../includes/admin.php';
require __DIR__ . '/../includes/atendimento.php';

$logada = logada();
$msg = '';
$soEquipe = false;
$temContaPainel = existeContaPainel();

// ---------- Ações ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    conferirCsrf();
    $acao = $_POST['acao'] ?? '';
    $volta = ['mes' => $_POST['mes_ref'] ?? '', 'prof' => $_POST['prof_ref'] ?? '', 'dia' => $_POST['dia_ref'] ?? ''];

    if ($acao === 'entrar') {
        if ($temContaPainel) {
            $msg = entrarEquipe((string) ($_POST['telefone'] ?? ''), (string) ($_POST['senha'] ?? ''));
            if ($msg === '') {
                $st = db()->prepare('SELECT admin FROM profissionais WHERE id = ?');
                $st->execute([(int) $_SESSION['prof_id']]);
                if ((int) $st->fetchColumn() === 1) voltarPara('./');
                $msg = 'Essa conta é da área da equipe e não tem acesso ao painel.';
                $soEquipe = true;
            }
        } else {
            // Primeiro acesso: enquanto ninguém tiver acesso ao painel, vale a senha do config.php
            if ((string) cfg('admin_senha') !== '' && hash_equals((string) cfg('admin_senha'), (string) ($_POST['senha'] ?? ''))) {
                session_regenerate_id(true);
                $_SESSION['admin_inicial'] = true;
                voltarPara('ajustes.php', [], 'acesso');
            }
            sleep(1);
            $msg = 'Senha incorreta.';
        }
    } elseif ($logada) {
        if ($acao === 'sair') {
            $_SESSION = [];
            session_destroy();
            voltarPara('./');
        }
        if (in_array($acao, ['concluir', 'salvar_extra', 'reabrir', 'faltou', 'desfazer', 'cancelar'], true)) {
            $_SESSION['aviso_cal'] = acaoAtendimento($acao, (int) $_POST['id'], $_POST);
            voltarPara('./', $volta, 'detalhe');
        }
    }
}

// ---------- Dados do calendário ----------
if ($logada) {
    $profs = db()->query('SELECT id, nome, cor, foto FROM profissionais WHERE ativo = 1 ORDER BY ordem, nome')->fetchAll();
    $profPorId = array_column($profs, null, 'id');
    $filtroProf = (int) ($_GET['prof'] ?? 0);
    if ($filtroProf && !isset($profPorId[$filtroProf])) $filtroProf = 0;
    $corFiltro = $filtroProf ? $profPorId[$filtroProf]['cor'] : '#9B1B3A';

    $mes = $_GET['mes'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) $mes = date('Y-m');
    $iniMes = "$mes-01";
    $fimMes = date('Y-m-d', strtotime("$iniMes +1 month"));
    $diasNoMes = (int) date('t', strtotime($iniMes));

    $dia = $_GET['dia'] ?? ($mes === date('Y-m') ? date('Y-m-d') : '');
    if ($dia !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia) || substr($dia, 0, 7) !== $mes || !strtotime($dia))) $dia = '';

    // Contagem por dia do mês (só confirmados: falta e cancelado ficam de fora)
    $sql = "SELECT DATE(inicio) d, COUNT(*) n, SUM(preco + extra_valor) v FROM agendamentos
            WHERE status IN " . STATUS_ATIVOS . " AND inicio >= ? AND inicio < ?" . ($filtroProf ? ' AND profissional_id = ?' : '') . ' GROUP BY DATE(inicio)';
    $st = db()->prepare($sql);
    $st->execute($filtroProf ? [$iniMes, $fimMes, $filtroProf] : [$iniMes, $fimMes]);
    $porDia = [];
    foreach ($st as $r) $porDia[$r['d']] = ['n' => (int) $r['n'], 'v' => (float) $r['v']];
    $maxDia = max(array_column($porDia, 'n') ?: [0]);
    $totalMes = array_sum(array_column($porDia, 'n'));
    $valorMes = array_sum(array_column($porDia, 'v'));

    // Bloqueios do mês (marcados no calendário)
    $st = db()->prepare('SELECT inicio, fim, profissional_id FROM bloqueios WHERE inicio < ? AND fim > ?');
    $st->execute([$fimMes, $iniMes]);
    $diasBloq = [];
    foreach ($st as $r) {
        if ($filtroProf && $r['profissional_id'] !== null && (int) $r['profissional_id'] !== $filtroProf) continue;
        for ($t = strtotime(substr($r['inicio'], 0, 10)); $t < strtotime($r['fim']); $t = strtotime('+1 day', $t)) $diasBloq[date('Y-m-d', $t)] = true;
    }

    $mesAnt = date('Y-m', strtotime("$iniMes -1 month"));
    $mesProx = date('Y-m', strtotime("$iniMes +1 month"));
    $nomesMes = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $tituloMes = ucfirst($nomesMes[(int) substr($mes, 5, 2) - 1]) . ' ' . substr($mes, 0, 4);
    $url = fn(array $p) => './?' . http_build_query(array_filter(array_merge(['mes' => $mes, 'prof' => $filtroProf, 'dia' => $dia], $p)));

    // ---------- Detalhe do dia ----------
    if ($dia !== '') {
        $iniDia = "$dia 00:00:00";
        $fimDia = date('Y-m-d', strtotime("$dia +1 day")) . ' 00:00:00';
        $doDia = buscarAtendimentos($dia, $dia, $filtroProf);
        $conf = array_values(array_filter($doDia, fn($a) => in_array($a['status'], ['confirmado', 'concluido'], true)));
        $faltas = count($doDia) - count($conf);
        $valorDia = array_sum(array_map(fn($a) => (float) $a['preco'] + (float) $a['extra_valor'], $conf));
        $feitos = count(array_filter($conf, fn($a) => $a['status'] === 'concluido'));

        // Quantos de cada serviço
        $porServico = [];
        foreach ($conf as $a) $porServico[$a['servico']] = ($porServico[$a['servico']] ?? 0) + 1;
        arsort($porServico);

        // Agenda ocupada: minutos marcados / minutos de trabalho de cada uma
        $listaProfs = $filtroProf ? [$profPorId[$filtroProf]] : $profs;
        $resumoProf = [];
        $algumaTrabalha = false;
        foreach ($listaProfs as $p) {
            $dela = array_values(array_filter($conf, fn($a) => (int) $a['profissional_id'] === (int) $p['id']));
            $minTrab = minutosJornada(jornada((int) $p['id'], $dia));
            $m = 0;
            foreach ($dela as $a) $m += (strtotime($a['fim']) - strtotime($a['inicio'])) / 60;
            $algumaTrabalha = $algumaTrabalha || $minTrab > 0;
            $resumoProf[] = $p + ['n' => count($dela), 'v' => array_sum(array_map(fn($a) => (float) $a['preco'] + (float) $a['extra_valor'], $dela)),
                                  'oc' => $minTrab > 0 ? min(100, (int) round($m / $minTrab * 100)) : null];
        }
        $exp = $algumaTrabalha;
        $comOc = array_filter(array_column($resumoProf, 'oc'), fn($v) => $v !== null);
        $ocupMedia = $comOc ? (int) round(array_sum($comOc) / count($comOc)) : null;

        $st = db()->prepare('SELECT * FROM bloqueios WHERE inicio < ? AND fim > ? ORDER BY inicio');
        $st->execute([$fimDia, $iniDia]);
        $bloqDia = array_filter($st->fetchAll(), fn($b) => !$filtroProf || $b['profissional_id'] === null || (int) $b['profissional_id'] === $filtroProf);
    }

    $aviso = $_SESSION['aviso_cal'] ?? '';
    unset($_SESSION['aviso_cal']);
}

function fotoAdmin(array $p, string $classe = ''): string
{
    return $p['foto']
        ? '<img class="foto ' . $classe . '" src="../' . e($p['foto']) . '" alt="" style="--cor:' . e($p['cor']) . '">'
        : '<span class="foto ' . $classe . '" style="--cor:' . e($p['cor']) . '" aria-hidden="true">' . e(iniciais($p['nome'])) . '</span>';
}

headAdmin('Calendário');
?>

<?php if (!$logada): ?>
  <form method="post" class="login">
    <p class="login-estudio"><?= e(cfg('nome_estudio')) ?></p>
    <h1>Painel</h1>
    <?= campoCsrf() ?>
    <input type="hidden" name="acao" value="entrar">
    <?php if ($temContaPainel): ?>
      <label class="campo">
        <span>Seu celular</span>
        <input type="tel" name="telefone" inputmode="numeric" autocomplete="username" placeholder="(73) 99999-9999" value="<?= e((string) ($_POST['telefone'] ?? '')) ?>" required autofocus>
      </label>
      <label class="campo">
        <span>Senha</span>
        <input type="password" name="senha" autocomplete="current-password" required>
      </label>
    <?php else: ?>
      <p class="topo-info">Primeiro acesso: use a senha que está no <code>config.php</code>. Depois você cria a sua conta com celular e senha.</p>
      <label class="campo">
        <span>Senha de primeiro acesso</span>
        <input type="password" name="senha" autocomplete="current-password" required autofocus>
      </label>
    <?php endif; ?>
    <?php if ($msg): ?><p class="erro" role="alert"><?= e($msg) ?><?php if ($soEquipe): ?> <a href="../equipe/">Ir pra minha agenda</a><?php endif; ?></p><?php endif; ?>
    <button class="botao">Entrar</button>
    <?php if ($temContaPainel): ?><p class="topo-info">É da equipe? Sua agenda fica em <a href="../equipe/">área da equipe</a>.</p><?php endif; ?>
  </form>

<?php else: ?>
  <?php cabecalhoAdmin('index.php'); ?>
  <?php if ($aviso): ?><p class="nota" role="status"><?= e($aviso) ?></p><?php endif; ?>

  <!-- Quem -->
  <nav class="quem" aria-label="Profissional">
    <a href="<?= e($url(['prof' => 0])) ?>" class="quem-item<?= $filtroProf ? '' : ' ativo' ?>" <?= $filtroProf ? '' : 'aria-current="true"' ?>>
      <span class="foto foto-todas" aria-hidden="true"><?= count($profs) ?></span><span>Todas</span>
    </a>
    <?php foreach ($profs as $p): $ativo = $filtroProf === (int) $p['id']; ?>
      <a href="<?= e($url(['prof' => $p['id']])) ?>" class="quem-item<?= $ativo ? ' ativo' : '' ?>" style="--cor: <?= e($p['cor']) ?>" <?= $ativo ? 'aria-current="true"' : '' ?>>
        <?= fotoAdmin($p) ?><span><?= e($p['nome']) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="cal-layout">
  <!-- Mês -->
  <section class="calendario" style="--cor: <?= e($corFiltro) ?>" aria-labelledby="t-mes">
    <div class="cal-topo">
      <a class="seta" href="<?= e($url(['mes' => $mesAnt, 'dia' => ''])) ?>" aria-label="Mês anterior">‹</a>
      <div class="cal-titulo">
        <h1 id="t-mes"><?= e($tituloMes) ?></h1>
        <p><?= $totalMes ?> <?= $totalMes === 1 ? 'atendimento' : 'atendimentos' ?>, <?= preco($valorMes) ?></p>
      </div>
      <a class="seta" href="<?= e($url(['mes' => $mesProx, 'dia' => ''])) ?>" aria-label="Próximo mês">›</a>
    </div>
    <?php if ($mes !== date('Y-m')): ?><p class="cal-hoje"><a href="<?= e($url(['mes' => date('Y-m'), 'dia' => date('Y-m-d')])) ?>">Voltar pra hoje</a></p><?php endif; ?>

    <div class="cal-grade">
      <?php foreach (['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'] as $sem): ?><span class="cal-sem" aria-hidden="true"><?= $sem ?></span><?php endforeach; ?>
      <?php
        $vazios = (int) date('w', strtotime($iniMes));
        for ($i = 0; $i < $vazios; $i++) echo '<span class="cal-vazio" aria-hidden="true"></span>';
        for ($d = 1; $d <= $diasNoMes; $d++):
            $iso = sprintf('%s-%02d', $mes, $d);
            $n = $porDia[$iso]['n'] ?? 0;
            $nivel = $n && $maxDia ? (int) ceil($n / $maxDia * 4) : 0;
            $fechado = true;
            foreach (($filtroProf ? [$filtroProf] : array_column($profs, 'id')) as $pid) if (jornada((int) $pid, $iso)) { $fechado = false; break; }
            $classes = 'cal-dia nivel-' . $nivel . ($fechado ? ' fechado' : '') . ($iso === date('Y-m-d') ? ' hoje' : '') . ($iso === $dia ? ' escolhido' : '') . ($iso < date('Y-m-d') ? ' passado' : '');
            $rotulo = dataExtenso($iso) . ': ' . ($fechado && !$n ? 'fechado' : ($n ? "$n " . ($n === 1 ? 'atendimento' : 'atendimentos') : 'nenhum atendimento')) . (isset($diasBloq[$iso]) ? ', tem bloqueio' : '');
      ?>
        <a class="<?= $classes ?>" href="<?= e($url(['dia' => $iso])) ?>#detalhe" aria-label="<?= e($rotulo) ?>" <?= $iso === $dia ? 'aria-current="date"' : '' ?>>
          <span class="cal-num"><?= $d ?></span>
          <?php if ($n): ?><span class="cal-qtd"><?= $n ?></span><?php endif; ?>
          <?php if (isset($diasBloq[$iso])): ?><span class="cal-bloq" aria-hidden="true"></span><?php endif; ?>
        </a>
      <?php endfor; ?>
    </div>
    <p class="cal-legenda">
      O número em cada dia é quantos horários <?= $filtroProf ? e($profPorId[$filtroProf]['nome']) . ' tem' : 'o estúdio tem' ?> marcados. Quanto mais forte a cor, mais cheio o dia.
      <span class="cal-legenda-bloq"><span class="cal-bloq" aria-hidden="true"></span>tem bloqueio</span>
      <span class="cal-legenda-bloq"><span class="cal-legenda-fechado" aria-hidden="true"></span>fechado</span>
    </p>
  </section>

  <!-- Dia -->
  <?php if ($dia === ''): ?>
    <p class="vazio-dia">Toque num dia pra ver os detalhes.</p>
  <?php else: ?>
  <section class="detalhe" id="detalhe" aria-labelledby="t-dia">
    <div class="detalhe-topo">
      <h2 id="t-dia"><?= e(ucfirst(dataExtenso($dia))) ?><?= $dia === date('Y-m-d') ? ' <span class="tag">hoje</span>' : '' ?></h2>
      <p><?= $filtroProf ? 'Só ' . e($profPorId[$filtroProf]['nome']) : 'Estúdio todo' ?><?= !$exp ? ', dia fechado' : '' ?></p>
    </div>

    <div class="numeros numeros-dia">
      <div class="numero">
        <p class="numero-rotulo">Atendimentos</p>
        <p class="numero-valor"><?= count($conf) ?></p>
        <p class="numero-detalhe"><?= plural($feitos, 'concluído', 'concluídos') ?></p>
      </div>
      <div class="numero">
        <p class="numero-rotulo">Faturamento</p>
        <p class="numero-valor"><?= preco($valorDia) ?></p>
        <p class="numero-detalhe"><?= $faltas ? $faltas . ($faltas === 1 ? ' falta' : ' faltas') . ' fora da conta' : 'sem faltas' ?></p>
      </div>
      <div class="numero">
        <p class="numero-rotulo">Ocupação</p>
        <p class="numero-valor"><?= $ocupMedia === null ? '–' : $ocupMedia . '%' ?></p>
        <p class="numero-detalhe"><?= $ocupMedia === null ? 'dia fechado' : 'da agenda' ?></p>
      </div>
    </div>

    <?php if ($porServico): ?>
      <div class="bloco-dia">
        <h3>Serviços do dia</h3>
        <ul class="servicos-dia">
          <?php foreach ($porServico as $nome => $qtd): ?>
            <li><strong><?= $qtd ?>×</strong> <?= e($nome) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if (!$filtroProf): ?>
      <div class="bloco-dia">
        <h3>Cada uma</h3>
        <ul class="ranking">
          <?php foreach ($resumoProf as $r): ?>
            <li style="--cor: <?= e($r['cor']) ?>">
              <a class="ranking-link" href="<?= e($url(['prof' => $r['id']])) ?>#detalhe" aria-label="Ver só <?= e($r['nome']) ?>"><?= fotoAdmin($r) ?></a>
              <div class="ranking-corpo">
                <div class="ranking-linha">
                  <strong><?= e($r['nome']) ?></strong>
                  <strong class="ranking-valor"><?= preco($r['v']) ?></strong>
                </div>
                <div class="barra" role="presentation"><span style="width: <?= (int) ($r['oc'] ?? 0) ?>%"></span></div>
                <p class="ranking-detalhe"><?= $r['n'] ?> <?= $r['n'] === 1 ? 'atendimento' : 'atendimentos' ?><?= $r['oc'] !== null ? ', agenda ' . $r['oc'] . '% ocupada' : '' ?></p>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="bloco-dia">
      <h3>Horários</h3>
      <?php if ($bloqDia): ?>
        <ul class="bloq-dia">
          <?php foreach ($bloqDia as $b): ?>
            <li>Bloqueado das <?= date('H:i', strtotime($b['inicio'])) ?> às <?= date('H:i', strtotime($b['fim'])) ?>,
              <?= e($b['profissional_id'] ? ($profPorId[$b['profissional_id']]['nome'] ?? '?') : 'estúdio todo') ?><?= $b['motivo'] ? ' (' . e($b['motivo']) . ')' : '' ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <ol class="lista">
        <?php foreach ($doDia as $a): ?>
          <?= cartaoAtendimento($a, $a['cor'], ['mes_ref' => $mes, 'prof_ref' => $filtroProf, 'dia_ref' => $dia], !$filtroProf) ?>
        <?php endforeach; ?>
        <?php if (!$doDia): ?><li class="vazio"><?= $exp ? 'Nenhum horário marcado nesse dia.' : 'Ninguém trabalha nesse dia.' ?></li><?php endif; ?>
      </ol>
      <a class="botao botao-leve botao-peq bloquear-dia" href="ajustes.php?<?= e(http_build_query(array_filter(['data' => $dia, 'prof' => $filtroProf]))) ?>#bloqueios">Bloquear horário nesse dia</a>
    </div>
  </section>
  <?php endif; ?>
  </div>
<?php endif; ?>

</main>
</body>
</html>
