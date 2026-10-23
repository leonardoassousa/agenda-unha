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

$dadosJs = [
    'servicos' => array_map(fn($s) => [
        'id' => (int) $s['id'], 'nome' => $s['nome'], 'descricao' => (string) $s['descricao'],
        'duracao' => (int) $s['duracao_min'], 'preco' => (float) $s['preco'],
    ], $servicos),
    'profissionais' => array_map(fn($p) => [
        'id' => (int) $p['id'], 'nome' => $p['nome'], 'cor' => $p['cor'],
        'foto' => $p['foto'] ?: null, 'iniciais' => iniciais($p['nome']),
    ], $profissionais),
    'diasDisponiveis' => diasDisponiveis(),
    'diasAFrente' => (int) cfg('dias_a_frente'),
    'hoje' => date('Y-m-d'),
    'whatsapp' => cfg('whatsapp'),
    'estudio' => cfg('nome_estudio'),
    'cliente' => $cliente,
    'avisoAutomatico' => ((cfg('aviso_whatsapp') ?: [])['modo'] ?? 'desligado') !== 'desligado',
    'cancelarAteHoras' => (int) (cfg('cancelar_ate_horas') ?? 2),
];

// Aberto agora? (mostrado no topo)
function statusEstudio(): array
{
    $ids = array_map('intval', array_column(profissionaisAtivas(), 'id'));
    $agora = time();
    $nomes = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
    for ($i = 0; $i <= 7; $i++) {
        $d = date('Y-m-d', strtotime("+$i day"));
        $ini = null; $fim = null;
        foreach ($ids as $id) {
            if (!$j = jornada($id, $d)) continue;
            $ini = $ini === null ? $j['ini'] : min($ini, $j['ini']);
            $fim = $fim === null ? $j['fim'] : max($fim, $j['fim']);
        }
        if ($ini === null) continue;
        if ($i === 0 && $agora >= $ini && $agora < $fim) return [true, 'Aberto agora, até ' . date('H:i', $fim)];
        if ($i === 0 && $agora >= $fim) continue;
        $quando = $i === 0 ? 'hoje' : ($i === 1 ? 'amanhã' : $nomes[(int) date('w', strtotime($d))]);
        return [false, "Fechado agora. Abre $quando às " . date('H:i', $ini)];
    }
    return [false, 'Fechado agora'];
}
[$aberto, $statusTexto] = statusEstudio();
$waEstudio = preg_replace('/\D/', '', (string) cfg('whatsapp'));
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Agendar horário · <?= e(cfg('nome_estudio')) ?></title>
<meta name="description" content="Escolha o serviço, a profissional e o horário. Leva menos de um minuto.">
<meta name="theme-color" content="#3A0E1E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Gloock&family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/cliente.css">
</head>
<body>

