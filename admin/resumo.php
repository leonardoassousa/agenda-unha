<?php
require __DIR__ . '/../includes/admin.php';
exigirLogin();

// ---------- Período ----------
$hoje = date('Y-m-d');
$periodos = [
    'hoje'   => ['Hoje', $hoje, $hoje],
    'ontem'  => ['Ontem', date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    'semana' => ['Esta semana', date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week'))],
    'mes'    => ['Este mês', date('Y-m-01'), date('Y-m-t')],
    'mes_passado' => ['Mês passado', date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
];
$p = $_GET['p'] ?? 'hoje';
$valida = fn($d) => is_string($d) && ($o = DateTime::createFromFormat('!Y-m-d', $d)) && $o->format('Y-m-d') === $d;
if ($p === 'outro' && $valida($_GET['de'] ?? null) && $valida($_GET['ate'] ?? null)) {
    [$de, $ate] = [$_GET['de'], $_GET['ate']];
    if ($de > $ate) [$de, $ate] = [$ate, $de];
    $rotulo = date('d/m/Y', strtotime($de)) . ' a ' . date('d/m/Y', strtotime($ate));
} else {
    if (!isset($periodos[$p])) $p = 'hoje';
    [$rotulo, $de, $ate] = $periodos[$p];
}
$ini = "$de 00:00:00";
$fim = date('Y-m-d', strtotime("$ate +1 day")) . ' 00:00:00';
$umDia = $de === $ate;

// ---------- Números ----------
$agora = date('Y-m-d H:i:s');
$st = db()->prepare("SELECT
        SUM(status IN ('confirmado','concluido')) atendimentos,
        COALESCE(SUM(CASE WHEN status IN ('confirmado','concluido') THEN preco + extra_valor END), 0) total,
        COALESCE(SUM(CASE WHEN status = 'concluido' THEN preco + extra_valor END), 0) realizado,
        COALESCE(SUM(CASE WHEN status = 'confirmado' THEN preco END), 0) a_receber,
        COUNT(DISTINCT CASE WHEN status IN ('confirmado','concluido') THEN cliente_telefone END) clientes,
        SUM(status = 'faltou') faltas,
        COALESCE(SUM(CASE WHEN status = 'faltou' THEN preco END), 0) perdido,
        SUM(status = 'cancelado') cancelados
    FROM agendamentos WHERE inicio >= ? AND inicio < ?");
$st->execute([$ini, $fim]);
$t = array_map(fn($v) => $v ?? 0, $st->fetch());
$ticket = $t['atendimentos'] ? $t['total'] / $t['atendimentos'] : 0;

$st = db()->prepare("SELECT p.id, p.nome, p.cor, p.foto,
        COUNT(a.id) atendimentos,
        COALESCE(SUM(a.preco + a.extra_valor), 0) total,
        COALESCE(SUM(CASE WHEN a.status = 'concluido' THEN a.preco + a.extra_valor END), 0) realizado,
        COALESCE(SUM(CASE WHEN a.status = 'confirmado' THEN a.preco END), 0) a_receber
    FROM profissionais p
    LEFT JOIN agendamentos a ON a.profissional_id = p.id AND a.status IN ('confirmado','concluido') AND a.inicio >= ? AND a.inicio < ?
    GROUP BY p.id ORDER BY total DESC, p.ordem");
$st->execute([$ini, $fim]);
$porProf = $st->fetchAll();
$maiorProf = max(array_column($porProf, 'total') ?: [0]);

$st = db()->prepare("SELECT s.nome, COUNT(*) qtd, SUM(a.preco + a.extra_valor) total FROM agendamentos a JOIN servicos s ON s.id = a.servico_id
    WHERE a.status IN ('confirmado','concluido') AND a.inicio >= ? AND a.inicio < ? GROUP BY s.id ORDER BY total DESC");
$st->execute([$ini, $fim]);
$porServico = $st->fetchAll();

$porDia = [];
if (!$umDia) {
    $st = db()->prepare("SELECT DATE(inicio) dia, COUNT(*) qtd, SUM(preco + extra_valor) total FROM agendamentos
        WHERE status IN ('confirmado','concluido') AND inicio >= ? AND inicio < ? GROUP BY DATE(inicio) ORDER BY dia");
    $st->execute([$ini, $fim]);
    $porDia = $st->fetchAll();
}
$maiorDia = max(array_column($porDia, 'total') ?: [0]);

headAdmin('Resumo');
cabecalhoAdmin('resumo.php');
?>
  <h1>Resumo</h1>

  <div class="filtro" role="list" aria-label="Período">
    <?php foreach ($periodos as $chave => [$nome]): ?>
      <a role="listitem" href="?p=<?= $chave ?>" class="<?= $p === $chave ? 'ativo' : '' ?>"><?= $nome ?></a>
    <?php endforeach; ?>
  </div>
  <form method="get" class="periodo-outro">
    <input type="hidden" name="p" value="outro">
    <label>De <input type="date" name="de" value="<?= e($de) ?>" required></label>
    <label>até <input type="date" name="ate" value="<?= e($ate) ?>" required></label>
    <button class="botao botao-leve botao-peq">Ver</button>
  </form>

  <p class="periodo-atual"><?= $umDia ? e(ucfirst(dataExtenso($de))) : e($rotulo) ?></p>

  <section class="numeros" aria-label="Números do período">
    <div class="numero numero-grande">
      <p class="numero-rotulo">Faturamento</p>
      <p class="numero-valor"><?= preco((float) $t['total']) ?></p>
      <p class="numero-detalhe">
        <?= preco((float) $t['realizado']) ?> já concluído (no caixa)<br>
        <?= preco((float) $t['a_receber']) ?> em horários ainda não concluídos
      </p>
    </div>
    <div class="numero">
      <p class="numero-rotulo">Atendimentos</p>
      <p class="numero-valor"><?= (int) $t['atendimentos'] ?></p>
      <p class="numero-detalhe"><?= (int) $t['clientes'] ?> <?= (int) $t['clientes'] === 1 ? 'cliente' : 'clientes' ?></p>
    </div>
    <div class="numero">
      <p class="numero-rotulo">Ticket médio</p>
      <p class="numero-valor"><?= preco($ticket) ?></p>
      <p class="numero-detalhe">por atendimento</p>
    </div>
    <div class="numero">
      <p class="numero-rotulo">Faltas</p>
      <p class="numero-valor"><?= (int) $t['faltas'] ?></p>
      <p class="numero-detalhe"><?= $t['faltas'] ? preco((float) $t['perdido']) . ' que não entrou' : 'nenhuma' ?><?= $t['cancelados'] ? '<br>' . (int) $t['cancelados'] . ' cancelado' . ($t['cancelados'] > 1 ? 's' : '') : '' ?></p>
    </div>
  </section>

  <section class="bloco">
    <h2>Por profissional</h2>
    <ul class="ranking">
      <?php foreach ($porProf as $r): $pct = $maiorProf > 0 ? $r['total'] / $maiorProf * 100 : 0; ?>
        <li style="--cor: <?= e($r['cor']) ?>">
          <?php if ($r['foto']): ?>
            <img class="foto" src="../<?= e($r['foto']) ?>" alt="">
          <?php else: ?>
            <span class="foto" aria-hidden="true"><?= e(iniciais($r['nome'])) ?></span>
          <?php endif; ?>
          <div class="ranking-corpo">
            <div class="ranking-linha">
              <strong><?= e($r['nome']) ?></strong>
              <strong class="ranking-valor"><?= preco((float) $r['total']) ?></strong>
            </div>
            <div class="barra" role="presentation"><span style="width: <?= round($pct, 1) ?>%"></span></div>
            <p class="ranking-detalhe"><?php
              $txt = (int) $r['atendimentos'] . ((int) $r['atendimentos'] === 1 ? ' atendimento' : ' atendimentos');
              if ($r['a_receber'] > 0) $txt .= ', ' . preco((float) $r['realizado']) . ' já concluído';
              echo e($txt);
            ?></p>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="bloco">
    <h2>Por serviço</h2>
    <?php if ($porServico): ?>
      <div class="tabela-rolagem">
        <table class="tabela">
          <thead><tr><th scope="col">Serviço</th><th scope="col">Qtd.</th><th scope="col">Total</th></tr></thead>
          <tbody>
            <?php foreach ($porServico as $r): ?>
              <tr><td><?= e($r['nome']) ?></td><td><?= (int) $r['qtd'] ?></td><td><?= preco((float) $r['total']) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="vazio">Nenhum atendimento nesse período.</p>
    <?php endif; ?>
  </section>

  <?php if (!$umDia): ?>
  <section class="bloco">
    <h2>Dia a dia</h2>
    <?php if ($porDia): ?>
      <ul class="dias-lista">
        <?php foreach ($porDia as $r): ?>
          <li>
            <a href="./?data=<?= e($r['dia']) ?>"><?= date('d/m', strtotime($r['dia'])) ?> <span><?= e(mb_substr(diaSemana($r['dia']), 0, 3)) ?></span></a>
            <div class="barra barra-neutra" role="presentation"><span style="width: <?= $maiorDia > 0 ? round($r['total'] / $maiorDia * 100, 1) : 0 ?>%"></span></div>
            <span class="dias-valor"><?= preco((float) $r['total']) ?> <small><?= (int) $r['qtd'] ?> atend.</small></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="vazio">Nenhum atendimento nesse período.</p>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <p class="rodape-nota">Faturamento conta os horários confirmados. Cancelados e faltas ficam de fora. O valor é o preço do serviço no dia em que a cliente marcou.</p>

</main>
</body>
</html>
