<?php
require __DIR__ . '/../includes/equipe.php';
require __DIR__ . '/../includes/disponibilidade.php';
$c = exigirColaboradora();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    conferirCsrf();
    $_SESSION['msg_disp'] = acaoDisponibilidade((int) $c['id'], $_POST);
    header('Location: disponibilidade.php#' . (($_POST['acao'] ?? '') === 'semana' ? 'semana' : 'dias'));
    exit;
}
$msg = $_SESSION['msg_disp'] ?? '';
unset($_SESSION['msg_disp']);

headEquipe('Meus horários');
cabecalhoEquipe('disponibilidade.php', $c);
?>
  <?php if ($msg): ?><p class="nota" role="status"><?= e($msg) ?></p><?php endif; ?>
  <?php renderDisponibilidade((int) $c['id'], 'você', campoCsrf()); ?>
</main>
</body>
</html>
