(function () {
  const A = window.AGENDA;
  const form = document.getElementById('form-agenda');
  const elDias = document.getElementById('dias');
  const elEquipe = document.getElementById('equipe');
  const elResumo = document.getElementById('resumo');
  const elErro = document.getElementById('erro');
  const btn = document.getElementById('btn-confirmar');
  const SEM = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
  const MES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
  const suave = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';

  const estado = { servico: null, profissional: null, data: null, hora: null };
  let pedido = 0;
  let jaRolouProsDados = false;

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

  // ---------- Resumo e envio ----------
  function atualizarResumo() {
    const s = servico();
    const p = prof(estado.profissional);
    if (!s) elResumo.textContent = 'Escolha o serviço.';
    else if (!estado.hora) elResumo.innerHTML = `<strong>${esc(s.nome)}</strong> ${preco(s.preco)}<br>Agora toque num horário.`;
    else {
      const [, m, d] = estado.data.split('-');
      elResumo.innerHTML = `<strong>${esc(s.nome)}</strong>, ${preco(s.preco)}<br>${d}/${m} às ${estado.hora} com ${esc(p.nome)}`;
    }
    if (btn.textContent !== 'Confirmando…') btn.textContent = A.cliente ? 'Confirmar agendamento' : 'Entrar pra confirmar';
    document.querySelectorAll('.prof').forEach((c) => c.classList.toggle('escolhida', Number(c.dataset.prof) === estado.profissional));
    btn.disabled = !(estado.servico && estado.data && estado.hora);
  }

  // ---------- Máscara de celular ----------
  document.querySelectorAll('.mascara-tel').forEach((inp) => inp.addEventListener('input', () => {
    let v = inp.value.replace(/\D/g, '').replace(/^0+/, '');
    if (v.length > 11 && v.startsWith('55')) v = v.slice(2); // colou com +55
    v = v.slice(0, 11);
    if (v.length > 6) v = `(${v.slice(0, 2)}) ${v.slice(2, v.length - 4)}-${v.slice(-4)}`;
    else if (v.length > 2) v = `(${v.slice(0, 2)}) ${v.slice(2)}`;
    inp.value = v;
  }));

  // ---------- Conta da cliente ----------
  const elAuth = document.getElementById('auth');
  const elLogada = document.getElementById('logada');
  const elConta = document.getElementById('conta');
  const elErroAuth = document.getElementById('erro-auth');
  const abaEntrar = document.getElementById('aba-entrar');
  const abaCriar = document.getElementById('aba-criar');
  const $ = (id) => document.getElementById(id);
  let meusHorarios = null;

  async function api(acao, dados) {
    const opcoes = dados === undefined ? {} : {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(dados),
    };
    const r = await fetch('api.php?acao=' + acao, { credentials: 'same-origin', ...opcoes });
    let j = {};
    try { j = await r.json(); } catch (e) { /* resposta vazia */ }
    return { ok: r.ok, status: r.status, j };
  }

  function renderConta() {
    const c = A.cliente;
    elAuth.hidden = !!c;
    elLogada.hidden = !c;
    if (c) {
      $('logada-nome').textContent = c.nome;
      $('logada-info').textContent = `${c.telefone}, ${c.email}`;
      elConta.innerHTML = `<span>Olá, ${esc(c.primeiro_nome)}</span>
        <button type="button" class="link" data-conta="meus">Meus horários</button>
        <button type="button" class="link" data-conta="sair">Sair</button>`;
    } else {
      elConta.innerHTML = `<button type="button" class="link" data-conta="entrar">Entrar na minha conta</button>`;
    }
    atualizarResumo();
  }

  function trocarAba(criar) {
    abaEntrar.setAttribute('aria-selected', String(!criar));
    abaCriar.setAttribute('aria-selected', String(criar));
    abaEntrar.tabIndex = criar ? -1 : 0;
    abaCriar.tabIndex = criar ? 0 : -1;
    $('painel-entrar').hidden = criar;
    $('painel-criar').hidden = !criar;
    elErroAuth.hidden = true;
  }
  abaEntrar.addEventListener('click', () => trocarAba(false));
  abaCriar.addEventListener('click', () => trocarAba(true));
  [abaEntrar, abaCriar].forEach((t) => t.addEventListener('keydown', (ev) => {
    if (ev.key === 'ArrowRight' || ev.key === 'ArrowLeft') {
      const criar = t === abaEntrar; trocarAba(criar); (criar ? abaCriar : abaEntrar).focus();
    }
  }));

  document.querySelectorAll('.ver-senha').forEach((b) => b.addEventListener('click', () => {
    const inp = b.previousElementSibling;
    const mostrar = inp.type === 'password';
    inp.type = mostrar ? 'text' : 'password';
    b.textContent = mostrar ? 'Esconder' : 'Mostrar';
    b.setAttribute('aria-pressed', String(mostrar));
    b.setAttribute('aria-label', mostrar ? 'Esconder senha' : 'Mostrar senha');
  }));

  function erroAuth(msg, campo) {
    elErroAuth.textContent = msg;
    elErroAuth.hidden = false;
    if (campo) campo.focus();
  }

  function mostrarAuth(msg) {
    rolarAte($('t-dados'));
    if (msg) erroAuth(msg);
    setTimeout(() => ($('painel-entrar').hidden ? $('cad-nome') : $('ent-tel')).focus({ preventScroll: true }), 350);
  }

  async function autenticar(acao, dados, botao, rotulo) {
    elErroAuth.hidden = true;
    botao.disabled = true;
    botao.textContent = 'Aguarde…';
    try {
      const { ok, j } = await api(acao, dados);
      if (!ok) return erroAuth(j.erro || 'Não deu certo. Tente de novo.');
      A.cliente = j.cliente;
      meusHorarios = j.horarios || [];
      ['ent-senha', 'cad-senha'].forEach((id) => { $(id).value = ''; });
      renderConta();
      elErro.hidden = true;
      if (estado.hora) {
        btn.focus({ preventScroll: true });
        btn.scrollIntoView({ behavior: suave, block: 'nearest' });
      } else {
        rolarAte($('t-servico'));
      }
    } catch (e) {
      erroAuth('Sem conexão. Verifique a internet e tente de novo.');
    } finally {
      botao.disabled = false;
      botao.textContent = rotulo;
    }
  }

  $('btn-entrar').addEventListener('click', () => {
    const tel = $('ent-tel').value.replace(/\D/g, '');
    if (tel.length < 10) return erroAuth('Digite o celular com DDD.', $('ent-tel'));
    if (!$('ent-senha').value) return erroAuth('Digite sua senha.', $('ent-senha'));
    autenticar('entrar', { telefone: tel, senha: $('ent-senha').value }, $('btn-entrar'), 'Entrar');
  });

  $('btn-criar').addEventListener('click', () => {
    const nome = $('cad-nome').value.trim().replace(/\s+/g, ' ');
    const tel = $('cad-tel').value.replace(/\D/g, '');
    const email = $('cad-email').value.trim();
    const senha = $('cad-senha').value;
    if (nome.split(' ').length < 2) return erroAuth('Digite seu nome completo (nome e sobrenome).', $('cad-nome'));
    if (tel.length < 10) return erroAuth('Digite o celular com DDD.', $('cad-tel'));
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return erroAuth('Digite um e-mail válido, ex.: maria@gmail.com.', $('cad-email'));
    if (senha.length < 6) return erroAuth('A senha precisa ter pelo menos 6 caracteres.', $('cad-senha'));
    autenticar('cadastrar', { nome, telefone: tel, email, senha }, $('btn-criar'), 'Criar conta');
  });

  // Enter dentro do login/cadastro aciona o botão dele (e não o "Confirmar agendamento")
  elAuth.addEventListener('keydown', (ev) => {
    if (ev.key !== 'Enter' || ev.target.tagName !== 'INPUT') return;
    ev.preventDefault();
    ev.target.closest('.auth-painel').querySelector('[data-enter]').click();
  });

  async function sair() {
    await api('sair', {}).catch(() => {});
    A.cliente = null;
    meusHorarios = null;
    fecharMeus();
    renderConta();
  }
  $('trocar-conta').addEventListener('click', sair);

  elConta.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-conta]');
    if (!b) return;
    if (b.dataset.conta === 'entrar') { fecharMeus(); trocarAba(false); mostrarAuth(); }
    if (b.dataset.conta === 'sair') sair();
    if (b.dataset.conta === 'meus') abrirMeus();
  });

  // ---------- Meus horários ----------
  const elMeus = $('meus');
  const elConf = $('confirmado');

  async function abrirMeus() {
    form.hidden = true; elConf.hidden = true; elMeus.hidden = false;
    window.scrollTo(0, 0); elMeus.focus();
    $('meus-info').textContent = 'Carregando…';
    $('meus-lista').innerHTML = '';
    const { ok, status, j } = await api('eu').catch(() => ({ ok: false, j: {} }));
    if (status === 200 && !j.cliente) { A.cliente = null; renderConta(); fecharMeus(); mostrarAuth('Sua sessão expirou. Entre de novo.'); return; }
    if (!ok) { $('meus-info').textContent = 'Não carregou. Verifique a internet.'; return; }
    meusHorarios = j.horarios;
    renderMeus();
  }

  function renderMeus() {
    const lista = meusHorarios || [];
    $('meus-info').textContent = lista.length
      ? `Dá pra cancelar pelo site até ${A.cancelarAteHoras} h antes do horário.`
      : 'Você não tem horários marcados.';
    $('meus-lista').innerHTML = lista.map((h) => {
      const p = prof(h.profissional_id) || { cor: '#9B1B3A', iniciais: '?', nome: h.profissional };
      return `<li class="meu" style="--cor:${esc(p.cor)}">
        ${fotoHtml(p)}
        <div class="meu-corpo">
          <p class="meu-quando">${esc(h.data_extenso)}, às ${esc(h.hora)}</p>
          <p class="meu-det">${esc(h.servico)} com ${esc(h.profissional)}, <span class="nowrap">${esc(h.preco_fmt)}</span></p>
          ${h.pode_cancelar
            ? `<button type="button" class="link link-perigo" data-cancelar="${h.id}">Cancelar horário</button>`
            : `<p class="meu-det">Pra cancelar agora, fale com o estúdio pelo WhatsApp.</p>`}
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
    if (t.dataset.cancelar) { li.querySelector('.meu-confirma').hidden = false; t.hidden = true; return; }
    if (t.hasAttribute('data-nao')) { li.querySelector('.meu-confirma').hidden = true; li.querySelector('[data-cancelar]').hidden = false; return; }
    if (t.dataset.sim) {
      t.disabled = true; t.textContent = 'Cancelando…';
      const { ok, status, j } = await api('cancelar', { id: Number(t.dataset.sim) }).catch(() => ({ ok: false, j: {} }));
      if (status === 401) { A.cliente = null; renderConta(); fecharMeus(); mostrarAuth('Sua sessão expirou. Entre de novo.'); return; }
      if (!ok) { $('meus-info').textContent = j.erro || 'Não deu pra cancelar. Tente de novo.'; t.disabled = false; t.textContent = 'Sim, cancelar'; return; }
      meusHorarios = j.horarios;
      renderMeus();
      $('meus-info').textContent = 'Horário cancelado. O estúdio já foi avisado.';
      if (estado.servico) carregarHorarios();
    }
  });

  function fecharMeus() {
    elMeus.hidden = true;
    form.hidden = false;
  }
  $('meus-voltar').addEventListener('click', () => { fecharMeus(); window.scrollTo(0, 0); });

  // ---------- Etapas ----------
  form.addEventListener('change', (ev) => {
    const t = ev.target;
    elErro.hidden = true;
    if (t.name === 'servico') {
      const primeira = estado.servico === null;
      estado.servico = Number(t.value);
      carregarHorarios();
      if (primeira) rolarAte(document.getElementById('t-dia'));
    }
    if (t.name === 'data') { estado.data = t.value; carregarHorarios(); }
    if (t.name === 'hora') {
      const [id, h] = t.value.split('|');
      estado.profissional = Number(id); estado.hora = h;
      atualizarResumo();
      if (!jaRolouProsDados) { jaRolouProsDados = true; rolarAte(document.getElementById('t-dados')); }
    }
  });

  function mostrarErro(msg) {
    elErro.textContent = msg;
    elErro.hidden = false;
  }

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (!estado.hora) return;
    if (!A.cliente) return mostrarAuth();

    btn.disabled = true;
    btn.textContent = 'Confirmando…';
    try {
      const { ok, status, j } = await api('agendar', {
        servico: estado.servico, profissional: estado.profissional, data: estado.data, hora: estado.hora,
        observacao: form.observacao.value.trim(), site: form.site.value,
      });
      if (status === 401) { A.cliente = null; renderConta(); return mostrarAuth('Sua sessão expirou. Entre de novo pra confirmar.'); }
      if (!ok) {
        mostrarErro(j.erro || 'Não deu pra agendar.');
        if (status === 409) carregarHorarios();
        return;
      }
      meusHorarios = null;
      mostrarConfirmacao(j.agendamento);
    } catch (e) {
      mostrarErro('Sem conexão. Verifique a internet e tente de novo.');
    } finally {
      btn.textContent = '';
      atualizarResumo();
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
    $('conf-nota').textContent = `Seu horário fica em "Meus horários", lá no topo. Dá pra cancelar por lá até ${A.cancelarAteHoras} h antes.`;
    const w = $('conf-whats');
    if (A.whatsapp && !A.avisoAutomatico) {
      const msg = `Oi! Sou ${A.cliente.nome} e acabei de agendar: ${ag.servico}, ${ag.data_extenso} às ${ag.hora}, com ${ag.profissional}.`;
      w.href = `https://wa.me/${A.whatsapp}?text=${encodeURIComponent(msg)}`;
      w.hidden = false;
    } else {
      w.hidden = true;
    }
    form.observacao.value = '';
    form.hidden = true;
    elConf.hidden = false;
    window.scrollTo(0, 0);
    elConf.focus();
  }

  $('conf-novo').addEventListener('click', () => {
    elConf.hidden = true;
    form.hidden = false;
    window.scrollTo(0, 0);
    carregarHorarios();
  });

  montarDias();
  renderEspera('Escolha o serviço pra ver os horários');
  renderConta();
})();