<main id="app">

  <!-- ============ 1. SERVIÇO ============ -->
  <section class="tela" id="tela-servico" aria-labelledby="t-servico">
    <header class="capa">
      <div class="capa-in">
        <p class="capa-status <?= $aberto ? 'aberto' : '' ?>"><span class="ponto" aria-hidden="true"></span><?= e($statusTexto) ?></p>
        <h1><?= e(cfg('nome_estudio')) ?></h1>
        <p class="capa-info">
          <?php if (cfg('instagram')): ?><span><?= e(cfg('instagram')) ?></span><?php endif; ?>
          <?php if (cfg('endereco')): ?><span><?= e(cfg('endereco')) ?></span><?php endif; ?>
        </p>
      </div>
    </header>
    <div class="folha">
      <ol class="passos" aria-label="Etapas do agendamento">
        <li aria-current="step">Serviço</li><li>Horário</li><li>Confirmar</li>
      </ol>
      <h2 class="titulo" id="t-servico">Qual serviço você quer?</h2>
      <ul class="servicos">
        <?php foreach ($servicos as $s): ?>
          <li>
            <button type="button" class="servico" data-servico="<?= (int) $s['id'] ?>">
              <span class="servico-texto">
                <span class="servico-nome"><?= e($s['nome']) ?></span>
                <?php if ($s['descricao']): ?><span class="servico-desc"><?= e($s['descricao']) ?></span><?php endif; ?>
              </span>
              <span class="servico-valor">
                <span class="servico-preco"><?= preco((float) $s['preco']) ?></span>
                <span class="servico-dur"><?= (int) $s['duracao_min'] ?> min</span>
              </span>
              <svg class="seta" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
            </button>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if (!$servicos): ?><p class="vazio">Nenhum serviço cadastrado ainda.</p><?php endif; ?>
    </div>
  </section>

  <!-- ============ 2. DIA, PROFISSIONAL E HORÁRIO ============ -->
  <section class="tela" id="tela-horario" hidden tabindex="-1" aria-labelledby="t-horario">
    <div class="folha folha-solta">
      <ol class="passos" aria-label="Etapas do agendamento">
        <li class="feito">Serviço</li><li aria-current="step">Horário</li><li>Confirmar</li>
      </ol>
      <div class="escolhido" id="escolhido"></div>

      <h2 class="titulo" id="t-horario">Escolha o dia</h2>
      <div class="dias" id="dias" role="radiogroup" aria-labelledby="t-horario"></div>

      <h2 class="titulo titulo-menor" id="t-pros">Com quem?</h2>
      <div class="pros" id="pros" role="tablist" aria-labelledby="t-pros"></div>

      <div class="horas" id="horas" role="radiogroup" aria-label="Horários livres" aria-live="polite"></div>
    </div>

    <div class="cta" id="cta">
      <p class="cta-resumo" id="cta-resumo">Toque num horário</p>
      <button type="button" class="botao" id="btn-continuar" disabled>Continuar</button>
    </div>
  </section>

  <!-- ============ LOGIN / CADASTRO ============ -->
  <section class="tela" id="tela-login" hidden tabindex="-1" aria-labelledby="t-login">
    <div class="folha folha-solta">
      <button type="button" class="voltar" data-voltar>Voltar</button>
      <h2 class="titulo titulo-grande" id="t-login">Entre na sua conta</h2>
      <p class="subtitulo" id="login-sub">Pra marcar e acompanhar seus horários.</p>
      <div id="login-escolha"></div>

      <div class="cartao auth">
        <div class="abas" role="tablist" aria-label="Conta">
          <button type="button" role="tab" id="aba-entrar" aria-controls="painel-entrar" aria-selected="true">Já tenho conta</button>
          <button type="button" role="tab" id="aba-criar" aria-controls="painel-criar" aria-selected="false" tabindex="-1">Criar conta</button>
        </div>

        <form id="painel-entrar" role="tabpanel" aria-labelledby="aba-entrar" class="form" novalidate>
          <label class="campo">
            <span>Celular</span>
            <input type="tel" id="ent-tel" class="mascara-tel" autocomplete="username" inputmode="numeric" placeholder="(73) 99999-9999">
          </label>
          <label class="campo">
            <span>Senha</span>
            <span class="senha-caixa">
              <input type="password" id="ent-senha" autocomplete="current-password">
              <button type="button" class="ver-senha" aria-label="Mostrar senha" aria-pressed="false">Mostrar</button>
            </span>
          </label>
          <button type="submit" class="botao botao-largo" id="btn-entrar">Entrar</button>
          <details class="esqueci">
            <summary>Esqueci a senha</summary>
            <p>Fale com o estúdio pelo WhatsApp e elas geram uma senha nova pra você.
              <?php if ($waEstudio): ?><a href="https://wa.me/<?= e($waEstudio) ?>?text=<?= rawurlencode('Oi! Esqueci a senha da minha conta de agendamento.') ?>" target="_blank" rel="noopener">Abrir conversa</a><?php endif; ?>
            </p>
          </details>
        </form>

        <form id="painel-criar" role="tabpanel" aria-labelledby="aba-criar" class="form" novalidate hidden>
          <label class="campo">
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
          <label class="campo">
            <span>Crie uma senha <em>(mínimo 6 caracteres)</em></span>
            <span class="senha-caixa">
              <input type="password" id="cad-senha" autocomplete="new-password" minlength="6">
              <button type="button" class="ver-senha" aria-label="Mostrar senha" aria-pressed="false">Mostrar</button>
            </span>
          </label>
          <button type="submit" class="botao botao-largo" id="btn-criar">Criar conta</button>
        </form>

        <p class="erro" id="erro-auth" role="alert" hidden></p>
      </div>
    </div>
  </section>

  <!-- ============ 3. CONFIRMAR ============ -->
  <section class="tela" id="tela-revisao" hidden tabindex="-1" aria-labelledby="t-rev">
    <div class="folha folha-solta">
      <ol class="passos" aria-label="Etapas do agendamento">
        <li class="feito">Serviço</li><li class="feito">Horário</li><li aria-current="step">Confirmar</li>
      </ol>
      <h2 class="titulo titulo-grande" id="t-rev">Confira e confirme</h2>
      <div class="cartao revisao" id="rev-cartao"></div>
      <?php if (cfg('pagamento')): ?>
      <div class="pagamento">
        <p class="pagamento-titulo">Pagamento no studio</p>
        <p><?= e(cfg('pagamento')) ?></p>
        <?php if (cfg('pagamento_obs')): ?><p class="pagamento-obs"><?= e(cfg('pagamento_obs')) ?></p><?php endif; ?>
      </div>
      <?php endif; ?>
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
      <button type="button" class="botao botao-largo botao-alto" id="btn-confirmar">Confirmar agendamento</button>
      <button type="button" class="link link-centro" data-voltar>Alterar horário</button>
    </div>
  </section>

  <!-- ============ CONFIRMADO ============ -->
  <section class="tela" id="tela-confirmado" hidden tabindex="-1" aria-labelledby="t-ok">
    <div class="folha folha-solta confirmado">
      <div class="selo-ok" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>
      <h2 class="titulo titulo-grande" id="t-ok">Horário marcado!</h2>
      <div class="cartao revisao" id="conf-cartao"></div>
      <p class="subtitulo" id="conf-nota"></p>
      <?php if (cfg('pagamento')): ?>
      <div class="pagamento">
        <p class="pagamento-titulo">Pagamento no studio</p>
        <p><?= e(cfg('pagamento')) ?></p>
        <?php if (cfg('pagamento_obs')): ?><p class="pagamento-obs"><?= e(cfg('pagamento_obs')) ?></p><?php endif; ?>
      </div>
      <?php endif; ?>
      <a class="botao botao-largo" id="conf-whats" target="_blank" rel="noopener" hidden>Avisar o estúdio no WhatsApp</a>
      <button type="button" class="botao botao-largo botao-leve" data-ir="meus">Ver meus horários</button>
      <button type="button" class="link link-centro" id="conf-novo">Marcar outro horário</button>
    </div>
  </section>

  <!-- ============ MEUS HORÁRIOS ============ -->
  <section class="tela" id="tela-meus" hidden tabindex="-1" aria-labelledby="t-meus">
    <div class="folha folha-solta">
      <h2 class="titulo titulo-grande" id="t-meus">Meus horários</h2>
      <p class="subtitulo" id="meus-info"></p>
      <ul class="meus" id="meus-lista"></ul>
      <button type="button" class="botao botao-largo" data-ir="servico" id="meus-agendar" hidden>Marcar um horário</button>
    </div>
  </section>

  <!-- ============ CONTA ============ -->
  <section class="tela" id="tela-conta" hidden tabindex="-1" aria-labelledby="t-conta">
    <div class="folha folha-solta">
      <h2 class="titulo titulo-grande" id="t-conta">Minha conta</h2>
      <div class="cartao perfil">
        <span class="perfil-avatar" id="perfil-avatar" aria-hidden="true"></span>
        <div>
          <p class="perfil-nome" id="perfil-nome"></p>
          <p class="perfil-info" id="perfil-tel"></p>
          <p class="perfil-info" id="perfil-email"></p>
        </div>
      </div>
      <details class="cartao sanfona">
        <summary>Trocar senha</summary>
        <form id="form-senha" class="form" novalidate>
          <label class="campo">
            <span>Senha atual</span>
            <input type="password" id="senha-atual" autocomplete="current-password">
          </label>
          <label class="campo">
            <span>Nova senha <em>(mínimo 6 caracteres)</em></span>
            <input type="password" id="senha-nova" autocomplete="new-password">
          </label>
          <p class="erro" id="erro-senha" role="alert" hidden></p>
          <p class="ok" id="ok-senha" role="status" hidden>Senha trocada.</p>
          <button type="submit" class="botao botao-leve botao-largo">Salvar nova senha</button>
        </form>
      </details>
      <?php if ($waEstudio): ?>
        <a class="cartao linha-acao" href="https://wa.me/<?= e($waEstudio) ?>" target="_blank" rel="noopener">
          <span>Falar com o estúdio no WhatsApp</span>
          <svg class="seta" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
        </a>
      <?php endif; ?>
      <button type="button" class="botao botao-leve botao-largo" id="btn-sair">Sair da conta</button>
    </div>
  </section>

</main>

<nav class="tabbar" aria-label="Menu">
  <button type="button" class="tab" data-ir="servico" data-aba="agendar" aria-current="page">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.5 2.5h5v4l1.5 2.2v11.3a1.5 1.5 0 0 1-1.5 1.5h-5a1.5 1.5 0 0 1-1.5-1.5V8.7l1.5-2.2z"/><path d="M8 12h8"/></svg>
    <span>Agendar</span>
  </button>
  <button type="button" class="tab" data-ir="meus" data-aba="meus">
    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>
    <span>Meus horários</span>
  </button>
  <button type="button" class="tab" data-ir="conta" data-aba="conta" id="tab-conta">
    <span class="tab-avatar" id="tab-avatar" hidden></span>
    <svg viewBox="0 0 24 24" aria-hidden="true" id="tab-icone-conta"><circle cx="12" cy="8.5" r="3.8"/><path d="M4.5 20c1.2-3.8 4-5.5 7.5-5.5s6.3 1.7 7.5 5.5"/></svg>
    <span id="tab-conta-rotulo">Entrar</span>
  </button>
</nav>

<script>window.AGENDA = <?= json_encode($dadosJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="assets/app.js"></script>
</body>
</html>
