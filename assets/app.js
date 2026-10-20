(function () {
  const A = window.AGENDA;
  const form = document.getElementById('form-agenda');
  const elDias = document.getElementById('dias');
  const elEquipe = document.getElementById('equipe');
  const elResumo = document.getElementById('resumo');
  const btn = document.getElementById('btn-avancar');
  const SEM = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
  const MES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
  const suave = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';

  const estado = { servico: null, profissional: null, data: null, hora: null };
  let pedido = 0;

  const isoLocal = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const servico = () => A.servicos.find((s) => s.id === estado.servico);
  const prof = (id) => A.profissionais.find((p) => p.id === id);
  const preco = (v) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  const esc = (t) => String(t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const rolarAte = (el) => el.scrollIntoView({ behavior: suave, block: 'start' });

  function fotoHtml(p, classe = '') {
    return p.foto
      ? `<img class="foto ${classe}" src="${esc(p.foto)}" alt="" style="--cor:${esc(p.cor)}">`
      : `<span class="foto ${classe}" style="--cor:${esc(p.cor)}" aria-hidden="true">${esc(p.iniciais)}</span>`;
  }

  // ---------- Dias ----------
  function montarDias() {
    const [y, m, d] = A.hoje.split('-').map(Number);
    const base = new Date(y, m - 1, d);
    let primeiroAberto = null;
    for (let i = 0; i <= A.diasAFrente; i++) {
      const dt = new Date(base); dt.setDate(base.getDate() + i);
      const iso = isoLocal(dt);
      const aberto = A.diasAbertos.includes(dt.getDay());
      const rotulo = i === 0 ? 'hoje' : i === 1 ? 'amanhã' : SEM[dt.getDay()];
      const lbl = document.createElement('label');
      lbl.className = 'chip';
      lbl.innerHTML = `<input type="radio" name="data" value="${iso}" ${aberto ? '' : 'disabled'}>
        <span class="dia"><span class="dia-sem">${rotulo}</span><span class="dia-num">${dt.getDate()}</span><span class="dia-mes">${MES[dt.getMonth()]}</span></span>`;
      lbl.querySelector('input').setAttribute('aria-label', `${rotulo}, ${dt.getDate()} de ${MES[dt.getMonth()]}${aberto ? '' : ', fechado'}`);
      elDias.appendChild(lbl);
      if (aberto && !primeiroAberto) primeiroAberto = lbl.querySelector('input');
    }
    if (primeiroAberto) { primeiroAberto.checked = true; estado.data = primeiroAberto.value; }
  }

  function selecionarDia(iso) {
    const input = elDias.querySelector(`input[value="${iso}"]`);
    if (!input) return;
    input.checked = true;
    input.closest('.chip').scrollIntoView({ behavior: suave, inline: 'center', block: 'nearest' });
    estado.data = iso;
    carregarHorarios();
  }

  // ---------- Cartões das profissionais ----------
  function cartao(p, conteudo, status) {
    return `<article class="prof" data-prof="${p.id}" style="--cor:${esc(p.cor)}">
      <div class="prof-topo">
        ${fotoHtml(p)}
        <div><p class="prof-nome">${esc(p.nome)}</p><p class="prof-status">${status}</p></div>
      </div>
      ${conteudo ? `<div class="prof-corpo">${conteudo}</div>` : ''}
    </article>`;
  }

  function renderEspera(msg) {
    elEquipe.innerHTML = A.profissionais.map((p) => cartao(p, '', msg)).join('');
  }

  function renderEquipe(lista) {
    elEquipe.innerHTML = lista.map(({ id, horarios }) => {
      const p = prof(id);
      if (!p) return '';
      if (!horarios.length) {
        return cartao(p, `<p class="prof-vazio">Sem horário livre nesse dia. <button type="button" data-proximo="${id}">Ver próximo dia livre</button></p>`, 'Agenda cheia nesse dia');
      }
      const grupos = [['Manhã', (h) => h < '12:00'], ['Tarde', (h) => h >= '12:00' && h < '18:00'], ['Noite', (h) => h >= '18:00']];
      const corpo = grupos.map(([nome, filtro]) => {
        const horas = horarios.filter(filtro);
        if (!horas.length) return '';
        const chips = horas.map((h) =>
          `<label class="chip"><input type="radio" name="hora" value="${id}|${h}" aria-label="${h} com ${esc(p.nome)}"><span class="hora">${h}</span></label>`).join('');
        return `<div class="periodo"><p class="periodo-nome">${nome}</p><div class="prof-horas">${chips}</div></div>`;
      }).join('');
      const n = horarios.length;
      return cartao(p, corpo, `${n} ${n === 1 ? 'horário livre' : 'horários livres'}`);
    }).join('');
  }

  async function carregarHorarios() {
    estado.hora = null; estado.profissional = null;
    atualizarResumo();
    if (!estado.servico) return renderEspera('Escolha o serviço pra ver os horários');
    if (!estado.data) return renderEspera('Escolha um dia');

    const meu = ++pedido;
    renderEspera('Buscando horários…');
    try {
      const q = new URLSearchParams({ acao: 'horarios', servico: estado.servico, data: estado.data });
      const r = await fetch('api.php?' + q);
      const j = await r.json();
      if (meu !== pedido) return; // resposta de uma busca antiga
      if (!r.ok) throw new Error(j.erro);
      renderEquipe(j.profissionais);
    } catch (e) {
      if (meu !== pedido) return;
      renderEspera('Não carregou. Verifique a internet e toque no dia de novo.');
    }
  }

  elEquipe.addEventListener('click', async (ev) => {
    const b = ev.target.closest('[data-proximo]');
    if (!b) return;
    b.disabled = true; b.textContent = 'Procurando…';
    try {
      const q = new URLSearchParams({ acao: 'proximo', servico: estado.servico, profissional: b.dataset.proximo, depois: estado.data });
      const j = await (await fetch('api.php?' + q)).json();
      if (j.data) { selecionarDia(j.data); return; }
      b.parentElement.textContent = 'Sem horário livre nos próximos dias.';
    } catch (e) {
      b.disabled = false; b.textContent = 'Ver próximo dia livre';
    }
  });

  // ---------- Resumo do rodapé ----------
  function atualizarResumo() {
    const s = servico();
    const p = prof(estado.profissional);
    if (!s) elResumo.textContent = 'Escolha o serviço.';
    else if (!estado.hora) elResumo.innerHTML = `<strong>${esc(s.nome)}</strong>, ${preco(s.preco)}<br>Agora toque num horário.`;
    else {
      const [, m, d] = estado.data.split('-');
      elResumo.innerHTML = `<strong>${esc(s.nome)}</strong>, ${preco(s.preco)}<br>${d}/${m} às ${estado.hora} com ${esc(p.nome)}`;
    }
    document.querySelectorAll('.prof').forEach((c) => c.classList.toggle('escolhida', Number(c.dataset.prof) === estado.profissional));
    btn.disabled = !(estado.servico && estado.data && estado.hora);
  }

  // ---------- Utilidades ----------
  const $ = (id) => document.getElementById(id);

  async function api(acao, dados) {
    const opcoes = dados === undefined ? {} : {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(dados),
    };
    const r = await fetch('api.php?acao=' + acao, { credentials: 'same-origin', ...opcoes });
    let j = {};
    try { j = await r.json(); } catch (e) { /* resposta vazia */ }
    return { ok: r.ok, status: r.status, j };
  }

  function mensagem(el, texto) {
    el.textContent = texto || '';
    el.hidden = !texto;
  }

  document.querySelectorAll('.mascara-tel').forEach((inp) => inp.addEventListener('input', () => {
    let v = inp.value.replace(/\D/g, '').replace(/^0+/, '');
    if (v.length > 11 && v.startsWith('55')) v = v.slice(2); // colou com +55
    v = v.slice(0, 11);
    if (v.length > 6) v = `(${v.slice(0, 2)}) ${v.slice(2, v.length - 4)}-${v.slice(-4)}`;
    else if (v.length > 2) v = `(${v.slice(0, 2)}) ${v.slice(2)}`;
    inp.value = v;
  }));

  document.querySelectorAll('.ver-senha').forEach((b) => b.addEventListener('click', () => {
    const inp = b.previousElementSibling;
    const mostrar = inp.type === 'password';
    inp.type = mostrar ? 'text' : 'password';
    b.textContent = mostrar ? 'Esconder' : 'Mostrar';
    b.setAttribute('aria-pressed', String(mostrar));
    b.setAttribute('aria-label', mostrar ? 'Esconder senha' : 'Mostrar senha');
  }));

  // ---------- Telas (com o botão voltar do celular funcionando) ----------
  const TELAS = ['agendar', 'login', 'revisao', 'confirmado', 'meus', 'conta'];
  const ABA_DA_TELA = { agendar: 'agendar', revisao: 'agendar', confirmado: 'agendar', meus: 'meus', conta: 'conta' };
  let telaAtual = 'agendar';
  let destinoLogin = 'conta';
  let scrollAgendar = 0;

  function irPara(tela, { push = true, replace = false, destino } = {}) {
    if (!TELAS.includes(tela)) tela = 'agendar';
    // Regras de acesso
    if (tela === 'revisao' && !estado.hora) tela = 'agendar';
    if (['meus', 'conta', 'revisao'].includes(tela) && !A.cliente) { destino = tela; tela = 'login'; }
    if (tela === 'login' && A.cliente) tela = destino || 'conta';
    if (tela === 'login') destinoLogin = destino || destinoLogin;

    if (telaAtual === 'agendar' && tela !== 'agendar') scrollAgendar = window.scrollY;
    TELAS.forEach((t) => { $('tela-' + t).hidden = t !== tela; });
    telaAtual = tela;

    const aba = tela === 'login' ? (ABA_DA_TELA[destinoLogin] || 'conta') : ABA_DA_TELA[tela];
    document.querySelectorAll('.nav-item').forEach((n) => {
      if (n.dataset.ir === aba) n.setAttribute('aria-current', 'page'); else n.removeAttribute('aria-current');
    });

    if (replace) history.replaceState({ tela }, '', '#' + tela);
    else if (push) history.pushState({ tela }, '', '#' + tela);

    if (tela === 'login') renderLogin();
    if (tela === 'revisao') renderRevisao();
    if (tela === 'meus') carregarMeus();
    if (tela === 'conta') renderPerfil();

    if (tela === 'agendar') {
      window.scrollTo(0, scrollAgendar);
    } else {
      window.scrollTo(0, 0);
      $('tela-' + tela).focus({ preventScroll: true });
    }
  }

  window.addEventListener('popstate', (ev) => irPara((ev.state && ev.state.tela) || 'agendar', { push: false }));

  document.addEventListener('click', (ev) => {
    const ir = ev.target.closest('[data-ir]');
    if (ir) { irPara(ir.dataset.ir, { destino: ir.dataset.ir }); return; }
    if (ev.target.closest('[data-voltar]')) {
      if (history.state && history.state.tela && history.state.tela !== 'agendar') history.back();
      else irPara('agendar');
    }
  });

  // ---------- Conta na barra ----------
  function renderNav() {
    const c = A.cliente;
    $('nav-avatar').hidden = !c;
    $('nav-icone-conta').style.display = c ? 'none' : '';
    $('nav-conta-rotulo').textContent = c ? c.primeiro_nome : 'Entrar';
    if (c) $('nav-avatar').textContent = c.primeiro_nome.charAt(0).toUpperCase();
  }

  // ---------- Login / cadastro ----------
  const elErroAuth = $('erro-auth');
  const abaEntrar = $('aba-entrar');
  const abaCriar = $('aba-criar');

  function trocarAba(criar) {
    abaEntrar.setAttribute('aria-selected', String(!criar));
    abaCriar.setAttribute('aria-selected', String(criar));
    abaEntrar.tabIndex = criar ? -1 : 0;
    abaCriar.tabIndex = criar ? 0 : -1;
    $('painel-entrar').hidden = criar;
    $('painel-criar').hidden = !criar;
    mensagem(elErroAuth, '');
  }
  abaEntrar.addEventListener('click', () => trocarAba(false));
  abaCriar.addEventListener('click', () => trocarAba(true));
  [abaEntrar, abaCriar].forEach((t) => t.addEventListener('keydown', (ev) => {
    if (ev.key === 'ArrowRight' || ev.key === 'ArrowLeft') {
      const criar = t === abaEntrar; trocarAba(criar); (criar ? abaCriar : abaEntrar).focus();
    }
  }));

  function cartaoEscolha() {
    const s = servico(); const p = prof(estado.profissional);
    if (!s || !p || !estado.hora) return '';
    const [y, m, d] = estado.data.split('-').map(Number);
    const dt = new Date(y, m - 1, d);
    const dia = `${['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'][dt.getDay()]}, ${d}/${String(m).padStart(2, '0')}`;
    return `<div class="escolha-cartao" style="--cor:${esc(p.cor)}">
      ${fotoHtml(p)}
      <div><p class="escolha-titulo">${esc(s.nome)}, ${preco(s.preco)}</p>
      <p class="escolha-info">${dia} às ${estado.hora} com ${esc(p.nome)}</p></div>
    </div>`;
  }

  function renderLogin() {
    const marcando = destinoLogin === 'revisao' && estado.hora;
    $('t-login').textContent = marcando ? 'Falta pouco' : 'Entre na sua conta';
    $('login-sub').textContent = marcando
      ? 'Entre na sua conta ou crie uma pra confirmar o horário que você escolheu.'
      : 'Pra marcar e acompanhar seus horários.';
    $('login-escolha').innerHTML = marcando ? cartaoEscolha() : '';
    $('login-escolha').hidden = !marcando;
    trocarAba(false);
  }

  async function autenticar(acao, dados, botao, rotulo) {
    mensagem(elErroAuth, '');
    botao.disabled = true;
    botao.textContent = 'Aguarde…';
    try {
      const { ok, j } = await api(acao, dados);
      if (!ok) return mensagem(elErroAuth, j.erro || 'Não deu certo. Tente de novo.');
      A.cliente = j.cliente;
      meusHorarios = j.horarios || null;
      ['ent-senha', 'cad-senha'].forEach((id) => { $(id).value = ''; });
      renderNav();
      irPara(destinoLogin === 'revisao' && !estado.hora ? 'agendar' : destinoLogin, { replace: true });
    } catch (e) {
      mensagem(elErroAuth, 'Sem conexão. Verifique a internet e tente de novo.');
    } finally {
      botao.disabled = false;
      botao.textContent = rotulo;
    }
  }

  $('painel-entrar').addEventListener('submit', (ev) => {
    ev.preventDefault();
    const tel = $('ent-tel').value.replace(/\D/g, '');
    if (tel.length < 10) { mensagem(elErroAuth, 'Digite o celular com DDD.'); return $('ent-tel').focus(); }
    if (!$('ent-senha').value) { mensagem(elErroAuth, 'Digite sua senha.'); return $('ent-senha').focus(); }
    autenticar('entrar', { telefone: tel, senha: $('ent-senha').value }, $('btn-entrar'), 'Entrar');
  });

  $('painel-criar').addEventListener('submit', (ev) => {
    ev.preventDefault();
    const nome = $('cad-nome').value.trim().replace(/\s+/g, ' ');
    const tel = $('cad-tel').value.replace(/\D/g, '');
    const email = $('cad-email').value.trim();
    const senha = $('cad-senha').value;
    const falha = (msg, campo) => { mensagem(elErroAuth, msg); campo.focus(); };
    if (nome.split(' ').length < 2) return falha('Digite seu nome completo (nome e sobrenome).', $('cad-nome'));
    if (tel.length < 10) return falha('Digite o celular com DDD.', $('cad-tel'));
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return falha('Digite um e-mail válido, ex.: maria@gmail.com.', $('cad-email'));
    if (senha.length < 6) return falha('A senha precisa ter pelo menos 6 caracteres.', $('cad-senha'));
    autenticar('cadastrar', { nome, telefone: tel, email, senha }, $('btn-criar'), 'Criar conta');
  });

  async function sair(proxima = 'agendar', destino) {
    await api('sair', {}).catch(() => {});
    A.cliente = null;
    meusHorarios = null;
    renderNav();
    irPara(proxima, { replace: true, destino });
  }

  function sessaoExpirou(destino) {
    A.cliente = null;
    renderNav();
    irPara('login', { destino });
    mensagem(elErroAuth, 'Sua sessão expirou. Entre de novo.');
  }

  // ---------- Agendar: Avançar ----------
  form.addEventListener('submit', (ev) => {
    ev.preventDefault();
    if (!estado.hora) return;
    irPara('revisao', { destino: 'revisao' });
  });

  // ---------- Revisão e confirmação ----------
  function renderRevisao() {
    const s = servico(); const p = prof(estado.profissional);
    const [y, m, d] = estado.data.split('-').map(Number);
    const dt = new Date(y, m - 1, d);
    const dia = `${['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'][dt.getDay()]}, ${d} de ${['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'][m - 1]}`;
    $('rev-cartao').innerHTML = `
      <div class="rev-prof" style="--cor:${esc(p.cor)}">${fotoHtml(p)}<div><p class="rev-com">Com</p><p class="rev-prof-nome">${esc(p.nome)}</p></div></div>
      <dl>
        <dt>Serviço</dt><dd>${esc(s.nome)}</dd>
        <dt>Quando</dt><dd class="primeira-maiuscula">${dia}, às ${estado.hora}</dd>
        <dt>Duração</dt><dd>${s.duracao} min</dd>
        <dt>Valor</dt><dd>${preco(s.preco)}</dd>
      </dl>`;
    $('rev-nome').textContent = A.cliente.nome;
    mensagem($('erro-rev'), '');
  }

  $('rev-trocar').addEventListener('click', () => sair('login', 'revisao'));

  $('btn-confirmar').addEventListener('click', async () => {
    const b = $('btn-confirmar');
    if (b.disabled) return;
    b.disabled = true;
    b.textContent = 'Confirmando…';
    mensagem($('erro-rev'), '');
    try {
      const { ok, status, j } = await api('agendar', {
        servico: estado.servico, profissional: estado.profissional, data: estado.data, hora: estado.hora,
        observacao: $('rev-obs').value.trim(), site: $('rev-site').value,
      });
      if (status === 401) return sessaoExpirou('revisao');
      if (!ok) {
        mensagem($('erro-rev'), j.erro || 'Não deu pra agendar.');
        if (status === 409) carregarHorarios(); // atualiza a lista pra quando ela voltar
        return;
      }
      meusHorarios = null;
      mostrarConfirmacao(j.agendamento);
    } catch (e) {
      mensagem($('erro-rev'), 'Sem conexão. Verifique a internet e tente de novo.');
    } finally {
      b.disabled = false;
      b.textContent = 'Confirmar agendamento';
    }
  });

  function mostrarConfirmacao(ag) {
    if (!ag) return;
    const p = prof(ag.profissional_id);
    $('conf-foto').innerHTML = p ? fotoHtml(p, 'conf-foto') : '<span class="unha unha-grande" aria-hidden="true"></span>';
    const dl = $('conf-detalhes');
    dl.innerHTML = '';
    [['Serviço', ag.servico], ['Quando', `${ag.data_extenso}, às ${ag.hora}`], ['Com', ag.profissional], ['Valor', ag.preco_fmt]].forEach(([k, v]) => {
      const dt = document.createElement('dt'); dt.textContent = k;
      const dd = document.createElement('dd'); dd.textContent = v;
      dl.append(dt, dd);
    });
    $('conf-nota').textContent = `Se precisar, dá pra cancelar em "Meus horários" até ${A.cancelarAteHoras} h antes.`;
    const w = $('conf-whats');
    if (A.whatsapp && !A.avisoAutomatico) {
      const msg = `Oi! Sou ${A.cliente.nome} e acabei de agendar: ${ag.servico}, ${ag.data_extenso} às ${ag.hora}, com ${ag.profissional}.`;
      w.href = `https://wa.me/${A.whatsapp}?text=${encodeURIComponent(msg)}`;
      w.hidden = false;
    } else {
      w.hidden = true;
    }
    // Limpa a escolha pra um próximo agendamento começar do zero
    $('rev-obs').value = '';
    estado.hora = null; estado.profissional = null;
    scrollAgendar = 0;
    carregarHorarios();
    irPara('confirmado', { replace: true });
  }

  $('conf-novo').addEventListener('click', () => irPara('agendar'));

  // ---------- Meus horários ----------
  let meusHorarios = null;

  async function carregarMeus() {
    if (meusHorarios) renderMeus(); else { $('meus-info').textContent = 'Carregando…'; $('meus-lista').innerHTML = ''; }
    const { ok, status, j } = await api('eu').catch(() => ({ ok: false, j: {} }));
    if (telaAtual !== 'meus') return;
    if (status === 200 && !j.cliente) return sessaoExpirou('meus');
    if (!ok) { $('meus-info').textContent = 'Não carregou. Verifique a internet.'; return; }
    meusHorarios = j.horarios;
    renderMeus();
  }

  function renderMeus(aviso) {
    const lista = meusHorarios || [];
    $('meus-info').textContent = aviso || (lista.length
      ? `Dá pra cancelar pelo site até ${A.cancelarAteHoras} h antes do horário.`
      : 'Você não tem horários marcados.');
    $('meus-agendar').hidden = lista.length > 0;
    $('meus-lista').innerHTML = lista.map((h) => {
      const p = prof(h.profissional_id) || { cor: '#9B1B3A', iniciais: '?', nome: h.profissional };
      return `<li class="meu" style="--cor:${esc(p.cor)}">
        ${fotoHtml(p)}
        <div class="meu-corpo">
          <p class="meu-quando">${esc(h.data_extenso)}, às ${esc(h.hora)}</p>
          <p class="meu-det">${esc(h.servico)} com ${esc(h.profissional)}, <span class="nowrap">${esc(h.preco_fmt)}</span></p>
          ${h.pode_cancelar
            ? `<button type="button" class="link link-perigo" data-cancelar="${h.id}">Cancelar horário</button>`
            : '<p class="meu-det">Pra cancelar agora, fale com o estúdio pelo WhatsApp.</p>'}
          <p class="meu-confirma" hidden>Cancelar mesmo?
            <button type="button" class="link link-perigo" data-sim="${h.id}">Sim, cancelar</button>
            <button type="button" class="link" data-nao>Não</button></p>
        </div>
      </li>`;
    }).join('');
  }

  $('meus-lista').addEventListener('click', async (ev) => {
    const t = ev.target;
    const li = t.closest('.meu');
    if (!li) return;
    if (t.dataset.cancelar) { li.querySelector('.meu-confirma').hidden = false; t.hidden = true; return; }
    if (t.hasAttribute('data-nao')) { li.querySelector('.meu-confirma').hidden = true; li.querySelector('[data-cancelar]').hidden = false; return; }
    if (t.dataset.sim) {
      t.disabled = true; t.textContent = 'Cancelando…';
      const { ok, status, j } = await api('cancelar', { id: Number(t.dataset.sim) }).catch(() => ({ ok: false, j: {} }));
      if (status === 401) return sessaoExpirou('meus');
      if (!ok) { $('meus-info').textContent = j.erro || 'Não deu pra cancelar. Tente de novo.'; t.disabled = false; t.textContent = 'Sim, cancelar'; return; }
      meusHorarios = j.horarios;
      renderMeus('Horário cancelado. O estúdio já foi avisado.');
      if (estado.servico) carregarHorarios();
    }
  });

  // ---------- Conta ----------
  function renderPerfil() {
    const c = A.cliente;
    $('perfil-avatar').textContent = c.primeiro_nome.charAt(0).toUpperCase();
    $('perfil-nome').textContent = c.nome;
    $('perfil-tel').textContent = c.telefone;
    $('perfil-email').textContent = c.email;
    mensagem($('erro-senha'), ''); $('ok-senha').hidden = true;
  }

  $('form-senha').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const atual = $('senha-atual').value; const nova = $('senha-nova').value;
    $('ok-senha').hidden = true;
    if (!atual) { mensagem($('erro-senha'), 'Digite a senha atual.'); return $('senha-atual').focus(); }
    if (nova.length < 6) { mensagem($('erro-senha'), 'A nova senha precisa ter pelo menos 6 caracteres.'); return $('senha-nova').focus(); }
    const { ok, status, j } = await api('senha', { atual, nova }).catch(() => ({ ok: false, j: {} }));
    if (status === 401) return sessaoExpirou('conta');
    if (!ok) return mensagem($('erro-senha'), j.erro || 'Não deu pra trocar a senha.');
    mensagem($('erro-senha'), '');
    $('senha-atual').value = ''; $('senha-nova').value = '';
    $('ok-senha').hidden = false;
  });

  $('btn-sair').addEventListener('click', () => sair('agendar'));

  // ---------- Etapas do agendamento ----------
  form.addEventListener('change', (ev) => {
    const t = ev.target;
    if (t.name === 'servico') {
      const primeira = estado.servico === null;
      estado.servico = Number(t.value);
      carregarHorarios();
      if (primeira) rolarAte($('t-dia'));
    }
    if (t.name === 'data') { estado.data = t.value; carregarHorarios(); }
    if (t.name === 'hora') {
      const [id, h] = t.value.split('|');
      estado.profissional = Number(id); estado.hora = h;
      atualizarResumo();
    }
  });

  // ---------- Início ----------
  montarDias();
  renderEspera('Escolha o serviço pra ver os horários');
  atualizarResumo();
  renderNav();
  const inicial = (location.hash || '').slice(1);
  history.replaceState({ tela: 'agendar' }, '', location.pathname + location.search);
  if (['meus', 'conta'].includes(inicial)) irPara(inicial, { destino: inicial });
})();
