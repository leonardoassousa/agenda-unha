<?php
require __DIR__ . '/../includes/admin.php';
require __DIR__ . '/../includes/whatsapp.php';
exigirLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    conferirCsrf();
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'foto') {
        $erro = salvarFoto((int) $_POST['id'], $_FILES['foto'] ?? null);
        $_SESSION['aviso_ajustes'] = $erro ?: 'Foto atualizada.';
        voltarPara('ajustes.php', [], 'equipe');
    }
    if ($acao === 'remover_foto') {
        removerFoto((int) $_POST['id']);
        voltarPara('ajustes.php', [], 'equipe');
    }
    if ($acao === 'testar_aviso') {
        $erro = enviarWhatsappEstudio("Teste do aviso de agendamento ✅\nSe chegou, está tudo certo.");
        $_SESSION['aviso_ajustes'] = $erro === ''
            ? 'Mensagem de teste enviada. Confira o WhatsApp do estúdio (pode levar até 1 minuto).'
            : 'O teste não foi: ' . $erro;
        voltarPara('ajustes.php', [], 'whatsapp');
    }
    if ($acao === 'bloquear') {
        $data = (string) $_POST['data'];
        $de = (string) $_POST['de'];
        $ate = (string) $_POST['ate'];
        $prof = (int) $_POST['profissional'] ?: null;
        $valida = ($d = DateTime::createFromFormat('!Y-m-d', $data)) && $d->format('Y-m-d') === $data
            && preg_match('/^\d{2}:\d{2}$/', $de) && preg_match('/^\d{2}:\d{2}$/', $ate) && $de < $ate;
        if ($valida) {
            db()->prepare('INSERT INTO bloqueios (profissional_id, inicio, fim, motivo) VALUES (?, ?, ?, ?)')
                ->execute([$prof, "$data $de:00", "$data $ate:00", mb_substr(trim((string) $_POST['motivo']), 0, 120) ?: null]);
            $_SESSION['aviso_ajustes'] = 'Horário bloqueado. Ele já sumiu do link de agendamento.';
        } else {
            $_SESSION['aviso_ajustes'] = 'Confira o dia e os horários: o "até" precisa ser depois do "das".';
        }
        voltarPara('ajustes.php', ['data' => $data], 'bloqueios');
    }
    if ($acao === 'desbloquear') {
        db()->prepare('DELETE FROM bloqueios WHERE id = ?')->execute([(int) $_POST['id']]);
        voltarPara('ajustes.php', [], 'bloqueios');
    }
    voltarPara('ajustes.php');
}

$aviso = $_SESSION['aviso_ajustes'] ?? '';
unset($_SESSION['aviso_ajustes']);
$profs = db()->query('SELECT id, nome, cor, foto FROM profissionais ORDER BY ordem, nome')->fetchAll();
$profPorId = array_column($profs, null, 'id');
$dataBloq = $_GET['data'] ?? date('Y-m-d');
if (!DateTime::createFromFormat('!Y-m-d', $dataBloq)) $dataBloq = date('Y-m-d');
$profBloq = (int) ($_GET['prof'] ?? 0);
$bloqueios = db()->query("SELECT * FROM bloqueios WHERE fim > NOW() ORDER BY inicio LIMIT 60")->fetchAll();
$modoAviso = (cfg('aviso_whatsapp') ?: [])['modo'] ?? 'desligado';

