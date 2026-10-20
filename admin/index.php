<?php
require __DIR__ . '/../includes/admin.php';
require __DIR__ . '/../includes/whatsapp.php';

$logada = logada();
$msg = '';

function voltar(string $query = ''): void
{
    $ancora = '';
    if (($p = strpos($query, '#')) !== false) { $ancora = substr($query, $p); $query = substr($query, 0, $p); }
    header('Location: ./' . ($query ? '?' . $query : '') . $ancora);
    exit;
}

const PASTA_FOTOS = 'uploads/profissionais';

function removerFoto(int $id): void
{
    $st = db()->prepare('SELECT foto FROM profissionais WHERE id = ?');
    $st->execute([$id]);
    $atual = $st->fetchColumn();
    if ($atual && strpos($atual, PASTA_FOTOS . '/') === 0) @unlink(__DIR__ . '/../' . $atual);
    db()->prepare('UPDATE profissionais SET foto = NULL WHERE id = ?')->execute([$id]);
}

/** Salva a foto quadrada (400x400). Retorna mensagem de erro ou '' se deu certo. */
function salvarFoto(int $id, ?array $arq): string
{
    if (!$arq || $arq['error'] === UPLOAD_ERR_NO_FILE) return 'Escolha uma foto.';
    if ($arq['error'] !== UPLOAD_ERR_OK) return 'A foto não chegou. Tente uma imagem menor que 5 MB.';
    if ($arq['size'] > 5 * 1024 * 1024) return 'A foto passou de 5 MB. Escolha uma menor.';
    $info = @getimagesize($arq['tmp_name']);
    $tipos = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($tipos[$info[2]])) return 'Use uma foto JPG, PNG ou WEBP.';

    $st = db()->prepare('SELECT id FROM profissionais WHERE id = ?');
    $st->execute([$id]);
    if (!$st->fetchColumn()) return 'Profissional não encontrada.';

    $dir = __DIR__ . '/../' . PASTA_FOTOS;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $nome = $id . '-' . bin2hex(random_bytes(4));

    if (function_exists('imagecreatetruecolor')) {
        // Recorta no centro e reduz pra 400x400: carrega rápido no celular
        $abrir = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'][$info[2]];
        $src = function_exists($abrir) ? @$abrir($arq['tmp_name']) : false;
        if (!$src) return 'Não deu pra abrir essa foto. Tente outra.';
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($arq['tmp_name']);
            $giro = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
            if ($giro) $src = imagerotate($src, $giro, 0);
        }
        $w = imagesx($src); $h = imagesy($src); $lado = min($w, $h);
        $dst = imagecreatetruecolor(400, 400);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // fundo branco pra PNG transparente
        // corta no centro na horizontal e um pouco pra cima na vertical (onde fica o rosto)
        imagecopyresampled($dst, $src, 0, 0, (int) (($w - $lado) / 2), (int) (($h - $lado) / 3), 400, 400, $lado, $lado);
        $arquivo = "$nome.jpg";
        imagejpeg($dst, "$dir/$arquivo", 85);
    } else {
        $arquivo = "$nome." . $tipos[$info[2]];
        if (!move_uploaded_file($arq['tmp_name'], "$dir/$arquivo")) return 'Não deu pra salvar a foto.';
    }

    removerFoto($id);
    db()->prepare('UPDATE profissionais SET foto = ? WHERE id = ?')->execute([PASTA_FOTOS . '/' . $arquivo, $id]);
    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    conferirCsrf();
    $acao = $_POST['acao'] ?? '';
    $volta = http_build_query(array_filter(['data' => $_POST['data_ref'] ?? '', 'prof' => $_POST['prof_ref'] ?? '']));

    if ($acao === 'entrar') {
        if (hash_equals((string) cfg('admin_senha'), (string) ($_POST['senha'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            voltar();
        }
        sleep(1);
        $msg = 'Senha incorreta.';
    } elseif ($logada) {
        if ($acao === 'sair') {
            $_SESSION = [];
            session_destroy();
            voltar();
        }
        $novoStatus = ['cancelar' => 'cancelado', 'faltou' => 'faltou', 'desfazer' => 'confirmado'][$acao] ?? null;
        if ($novoStatus) {
            if ($novoStatus === 'confirmado') {
                // "Desfazer falta": só volta se o horário não foi ocupado por outra cliente
                $st = db()->prepare("SELECT a.id FROM agendamentos a JOIN agendamentos b
                                      ON b.profissional_id = a.profissional_id AND b.id <> a.id AND b.status = 'confirmado'
                                     AND b.inicio < a.fim AND b.fim > a.inicio WHERE a.id = ?");
                $st->execute([(int) $_POST['id']]);
                if ($st->fetchColumn()) { $_SESSION['aviso_agenda'] = 'Esse horário já foi ocupado por outra cliente.'; voltar($volta); }
            }
            db()->prepare('UPDATE agendamentos SET status = ? WHERE id = ?')->execute([$novoStatus, (int) $_POST['id']]);
            voltar($volta);
        }
        if ($acao === 'testar_aviso') {
            $erro = enviarWhatsappEstudio("Teste do aviso de agendamento ✅\nSe chegou, está tudo certo.");
            $_SESSION['aviso_agenda'] = $erro === '' ? 'Mensagem de teste enviada. Confira o WhatsApp do estúdio (pode levar até 1 minuto).' : 'O teste não foi: ' . $erro;
            voltar($volta . '#teste-aviso');
        }
        if ($acao === 'bloquear') {
            $data = (string) $_POST['data'];
            $de = (string) $_POST['de'];
            $ate = (string) $_POST['ate'];
            $prof = (int) $_POST['profissional'] ?: null;
            $ok = DateTime::createFromFormat('!Y-m-d', $data) && preg_match('/^\d{2}:\d{2}$/', $de) && preg_match('/^\d{2}:\d{2}$/', $ate) && $de < $ate;
            if ($ok) {
                db()->prepare('INSERT INTO bloqueios (profissional_id, inicio, fim, motivo) VALUES (?, ?, ?, ?)')
                    ->execute([$prof, "$data $de:00", "$data $ate:00", mb_substr(trim((string) $_POST['motivo']), 0, 120) ?: null]);
            }
            voltar($volta);
        }
        if ($acao === 'foto') {
            $msgFoto = salvarFoto((int) $_POST['id'], $_FILES['foto'] ?? null);
            if ($msgFoto) { $_SESSION['aviso'] = $msgFoto; }
            voltar($volta . '#equipe');
        }
        if ($acao === 'remover_foto') {
            removerFoto((int) $_POST['id']);
            voltar($volta . '#equipe');
        }
        if ($acao === 'desbloquear') {
            db()->prepare('DELETE FROM bloqueios WHERE id = ?')->execute([(int) $_POST['id']]);
            voltar($volta);
        }
    }
}


if ($logada) {
    $data = $_GET['data'] ?? date('Y-m-d');
    if (!DateTime::createFromFormat('!Y-m-d', $data)) $data = date('Y-m-d');
    $filtroProf = (int) ($_GET['prof'] ?? 0);

    $profs = db()->query('SELECT id, nome, cor, foto FROM profissionais ORDER BY ordem, nome')->fetchAll();
    $aviso = $_SESSION['aviso'] ?? '';
    $avisoAgenda = $_SESSION['aviso_agenda'] ?? '';
    unset($_SESSION['aviso'], $_SESSION['aviso_agenda']);
    $profPorId = array_column($profs, null, 'id');

    $sql = "SELECT a.*, s.nome servico FROM agendamentos a JOIN servicos s ON s.id = a.servico_id
            WHERE a.status IN ('confirmado','faltou') AND a.inicio >= ? AND a.inicio < ?" . ($filtroProf ? ' AND a.profissional_id = ?' : '') . ' ORDER BY a.inicio, a.profissional_id';
    $par = ["$data 00:00:00", date('Y-m-d', strtotime("$data +1 day")) . ' 00:00:00'];
    if ($filtroProf) $par[] = $filtroProf;
    $st = db()->prepare($sql);
    $st->execute($par);
    $agendamentos = $st->fetchAll();
    $confirmados = array_filter($agendamentos, fn($a) => $a['status'] === 'confirmado');

    $st = db()->prepare('SELECT * FROM bloqueios WHERE inicio < ? AND fim > ? ORDER BY inicio');
    $st->execute([date('Y-m-d', strtotime("$data +1 day")) . ' 00:00:00', "$data 00:00:00"]);
    $bloqueios = $st->fetchAll();

    $total = array_sum(array_column($confirmados, 'preco'));
    $anterior = date('Y-m-d', strtotime("$data -1 day"));
    $proximo = date('Y-m-d', strtotime("$data +1 day"));
    $q = fn(array $p) => '?' . http_build_query(array_filter(array_merge(['data' => $data, 'prof' => $filtroProf], $p)));
}
?>
<?php headAdmin('Agenda'); ?>

<?php if (!$logada): ?>
  <form method="post" class="login">
    <h1>Agenda</h1>
    <p class="topo-info"><?= e(cfg('nome_estudio')) ?></p>
    <?= campoCsrf() ?>
    <input type="hidden" name="acao" value="entrar">
    <label class="campo">
      <span>Senha</span>
      <input type="password" name="senha" autocomplete="current-password" required autofocus>
    </label>
    <?php if ($msg): ?><p class="erro" role="alert"><?= e($msg) ?></p><?php endif; ?>
    <button class="botao">Entrar</button>
  </form>

<?php else: ?>
  <?php cabecalhoAdmin('index.php'); ?>
  <h1>Agenda</h1>
  <?php if ($avisoAgenda): ?><p class="nota" role="status"><?= e($avisoAgenda) ?></p><?php endif; ?>

  <nav class="navdia" aria-label="Escolher dia">
    <a class="seta" href="<?= e($q(['data' => $anterior])) ?>" aria-label="Dia anterior">‹</a>
    <form method="get" class="navdia-data">
      <?php if ($filtroProf): ?><input type="hidden" name="prof" value="<?= $filtroProf ?>"><?php endif; ?>
      <input type="date" name="data" value="<?= e($data) ?>" onchange="this.form.submit()" aria-label="Data">
      <strong><?= e(dataExtenso($data)) ?></strong>
    </form>
    <a class="seta" href="<?= e($q(['data' => $proximo])) ?>" aria-label="Próximo dia">›</a>
  </nav>

  <div class="filtro" role="list">
    <a role="listitem" href="<?= e($q(['prof' => 0])) ?>" class="<?= $filtroProf ? '' : 'ativo' ?>">Todas</a>
    <?php foreach ($profs as $p): ?>
      <a role="listitem" href="<?= e($q(['prof' => $p['id']])) ?>" class="<?= $filtroProf === (int) $p['id'] ? 'ativo' : '' ?>">
        <i class="bolinha" style="--cor: <?= e($p['cor']) ?>"></i><?= e($p['nome']) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <p class="totais">
    <?= count($confirmados) ?> <?= count($confirmados) === 1 ? 'atendimento' : 'atendimentos' ?>,
    <?= preco((float) $total) ?>
    <?php $faltas = count($agendamentos) - count($confirmados); if ($faltas): ?>(<?= $faltas ?> <?= $faltas === 1 ? 'falta' : 'faltas' ?>)<?php endif; ?>
  </p>

  <ol class="lista">
    <?php foreach ($agendamentos as $a):
        $p = $profPorId[$a['profissional_id']] ?? ['nome' => '?', 'cor' => '#999999'];
        $tel = $a['cliente_telefone'];
        $faltou = $a['status'] === 'faltou';
        $jaComecou = strtotime($a['inicio']) <= time();
    ?>
      <li class="item<?= $faltou ? ' item-faltou' : '' ?>" style="--cor: <?= e($p['cor']) ?>">
        <div class="item-hora">
          <strong><?= date('H:i', strtotime($a['inicio'])) ?></strong>
          <span><?= date('H:i', strtotime($a['fim'])) ?></span>
        </div>
        <div class="item-corpo">
          <p class="item-cliente"><?= e($a['cliente_nome']) ?><?php if ($faltou): ?> <span class="selo">Faltou</span><?php endif; ?></p>
          <p><?= e($a['servico']) ?>, com <?= e($p['nome']) ?>, <span class="nowrap"><?= preco((float) $a['preco']) ?></span></p>
          <?php if ($a['observacao']): ?><p class="item-obs"><?= e($a['observacao']) ?></p><?php endif; ?>
          <?php if (($a['aviso'] ?? '') === 'falhou'): ?><p class="item-obs">O aviso no WhatsApp desse horário não foi enviado.</p><?php endif; ?>
          <div class="item-acoes">
            <a href="https://wa.me/55<?= e($tel) ?>" target="_blank" rel="noopener"><?= e(telefoneBonito($tel)) ?></a>
            <form method="post">
              <?= campoCsrf() ?>
              <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
              <input type="hidden" name="data_ref" value="<?= e($data) ?>">
              <input type="hidden" name="prof_ref" value="<?= $filtroProf ?>">
              <?php if ($faltou): ?>
                <button class="link" name="acao" value="desfazer">Desfazer falta</button>
              <?php else: ?>
                <?php if ($jaComecou): ?><button class="link" name="acao" value="faltou">Faltou</button><?php endif; ?>
                <button class="link link-perigo" name="acao" value="cancelar" onclick="return confirm('Cancelar o horário de <?= e(addslashes($a['cliente_nome'])) ?>? O horário fica livre de novo.')">Cancelar</button>
              <?php endif; ?>
            </form>
          </div>
        </div>
      </li>
    <?php endforeach; ?>
    <?php if (!$agendamentos): ?>
      <li class="vazio">Nenhum horário marcado nesse dia.</li>
    <?php endif; ?>
  </ol>

  <section class="bloqueios" id="equipe">
    <h2>Fotos da equipe</h2>
    <p class="topo-info">A foto aparece pra cliente na hora de escolher o horário. Uma foto de rosto, de frente, fica melhor.</p>
    <?php if ($aviso): ?><p class="erro" role="alert"><?= e($aviso) ?></p><?php endif; ?>
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
            <form method="post" enctype="multipart/form-data" class="form-foto">
              <?= campoCsrf() ?>
              <input type="hidden" name="acao" value="foto">
              <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <input type="hidden" name="data_ref" value="<?= e($data) ?>">
              <input type="hidden" name="prof_ref" value="<?= $filtroProf ?>">
              <label class="botao botao-leve botao-peq">
                <?= $p['foto'] ? 'Trocar foto' : 'Enviar foto' ?>
                <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" onchange="this.form.submit()" class="arquivo">
              </label>
            </form>
            <?php if ($p['foto']): ?>
              <form method="post">
                <?= campoCsrf() ?>
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <input type="hidden" name="data_ref" value="<?= e($data) ?>">
                <input type="hidden" name="prof_ref" value="<?= $filtroProf ?>">
                <button class="link link-perigo" name="acao" value="remover_foto">Remover</button>
              </form>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="bloqueios" id="teste-aviso">
    <h2>Aviso no WhatsApp</h2>
    <?php $modoAviso = (cfg('aviso_whatsapp') ?: [])['modo'] ?? 'desligado'; ?>
    <p class="topo-info">
      <?= $modoAviso === 'desligado'
          ? 'Desligado. Pra ligar, preencha o bloco aviso_whatsapp no config.php.'
          : 'Ligado. A cada horário marcado chega uma mensagem no WhatsApp do estúdio.' ?>
    </p>
    <form method="post" class="form-teste">
      <?= campoCsrf() ?>
      <input type="hidden" name="data_ref" value="<?= e($data) ?>">
      <input type="hidden" name="prof_ref" value="<?= $filtroProf ?>">
      <button class="botao botao-leve botao-peq" name="acao" value="testar_aviso">Enviar mensagem de teste</button>
    </form>
  </section>

  <section class="bloqueios">
    <h2>Bloquear horário</h2>
    <p class="topo-info">Folga, consulta, saída mais cedo. O horário some do link na hora.</p>

    <?php if ($bloqueios): ?>
      <ul class="lista-bloq">
        <?php foreach ($bloqueios as $b): ?>
          <li>
            <span>
              <strong><?= date('H:i', strtotime($b['inicio'])) ?> às <?= date('H:i', strtotime($b['fim'])) ?></strong>,
              <?= e($b['profissional_id'] ? ($profPorId[$b['profissional_id']]['nome'] ?? '?') : 'todas') ?>
              <?php if ($b['motivo']): ?>(<?= e($b['motivo']) ?>)<?php endif; ?>
            </span>
            <form method="post">
              <?= campoCsrf() ?>
              <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
              <input type="hidden" name="data_ref" value="<?= e($data) ?>">
              <input type="hidden" name="prof_ref" value="<?= $filtroProf ?>">
              <button class="link link-perigo" name="acao" value="desbloquear">Liberar</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <form method="post" class="campos form-bloq">
      <?= campoCsrf() ?>
      <input type="hidden" name="acao" value="bloquear">
      <input type="hidden" name="data_ref" value="<?= e($data) ?>">
      <input type="hidden" name="prof_ref" value="<?= $filtroProf ?>">
      <label class="campo campo-largo">
        <span>Quem</span>
        <select name="profissional">
          <option value="0">Todas (fecha o estúdio)</option>
          <?php foreach ($profs as $p): ?>
            <option value="<?= (int) $p['id'] ?>" <?= $filtroProf === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="campo campo-largo"><span>Dia</span><input type="date" name="data" value="<?= e($data) ?>" required></label>
      <label class="campo"><span>Das</span><input type="time" name="de" value="08:00" required></label>
      <label class="campo"><span>Até</span><input type="time" name="ate" value="20:00" required></label>
      <label class="campo campo-largo"><span>Motivo <em>(opcional)</em></span><input type="text" name="motivo" maxlength="120"></label>
      <button class="botao botao-leve campo-largo">Bloquear horário</button>
    </form>
  </section>
<?php endif; ?>

</main>
</body>
</html>
