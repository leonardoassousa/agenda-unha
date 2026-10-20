<?php
require __DIR__ . '/includes/cliente.php';

// Já logada? (só abre sessão se ela já tiver o cookie)
$cliente = null;
if (isset($_COOKIE['agenda_cliente'])) {
    iniciarSessaoCliente();
    $cliente = clientePublico(clienteAtual());
    session_write_close();
}

$servicos = servicosAtivos();
$profissionais = profissionaisAtivas();
$diasAbertos = [];
foreach (cfg('horarios') as $dia => $h) if ($h) $diasAbertos[] = $dia;

$dadosJs = [
    'servicos' => array_map(fn($s) => [
        'id' => (int) $s['id'], 'nome' => $s['nome'], 'duracao' => (int) $s['duracao_min'], 'preco' => (float) $s['preco'],
    ], $servicos),
    'profissionais' => array_map(fn($p) => [
        'id' => (int) $p['id'], 'nome' => $p['nome'], 'cor' => $p['cor'],
        'foto' => $p['foto'] ?: null, 'iniciais' => iniciais($p['nome']),
    ], $profissionais),
    'diasAbertos' => $diasAbertos,
    'diasAFrente' => (int) cfg('dias_a_frente'),
    'hoje' => date('Y-m-d'),
    'whatsapp' => cfg('whatsapp'),
    'estudio' => cfg('nome_estudio'),
    'cliente' => $cliente,
    'avisoAutomatico' => ((cfg('aviso_whatsapp') ?: [])['modo'] ?? 'desligado') !== 'desligado',
    'cancelarAteHoras' => (int) (cfg('cancelar_ate_horas') ?? 2),
];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Agendar horário · <?= e(cfg('nome_estudio')) ?></title>
<meta name="description" content="Escolha o serviço, a profissional e o horário. Leva menos de um minuto.">
<meta name="theme-color" content="#FBF3F4">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Gloock&family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="pagina" id="app">

  <header class="topo">
    <h1><?= e(cfg('nome_estudio')) ?></h1>
    <p class="topo-info">
      <?php if (cfg('instagram')): ?><span><?= e(cfg('instagram')) ?></span><?php endif; ?>
      <?php if (cfg('endereco')): ?><span><?= e(cfg('endereco')) ?></span><?php endif; ?>
    </p>
    <div class="conta" id="conta"></div>
  </header>

  <form id="form-agenda" novalidate>

    <section class="etapa" aria-labelledby="t-servico">
      <h2 id="t-servico"><span class="num">1</span> O que você vai fazer?</h2>
      <div class="servicos" role="radiogroup" aria-labelledby="t-servico">
        <?php foreach ($servicos as $s): ?>
          <label class="servico">
            <input type="radio" name="servico" value="<?= (int) $s['id'] ?>">
            <span class="servico-nome"><?= e($s['nome']) ?></span>
            <span class="servico-preco"><?= preco((float) $s['preco']) ?></span>
            <span class="servico-desc"><?= e($s['descricao']) ?></span>
            <span class="servico-dur"><?= (int) $s['duracao_min'] ?> min</span>
          </label>
        <?php endforeach; ?>
        <?php if (!$servicos): ?><p class="vazio">Nenhum serviço cadastrado ainda.</p><?php endif; ?>
      </div>
    </section>

    <section class="etapa" aria-labelledby="t-dia">
      <h2 id="t-dia"><span class="num">2</span> Escolha o dia</h2>
      <div class="dias" id="dias" role="radiogroup" aria-labelledby="t-dia"></div>
    </section>

    <section class="etapa" aria-labelledby="t-hora">
      <h2 id="t-hora"><span class="num">3</span> Escolha o horário e a profissional</h2>
      <div class="equipe" id="equipe" aria-live="polite"></div>
    </section>

    <section class="etapa" aria-labelledby="t-dados">
      <h2 id="t-dados"><span class="num">4</span> Sua conta</h2>

      <div id="auth" class="auth">
        <p class="auth-intro">Pra confirmar o horário, entre na sua conta. Da próxima vez você já entra direto.</p>
        <div class="auth-abas" role="tablist" aria-label="Conta">
          <button type="button" role="tab" id="aba-entrar" aria-controls="painel-entrar" aria-selected="true">Já tenho conta</button>
          <button type="button" role="tab" id="aba-criar" aria-controls="painel-criar" aria-selected="false" tabindex="-1">Criar conta</button>
        </div>

        <div id="painel-entrar" role="tabpanel" aria-labelledby="aba-entrar" class="campos auth-painel">
          <label class="campo campo-largo">
            <span>Celular</span>
            <input type="tel" id="ent-tel" class="mascara-tel" autocomplete="username" inputmode="numeric" placeholder="(73) 99999-9999">
          </label>
          <label class="campo campo-largo">
            <span>Senha</span>
            <span class="senha-caixa">
              <input type="password" id="ent-senha" autocomplete="current-password">
              <button type="button" class="ver-senha" aria-label="Mostrar senha" aria-pressed="false">Mostrar</button>
            </span>
          </label>
          <button type="button" class="botao campo-largo" id="btn-entrar" data-enter>Entrar</button>
          <details class="esqueci campo-largo">
            <summary>Esqueci a senha</summary>
            <p>Fale com o estúdio pelo WhatsApp<?php if (cfg('whatsapp')): ?> (<a href="https://wa.me/<?= e(preg_replace('/\D/', '', cfg('whatsapp'))) ?>?text=<?= rawurlencode('Oi! Esqueci a senha da minha conta de agendamento.') ?>" target="_blank" rel="noopener">abrir conversa</a>)<?php endif; ?>. Elas geram uma senha nova pra você.</p>
          </details>
        </div>

        <div id="painel-criar" role="tabpanel" aria-labelledby="aba-criar" class="campos auth-painel" hidden>
          <label class="campo campo-largo">
            <span>Nome completo</span>
            <input type="text" id="cad-nome" autocomplete="name" maxlength="100" placeholder="Ex.: Maria Souza Lima">
          </label>
          <label class="campo">
            <span>Celular (WhatsApp)</span>
            <input type="tel" id="cad-tel" class="mascara-tel" autocomplete="tel-national" inputmode="numeric" placeholder="(73) 99999-9999">
          </label>
          <label class="campo">
            <span>E-mail</span>
            <input type="email" id="cad-email" autocomplete="email" maxlength="150" placeholder="maria@gmail.com">
          </label>
          <label class="campo campo-largo">
            <span>Crie uma senha <em>(mínimo 6 caracteres)</em></span>
            <span class="senha-caixa">
              <input type="password" id="cad-senha" autocomplete="new-password" minlength="6">
              <button type="button" class="ver-senha" aria-label="Mostrar senha" aria-pressed="false">Mostrar</button>
            </span>
          </label>
          <button type="button" class="botao campo-largo" id="btn-criar" data-enter>Criar conta</button>
        </div>

        <p class="erro erro-auth" id="erro-auth" role="alert" hidden></p>
      </div>

      <div id="logada" class="logada" hidden>
        <div class="logada-cartao">
          <div>
            <p class="logada-nome" id="logada-nome"></p>
            <p class="logada-info" id="logada-info"></p>
          </div>
          <button type="button" class="link" id="trocar-conta">Não é você?</button>
        </div>
        <label class="campo">
          <span>Observação <em>(opcional)</em></span>
          <input type="text" name="observacao" maxlength="255" placeholder="Ex.: quero tirar o gel antigo">
        </label>
      </div>
      <input type="text" name="site" class="isca" tabindex="-1" autocomplete="off" aria-hidden="true">
    </section>

    <div class="rodape">
      <p class="resumo" id="resumo">Escolha serviço, dia e horário.</p>
      <p class="erro" id="erro" role="alert" hidden></p>
      <button type="submit" class="botao" id="btn-confirmar" disabled>Confirmar agendamento</button>
    </div>
  </form>

  <section class="confirmado" id="confirmado" hidden tabindex="-1" aria-labelledby="t-ok">
    <div id="conf-foto"></div>
    <h2 id="t-ok">Horário marcado</h2>
    <dl id="conf-detalhes"></dl>
    <p class="conf-nota" id="conf-nota"></p>
    <a class="botao" id="conf-whats" target="_blank" rel="noopener" hidden>Enviar confirmação no WhatsApp</a>
    <button type="button" class="botao botao-leve" id="conf-novo">Marcar outro horário</button>
  </section>

  <section class="meus" id="meus" hidden tabindex="-1" aria-labelledby="t-meus">
    <button type="button" class="link" id="meus-voltar">Voltar pra agendar</button>
    <h2 id="t-meus">Meus horários</h2>
    <p class="topo-info" id="meus-info"></p>
    <ul class="meus-lista" id="meus-lista"></ul>
  </section>

</main>
<script>window.AGENDA = <?= json_encode($dadosJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="assets/app.js"></script>
</body>
</html>
