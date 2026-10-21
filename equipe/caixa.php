<?php
require __DIR__ . '/../includes/equipe.php';
require __DIR__ . '/../includes/caixa.php';
$c = exigirColaboradora();

$mes = mesValido($_GET['mes'] ?? null);
$dia = diaValido($_GET['dia'] ?? null, $mes);
$url = fn(array $p) => 'caixa.php?' . http_build_query(array_filter(array_merge(['mes' => $mes, 'dia' => $dia], array_diff_key($p, ['prof' => 1]))));

headEquipe('Meu caixa');
cabecalhoEquipe('caixa.php', $c);
renderCaixa($mes, $dia, (int) $c['id'], [$c], $url, false);
?>
</main>
</body>
</html>
