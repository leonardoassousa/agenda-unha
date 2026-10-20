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
<header class="navbar">
  <div class="navbar-interna">
    <p class="marca"><?= e(cfg('nome_estudio')) ?></p>
    <nav class="nav" aria-label="Menu">
      <button type="button" class="nav-item" data-ir="agendar" aria-current="page">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h6v4l1.5 2v10.5a1.5 1.5 0 0 1-1.5 1.5H9a1.5 1.5 0 0 1-1.5-1.5V9L9 7z"/><path d="M7.5 12h9"/></svg>
        <span>Serviços</span>
      </button>
      <button type="button" class="nav-item" data-ir="meus" aria-label="Meus horários">
        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>
        <span>Horários</span>
      </button>
      <button type="button" class="nav-item" data-ir="conta" id="nav-conta">
        <span class="nav-avatar" id="nav-avatar" hidden></span>
        <svg viewBox="0 0 24 24" aria-hidden="true" id="nav-icone-conta"><circle cx="12" cy="8.5" r="3.8"/><path d="M4.5 20c1.2-3.8 4-5.5 7.5-5.5s6.3 1.7 7.5 5.5"/></svg>
        <span id="nav-conta-rotulo">Entrar</span>
      </button>
    </nav>
  </div>
</header>

<main class="pagina" id="app">

  <!-- ============ AGENDAR ============ -->
  <div class="tela" id="tela-agendar">
    <header class="topo">
      <h1><?= e(cfg('nome_estudio')) ?></h1>
      <p class="topo-info">
        <?php if (cfg('instagram')): ?><span><?= e(cfg('instagram')) ?></span><?php endif; ?>
        <?php if (cfg('endereco')): ?><span><?= e(cfg('endereco')) ?></span><?php endif; ?>
      </p>
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

      <div class="rodape">
        <p class="resumo" id="resumo">Escolha o serviço.</p>
        <button type="submit" class="botao" id="btn-avancar" disabled>Avançar</button>
      </div>
    </form>
  </div>

  <!-- ============ LOGIN / CADASTRO ============ -->
  <section class="tela tela-estreita" id="tela-login" hidden tabindex="-1" aria-labelledby="t-login">
    <button type="button" class="voltar" data-voltar>Voltar</button>
    <h2 class="titulo-tela" id="t-login">Entre na sua conta</h2>
    <p class="subtitulo" id="login-sub">Pra marcar e acompanhar seus horários.</p>
    <div class="escolha" id="login-escolha" hidden></div>

    <div class="auth" id="auth">
      <div class="auth-abas" role="tablist" aria-label="Conta">
        <button type="button" role="tab" id="aba-entrar" aria-controls="painel-entrar" aria-selected="true">Já tenho conta</button>
        <button type="button" role="tab" id="aba-criar" aria-controls="painel-criar" aria-selected="false" tabindex="-1">Criar conta</button>
      </div>

      <form id="painel-entrar" role="tabpanel" aria-labelledby="aba-entrar" class="campos auth-painel" novalidate>
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
        <button type="submit" class="botao campo-largo" id="btn-entrar">Entrar</button>
        <details class="esqueci campo-largo">
          <summary>Esqueci a senha</summary>
          <p>Fale com o estúdio pelo WhatsApp<?php if (cfg('whatsapp')): ?> (<a href="https://wa.me/<?= e(preg_replace('/\D/', '', cfg('whatsapp'))) ?>?text=<?= rawurlencode('Oi! Esqueci a senha da minha conta de agendamento.') ?>" target="_blank" rel="noopener">abrir conversa</a>)<?php endif; ?>. Elas geram uma senha nova pra você.</p>
        </details>
      </form>

      <form id="painel-criar" role="tabpanel" aria-labelledby="aba-criar" class="campos auth-painel" novalidate hidden>
        <label class="campo campo-largo">
          <span>Nome completo</span>
          <input type="text" id="cad-nome" autocomplete="name" maxlength="100" placeholder="Ex.: Maria Souza Lima">
        </label>
        <label class="campo campo-largo">
          <span>Celular (WhatsApp)</span>
          <input type="tel" id="cad-tel" class="mascara-tel" autocomplete="tel-national" inputmode="numeric" placeholder="(73) 99999-9999">
        </label>
        <label class="campo campo-largo">
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
        <button type="submit" class="botao campo-largo" id="btn-criar">Criar conta</button>
      </form>

      <p class="erro erro-auth" id="erro-auth" role="alert" hidden></p>
    </div>
  </section>

  <!-- ============ REVISÃO ============ -->
  <section class="tela tela-estreita" id="tela-revisao" hidden tabindex="-1" aria-labelledby="t-rev">
    <button type="button" class="voltar" data-voltar>Alterar horário</button>
    <h2 class="titulo-tela" id="t-rev">Confira e confirme</h2>
    <div class="revisao-cartao" id="rev-cartao"></div>
    <div class="rev-conta">
      <p>Agendando como <strong id="rev-nome"></strong></p>
      <button type="button" class="link" id="rev-trocar">Trocar de conta</button>
    </div>
    <label class="campo">
      <span>Observação <em>(opcional)</em></span>
      <input type="text" id="rev-obs" maxlength="255" placeholder="Ex.: quero tirar o gel antigo">
    </label>
    <input type="text" id="rev-site" class="isca" tabindex="-1" autocomplete="off" aria-hidden="true">
    <p class="erro" id="erro-rev" role="alert" hidden></p>
    <button type="button" class="botao botao-cheio" id="btn-confirmar">Confirmar agendamento</button>
  </section>

  <!-- ============ CONFIRMADO ============ -->
  <section class="tela tela-estreita confirmado" id="tela-confirmado" hidden tabindex="-1" aria-labelledby="t-ok">
    <div id="conf-foto"></div>
    <h2 id="t-ok">Horário marcado</h2>
    <dl id="conf-detalhes"></dl>
    <p class="conf-nota" id="conf-nota"></p>
    <a class="botao" id="conf-whats" target="_blank" rel="noopener" hidden>Enviar confirmação no WhatsApp</a>
    <button type="button" class="botao botao-leve" data-ir="meus">Ver meus horários</button>
    <button type="button" class="link" id="conf-novo">Marcar outro horário</button>
  </section>

  <!-- ============ MEUS HORÁRIOS ============ -->
  <section class="tela tela-estreita" id="tela-meus" hidden tabindex="-1" aria-labelledby="t-meus">
    <h2 class="titulo-tela" id="t-meus">Meus horários</h2>
    <p class="subtitulo" id="meus-info"></p>
    <ul class="meus-lista" id="meus-lista"></ul>
    <button type="button" class="botao botao-leve" data-ir="agendar" id="meus-agendar" hidden>Marcar um horário</button>
  </section>

  <!-- ============ CONTA ============ -->
  <section class="tela tela-estreita" id="tela-conta" hidden tabindex="-1" aria-labelledby="t-conta">
    <h2 class="titulo-tela" id="t-conta">Minha conta</h2>
    <div class="perfil">
      <span class="perfil-avatar" id="perfil-avatar" aria-hidden="true"></span>
      <div>
        <p class="perfil-nome" id="perfil-nome"></p>
        <p class="perfil-info" id="perfil-tel"></p>
        <p class="perfil-info" id="perfil-email"></p>
      </div>
    </div>

    <details class="bloco-conta">
      <summary>Trocar senha</summary>
      <form id="form-senha" class="campos" novalidate>
        <label class="campo campo-largo">
          <span>Senha atual</span>
          <input type="password" id="senha-atual" autocomplete="current-password">
        </label>
        <label class="campo campo-largo">
          <span>Nova senha <em>(mínimo 6 caracteres)</em></span>
          <input type="password" id="senha-nova" autocomplete="new-password">
        </label>
        <p class="erro campo-largo" id="erro-senha" role="alert" hidden></p>
        <p class="ok campo-largo" id="ok-senha" role="status" hidden>Senha trocada.</p>
        <button type="submit" class="botao botao-leve campo-largo">Salvar nova senha</button>
      </form>
    </details>

    <button type="button" class="botao botao-leve" id="btn-sair">Sair da conta</button>
  </section>

</main>
<script>window.AGENDA = <?= json_encode($dadosJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="assets/app.js"></script>
</body>
</html>
