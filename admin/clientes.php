<?php
require __DIR__ . '/../includes/admin.php';
exigirLogin();

$senhaNova = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    conferirCsrf();
    if (($_POST['acao'] ?? '') === 'nova_senha') {
        $st = db()->prepare('SELECT id, nome, telefone FROM clientes WHERE id = ?');
        $st->execute([(int) $_POST['id']]);
        if ($c = $st->fetch()) {
            // Senha fácil de ditar: sem letras parecidas (0/O, 1/l)
            $alfabeto = 'abcdefghjkmnpqrstuvwxyz23456789';
            $senha = '';
            for ($i = 0; $i < 8; $i++) $senha .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            db()->prepare('UPDATE clientes SET senha_hash = ? WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), $c['id']]);
            db()->prepare('DELETE FROM login_tentativas WHERE chave = ?')->execute(['tel:' . $c['telefone']]);
            $_SESSION['senha_nova'] = ['nome' => $c['nome'], 'telefone' => $c['telefone'], 'senha' => $senha];
        }
    }
    header('Location: clientes.php' . (isset($_POST['q']) && $_POST['q'] !== '' ? '?q=' . rawurlencode($_POST['q']) : ''));
    exit;
}
$senhaNova = $_SESSION['senha_nova'] ?? null;
unset($_SESSION['senha_nova']);

$q = trim((string) ($_GET['q'] ?? ''));
$params = [];
$where = '';
if ($q !== '') {
    $digitos = preg_replace('/\D/', '', $q);
    $where = 'WHERE c.nome LIKE ? OR c.email LIKE ?' . ($digitos !== '' ? ' OR c.telefone LIKE ?' : '');
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    $params = [$like, $like];
    if ($digitos !== '') $params[] = '%' . $digitos . '%';
}
$st = db()->prepare("SELECT c.*,
        (SELECT COUNT(*) FROM agendamentos a WHERE a.cliente_id = c.id AND (a.status = 'concluido' OR (a.status = 'confirmado' AND a.fim <= NOW()))) feitos,
        (SELECT COUNT(*) FROM agendamentos a WHERE a.cliente_id = c.id AND a.status = 'faltou') faltas,
        (SELECT MIN(a.inicio) FROM agendamentos a WHERE a.cliente_id = c.id AND a.status = 'confirmado' AND a.inicio > NOW()) proximo
    FROM clientes c $where ORDER BY c.nome LIMIT 200");
$st->execute($params);
$clientes = $st->fetchAll();
$total = (int) db()->query('SELECT COUNT(*) FROM clientes')->fetchColumn();

headAdmin('Clientes');
cabecalhoAdmin('clientes.php');
?>
  <h1>Clientes</h1>
  <p class="topo-info"><?= $total ?> <?= $total === 1 ? 'cliente cadastrada' : 'clientes cadastradas' ?></p>

  <?php if ($senhaNova):
      $msg = "Oi, {$senhaNova['nome']}! Sua nova senha pra agendar é: {$senhaNova['senha']}"; ?>
    <div class="nota nota-senha" role="status">
      <p>Nova senha de <strong><?= e($senhaNova['nome']) ?></strong>:</p>
      <p class="senha-gerada"><?= e($senhaNova['senha']) ?></p>
      <p>Ela só aparece agora. Mande pra cliente:</p>
      <a class="botao botao-peq" href="https://wa.me/55<?= e($senhaNova['telefone']) ?>?text=<?= rawurlencode($msg) ?>" target="_blank" rel="noopener">Enviar pelo WhatsApp</a>
    </div>
  <?php endif; ?>

  <form method="get" class="busca">
    <label class="campo campo-largo">
      <span>Buscar</span>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Nome, celular ou e-mail">
    </label>
  </form>

  <ul class="lista-clientes">
    <?php foreach ($clientes as $c): ?>
      <li>
        <div class="cli-corpo">
          <p class="cli-nome"><?= e($c['nome']) ?></p>
          <p class="cli-info">
            <a href="https://wa.me/55<?= e($c['telefone']) ?>" target="_blank" rel="noopener"><?= e(telefoneBonito($c['telefone'])) ?></a>
            <span><?= e($c['email']) ?></span>
          </p>
          <p class="cli-info">
            <?= (int) $c['feitos'] ?> <?= (int) $c['feitos'] === 1 ? 'atendimento' : 'atendimentos' ?>
            <?php if ($c['faltas']): ?>, <?= (int) $c['faltas'] ?> <?= (int) $c['faltas'] === 1 ? 'falta' : 'faltas' ?><?php endif; ?>
            <?php if ($c['proximo']): ?><br>Próximo: <a href="./?data=<?= e(substr($c['proximo'], 0, 10)) ?>"><?= date('d/m \à\s H:i', strtotime($c['proximo'])) ?></a><?php endif; ?>
          </p>
        </div>
        <form method="post">
          <?= campoCsrf() ?>
          <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <input type="hidden" name="q" value="<?= e($q) ?>">
          <button class="link" name="acao" value="nova_senha" onclick="return confirm('Gerar uma senha nova pra <?= e(addslashes($c['nome'])) ?>? A senha antiga para de funcionar.')">Gerar nova senha</button>
        </form>
      </li>
    <?php endforeach; ?>
    <?php if (!$clientes): ?>
      <li class="vazio"><?= $q !== '' ? 'Ninguém encontrado com essa busca.' : 'Ainda não tem cliente cadastrada. Elas aparecem aqui quando criam conta pelo link.' ?></li>
    <?php endif; ?>
  </ul>

</main>
</body>
</html>
