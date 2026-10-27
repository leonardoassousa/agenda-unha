<?php
require __DIR__ . '/../includes/equipe.php';
require __DIR__ . '/../includes/atendimento.php';

$erro = '';
$c = colaboradora();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    conferirCsrf();
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'entrar') {
        $erro = entrarEquipe((string) ($_POST['telefone'] ?? ''), (string) ($_POST['senha'] ?? ''));
        if ($erro === '') { header('Location: ./'); exit; }
    } elseif ($c && $acao === 'sair') {
        $_SESSION = [];
        session_destroy();
        header('Location: ./');
        exit;
    } elseif ($c && $acao === 'trocar_senha') {
        $_SESSION['msg_eq'] = trocarSenhaPropria((int) $c['id'], (string) ($_POST['atual'] ?? ''), (string) ($_POST['nova'] ?? ''));
        header('Location: ./#senha');
        exit;
    } elseif ($c && in_array($acao, ['concluir', 'salvar_extra', 'reabrir', 'faltou', 'desfazer', 'cancelar'], true)) {
        $_SESSION['msg_eq'] = acaoAtendimento($acao, (int) $_POST['id'], $_POST, (int) $c['id']);
        $dia = (string) ($_POST['dia_ref'] ?? '');
        header('Location: ./' . ($dia ? '?dia=' . rawurlencode($dia) : '') . '#lista');
        exit;
    }
}

headEquipe($c ? 'Minha agenda' : 'Entrar');

if (!$c): ?>
  <form method="post" class="login">
    <h1>Área da equipe</h1>
    <p class="topo-info"><?= e(cfg('nome_estudio')) ?></p>
    <?= campoCsrf() ?>
    <input type="hidden" name="acao" value="entrar">
    <label class="campo">
      <span>Seu celular</span>
      <input type="tel" name="telefone" inputmode="numeric" autocomplete="username" placeholder="(73) 99999-9999" required autofocus>
    </label>
    <label class="campo">
      <span>Senha</span>
      <input type="password" name="senha" autocomplete="current-password" required>
    </label>
    <?php if ($erro): ?><p class="erro" role="alert"><?= e($erro) ?></p><?php endif; ?>
    <button class="botao">Entrar</button>
    <p class="topo-info">A senha é criada pela dona do estúdio, no painel.</p>
  </form>
<?php else:
    $dia = $_GET['dia'] ?? date('Y-m-d');
    $d = DateTime::createFromFormat('!Y-m-d', (string) $dia);
    if (!$d || $d->format('Y-m-d') !== $dia) $dia = date('Y-m-d');
    $lista = buscarAtendimentos($dia, $dia, (int) $c['id']);
    $ativos = array_filter($lista, fn($a) => in_array($a['status'], ['confirmado', 'concluido'], true));
    $concl = array_filter($lista, fn($a) => $a['status'] === 'concluido');
    $caixaDia = array_sum(array_map(fn($a) => (float) $a['preco'] + (float) $a['extra_valor'], $concl));
    $pendentes = count(array_filter($ativos, fn($a) => $a['status'] === 'confirmado' && strtotime($a['inicio']) <= time()));
    $j = jornada((int) $c['id'], $dia);
    $msg = $_SESSION['msg_eq'] ?? '';
    unset($_SESSION['msg_eq']);
    $ant = date('Y-m-d', strtotime("$dia -1 day"));
    $prox = date('Y-m-d', strtotime("$dia +1 day"));

    cabecalhoEquipe('index.php', $c); ?>
  <?php if ($msg): ?><p class="nota" role="status"><?= e($msg) ?></p><?php endif; ?>

  <nav class="navdia" aria-label="Escolher dia">
    <a class="seta" href="?dia=<?= $ant ?>" aria-label="Dia anterior">‹</a>
    <form method="get" class="navdia-data">
      <input type="date" name="dia" value="<?= e($dia) ?>" onchange="this.form.submit()" aria-label="Data">
      <strong><?= e(dataExtenso($dia)) ?><?= $dia === date('Y-m-d') ? ' (hoje)' : '' ?></strong>
    </form>
    <a class="seta" href="?dia=<?= $prox ?>" aria-label="Próximo dia">›</a>
  </nav>
  <?php if ($dia !== date('Y-m-d')): ?><p class="cal-hoje"><a href="./">Voltar pra hoje</a></p><?php endif; ?>

  <div class="numeros numeros-dia">
    <div class="numero">
      <p class="numero-rotulo">Horários</p>
      <p class="numero-valor"><?= count($ativos) ?></p>
      <p class="numero-detalhe"><?= $j ? 'das ' . date('H:i', $j['ini']) . ' às ' . date('H:i', $j['fim']) : 'você não trabalha' ?></p>
    </div>
    <div class="numero">
      <p class="numero-rotulo">Concluídos</p>
      <p class="numero-valor"><?= count($concl) ?></p>
      <p class="numero-detalhe"><?= $pendentes ? $pendentes . ' esperando concluir' : 'nada pendente' ?></p>
    </div>
    <div class="numero">
      <p class="numero-rotulo">Caixa do dia</p>
      <p class="numero-valor"><?= preco($caixaDia) ?></p>
      <p class="numero-detalhe">só concluídos</p>
    </div>
  </div>

  <section class="bloco" id="lista">
    <h2>Atendimentos</h2>
    <p class="topo-info">Quando terminar, toque em <strong>Concluir</strong>. Se fez algo a mais, escreva o que foi e o valor: ele soma no serviço.</p>
    <ol class="lista">
      <?php foreach ($lista as $a): ?>
        <?= cartaoAtendimento($a, $c['cor'], ['dia_ref' => $dia], false) ?>
      <?php endforeach; ?>
      <?php if (!$lista): ?><li class="vazio"><?= $j ? 'Nenhum horário marcado nesse dia.' : 'Você está de folga nesse dia.' ?></li><?php endif; ?>
    </ol>
  </section>

  <details class="bloco sanfona-eq" id="senha">
    <summary>Trocar minha senha</summary>
    <form method="post" class="campos form-bloq">
      <?= campoCsrf() ?>
      <input type="hidden" name="acao" value="trocar_senha">
      <label class="campo campo-largo"><span>Senha atual</span><input type="password" name="atual" autocomplete="current-password" required></label>
      <label class="campo campo-largo"><span>Nova senha <em>(mínimo 6)</em></span><input type="password" name="nova" autocomplete="new-password" minlength="6" required></label>
      <button class="botao botao-leve campo-largo">Salvar nova senha</button>
    </form>
  </details>
<?php endif; ?>

</main>
</body>
</html>
