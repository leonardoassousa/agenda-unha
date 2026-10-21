<?php
require __DIR__ . '/../includes/admin.php';
require __DIR__ . '/../includes/caixa.php';
exigirLogin();

$profs = db()->query('SELECT id, nome, cor, foto FROM profissionais WHERE ativo = 1 ORDER BY ordem, nome')->fetchAll();
$ids = array_map('intval', array_column($profs, 'id'));
$prof = (int) ($_GET['prof'] ?? 0);
if (!in_array($prof, $ids, true)) $prof = 0;
$mes = mesValido($_GET['mes'] ?? null);
$dia = diaValido($_GET['dia'] ?? null, $mes);
$url = fn(array $p) => 'caixa.php?' . http_build_query(array_filter(array_merge(['mes' => $mes, 'prof' => $prof, 'dia' => $dia], $p)));

function fotoCaixa(array $p): string
{
    return $p['foto']
        ? '<img class="foto" src="../' . e($p['foto']) . '" alt="" style="--cor:' . e($p['cor']) . '">'
        : '<span class="foto" style="--cor:' . e($p['cor']) . '" aria-hidden="true">' . e(iniciais($p['nome'])) . '</span>';
}

headAdmin('Caixa');
cabecalhoAdmin('caixa.php');
?>
  <nav class="quem" aria-label="Profissional">
    <a href="<?= e($url(['prof' => 0])) ?>" class="quem-item<?= $prof ? '' : ' ativo' ?>" <?= $prof ? '' : 'aria-current="true"' ?>>
      <span class="foto foto-todas" aria-hidden="true"><?= count($profs) ?></span><span>Todas</span>
    </a>
    <?php foreach ($profs as $p): $ativo = $prof === (int) $p['id']; ?>
      <a href="<?= e($url(['prof' => $p['id']])) ?>" class="quem-item<?= $ativo ? ' ativo' : '' ?>" style="--cor: <?= e($p['cor']) ?>" <?= $ativo ? 'aria-current="true"' : '' ?>>
        <?= fotoCaixa($p) ?><span><?= e($p['nome']) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <?php renderCaixa($mes, $dia, $prof, $profs, $url); ?>

</main>
</body>
</html>
