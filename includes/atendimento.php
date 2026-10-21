<?php
// Ações e cartão de um atendimento: usados no painel da dona e na área da equipe.
require_once __DIR__ . '/app.php';

/**
 * Executa uma ação num atendimento. $somenteProf limita à colaboradora logada (área da equipe).
 * Retorna a mensagem pra mostrar na tela.
 */
function acaoAtendimento(string $acao, int $id, array $post, ?int $somenteProf = null): string
{
    $st = db()->prepare('SELECT * FROM agendamentos WHERE id = ?');
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a || $a['status'] === 'cancelado') return 'Atendimento não encontrado.';
    if ($somenteProf && (int) $a['profissional_id'] !== $somenteProf) return 'Esse atendimento é de outra colaboradora.';

    $obs = mb_substr(trim(strip_tags((string) ($post['extra_obs'] ?? ''))), 0, 255);
    $valor = lerValor((string) ($post['extra_valor'] ?? '0'));
    if ($valor < 0 || $valor > 10000) return 'Confira o valor a mais: ele precisa ficar entre R$ 0 e R$ 10.000.';

    switch ($acao) {
        case 'concluir':
            if (!in_array($a['status'], ['confirmado', 'concluido'], true)) return 'Esse atendimento não pode ser concluído.';
            db()->prepare("UPDATE agendamentos SET status = 'concluido', concluido_em = COALESCE(concluido_em, NOW()), extra_valor = ?, extra_obs = ? WHERE id = ?")
                ->execute([$valor, $obs ?: null, $id]);
            $total = (float) $a['preco'] + $valor;
            return 'Concluído: ' . $a['cliente_nome'] . ', ' . preco($total) . ($valor > 0 ? ' (com ' . preco($valor) . ' a mais)' : '') . '.';

        case 'salvar_extra':
            db()->prepare('UPDATE agendamentos SET extra_valor = ?, extra_obs = ? WHERE id = ?')->execute([$valor, $obs ?: null, $id]);
            return 'Valor a mais salvo.';

        case 'reabrir':
            db()->prepare("UPDATE agendamentos SET status = 'confirmado', concluido_em = NULL WHERE id = ? AND status = 'concluido'")->execute([$id]);
            return 'Atendimento voltou pra "a concluir".';

        case 'faltou':
            if ($a['status'] !== 'confirmado') return 'Só dá pra marcar falta num horário ainda não concluído.';
            db()->prepare("UPDATE agendamentos SET status = 'faltou' WHERE id = ?")->execute([$id]);
            return 'Marcado como falta.';

        case 'desfazer':
            if ($a['status'] !== 'faltou') return 'Nada pra desfazer.';
            // Só volta se ninguém ocupou o horário nesse meio-tempo
            $st = db()->prepare("SELECT id FROM agendamentos WHERE profissional_id = ? AND id <> ? AND status IN " . STATUS_ATIVOS . " AND inicio < ? AND fim > ?");
            $st->execute([$a['profissional_id'], $id, $a['fim'], $a['inicio']]);
            if ($st->fetchColumn()) return 'Esse horário já foi ocupado por outra cliente.';
            db()->prepare("UPDATE agendamentos SET status = 'confirmado' WHERE id = ?")->execute([$id]);
            return 'Falta desfeita.';

        case 'cancelar':
            if ($a['status'] !== 'confirmado') return 'Só dá pra cancelar um horário ainda não concluído.';
            db()->prepare("UPDATE agendamentos SET status = 'cancelado' WHERE id = ?")->execute([$id]);
            return 'Horário cancelado. Ele ficou livre no link.';
    }
    return 'Ação desconhecida.';
}

function mensagemLembrete(array $a): string
{
    $nome = preg_split('/\s+/', trim($a['cliente_nome']))[0];
    $hoje = substr($a['inicio'], 0, 10) === date('Y-m-d');
    $quando = $hoje ? 'hoje' : dataExtenso(substr($a['inicio'], 0, 10));
    return "Oi, $nome! Passando pra lembrar do seu horário $quando, às " . substr($a['inicio'], 11, 5)
        . ', com ' . $a['profissional'] . ' (' . $a['servico'] . '), no ' . cfg('nome_estudio') . '. '
        . 'Se não puder vir, avise por aqui. Até já!';
}

/** Link do WhatsApp da cliente com o lembrete pronto (funciona sem API nenhuma). */
function linkLembrete(array $a): string
{
    return 'https://wa.me/55' . $a['cliente_telefone'] . '?text=' . rawurlencode(mensagemLembrete($a));
}

/**
 * Cartão de um atendimento com as ações.
 * $a precisa ter: id, inicio, fim, status, cliente_nome, cliente_telefone, observacao, preco, extra_valor, extra_obs,
 * servico, profissional, lembrete, aviso.  $ocultos: campos escondidos pra voltar pra mesma tela.
 */
