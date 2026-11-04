<?php
// Marcar horário pelo painel: a dona escolhe (ou cadastra) a cliente, o serviço, a manicure, o dia,
// o horário e o valor combinado. Pode deixar o horário fixo toda semana.
require __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/painel_agenda.php';
require_once __DIR__ . '/../includes/whatsapp.php';
exigirLogin();
garantirEstruturaPainel();

$profs = profissionaisAtivas();
$servicos = servicosAtivos();
$erro = '';
$v = [
    // Valores do formulário (voltam preenchidos se der erro)
    'cliente_id' => (int) ($_GET['cliente'] ?? 0),
    'nova' => '',
    'nome' => '',
    'telefone' => '',
    'email' => '',
    'servico' => 0,
    'profs' => array_filter([(int) ($_GET['prof'] ?? 0)]),
    'data' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['data'] ?? '')) && $_GET['data'] >= date('Y-m-d') ? $_GET['data'] : date('Y-m-d'),
    'hora' => '',
    'valor' => '',
    'observacao' => '',
    'repetir' => '',
    'ate' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    conferirCsrf();
    foreach (['cliente_id', 'servico'] as $k) {
        $v[$k] = (int) ($_POST[$k] ?? 0);
    }
    foreach (['nova', 'nome', 'telefone', 'email', 'data', 'hora', 'valor', 'observacao', 'repetir', 'ate'] as $k) {
        $v[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $v['profs'] = array_map('intval', (array) ($_POST['profs'] ?? []));
    try {
        if ($v['nova'] === '1') {
            $v['cliente_id'] = cadastrarClientePeloPainel($v['nome'], $v['telefone'], $v['email']);
        }
        $r = agendarPeloPainel([
            'cliente_id' => $v['cliente_id'],
            'servico' => $v['servico'],
            'profs' => $v['profs'],
            'data' => $v['data'],
            'hora' => $v['hora'],
            'valor' => $v['valor'],
            'observacao' => $v['observacao'],
            'repetir' => $v['repetir'] === '1',
            'ate' => $v['ate'],
        ]);
        $ag = dadosDoAgendamento($r['ids'][0]);
        $aviso = 'Horário marcado: ' . $ag['cliente_nome'] . ', ' . dataExtenso($ag['data']) . ' às ' . $ag['hora']
            . ', com ' . $ag['profissional'] . ', ' . preco($ag['preco']) . '.';
        if ($r['serie']) {
            $s = dadosSerie($r['serie']);
            $aviso .= ' Horário fixo: ' . mb_strtolower($s['texto']) . ' (' . plural(count($r['ids']), 'dia marcado', 'dias marcados') . ').';
        }
        if ($r['pulados']) {
            $aviso .= ' Ficaram de fora: ' . implode(', ', array_map(fn($d, $m) => "$d ($m)", array_keys($r['pulados']), $r['pulados'])) . '.';
        }
        if ($r['fora_do_horario']) {
            $aviso .= ' Atenção: o horário fica fora do expediente da manicure em pelo menos um dia.';
        }
        $_SESSION['aviso_cal'] = $aviso;
        depoisDaResposta(fn() => avisarEstudio($ag));
        voltarPara('./', ['mes' => substr($ag['data'], 0, 7), 'dia' => $ag['data']], 'detalhe');
    } catch (InvalidArgumentException | RuntimeException $e) {
        $erro = $e->getMessage();
    }
}

$clientes = clientesParaBusca();
$cliEscolhida = null;
foreach ($clientes as $c) {
    if ((int) $c['id'] === $v['cliente_id']) {
        $cliEscolhida = $c;
    }
}
if ($v['ate'] === '') {
    $v['ate'] = date('Y-m-d', strtotime($v['data'] . ' +8 weeks'));
}

$dadosJs = [
    'clientes' => array_map(fn($c) => ['id' => (int) $c['id'], 'nome' => $c['nome'], 'tel' => $c['telefone'], 'telFmt' => telefoneBonito($c['telefone'])], $clientes),
    'servicos' => array_map(fn($s) => ['id' => (int) $s['id'], 'preco' => (float) $s['preco'], 'duracao' => (int) $s['duracao_min'], 'dupla' => (bool) $s['dupla']], $servicos),
];

headAdmin('Marcar horário');
cabecalhoAdmin('agendar.php');
?>
  <h1>Marcar horário</h1>
  <p class="topo-info">Pra cliente que marcou por WhatsApp, telefone ou no balcão. Ela recebe o lembrete igual a quem marca pelo link.</p>
  <?php if ($erro): ?><p class="nota nota-alerta" role="alert"><?= e($erro) ?></p><?php endif; ?>

  <form method="post" class="marcar" id="marcar" novalidate>
    <?= campoCsrf() ?>

    <!-- Cliente -->
    <fieldset class="marcar-parte">
      <legend>Cliente</legend>
      <input type="hidden" name="cliente_id" id="cliente_id" value="<?= $v['cliente_id'] ?: '' ?>">
      <input type="hidden" name="nova" id="nova" value="<?= e($v['nova']) ?>">

      <div class="cli-escolhida" id="cli-escolhida" <?= $cliEscolhida ? '' : 'hidden' ?>>
        <p><strong id="cli-escolhida-nome"><?= e($cliEscolhida['nome'] ?? '') ?></strong>
          <span id="cli-escolhida-tel"><?= $cliEscolhida ? e(telefoneBonito($cliEscolhida['telefone'])) : '' ?></span></p>
        <button type="button" class="link" id="cli-trocar">Trocar</button>
      </div>

      <div class="cli-buscar" id="cli-buscar" <?= $cliEscolhida || $v['nova'] === '1' ? 'hidden' : '' ?>>
        <label class="campo">
          <span>Buscar cliente</span>
          <input type="search" id="cli-busca" placeholder="Nome ou celular" autocomplete="off">
        </label>
        <ul class="cli-resultados" id="cli-resultados" aria-live="polite"></ul>
        <button type="button" class="botao botao-leve botao-peq" id="cli-nova-btn">+ Cliente nova</button>
      </div>

      <div class="campos cli-nova" id="cli-nova" <?= $v['nova'] === '1' ? '' : 'hidden' ?>>
        <label class="campo campo-largo"><span>Nome</span><input type="text" name="nome" maxlength="100" value="<?= e($v['nome']) ?>" placeholder="Nome e sobrenome"></label>
        <label class="campo campo-largo"><span>Celular (WhatsApp)</span><input type="tel" name="telefone" inputmode="numeric" value="<?= e($v['telefone']) ?>" placeholder="(73) 99999-9999"></label>
        <label class="campo campo-largo"><span>E-mail <em>(opcional)</em></span><input type="email" name="email" maxlength="150" value="<?= e($v['email']) ?>"></label>
        <p class="topo-info campo-largo">Se esse celular já tiver ficha, o horário vai pra ficha dela. Depois, se ela quiser marcar pelo link, é só tocar em "Criar conta" com o mesmo celular.</p>
        <button type="button" class="link campo-largo" id="cli-nova-cancelar">Buscar uma cliente que já existe</button>
      </div>
    </fieldset>

    <!-- Serviço e valor -->
    <fieldset class="marcar-parte">
      <legend>Serviço</legend>
      <div class="campos">
        <label class="campo campo-largo">
          <span>Serviço</span>
          <select name="servico" id="servico" required>
            <option value="">Escolha…</option>
            <?php foreach ($servicos as $s): ?>
              <option value="<?= (int) $s['id'] ?>" <?= $v['servico'] === (int) $s['id'] ? 'selected' : '' ?>>
                <?= e($s['nome']) ?> (<?= e(duracaoTexto((int) $s['duracao_min'])) ?>, <?= e(preco((float) $s['preco'])) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="campo campo-valor">
          <span>Valor combinado</span>
          <span class="valor-caixa"><span aria-hidden="true">R$</span>
            <input type="text" name="valor" id="valor" inputmode="decimal" value="<?= e($v['valor']) ?>" placeholder="0,00" aria-label="Valor em reais">
          </span>
        </label>
        <p class="topo-info campo-largo" id="valor-dica">Vem com o preço da tabela. Mude se combinou outro valor: é esse que vai pro caixa quando concluir.</p>
      </div>
    </fieldset>

    <!-- Manicure -->
    <fieldset class="marcar-parte">
      <legend id="t-quem">Manicure</legend>
      <div class="marcar-pros" id="pros">
        <?php foreach ($profs as $p): ?>
          <label class="marcar-pro" style="--cor: <?= e($p['cor']) ?>">
            <input type="checkbox" name="profs[]" value="<?= (int) $p['id'] ?>" <?= in_array((int) $p['id'], $v['profs'], true) ? 'checked' : '' ?>>
            <?= fotoColab($p) ?>
            <span><?= e($p['nome']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <!-- Dia e horário -->
    <fieldset class="marcar-parte">
      <legend>Dia e horário</legend>
      <div class="campos">
        <label class="campo"><span>Dia</span><input type="date" name="data" id="data" min="<?= date('Y-m-d') ?>" value="<?= e($v['data']) ?>" required></label>
        <label class="campo"><span>Horário</span><input type="time" name="hora" id="hora" step="300" value="<?= e($v['hora']) ?>" required></label>
      </div>
      <p class="topo-info" id="livres-info">Escolha o serviço e a manicure pra ver os horários livres.</p>
      <div class="livres" id="livres"></div>
    </fieldset>

    <!-- Repetir -->
    <fieldset class="marcar-parte">
      <legend>Horário fixo</legend>
      <label class="marcar-check">
        <input type="checkbox" name="repetir" value="1" id="repetir" <?= $v['repetir'] === '1' ? 'checked' : '' ?>>
        <span>Repetir toda semana (cliente que vem sempre no mesmo dia e horário)</span>
      </label>
      <div class="campos" id="repetir-campos" <?= $v['repetir'] === '1' ? '' : 'hidden' ?>>
        <label class="campo campo-largo"><span>Até</span><input type="date" name="ate" id="ate" value="<?= e($v['ate']) ?>"></label>
        <p class="topo-info campo-largo" id="repetir-resumo"></p>
      </div>
    </fieldset>

    <label class="campo marcar-obs">
      <span>Observação <em>(opcional)</em></span>
      <input type="text" name="observacao" maxlength="255" value="<?= e($v['observacao']) ?>" placeholder="Ex.: vai tirar o gel antigo">
    </label>

    <button class="botao marcar-enviar">Marcar horário</button>
  </form>

<script>window.MARCAR = <?= json_encode($dadosJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="../assets/marcar.js?v=1.9"></script>
</main>
</body>
</html>
