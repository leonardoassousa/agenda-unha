<?php
// Caixa: serviços do mês (ou de um dia) com totais. Usado no painel e na área da equipe.
require_once __DIR__ . '/atendimento.php';

const NOMES_MES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

function mesValido(?string $mes): string
{
    return $mes && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) ? $mes : date('Y-m');
}

function diaValido(?string $dia, string $mes): string
{
    if (!$dia || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia) || substr($dia, 0, 7) !== $mes) return '';
    $d = DateTime::createFromFormat('!Y-m-d', $dia);
    return $d && $d->format('Y-m-d') === $dia ? $dia : '';
}

/** Soma uma lista de atendimentos. */
function somarCaixa(array $lista): array
{
    $agora = time();
    $t = ['caixa' => 0.0, 'concluidos' => 0, 'extras' => 0.0, 'pendente' => 0.0, 'n_pendente' => 0, 'agendado' => 0.0, 'n_agendado' => 0, 'faltas' => 0];
    foreach ($lista as $a) {
        $valor = (float) $a['preco'] + (float) $a['extra_valor'];
        if ($a['status'] === 'concluido') { $t['caixa'] += $valor; $t['concluidos']++; $t['extras'] += (float) $a['extra_valor']; }
        elseif ($a['status'] === 'faltou') $t['faltas']++;
        elseif (strtotime($a['inicio']) <= $agora) { $t['pendente'] += $valor; $t['n_pendente']++; }
        else { $t['agendado'] += $valor; $t['n_agendado']++; }
    }
    return $t;
}

/**
 * Desenha o caixa.
 * $url(array $params): string monta o link mantendo os filtros.
 * $profs: lista de profissionais (pra mostrar "cada uma" quando $profId = 0).
 */
