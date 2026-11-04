// Painel: marcar horário pela dona (admin/agendar.php).
(function () {
  'use strict';
  const M = window.MARCAR;
  const $ = (id) => document.getElementById(id);
  const form = $('marcar');
  const SEMANA = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];

  const esc = (t) =>
    String(t ?? '').replace(
      /[&<>"']/g,
      (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c],
    );
  const semAcento = (t) => String(t).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const dinheiro = (v) => Number(v).toFixed(2).replace('.', ',');
  const dataDe = (iso) => {
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(y, m - 1, d);
  };
  const ddmm = (d) => `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`;

  // ---------- Cliente ----------
  function escolherCliente(c) {
    $('cliente_id').value = c.id;
    $('nova').value = '';
    $('cli-escolhida-nome').textContent = c.nome;
    $('cli-escolhida-tel').textContent = c.telFmt;
    $('cli-escolhida').hidden = false;
    $('cli-buscar').hidden = true;
    $('cli-nova').hidden = true;
  }

  $('cli-busca').addEventListener('input', () => {
    const q = semAcento($('cli-busca').value.trim());
    const dig = q.replace(/\D/g, '');
    if (q.length < 2) {
      $('cli-resultados').innerHTML = '';
      return;
    }
    const achou = M.clientes
      .filter((c) => semAcento(c.nome).includes(q) || (dig.length >= 3 && c.tel.includes(dig)))
      .slice(0, 8);
    $('cli-resultados').innerHTML = achou.length
      ? achou
          .map(
            (c) =>
              `<li><button type="button" class="cli-item" data-id="${c.id}"><strong>${esc(c.nome)}</strong><span>${esc(c.telFmt)}</span></button></li>`,
          )
          .join('')
      : '<li class="cli-vazio">Ninguém com esse nome ou celular. Toque em "Cliente nova" pra cadastrar.</li>';
  });

  $('cli-resultados').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-id]');
    if (!b) return;
    const c = M.clientes.find((x) => x.id === Number(b.dataset.id));
    if (c) escolherCliente(c);
  });

  $('cli-trocar').addEventListener('click', () => {
    $('cliente_id').value = '';
    $('cli-escolhida').hidden = true;
    $('cli-buscar').hidden = false;
    $('cli-busca').value = '';
    $('cli-resultados').innerHTML = '';
    $('cli-busca').focus();
  });

  $('cli-nova-btn').addEventListener('click', () => {
    $('cliente_id').value = '';
    $('nova').value = '1';
    $('cli-buscar').hidden = true;
    $('cli-nova').hidden = false;
    const nome = $('cli-busca').value.trim();
    const campoNome = form.querySelector('[name=nome]');
    if (nome && !/\d/.test(nome) && !campoNome.value) campoNome.value = nome;
    campoNome.focus();
  });

  $('cli-nova-cancelar').addEventListener('click', () => {
    $('nova').value = '';
    $('cli-nova').hidden = true;
    $('cli-buscar').hidden = false;
    $('cli-busca').focus();
  });

  const tel = form.querySelector('[name=telefone]');
  tel.addEventListener('input', () => {
    let v = tel.value.replace(/\D/g, '').replace(/^0+/, '');
    if (v.length > 11 && v.startsWith('55')) v = v.slice(2);
    v = v.slice(0, 11);
    if (v.length > 6) v = `(${v.slice(0, 2)}) ${v.slice(2, v.length - 4)}-${v.slice(-4)}`;
    else if (v.length > 2) v = `(${v.slice(0, 2)}) ${v.slice(2)}`;
    tel.value = v;
  });

  // ---------- Serviço, valor e manicures ----------
  const servico = () => M.servicos.find((s) => s.id === Number($('servico').value));
  let valorAuto = '';

  function aoMudarServico() {
    const s = servico();
    const v = $('valor');
    // Troca o valor pelo da tabela, a não ser que a dona já tenha digitado outro
    if (s && (v.value === '' || v.value === valorAuto)) {
      v.value = dinheiro(s.preco);
      valorAuto = v.value;
    }
    const dupla = !!(s && s.dupla);
    $('t-quem').textContent = dupla ? 'Manicures (marque as duas)' : 'Manicure';
    if (!dupla) {
      // Só uma: fica a primeira marcada
      const marcadas = [...form.querySelectorAll('[name="profs[]"]:checked')];
      marcadas.slice(1).forEach((x) => (x.checked = false));
    }
    carregarLivres();
  }
  $('servico').addEventListener('change', aoMudarServico);

  $('pros').addEventListener('change', (ev) => {
    const cb = ev.target;
    if (cb.name !== 'profs[]') return;
    const max = servico() && servico().dupla ? 2 : 1;
    const marcadas = [...form.querySelectorAll('[name="profs[]"]:checked')];
    if (cb.checked && marcadas.length > max) {
      marcadas
        .filter((x) => x !== cb)
        .slice(0, marcadas.length - max)
        .forEach((x) => (x.checked = false));
    }
    carregarLivres();
  });

  // ---------- Horários livres (os mesmos que aparecem no link) ----------
  let pedido = 0;
  async function carregarLivres() {
    const s = servico();
    const profs = [...form.querySelectorAll('[name="profs[]"]:checked')].map((x) => Number(x.value));
    const data = $('data').value;
    const precisa = s && s.dupla ? 2 : 1;
    $('livres').innerHTML = '';
    if (!s || profs.length < precisa || !data) {
      $('livres-info').textContent =
        s && s.dupla
          ? 'Marque as duas manicures pra ver os horários em que elas estão livres juntas.'
          : 'Escolha o serviço e a manicure pra ver os horários livres.';
      return;
    }
    const meu = ++pedido;
    $('livres-info').textContent = 'Procurando horários livres…';
    let j = {};
    try {
      const r = await fetch(`../api.php?acao=horarios&servico=${s.id}&data=${data}`, { credentials: 'same-origin' });
      j = await r.json();
    } catch (e) {
      j = {};
    }
    if (meu !== pedido) return;
    const vagas = {};
    (j.profissionais || []).forEach((p) => (vagas[p.id] = p.horarios || []));
    let lista = vagas[profs[0]] || [];
    if (profs.length === 2) {
      const daOutra = new Set(vagas[profs[1]] || []);
      lista = lista.filter((h) => daOutra.has(h));
    }
    if (!lista.length) {
      $('livres-info').textContent =
        'Nesse dia não tem horário livre no link pra essa escolha. Se precisar encaixar, digite o horário acima: o sistema só não deixa marcar em cima de outro horário ou bloqueio.';
      return;
    }
    $('livres-info').textContent = 'Horários livres (toque pra usar) ou digite outro acima:';
    $('livres').innerHTML = lista
      .map(
        (h) =>
          `<button type="button" class="livre${h === $('hora').value ? ' ativo' : ''}" data-hora="${h}">${h}</button>`,
      )
      .join('');
  }

  $('livres').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-hora]');
    if (!b) return;
    $('hora').value = b.dataset.hora;
    $('livres')
      .querySelectorAll('.livre')
      .forEach((x) => x.classList.toggle('ativo', x === b));
    resumoRepetir();
  });
  $('data').addEventListener('change', () => {
    carregarLivres();
    resumoRepetir();
  });
  $('hora').addEventListener('change', resumoRepetir);

  // ---------- Horário fixo ----------
  function resumoRepetir() {
    const el = $('repetir-resumo');
    const ini = $('data').value;
    const ate = $('ate').value;
    if (!ini || !ate || ate <= ini) {
      el.textContent = 'Escolha até quando repete (uma data depois do primeiro dia).';
      return;
    }
    const d = dataDe(ini);
    const fim = dataDe(ate);
    let n = 0;
    for (const x = new Date(d); x <= fim && n < 26; x.setDate(x.getDate() + 7)) n++;
    const dia = SEMANA[d.getDay()];
    const todo = d.getDay() === 0 || d.getDay() === 6 ? 'Todo' : 'Toda';
    el.textContent = `${todo} ${dia}${$('hora').value ? ' às ' + $('hora').value : ''}, de ${ddmm(d)} até ${ddmm(fim)}: ${n} ${
      n === 1 ? 'horário' : 'horários'
    }. Se alguma semana já estiver ocupada, ela fica de fora e o painel avisa.`;
  }
  $('repetir').addEventListener('change', () => {
    $('repetir-campos').hidden = !$('repetir').checked;
    resumoRepetir();
  });
  $('ate').addEventListener('change', resumoRepetir);

  // ---------- Antes de enviar ----------
  form.addEventListener('submit', (ev) => {
    const faltas = [];
    if (!$('cliente_id').value && $('nova').value !== '1') faltas.push('a cliente');
    if (!servico()) faltas.push('o serviço');
    const precisa = servico() && servico().dupla ? 2 : 1;
    if (form.querySelectorAll('[name="profs[]"]:checked').length !== precisa)
      faltas.push(precisa === 2 ? 'as duas manicures' : 'a manicure');
    if (!$('hora').value) faltas.push('o horário');
    if (faltas.length) {
      ev.preventDefault();
      alert('Falta escolher ' + faltas.join(', ').replace(/, ([^,]*)$/, ' e $1') + '.');
    }
  });

  aoMudarServico();
  resumoRepetir();
})();
