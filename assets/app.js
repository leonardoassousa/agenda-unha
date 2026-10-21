// Agenda de unhas: tela da cliente.
// Telas: servico -> horario -> (login) -> revisao -> confirmado; mais "meus" e "conta" na barra de baixo.
(function () {
  'use strict';
  const A = window.AGENDA;
  const $ = (id) => document.getElementById(id);

  const SEM = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
  const SEMANA = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
  const MES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
  const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
  const suave = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';

  // O que a cliente escolheu
  const estado = { servico: null, data: null, profVista: null, profissional: null, hora: null };
  let vagas = null;          // { profId: ['09:00', ...] } do dia escolhido
  let pedidoHorarios = 0;    // descarta respostas atrasadas
  let meusHorarios = null;

  // ---------- Utilidades ----------
  const esc = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const preco = (v) => Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  const isoLocal = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const dataDe = (iso) => { const [y, m, d] = iso.split('-').map(Number); return new Date(y, m - 1, d); };
  const servico = () => A.servicos.find((s) => s.id === estado.servico);
  const prof = (id) => A.profissionais.find((p) => p.id === id);
  const diaCurto = (iso) => { const d = dataDe(iso); return `${SEMANA[d.getDay()]}, ${d.getDate()}/${String(d.getMonth() + 1).padStart(2, '0')}`; };
  const diaLongo = (iso) => { const d = dataDe(iso); return `${SEMANA[d.getDay()]}, ${d.getDate()} de ${MESES[d.getMonth()]}`; };

  function fotoHtml(p, classe = '') {
    if (!p) return '';
    return p.foto
      ? `<img class="foto ${classe}" src="${esc(p.foto)}" alt="" style="--cor:${esc(p.cor)}">`
      : `<span class="foto ${classe}" style="--cor:${esc(p.cor)}" aria-hidden="true">${esc(p.iniciais)}</span>`;
  }

  function mensagem(el, texto) { el.textContent = texto || ''; el.hidden = !texto; }

  async function api(acao, dados, params = {}) {
    const q = new URLSearchParams({ acao, ...params });
    const opcoes = dados === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(dados) };
    const r = await fetch('api.php?' + q, { credentials: 'same-origin', ...opcoes });
    let j = {};
    try { j = await r.json(); } catch (e) { /* sem corpo */ }
    return { ok: r.ok, status: r.status, j };
  }
  const semRede = () => ({ ok: false, status: 0, j: { erro: 'Sem conexão. Verifique a internet e tente de novo.' } });

  // ---------- Telas (o voltar do celular funciona) ----------
  const TELAS = ['servico', 'horario', 'login', 'revisao', 'confirmado', 'meus', 'conta'];
  const ABA = { servico: 'agendar', horario: 'agendar', revisao: 'agendar', confirmado: 'agendar', meus: 'meus', conta: 'conta' };
  let telaAtual = 'servico';
  let destinoLogin = 'conta';
  const rolagem = {};

  function irPara(tela, { push = true, replace = false, destino } = {}) {
    if (!TELAS.includes(tela)) tela = 'servico';
    if (tela === 'horario' && !estado.servico) tela = 'servico';
    if (tela === 'revisao' && !estado.hora) tela = estado.servico ? 'horario' : 'servico';
    if (['meus', 'conta', 'revisao'].includes(tela) && !A.cliente) { destino = tela; tela = 'login'; }
    if (tela === 'login' && A.cliente) tela = destino || 'conta';
    if (tela === 'login') destinoLogin = destino || destinoLogin;

    rolagem[telaAtual] = window.scrollY;
    TELAS.forEach((t) => { $('tela-' + t).hidden = t !== tela; });
    const veioDe = telaAtual;
    telaAtual = tela;

    const aba = tela === 'login' ? (ABA[destinoLogin] || 'conta') : ABA[tela];
    document.querySelectorAll('.tab').forEach((t) => {
      if (t.dataset.aba === aba) t.setAttribute('aria-current', 'page'); else t.removeAttribute('aria-current');
    });

    if (replace) history.replaceState({ tela }, '', '#' + tela);
    else if (push) history.pushState({ tela }, '', '#' + tela);

    if (tela === 'horario') renderEscolhido();
    if (tela === 'login') renderLogin();
    if (tela === 'revisao') renderRevisao();
    if (tela === 'meus') carregarMeus();
    if (tela === 'conta') renderPerfil();

    // Volta pro lugar onde estava nas telas de escolha; nas outras, começa do topo
    const manter = (tela === 'servico' || tela === 'horario') && !push;
    window.scrollTo(0, manter ? (rolagem[tela] || 0) : 0);
    if (tela !== 'servico' && veioDe !== tela) $('tela-' + tela).focus({ preventScroll: true });
  }

  window.addEventListener('popstate', (ev) => irPara((ev.state && ev.state.tela) || 'servico', { push: false }));

  document.addEventListener('click', (ev) => {
    const ir = ev.target.closest('[data-ir]');
    if (ir) { irPara(ir.dataset.ir, { destino: ir.dataset.ir }); return; }
    if (ev.target.closest('[data-voltar]')) {
      if (history.state && history.state.tela && history.state.tela !== 'servico') history.back();
      else irPara('servico');
    }
  });

  // ---------- 1. Serviço ----------
  document.querySelectorAll('[data-servico]').forEach((b) => b.addEventListener('click', () => {
    const id = Number(b.dataset.servico);
    if (estado.servico !== id) { estado.servico = id; limparHora(); carregarHorarios(); }
    irPara('horario');
  }));

  function renderEscolhido() {
    const s = servico();
    $('escolhido').innerHTML = s ? `<div><p class="escolhido-nome">${esc(s.nome)}</p>
      <p class="escolhido-info">${s.duracao} min, ${preco(s.preco)}</p></div>
      <button type="button" class="link" data-ir="servico">Trocar</button>` : '';
  }

  // ---------- 2. Dia ----------
  function montarDias() {
    const base = dataDe(A.hoje);
    let primeiro = null;
    const elDias = $('dias');
    for (let i = 0; i <= A.diasAFrente; i++) {
      const dt = new Date(base); dt.setDate(base.getDate() + i);
      const iso = isoLocal(dt);
      const aberto = A.diasDisponiveis.includes(iso);
      const rotulo = i === 0 ? 'hoje' : i === 1 ? 'amanhã' : SEM[dt.getDay()];
      const lbl = document.createElement('label');
      lbl.className = 'dia';
      lbl.innerHTML = `<input type="radio" name="data" value="${iso}" ${aberto ? '' : 'disabled'}
          aria-label="${rotulo}, ${dt.getDate()} de ${MESES[dt.getMonth()]}${aberto ? '' : ', fechado'}">
        <span class="dia-caixa"><span class="dia-sem">${rotulo}</span><span class="dia-num">${dt.getDate()}</span><span class="dia-mes">${MES[dt.getMonth()]}</span></span>`;
      elDias.appendChild(lbl);
      if (aberto && !primeiro) primeiro = lbl.querySelector('input');
    }
    if (primeiro) { primeiro.checked = true; estado.data = primeiro.value; }
    elDias.addEventListener('change', (ev) => {
      if (ev.target.name !== 'data') return;
      estado.data = ev.target.value;
      limparHora();
      carregarHorarios();
    });
  }

  function selecionarDia(iso) {
    const input = $('dias').querySelector(`input[value="${iso}"]`);
    if (!input) return;
    input.checked = true;
    input.closest('.dia').scrollIntoView({ behavior: suave, inline: 'center', block: 'nearest' });
    estado.data = iso;
    limparHora();
    carregarHorarios();
  }

  // ---------- 2. Profissional e horário ----------
  function limparHora() { estado.hora = null; estado.profissional = null; atualizarCta(); }

  async function carregarHorarios() {
    if (!estado.servico || !estado.data) return;
    const meu = ++pedidoHorarios;
    vagas = null;
    renderPros();
    $('horas').innerHTML = '<div class="carregando" aria-label="Carregando horários"><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>';
    const { ok, j } = await api('horarios', undefined, { servico: estado.servico, data: estado.data }).catch(semRede);
    if (meu !== pedidoHorarios) return;
    if (!ok) { $('horas').innerHTML = `<p class="aviso">${esc(j.erro || 'Não carregou os horários.')} <button type="button" class="link" data-recarregar>Tentar de novo</button></p>`; return; }
    vagas = {};
    (j.profissionais || []).forEach((p) => { vagas[p.id] = p.horarios; });
    // Mostra a profissional que já estava aberta; senão, a primeira com vaga
    if (!estado.profVista || !(estado.profVista in vagas)) {
      const comVaga = A.profissionais.find((p) => (vagas[p.id] || []).length);
      estado.profVista = (comVaga || A.profissionais[0] || {}).id ?? null;
    }
    renderPros();
    renderHoras();
  }

  function renderPros() {
    $('pros').innerHTML = A.profissionais.map((p) => {
      const n = vagas ? (vagas[p.id] || []).length : null;
      const txt = n === null ? '…' : n === 0 ? 'Sem vaga' : `${n} ${n === 1 ? 'vaga' : 'vagas'}`;
      const sel = p.id === estado.profVista;
      return `<button type="button" class="pro${n === 0 ? ' lotada' : ''}" role="tab" data-pro="${p.id}"
          aria-selected="${sel}" tabindex="${sel ? 0 : -1}" style="--cor:${esc(p.cor)}" aria-controls="horas">
        ${fotoHtml(p)}<span class="pro-nome">${esc(p.nome)}</span><span class="pro-vagas">${txt}</span></button>`;
    }).join('');
  }

  $('pros').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-pro]');
    if (!b) return;
    estado.profVista = Number(b.dataset.pro);
    renderPros();
    renderHoras();
    $('pros').querySelector(`[data-pro="${estado.profVista}"]`).focus();
  });
  $('pros').addEventListener('keydown', (ev) => {
    if (!['ArrowRight', 'ArrowLeft'].includes(ev.key)) return;
    const ids = A.profissionais.map((p) => p.id);
    const i = ids.indexOf(estado.profVista);
    const novo = ids[(i + (ev.key === 'ArrowRight' ? 1 : ids.length - 1)) % ids.length];
    $('pros').querySelector(`[data-pro="${novo}"]`).click();
  });

  function renderHoras() {
    const p = prof(estado.profVista);
    const lista = vagas && p ? (vagas[p.id] || []) : [];
    if (!p) { $('horas').innerHTML = '<p class="aviso">Nenhuma profissional disponível.</p>'; return; }
    if (!lista.length) {
      $('horas').innerHTML = `<p class="aviso">${esc(p.nome)} não tem horário livre nesse dia.
        <button type="button" class="link" data-proximo="${p.id}">Ver o próximo dia livre dela</button></p>`;
      return;
    }
    const grupos = [['Manhã', (h) => h < '12:00'], ['Tarde', (h) => h >= '12:00' && h < '18:00'], ['Noite', (h) => h >= '18:00']];
    $('horas').innerHTML = grupos.map(([nome, f]) => {
      const hs = lista.filter(f);
      if (!hs.length) return '';
      return `<div class="periodo"><p class="periodo-nome">${nome}</p><div class="grade">${hs.map((h) => {
        const marcado = estado.profissional === p.id && estado.hora === h;
        return `<label class="hora" style="--cor:${esc(p.cor)}"><input type="radio" name="hora" value="${h}" ${marcado ? 'checked' : ''}
          aria-label="${h} com ${esc(p.nome)}"><span>${h}</span></label>`;
      }).join('')}</div></div>`;
    }).join('');
  }

  $('horas').addEventListener('change', (ev) => {
    if (ev.target.name !== 'hora') return;
    estado.profissional = estado.profVista;
    estado.hora = ev.target.value;
    atualizarCta();
  });

  $('horas').addEventListener('click', async (ev) => {
    if (ev.target.closest('[data-recarregar]')) { carregarHorarios(); return; }
    const b = ev.target.closest('[data-proximo]');
    if (!b) return;
    b.disabled = true; b.textContent = 'Procurando…';
    const { ok, j } = await api('proximo', undefined, { servico: estado.servico, profissional: b.dataset.proximo, depois: estado.data }).catch(semRede);
    if (ok && j.data) { selecionarDia(j.data); return; }
    b.replaceWith(Object.assign(document.createElement('span'), { textContent: ok ? ' Ela não tem vaga nos próximos dias.' : ' Não deu pra procurar agora.' }));
  });

  function atualizarCta() {
    const s = servico(); const p = prof(estado.profissional);
    const pronto = !!(s && estado.hora && p);
    $('btn-continuar').disabled = !pronto;
    $('cta-resumo').innerHTML = pronto
      ? `<strong>${esc(diaCurto(estado.data))} às ${estado.hora}</strong><br>com ${esc(p.nome)}, ${preco(s.preco)}`
      : 'Toque num horário';
  }

  $('btn-continuar').addEventListener('click', () => { if (estado.hora) irPara('revisao', { destino: 'revisao' }); });

  // ---------- Barra de baixo: conta ----------
  function renderTab() {
    const c = A.cliente;
    $('tab-avatar').hidden = !c;
    $('tab-icone-conta').style.display = c ? 'none' : '';
    $('tab-conta-rotulo').textContent = c ? c.primeiro_nome : 'Entrar';
    if (c) $('tab-avatar').textContent = c.primeiro_nome.charAt(0).toUpperCase();
  }

  // ---------- Login e cadastro ----------
  const elErroAuth = $('erro-auth');

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

  function trocarAba(criar) {
    $('aba-entrar').setAttribute('aria-selected', String(!criar));
    $('aba-criar').setAttribute('aria-selected', String(criar));
    $('aba-entrar').tabIndex = criar ? -1 : 0;
    $('aba-criar').tabIndex = criar ? 0 : -1;
    $('painel-entrar').hidden = criar;
    $('painel-criar').hidden = !criar;
    mensagem(elErroAuth, '');
  }
  $('aba-entrar').addEventListener('click', () => trocarAba(false));
  $('aba-criar').addEventListener('click', () => trocarAba(true));
  ['aba-entrar', 'aba-criar'].forEach((id) => $(id).addEventListener('keydown', (ev) => {
    if (ev.key === 'ArrowRight' || ev.key === 'ArrowLeft') { const criar = id === 'aba-entrar'; trocarAba(criar); $(criar ? 'aba-criar' : 'aba-entrar').focus(); }
  }));

  function renderLogin() {
    const marcando = destinoLogin === 'revisao' && estado.hora;
    $('t-login').textContent = marcando ? 'Falta pouco' : 'Entre na sua conta';
    $('login-sub').textContent = marcando
      ? 'Entre ou crie sua conta pra confirmar o horário que você escolheu.'
      : 'Pra marcar e acompanhar seus horários.';
    const s = servico(); const p = prof(estado.profissional);
    $('login-escolha').innerHTML = marcando && s && p ? `<div class="escolha" style="--cor:${esc(p.cor)}">${fotoHtml(p, 'foto-p')}
      <div><p class="escolha-titulo">${esc(s.nome)}, ${preco(s.preco)}</p>
      <p class="escolha-info">${esc(diaCurto(estado.data))} às ${estado.hora} com ${esc(p.nome)}</p></div></div>` : '';
    trocarAba(false);
  }

  async function autenticar(acao, dados, botao, rotulo) {
    mensagem(elErroAuth, '');
    botao.disabled = true; botao.textContent = 'Aguarde…';
    try {
      const { ok, j } = await api(acao, dados).catch(semRede);
      if (!ok) { mensagem(elErroAuth, j.erro || 'Não deu certo. Tente de novo.'); return; }
      A.cliente = j.cliente;
      meusHorarios = j.horarios || null;
      ['ent-senha', 'cad-senha'].forEach((id) => { $(id).value = ''; });
      renderTab();
      irPara(destinoLogin === 'revisao' && !estado.hora ? 'servico' : destinoLogin, { replace: true });
    } finally {
      botao.disabled = false; botao.textContent = rotulo;
    }
  }

  $('painel-entrar').addEventListener('submit', (ev) => {
    ev.preventDefault();
    const tel = $('ent-tel').value.replace(/\D/g, '');
    if (tel.length < 10) { mensagem(elErroAuth, 'Digite o celular com DDD.'); $('ent-tel').focus(); return; }
    if (!$('ent-senha').value) { mensagem(elErroAuth, 'Digite sua senha.'); $('ent-senha').focus(); return; }
    autenticar('entrar', { telefone: tel, senha: $('ent-senha').value }, $('btn-entrar'), 'Entrar');
  });

  $('painel-criar').addEventListener('submit', (ev) => {
    ev.preventDefault();
    const nome = $('cad-nome').value.trim().replace(/\s+/g, ' ');
    const tel = $('cad-tel').value.replace(/\D/g, '');
    const email = $('cad-email').value.trim();
    const senha = $('cad-senha').value;
    const falha = (msg, id) => { mensagem(elErroAuth, msg); $(id).focus(); };
    if (nome.split(' ').length < 2) return falha('Digite seu nome completo (nome e sobrenome).', 'cad-nome');
    if (tel.length < 10) return falha('Digite o celular com DDD.', 'cad-tel');
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return falha('Digite um e-mail válido, ex.: maria@gmail.com.', 'cad-email');
    if (senha.length < 6) return falha('A senha precisa ter pelo menos 6 caracteres.', 'cad-senha');
    autenticar('cadastrar', { nome, telefone: tel, email, senha }, $('btn-criar'), 'Criar conta');
  });

  async function sair(proxima = 'servico', destino) {
    await api('sair', {}).catch(() => {});
    A.cliente = null; meusHorarios = null;
    renderTab();
    irPara(proxima, { replace: true, destino });
  }

  function sessaoExpirou(destino) {
    A.cliente = null; renderTab();
    irPara('login', { destino });
    mensagem(elErroAuth, 'Sua sessão expirou. Entre de novo.');
  }

  // ---------- 3. Revisão e confirmação ----------
  function cartaoResumo(ag) {
    const p = prof(ag.profissionalId);
    return `<div class="rev-prof" style="--cor:${esc(p ? p.cor : '')}">${fotoHtml(p)}
        <div><p class="rev-com">Com</p><p class="rev-prof-nome">${esc(ag.profissional)}</p></div></div>
      <dl><dt>Serviço</dt><dd>${esc(ag.servico)}</dd>
        <dt>Quando</dt><dd>${esc(ag.quando)}</dd>
        ${ag.duracao ? `<dt>Duração</dt><dd>${ag.duracao} min</dd>` : ''}
        <dt>Valor</dt><dd>${esc(ag.valor)}</dd></dl>`;
  }

  function renderRevisao() {
    const s = servico(); const p = prof(estado.profissional);
    $('rev-cartao').innerHTML = cartaoResumo({
      profissionalId: p.id, profissional: p.nome, servico: s.nome, duracao: s.duracao,
      quando: `${diaLongo(estado.data)}, às ${estado.hora}`, valor: preco(s.preco),
    });
    $('rev-nome').textContent = A.cliente.nome;
    mensagem($('erro-rev'), '');
  }

  $('rev-trocar').addEventListener('click', () => sair('login', 'revisao'));

  $('btn-confirmar').addEventListener('click', async () => {
    const b = $('btn-confirmar');
    if (b.disabled) return;
    b.disabled = true; b.textContent = 'Confirmando…';
    mensagem($('erro-rev'), '');
    try {
      const { ok, status, j } = await api('agendar', {
        servico: estado.servico, profissional: estado.profissional, data: estado.data, hora: estado.hora,
        observacao: $('rev-obs').value.trim(), site: $('rev-site').value,
      }).catch(semRede);
      if (status === 401) { sessaoExpirou('revisao'); return; }
      if (!ok) {
        mensagem($('erro-rev'), j.erro || 'Não deu pra agendar.');
        if (status === 409) carregarHorarios(); // a lista já volta atualizada
        return;
      }
      mostrarConfirmacao(j.agendamento);
    } finally {
      b.disabled = false; b.textContent = 'Confirmar agendamento';
    }
  });

  function mostrarConfirmacao(ag) {
    if (!ag) return;
    $('conf-cartao').innerHTML = cartaoResumo({
      profissionalId: ag.profissional_id, profissional: ag.profissional, servico: ag.servico,
      quando: `${ag.data_extenso}, às ${ag.hora}`, valor: ag.preco_fmt,
    });
    $('conf-nota').textContent = `Se precisar, dá pra cancelar em "Meus horários" até ${A.cancelarAteHoras} h antes.`;
    const w = $('conf-whats');
    if (A.whatsapp && !A.avisoAutomatico) {
      const msg = `Oi! Sou ${A.cliente.nome} e acabei de agendar: ${ag.servico}, ${ag.data_extenso} às ${ag.hora}, com ${ag.profissional}.`;
      w.href = `https://wa.me/${String(A.whatsapp).replace(/\D/g, '')}?text=${encodeURIComponent(msg)}`;
      w.hidden = false;
    } else w.hidden = true;
    // Próximo agendamento começa limpo
    $('rev-obs').value = '';
    meusHorarios = null;
    limparHora();
    carregarHorarios();
    irPara('confirmado', { replace: true });
  }

  $('conf-novo').addEventListener('click', () => irPara('servico'));

  // ---------- Meus horários ----------
  async function carregarMeus() {
    if (meusHorarios) renderMeus(); else { $('meus-info').textContent = 'Carregando…'; $('meus-lista').innerHTML = ''; $('meus-agendar').hidden = true; }
    const { ok, status, j } = await api('eu').catch(semRede);
    if (telaAtual !== 'meus') return;
    if (status === 200 && !j.cliente) { sessaoExpirou('meus'); return; }
    if (!ok) { $('meus-info').textContent = j.erro || 'Não carregou.'; return; }
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
      return `<li class="cartao meu" style="--cor:${esc(p.cor)}">${fotoHtml(p, 'foto-p')}
        <div class="meu-corpo">
          <p class="meu-quando">${esc(h.data_extenso)}, às ${esc(h.hora)}</p>
          <p class="meu-det">${esc(h.servico)} com ${esc(h.profissional)}, <span class="nowrap">${esc(h.preco_fmt)}</span></p>
          ${h.pode_cancelar
            ? `<button type="button" class="link link-perigo" data-cancelar="${h.id}">Cancelar horário</button>`
            : '<p class="meu-det">Pra cancelar agora, fale com o estúdio pelo WhatsApp.</p>'}
          <p class="meu-confirma" hidden>Cancelar mesmo?
            <button type="button" class="link link-perigo" data-sim="${h.id}">Sim, cancelar</button>
            <button type="button" class="link" data-nao>Não</button></p>
        </div></li>`;
    }).join('');
  }

  $('meus-lista').addEventListener('click', async (ev) => {
    const t = ev.target.closest('button');
    const li = ev.target.closest('.meu');
    if (!t || !li) return;
    if (t.dataset.cancelar) { li.querySelector('.meu-confirma').hidden = false; t.hidden = true; return; }
    if (t.hasAttribute('data-nao')) { li.querySelector('.meu-confirma').hidden = true; li.querySelector('[data-cancelar]').hidden = false; return; }
    if (t.dataset.sim) {
      t.disabled = true; t.textContent = 'Cancelando…';
      const { ok, status, j } = await api('cancelar', { id: Number(t.dataset.sim) }).catch(semRede);
      if (status === 401) { sessaoExpirou('meus'); return; }
      if (!ok) { $('meus-info').textContent = j.erro || 'Não deu pra cancelar.'; t.disabled = false; t.textContent = 'Sim, cancelar'; return; }
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
    if (!atual) { mensagem($('erro-senha'), 'Digite a senha atual.'); $('senha-atual').focus(); return; }
    if (nova.length < 6) { mensagem($('erro-senha'), 'A nova senha precisa ter pelo menos 6 caracteres.'); $('senha-nova').focus(); return; }
    const { ok, status, j } = await api('senha', { atual, nova }).catch(semRede);
    if (status === 401) { sessaoExpirou('conta'); return; }
    if (!ok) { mensagem($('erro-senha'), j.erro || 'Não deu pra trocar a senha.'); return; }
    mensagem($('erro-senha'), '');
    $('senha-atual').value = ''; $('senha-nova').value = '';
    $('ok-senha').hidden = false;
  });

  $('btn-sair').addEventListener('click', () => sair('servico'));

  // ---------- Início ----------
  montarDias();
  atualizarCta();
  renderTab();
  const inicial = (location.hash || '').slice(1);
  history.replaceState({ tela: 'servico' }, '', location.pathname + location.search);
  if (['meus', 'conta'].includes(inicial)) irPara(inicial, { destino: inicial });
})();