function renderCaixa(string $mes, string $dia, int $profId, array $profs, callable $url, bool $mostrarProf = true): void
{
    $iniMes = "$mes-01";
    $fimMes = date('Y-m-t', strtotime($iniMes));
    $doMes = buscarAtendimentos($iniMes, $fimMes, $profId);
    $mesT = somarCaixa($doMes);
    $tituloMes = ucfirst(NOMES_MES[(int) substr($mes, 5, 2) - 1]) . ' ' . substr($mes, 0, 4);
    $mesAnt = date('Y-m', strtotime("$iniMes -1 month"));
    $mesProx = date('Y-m', strtotime("$iniMes +1 month"));

    $porDia = [];
    foreach ($doMes as $a) $porDia[substr($a['inicio'], 0, 10)][] = $a;
    $lista = $dia ? ($porDia[$dia] ?? []) : $doMes;
    $sel = $dia ? somarCaixa($lista) : $mesT;
    $profPorId = array_column($profs, null, 'id');
    ?>
  <section class="caixa">
    <div class="cal-topo">
      <a class="seta" href="<?= e($url(['mes' => $mesAnt, 'dia' => ''])) ?>" aria-label="Mês anterior">‹</a>
      <div class="cal-titulo">
        <h1><?= e($tituloMes) ?></h1>
        <p><?= $profId ? e($profPorId[$profId]['nome'] ?? '') : 'Estúdio todo' ?></p>
      </div>
      <a class="seta" href="<?= e($url(['mes' => $mesProx, 'dia' => ''])) ?>" aria-label="Próximo mês">›</a>
    </div>

    <div class="caixa-total">
      <p class="caixa-rotulo"><?= $dia ? e(ucfirst(dataExtenso($dia))) : 'Caixa do mês' ?></p>
      <p class="caixa-valor"><?= preco($sel['caixa']) ?></p>
      <p class="caixa-sub">
        <?= $sel['concluidos'] ?> <?= $sel['concluidos'] === 1 ? 'serviço concluído' : 'serviços concluídos' ?><?= $sel['extras'] > 0 ? ', ' . preco($sel['extras']) . ' de valor a mais' : '' ?>
      </p>
      <?php if ($dia): ?>
        <p class="caixa-sub caixa-mes">No mês: <strong><?= preco($mesT['caixa']) ?></strong> em <?= plural($mesT['concluidos'], 'serviço', 'serviços') ?>. <a href="<?= e($url(['dia' => ''])) ?>">Ver o mês todo</a></p>
      <?php endif; ?>
    </div>

    <div class="numeros numeros-dia">
      <div class="numero">
        <p class="numero-rotulo">A concluir</p>
        <p class="numero-valor"><?= preco($sel['pendente']) ?></p>
        <p class="numero-detalhe"><?= $sel['n_pendente'] === 1 ? '1 já passou e não foi concluído' : $sel['n_pendente'] . ' já passaram e não foram concluídos' ?></p>
      </div>
      <div class="numero">
        <p class="numero-rotulo">Agendado</p>
        <p class="numero-valor"><?= preco($sel['agendado']) ?></p>
        <p class="numero-detalhe"><?= $sel['n_agendado'] === 1 ? '1 horário que ainda vai acontecer' : $sel['n_agendado'] . ' horários que ainda vão acontecer' ?></p>
      </div>
      <div class="numero">
        <p class="numero-rotulo">Faltas</p>
        <p class="numero-valor"><?= $sel['faltas'] ?></p>
        <p class="numero-detalhe">não entram no caixa</p>
      </div>
    </div>

    <?php if (!$profId && $profs): ?>
      <div class="bloco-dia">
        <h3>Cada uma <?= $dia ? 'no dia' : 'no mês' ?></h3>
        <ul class="caixa-pessoas">
          <?php foreach ($profs as $p):
              $t = somarCaixa(array_filter($lista, fn($a) => (int) $a['profissional_id'] === (int) $p['id'])); ?>
            <li style="--cor: <?= e($p['cor']) ?>">
              <a href="<?= e($url(['prof' => $p['id']])) ?>">
                <span class="caixa-pessoa-nome"><i class="bolinha" aria-hidden="true"></i><?= e($p['nome']) ?></span>
                <span class="caixa-pessoa-valor"><?= preco($t['caixa']) ?></span>
                <span class="caixa-pessoa-det"><?= plural($t['concluidos'], 'concluído', 'concluídos') ?><?= $t['n_pendente'] ? ', ' . $t['n_pendente'] . ' a concluir' : '' ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="bloco-dia">
      <h3><?= $dia ? 'Serviços do dia' : 'Todos os serviços do mês' ?></h3>
      <?php if (!$lista): ?>
        <p class="vazio"><?= $dia ? 'Nenhum serviço nesse dia.' : 'Nenhum serviço nesse mês.' ?></p>
      <?php endif; ?>
      <?php foreach (($dia ? [$dia => $lista] : $porDia) as $d => $itens): $t = somarCaixa($itens); ?>
        <div class="caixa-dia">
          <a class="caixa-dia-topo" href="<?= e($url(['dia' => $d])) ?>">
            <span class="caixa-dia-nome"><?= e(ucfirst(diaSemana($d))) ?>, <?= date('d/m', strtotime($d)) ?></span>
            <span class="caixa-dia-valor"><?= preco($t['caixa']) ?></span>
          </a>
          <ul class="caixa-linhas">
            <?php foreach ($itens as $a):
                $valor = (float) $a['preco'] + (float) $a['extra_valor'];
                $estado = $a['status'] === 'concluido' ? 'ok' : ($a['status'] === 'faltou' ? 'falta' : (strtotime($a['inicio']) <= time() ? 'pendente' : 'futuro'));
                $rotulo = ['ok' => 'Concluído', 'falta' => 'Faltou', 'pendente' => 'A concluir', 'futuro' => 'Agendado'][$estado];
            ?>
              <li class="linha-<?= $estado ?>" style="--cor: <?= e($a['cor']) ?>">
                <span class="linha-hora"><?= substr($a['inicio'], 11, 5) ?></span>
                <span class="linha-desc">
                  <strong><?= e($a['cliente_nome']) ?></strong>
                  <span><?= e($a['servico']) ?><?= $mostrarProf && !$profId ? ', ' . e($a['profissional']) : '' ?></span>
                  <?php if ((float) $a['extra_valor'] > 0): ?><span class="linha-extra">+ <?= preco((float) $a['extra_valor']) ?><?= $a['extra_obs'] ? ' (' . e($a['extra_obs']) . ')' : '' ?></span><?php endif; ?>
                </span>
                <span class="linha-valor"><?= preco($valor) ?><small><?= $rotulo ?></small></span>
              </li>
            <?php endforeach; ?>
          </ul>
          <p class="caixa-dia-rodape"><?= plural($t['concluidos'], 'concluído', 'concluídos') ?><?= $t['n_pendente'] ? ', ' . $t['n_pendente'] . ' a concluir (' . preco($t['pendente']) . ')' : '' ?><?= $t['n_agendado'] ? ', ' . plural($t['n_agendado'], 'agendado', 'agendados') : '' ?></p>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="rodape-nota">Entra no caixa só o que foi concluído: valor do serviço + valor a mais. O que passou e não foi concluído aparece em "A concluir".</p>
  </section>
    <?php
}