headAdmin('Ajustes');
cabecalhoAdmin('ajustes.php');
?>
  <h1>Ajustes</h1>
  <?php if ($aviso): ?><p class="nota" role="status"><?= e($aviso) ?></p><?php endif; ?>

  <section class="bloco" id="bloqueios">
    <h2>Bloquear horário</h2>
    <p class="topo-info">Folga, consulta, saída mais cedo. O horário some do link na hora.</p>
    <form method="post" class="campos form-bloq">
      <?= campoCsrf() ?>
      <input type="hidden" name="acao" value="bloquear">
      <label class="campo campo-largo">
        <span>Quem</span>
        <select name="profissional">
          <option value="0">Todas (fecha o estúdio)</option>
          <?php foreach ($profs as $p): ?>
            <option value="<?= (int) $p['id'] ?>" <?= $profBloq === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="campo campo-largo"><span>Dia</span><input type="date" name="data" value="<?= e($dataBloq) ?>" required></label>
      <label class="campo"><span>Das</span><input type="time" name="de" value="08:00" required></label>
      <label class="campo"><span>Até</span><input type="time" name="ate" value="20:00" required></label>
      <label class="campo campo-largo"><span>Motivo <em>(opcional)</em></span><input type="text" name="motivo" maxlength="120" placeholder="Ex.: folga, médico"></label>
      <button class="botao campo-largo">Bloquear horário</button>
    </form>

    <?php if ($bloqueios): ?>
      <h3 class="sub">Próximos bloqueios</h3>
      <ul class="lista-bloq">
        <?php foreach ($bloqueios as $b): ?>
          <li>
            <span>
              <strong><?= e(ucfirst(dataExtenso(substr($b['inicio'], 0, 10)))) ?></strong><br>
              <?= date('H:i', strtotime($b['inicio'])) ?> às <?= date('H:i', strtotime($b['fim'])) ?>,
              <?= e($b['profissional_id'] ? ($profPorId[$b['profissional_id']]['nome'] ?? '?') : 'estúdio todo') ?>
              <?php if ($b['motivo']): ?>(<?= e($b['motivo']) ?>)<?php endif; ?>
            </span>
            <form method="post">
              <?= campoCsrf() ?>
              <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
              <button class="link link-perigo" name="acao" value="desbloquear">Liberar</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="bloco" id="equipe">
    <h2>Fotos da equipe</h2>
    <p class="topo-info">A foto aparece pra cliente na hora de escolher o horário. Foto de rosto, de frente, fica melhor.</p>
    <ul class="lista-equipe">
      <?php foreach ($profs as $p): ?>
        <li style="--cor: <?= e($p['cor']) ?>">
          <?php if ($p['foto']): ?>
            <img class="foto" src="../<?= e($p['foto']) ?>" alt="Foto de <?= e($p['nome']) ?>">
          <?php else: ?>
            <span class="foto" aria-hidden="true"><?= e(iniciais($p['nome'])) ?></span>
          <?php endif; ?>
          <div class="equipe-dados">
            <strong><?= e($p['nome']) ?></strong>
            <form method="post" enctype="multipart/form-data">
              <?= campoCsrf() ?>
              <input type="hidden" name="acao" value="foto">
              <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <label class="botao botao-leve botao-peq">
                <?= $p['foto'] ? 'Trocar foto' : 'Enviar foto' ?>
                <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" onchange="this.form.submit()" class="arquivo">
              </label>
            </form>
            <?php if ($p['foto']): ?>
              <form method="post">
                <?= campoCsrf() ?>
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button class="link link-perigo" name="acao" value="remover_foto">Remover</button>
              </form>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="bloco" id="whatsapp">
    <h2>Aviso no WhatsApp</h2>
    <p class="topo-info">
      <?= $modoAviso === 'desligado'
          ? 'Desligado. Pra ligar, preencha o bloco aviso_whatsapp no config.php.'
          : 'Ligado. A cada horário marcado ou cancelado chega uma mensagem no WhatsApp do estúdio.' ?>
    </p>
    <form method="post" class="form-teste">
      <?= campoCsrf() ?>
      <button class="botao botao-leve botao-peq" name="acao" value="testar_aviso">Enviar mensagem de teste</button>
    </form>
  </section>

  <section class="bloco">
    <h2>Sair do painel</h2>
    <form method="post" action="./">
      <?= campoCsrf() ?>
      <button class="botao botao-leve botao-peq" name="acao" value="sair">Sair</button>
    </form>
  </section>

</main>
</body>
</html>
