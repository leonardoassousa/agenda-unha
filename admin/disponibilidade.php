<?php
require __DIR__ . '/../includes/admin.php';
require __DIR__ . '/../includes/disponibilidade.php';
exigirLogin();

$profs = db()->query('SELECT id, nome, cor, foto FROM profissionais WHERE ativo = 1 ORDER BY ordem, nome')->fetchAll();
$porId = array_column($profs, null, 'id');
$prof = (int) ($_GET['prof'] ?? $_POST['prof'] ?? ($profs[0]['id'] ?? 0));
if (!isset($porId[$prof])) { header('Location: ajustes.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    conferirCsrf();
    $_SESSION['msg_disp'] = acaoDisponibilidade($prof, $_POST);
    header('Location: disponibilidade.php?prof=' . $prof . '#' . (($_POST['acao'] ?? '') === 'semana' ? 'semana' : 'dias'));
    exit;
}
$msg = $_SESSION['msg_disp'] ?? '';
unset($_SESSION['msg_disp']);

headAdmin('Horários da equipe');
cabecalhoAdmin('ajustes.php');
?>
  <h1>Horários da equipe</h1>
  <nav class="quem" aria-label="Profissional">
    <?php foreach ($profs as $p): $ativo = $prof === (int) $p['id']; ?>
      <a href="?prof=<?= (int) $p['id'] ?>" class="quem-item<?= $ativo ? ' ativo' : '' ?>" style="--cor: <?= e($p['cor']) ?>" <?= $ativo ? 'aria-current="true"' : '' ?>>
        <?= $p['foto'] ? '<img class="foto" src="../' . e($p['foto']) . '" alt="">' : '<span class="foto" aria-hidden="true">' . e(iniciais($p['nome'])) . '</span>' ?>
        <span><?= e($p['nome']) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
  <?php if ($msg): ?><p class="nota" role="status"><?= e($msg) ?></p><?php endif; ?>
  <?php renderDisponibilidade($prof, $porId[$prof]['nome'], campoCsrf() . '<input type="hidden" name="prof" value="' . $prof . '">'); ?>
</main>
</body>
</html>