function cartaoAtendimento(array $a, string $cor, array $ocultos, bool $mostrarProf = true, bool $podeCancelar = true): string
{
    $agora = time();
    $ini = strtotime($a['inicio']);
    $status = $a['status'];
    $extra = (float) $a['extra_valor'];
    $total = (float) $a['preco'] + $extra;
    $campos = campoCsrf() . '<input type="hidden" name="id" value="' . (int) $a['id'] . '">';
    foreach ($ocultos as $k => $v) $campos .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';

    $selo = ['concluido' => '<span class="selo selo-ok">Concluído</span>', 'faltou' => '<span class="selo">Faltou</span>'][$status] ?? '';
    if ($status === 'confirmado' && $ini <= $agora) $selo = '<span class="selo selo-pendente">A concluir</span>';

    ob_start(); ?>
    <li class="item item-<?= e($status) ?>" style="--cor: <?= e($cor) ?>">
      <div class="item-hora">
        <strong><?= date('H:i', $ini) ?></strong>
        <span><?= date('H:i', strtotime($a['fim'])) ?></span>
      </div>
      <div class="item-corpo">
        <p class="item-cliente"><?= e($a['cliente_nome']) ?> <?= $selo ?></p>
        <p><?= e($a['servico']) ?><?= $mostrarProf ? ', com ' . e($a['profissional']) : '' ?></p>
        <p class="item-valor">
          <?php if ($extra > 0): ?>
            <?= preco((float) $a['preco']) ?> + <?= preco($extra) ?> a mais = <strong><?= preco($total) ?></strong>
          <?php else: ?>
            <strong><?= preco($total) ?></strong>
          <?php endif; ?>
        </p>
        <?php if ($a['extra_obs']): ?><p class="item-extra">A mais: <?= e($a['extra_obs']) ?></p><?php endif; ?>
        <?php if ($a['observacao']): ?><p class="item-obs">Cliente pediu: <?= e($a['observacao']) ?></p><?php endif; ?>
        <?php if (($a['aviso'] ?? '') === 'falhou'): ?><p class="item-obs">O aviso no WhatsApp do estúdio não foi enviado.</p><?php endif; ?>
        <?php if (($a['lembrete'] ?? '') === 'enviado'): ?><p class="item-obs">Lembrete enviado no WhatsApp da cliente.</p><?php endif; ?>

        <div class="item-acoes">
          <a href="https://wa.me/55<?= e($a['cliente_telefone']) ?>" target="_blank" rel="noopener"><?= e(telefoneBonito($a['cliente_telefone'])) ?></a>
          <?php if ($status === 'confirmado' && $ini > $agora): ?>
            <a href="<?= e(linkLembrete($a)) ?>" target="_blank" rel="noopener">Lembrar no WhatsApp</a>
          <?php endif; ?>
          <form method="post">
            <?= $campos ?>
            <?php if ($status === 'faltou'): ?>
              <button class="link" name="acao" value="desfazer">Desfazer falta</button>
            <?php elseif ($status === 'concluido'): ?>
              <button class="link" name="acao" value="reabrir">Reabrir</button>
            <?php else: ?>
              <?php if ($ini <= $agora): ?><button class="link" name="acao" value="faltou">Faltou</button><?php endif; ?>
              <?php if ($podeCancelar): ?>
                <button class="link link-perigo" name="acao" value="cancelar" onclick="return confirm('Cancelar o horário de <?= e(addslashes($a['cliente_nome'])) ?>? Ele fica livre de novo no link.')">Cancelar</button>
              <?php endif; ?>
            <?php endif; ?>
          </form>
        </div>

        <?php if ($status === 'confirmado' || $status === 'concluido'): ?>
          <details class="concluir"<?= $status === 'confirmado' && $ini <= $agora ? ' open' : '' ?>>
            <summary><?= $status === 'concluido' ? 'Editar valor a mais' : 'Concluir atendimento' ?></summary>
            <form method="post" class="concluir-form">
              <?= $campos ?>
              <label class="campo">
                <span>Fez algo a mais? <em>(opcional)</em></span>
                <input type="text" name="extra_obs" maxlength="255" value="<?= e($a['extra_obs'] ?? '') ?>" placeholder="Ex.: decoração em 2 unhas">
              </label>
              <label class="campo campo-valor">
                <span>Valor a mais</span>
                <span class="valor-caixa"><span aria-hidden="true">R$</span>
                  <input type="text" name="extra_valor" inputmode="decimal" value="<?= $extra > 0 ? e(number_format($extra, 2, ',', '')) : '' ?>" placeholder="0,00" aria-label="Valor a mais em reais">
                </span>
              </label>
              <button class="botao botao-peq" name="acao" value="<?= $status === 'concluido' ? 'salvar_extra' : 'concluir' ?>">
                <?= $status === 'concluido' ? 'Salvar' : 'Concluir' ?>
              </button>
            </form>
          </details>
        <?php endif; ?>
      </div>
    </li>
    <?php
    return ob_get_clean();
}

/** Busca atendimentos com os nomes (pra listas e caixa). */
function buscarAtendimentos(string $de, string $ate, int $profId = 0, array $status = ['confirmado', 'concluido', 'faltou']): array
{
    $in = implode(',', array_fill(0, count($status), '?'));
    $sql = "SELECT a.*, s.nome servico, p.nome profissional, p.cor FROM agendamentos a
            JOIN servicos s ON s.id = a.servico_id JOIN profissionais p ON p.id = a.profissional_id
            WHERE a.inicio >= ? AND a.inicio < ? AND a.status IN ($in)" . ($profId ? ' AND a.profissional_id = ?' : '') . '
            ORDER BY a.inicio, a.profissional_id';
    $par = array_merge(["$de 00:00:00", date('Y-m-d', strtotime("$ate +1 day")) . ' 00:00:00'], $status);
    if ($profId) $par[] = $profId;
    $st = db()->prepare($sql);
    $st->execute($par);
    return $st->fetchAll();
}
