/* Painel administrativo da loja Odin Focus: financeiro, pedidos, clientes, catálogo, assinaturas e configurações */
(() => {
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const esc = Loja.esc;
  const brl = Loja.brl;

  const A = { admin: null, csrf: '', loja: null, tela: '', periodo: { tipo: 'mes' } };

  const STATUS_PEDIDO = {
    aguardando_pagamento: 'Aguardando pagamento', em_analise: 'Em análise', pago: 'Pago', cancelado: 'Cancelado', estornado: 'Estornado'
  };
  const STATUS_MP = {
    approved: 'Aprovado', pending: 'Pendente', in_process: 'Em análise', authorized: 'Autorizado',
    rejected: 'Recusado', cancelled: 'Cancelado', refunded: 'Estornado', charged_back: 'Contestado'
  };
  const STATUS_ASSINATURA = { ativa: 'Ativa', atrasada: 'Atrasada', encerrada: 'Encerrada', cancelada: 'Cancelada' };
  const METODOS = { pix: 'Pix', boleto: 'Boleto', credito: 'Crédito', debito: 'Débito', outro: 'Outro' };
  const ORIGENS = { loja: 'Loja', admin: 'Painel', renovacao: 'Renovação' };
  const RENOVACOES = { unica: 'Pagamento único', mensal: 'Mensal', trimestral: 'Trimestral', semestral: 'Semestral', anual: 'Anual' };

  const selo = (chave, texto) => `<span class="status st-${esc(chave)}">${esc(texto)}</span>`;
  const reaisCampo = (v) => (v === null || v === undefined || v === '' ? '' : Number(v).toFixed(2).replace('.', ','));
  const numero = (v) => Number(String(v ?? '').replace(/\./g, '').replace(',', '.')) || 0;
  const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const falha = (e) => Loja.aviso(e.message, 'erro');
  const zap = (celular, texto) => `https://wa.me/55${Loja.digitos(celular)}?text=${encodeURIComponent(texto)}`;
  const compacto = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', notation: 'compact', maximumFractionDigits: 1 });

  $$('[data-icone]').forEach((el) => { el.innerHTML = Loja.icone(el.dataset.icone); });
  $$('[data-marca]').forEach((el) => { el.innerHTML = Loja.marca(); });

  /** Chamada à API com o token CSRF; sessão expirada volta para o login. */
  async function api(rota, opcoes = {}) {
    try {
      return await Loja.api(rota, { ...opcoes, csrf: A.csrf });
    } catch (e) {
      if (e.status === 401 && A.admin) {
        A.admin = null;
        mostrarLogin('Sua sessão expirou. Entre novamente.');
      }
      throw e;
    }
  }

  // ---------------- Login e navegação ----------------

  function mostrarLogin(msg = '') {
    if ($('#dlg').open) $('#dlg').close();
    $('#app').hidden = true;
    $('#telaLogin').hidden = false;
    const erro = $('#erroLogin');
    erro.textContent = msg;
    erro.hidden = !msg;
    $('#formLogin').elements.email.focus();
  }

  $('#formLogin').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    const btn = f.querySelector('button');
    btn.disabled = true;
    try {
      const r = await Loja.api('admin/entrar', { metodo: 'POST', dados: { email: f.elements.email.value, senha: f.elements.senha.value } });
      f.reset();
      await entrar(r.admin);
    } catch (err) {
      $('#erroLogin').textContent = err.message;
      $('#erroLogin').hidden = false;
    } finally {
      btn.disabled = false;
    }
  });

  async function entrar(admin) {
    A.admin = admin;
    A.csrf = admin.csrf;
    await carregarLoja();
    $('#telaLogin').hidden = true;
    $('#app').hidden = false;
    $('#nomeAdmin').textContent = admin.nome;
    ir();
  }

  async function carregarLoja() {
    A.loja = (await Loja.api('loja')).loja;
    Loja.aplicarMarca(A.loja);
    // Cores do tema: recarrega o CSS gerado pelo servidor (depois de salvar, já aparecem as novas).
    const tema = document.getElementById('temaCss');
    if (tema) tema.href = `../api/?r=tema.css&v=${Date.now()}`;
    Loja.nomeLogo($('#nomeLoja'), A.loja.nome);
    document.title = `Painel · ${A.loja.nome}`;
    $$('#nav a[data-modulo]').forEach((a) => { a.hidden = !(a.dataset.modulo in A.loja.modulos); });
  }

  $('#btnSair').addEventListener('click', async () => {
    try { await api('admin/sair', { metodo: 'POST' }); } catch { /* sessão já encerrada */ }
    A.admin = null;
    mostrarLogin();
  });
  $('#btnMenu').addEventListener('click', () => $('#lateral').classList.toggle('aberta'));
  $('#nav').addEventListener('click', () => $('#lateral').classList.remove('aberta'));

  const TELAS = {
    painel: ['Painel financeiro', telaPainel],
    pedidos: ['Pedidos', telaPedidos],
    clientes: ['Clientes', telaClientes],
    produtos: ['Produtos', (el, ac) => telaCatalogo('produto', el, ac)],
    servicos: ['Serviços', (el, ac) => telaCatalogo('servico', el, ac)],
    assinaturas: ['Assinaturas', telaAssinaturas],
    configuracoes: ['Configurações', telaConfiguracoes]
  };
  const MODULO_DA_TELA = { produtos: 'produto', servicos: 'servico', assinaturas: 'servico' };

  function ir() {
    if (!A.admin) return;
    let t = location.hash.slice(1);
    if (!TELAS[t] || (MODULO_DA_TELA[t] && !(MODULO_DA_TELA[t] in A.loja.modulos))) t = 'painel';
    A.tela = t;
    $$('#nav a').forEach((a) => a.classList.toggle('ativa', a.dataset.tela === t));
    $('#tituloTela').textContent = TELAS[t][0];
    $('#acoesTela').innerHTML = '';
    $('#conteudo').innerHTML = '<p class="carregando">Carregando…</p>';
    $('#lateral').classList.remove('aberta');
    TELAS[t][1]($('#conteudo'), $('#acoesTela')).catch((e) => {
      $('#conteudo').innerHTML = `<div class="alerta">${Loja.icone('alerta')}<p>${esc(e.message)}</p></div>`;
    });
  }
  window.addEventListener('hashchange', ir);

  function recarregarTela() {
    if (['pedidos', 'clientes', 'produtos', 'servicos', 'assinaturas', 'painel'].includes(A.tela)) ir();
  }

  // ---------------- Diálogo ----------------

  const dlg = $('#dlg');
  function dialogo(titulo, corpo, rodape = '') {
    dlg.innerHTML = `
      <div class="dlg-cab"><h2>${titulo}</h2><button type="button" class="btn-icone" data-fechar aria-label="Fechar">${Loja.icone('fechar')}</button></div>
      <div class="dlg-corpo">${corpo}</div>
      ${rodape ? `<div class="dlg-rodape">${rodape}</div>` : ''}`;
    if (!dlg.open) dlg.showModal();
    dlg.scrollTop = 0;
    return dlg;
  }
  dlg.addEventListener('click', (e) => {
    if (e.target === dlg || e.target.closest('[data-fechar]')) dlg.close();
  });

  function erroDeForm(form, e) {
    Loja.aviso(e.message, 'erro');
    $$('.invalido', form).forEach((el) => el.classList.remove('invalido'));
    const el = e.campo && form.elements[e.campo];
    if (el) {
      el.classList.add('invalido');
      el.focus();
    }
  }

  const tabela = (cabecalhos, linhas, vazio = 'Nada por aqui ainda.') => (linhas.length
    ? `<table class="tabela"><thead><tr>${cabecalhos.map((c) => `<th class="${c.startsWith('#') ? 'num' : ''}">${esc(c.replace(/^#/, ''))}</th>`).join('')}</tr></thead><tbody>${linhas.join('')}</tbody></table>`
    : `<p class="vazio-tabela">${vazio}</p>`);

  const miniatura = (url, alt) => `<span class="mini">${url ? `<img src="../${esc(url)}" alt="${esc(alt)}" loading="lazy">` : `<span class="sem-foto">${Loja.marca()}</span>`}</span>`;

  // ---------------- Painel financeiro ----------------

  function calcularPeriodo(p) {
    const h = new Date();
    const d = (y, m, dia) => new Date(y, m, dia);
    switch (p.tipo) {
      case 'mes_passado': return [iso(d(h.getFullYear(), h.getMonth() - 1, 1)), iso(d(h.getFullYear(), h.getMonth(), 0))];
      case '7': return [iso(d(h.getFullYear(), h.getMonth(), h.getDate() - 6)), iso(h)];
      case '30': return [iso(d(h.getFullYear(), h.getMonth(), h.getDate() - 29)), iso(h)];
      case 'ano': return [iso(d(h.getFullYear(), 0, 1)), iso(h)];
      case 'custom': return [p.de || iso(d(h.getFullYear(), h.getMonth(), 1)), p.ate || iso(h)];
      default: return [iso(d(h.getFullYear(), h.getMonth(), 1)), iso(h)];
    }
  }

  async function telaPainel(el, acoes) {
    const [de, ate] = calcularPeriodo(A.periodo);
    acoes.innerHTML = `
      <div class="filtros">
        <label>Período
          <select id="perTipo">
            <option value="mes">Este mês</option><option value="mes_passado">Mês passado</option>
            <option value="7">Últimos 7 dias</option><option value="30">Últimos 30 dias</option>
            <option value="ano">Este ano</option><option value="custom">Personalizado</option>
          </select>
        </label>
        <label ${A.periodo.tipo === 'custom' ? '' : 'hidden'}>De <input type="date" id="perDe" value="${de}"></label>
        <label ${A.periodo.tipo === 'custom' ? '' : 'hidden'}>Até <input type="date" id="perAte" value="${ate}"></label>
        <a class="btn btn-pequeno btn-linha" href="../api/?r=relatorios/pedidos.csv&de=${de}&ate=${ate}">Exportar planilha</a>
      </div>`;
    $('#perTipo').value = A.periodo.tipo;
    $('#perTipo').addEventListener('change', (e) => {
      A.periodo = { tipo: e.target.value, de, ate };
      ir();
    });
    ['#perDe', '#perAte'].forEach((s) => $(s).addEventListener('change', () => {
      A.periodo = { tipo: 'custom', de: $('#perDe').value, ate: $('#perAte').value };
      ir();
    }));

    const r = await api(`relatorios/resumo&de=${de}&ate=${ate}`);
    const t = r.totais;
    const ant = r.anterior;
    const mods = r.modulos || {};

    const delta = (atual, antes) => {
      if (!antes) return '<span class="delta igual">sem vendas no período anterior</span>';
      const p = ((atual - antes) / antes) * 100;
      const cls = Math.abs(p) < 0.5 ? 'igual' : p > 0 ? 'sobe' : 'desce';
      return `<span class="delta ${cls}">${p > 0 ? '▲' : p < 0 ? '▼' : '•'} ${Math.abs(p).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}% em relação ao período anterior</span>`;
    };
    const tile = (rotulo, valor, extra = '') => `<div class="indicador"><span class="rotulo">${rotulo}</span><span class="valor">${valor}</span>${extra ? `<span class="extra">${extra}</span>` : ''}</div>`;

    const alertas = [];
    if (!r.mercado_pago_configurado) alertas.push(`Os pagamentos online ainda não estão ligados. Informe as chaves do Mercado Pago em <a href="#configuracoes">Configurações</a>.`);
    if (mods.produto && mods.produto.estoque_baixo.length) alertas.push(`${mods.produto.estoque_baixo.length} produto(s) com estoque baixo. Veja a lista abaixo.`);
    if (mods.servico && mods.servico.assinaturas_atrasadas) alertas.push(`${mods.servico.assinaturas_atrasadas} assinatura(s) com pagamento atrasado. <a href="#assinaturas">Ver assinaturas</a>.`);

    const tiles = [
      tile('Lucro estimado', brl(t.lucro_estimado), `Margem de ${t.margem.toLocaleString('pt-BR')}% sobre o faturamento`),
      tile('Pedidos pagos', t.pedidos_pagos.toLocaleString('pt-BR'), `Ticket médio ${brl(t.ticket_medio)}`),
      tile('Valor líquido recebido', brl(t.valor_liquido), `Após taxas do Mercado Pago (${brl(t.taxas_mercado_pago)})`),
      tile('Custo dos itens vendidos', brl(t.custo_itens), `Frete cobrado: ${brl(t.frete_cobrado)}`),
      tile('Aguardando pagamento', brl(r.aguardando.valor), `${r.aguardando.pedidos} pedido(s) em aberto`),
      tile('Estornos no período', brl(r.estornados.valor + t.estornos_parciais), `${r.estornados.pedidos} pedido(s) estornado(s) · ${r.cancelados} cancelado(s)`),
      tile('Clientes novos', r.clientes_novos.toLocaleString('pt-BR'))
    ];
    if (mods.servico) tiles.push(tile('Receita recorrente mensal', brl(mods.servico.receita_recorrente_mensal), `${mods.servico.assinaturas_ativas} assinatura(s) ativa(s)`));
    if (mods.produto) tiles.push(tile('Capital em estoque', brl(mods.produto.capital_em_estoque), `A preço de custo · ${mods.produto.produtos_ativos} produto(s) ativo(s)`));

    const maxMetodo = Math.max(...r.por_metodo.map((m) => m.receita), 1);
    el.innerHTML = `
      ${alertas.map((a) => `<div class="alerta">${Loja.icone('alerta')}<p>${a}</p></div>`).join('')}
      <section class="bloco">
        <div class="hero-num">
          <span class="rotulo">Faturamento de ${Loja.data(de)} a ${Loja.data(ate)}</span>
          <span class="valor">${brl(t.receita_bruta)}</span>
          ${delta(t.receita_bruta, ant.receita_bruta)}
        </div>
      </section>
      <section class="indicadores">${tiles.join('')}</section>
      <section class="bloco">
        <h2>Faturamento por ${serieAgrupada(de, ate) ? 'mês' : 'dia'}</h2>
        <p class="bloco-sub">Pedidos pagos, pela data de confirmação do pagamento.</p>
        <div class="grafico" id="grafico"></div>
        <details class="tabela-alt"><summary>Ver em tabela</summary><div class="tabela-caixa">${tabela(['Data', '#Pedidos', '#Faturamento'],
          r.por_dia.map((d) => `<tr><td>${Loja.data(d.dia)}</td><td class="num">${d.pedidos}</td><td class="num">${brl(d.receita)}</td></tr>`), 'Nenhuma venda paga no período.')}</div></details>
      </section>
      <div class="colunas-2">
        <section class="bloco">
          <h2>Por forma de pagamento</h2>
          <p class="bloco-sub">Faturamento dos pedidos pagos.</p>
          ${r.por_metodo.length ? `<div class="barras">${r.por_metodo.map((m) => `
            <div class="barra-item"><span>${esc(m.metodo_texto)}</span>
              <span class="barra-trilho"><span style="width:${Math.max(1, (m.receita / maxMetodo) * 100).toFixed(1)}%"></span></span>
              <span class="num">${brl(m.receita)}<small>${m.pedidos} pedido(s)</small></span></div>`).join('')}</div>` : '<p class="vazio-tabela">Sem vendas no período.</p>'}
          ${r.por_tipo.length > 1 ? `<div class="tabela-caixa" style="margin-top:18px">${tabela(['Tipo', '#Faturamento', '#Lucro bruto'], r.por_tipo.map((p) => `<tr><td>${p.tipo === 'produto' ? 'Produtos' : p.tipo === 'servico' ? 'Serviços' : esc(p.tipo)}</td><td class="num">${brl(p.receita)}</td><td class="num">${brl(p.lucro)}</td></tr>`))}</div>` : ''}
        </section>
        <section class="bloco">
          <h2>Mais vendidos</h2>
          <p class="bloco-sub">Os 10 itens com maior faturamento no período.</p>
          <div class="tabela-caixa">${tabela(['Item', '#Qtd.', '#Faturamento', '#Lucro'], r.top_itens.map((i) => `
            <tr><td>${esc(i.descricao)}<br><span class="fraco">${i.tipo === 'servico' ? 'Serviço' : 'Produto'} · ${esc(i.codigo)}</span></td>
            <td class="num">${i.quantidade}</td><td class="num">${brl(i.receita)}</td><td class="num">${brl(i.lucro)}</td></tr>`), 'Sem vendas no período.')}</div>
        </section>
      </div>
      ${mods.produto && mods.produto.estoque_baixo.length ? `
        <section class="bloco">
          <h2>Estoque baixo</h2>
          <p class="bloco-sub">Produtos ativos com estoque igual ou menor que o mínimo configurado.</p>
          <div class="tabela-caixa">${tabela(['Código', 'Produto', '#Estoque'], mods.produto.estoque_baixo.map((p) => `
            <tr class="clicavel" data-produto="${esc(p.codigo)}"><td>${esc(p.codigo)}</td><td>${esc(p.titulo)}</td><td class="num ${p.estoque < 0 ? 'neg' : 'baixo'}">${p.estoque}</td></tr>`))}</div>
        </section>` : ''}
      ${mods.servico && mods.servico.renovacoes_proximas.length ? `
        <section class="bloco">
          <h2>Renovações nos próximos 30 dias</h2>
          <p class="bloco-sub">Assinaturas no cartão são renovadas sozinhas (Asaas ou Mercado Pago); nas antigas, o link de pagamento é enviado por e-mail antes do vencimento.</p>
          <div class="tabela-caixa">${tabela(['Cliente', 'Serviço', '#Valor', 'Vencimento', 'Situação'], mods.servico.renovacoes_proximas.map((a) => `
            <tr><td>${esc(a.cliente)}</td><td>${esc(a.descricao)}</td><td class="num">${brl(a.valor)}</td><td>${Loja.data(a.proxima_cobranca)}</td><td>${selo(a.status, STATUS_ASSINATURA[a.status])}</td></tr>`))}</div>
        </section>` : ''}`;

    graficoColunas($('#grafico'), serieFaturamento(r.por_dia, de, ate));
    $$('[data-produto]', el).forEach((tr) => tr.addEventListener('click', () => abrirItemCatalogo('produto', tr.dataset.produto)));
  }

  const serieAgrupada = (de, ate) => (new Date(`${ate}T12:00`) - new Date(`${de}T12:00`)) / 864e5 + 1 > 62;

  /** Um ponto por dia do período (ou por mês, em períodos longos), com zero nos dias sem venda. */
  function serieFaturamento(porDia, de, ate) {
    const mapa = new Map(porDia.map((d) => [d.dia, d]));
    const inicio = new Date(`${de}T12:00`);
    const fim = new Date(`${ate}T12:00`);
    const pontos = [];
    if (!serieAgrupada(de, ate)) {
      for (let d = new Date(inicio); d <= fim; d.setDate(d.getDate() + 1)) {
        const k = iso(d);
        const v = mapa.get(k);
        pontos.push({ rotulo: `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`, titulo: d.toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: 'short' }), valor: v ? v.receita : 0, pedidos: v ? v.pedidos : 0 });
      }
      return pontos;
    }
    const meses = new Map();
    for (let d = new Date(inicio.getFullYear(), inicio.getMonth(), 1); d <= fim; d.setMonth(d.getMonth() + 1)) {
      meses.set(`${d.getFullYear()}-${d.getMonth()}`, { rotulo: d.toLocaleDateString('pt-BR', { month: 'short' }).replace('.', ''), titulo: d.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' }), valor: 0, pedidos: 0 });
    }
    for (const v of porDia) {
      const d = new Date(`${v.dia}T12:00`);
      const m = meses.get(`${d.getFullYear()}-${d.getMonth()}`);
      if (m) {
        m.valor += v.receita;
        m.pedidos += v.pedidos;
      }
    }
    return [...meses.values()];
  }

  /** Colunas finas de uma só cor, grade discreta, rótulo só no maior valor e dica ao passar o mouse. */
  function graficoColunas(caixa, serie) {
    let larguraAnterior = 0;
    const desenhar = () => {
      const W = Math.round(caixa.clientWidth);
      if (!W || W === larguraAnterior) return;
      larguraAnterior = W;
      const max = Math.max(0, ...serie.map((s) => s.valor));
      if (!max) {
        caixa.innerHTML = '<p class="grafico-vazio">Nenhuma venda paga neste período.</p>';
        return;
      }
      const H = 250;
      const m = { t: 24, r: 8, b: 28, l: 70 };
      const pw = W - m.l - m.r;
      const ph = H - m.t - m.b;
      const bruto = max / 4;
      const mag = 10 ** Math.floor(Math.log10(bruto));
      const n = bruto / mag;
      const passo = (n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10) * mag;
      const topo = Math.ceil(max / passo) * passo;
      const y = (v) => m.t + ph - (v / topo) * ph;
      const slot = pw / serie.length;
      const bw = Math.max(2, Math.min(24, slot * 0.62));
      const cadaRotulo = Math.max(1, Math.ceil(serie.length / Math.max(1, Math.floor(pw / 52))));
      const iMax = serie.findIndex((s) => s.valor === max);

      let svg = '';
      for (let v = 0; v <= topo + passo / 2; v += passo) {
        svg += `<line class="${v ? 'grade-y' : 'eixo'}" x1="${m.l}" x2="${W - m.r}" y1="${y(v)}" y2="${y(v)}"/>`;
        svg += `<text x="${m.l - 10}" y="${y(v) + 4}" text-anchor="end">${esc(compacto.format(v))}</text>`;
      }
      serie.forEach((s, i) => {
        const cx = m.l + slot * i + slot / 2;
        if (s.valor > 0) {
          const x = cx - bw / 2;
          const topoY = y(s.valor);
          const h = m.t + ph - topoY;
          const rr = Math.min(4, bw / 2, h);
          svg += `<path class="coluna" data-i="${i}" d="M${x},${m.t + ph}V${topoY + rr}Q${x},${topoY} ${x + rr},${topoY}H${x + bw - rr}Q${x + bw},${topoY} ${x + bw},${topoY + rr}V${m.t + ph}Z"/>`;
        }
        if (i % cadaRotulo === 0) svg += `<text x="${cx}" y="${H - 8}" text-anchor="middle">${esc(s.rotulo)}</text>`;
      });
      const cxMax = m.l + slot * iMax + slot / 2;
      svg += `<text class="rotulo-max" x="${Math.min(Math.max(cxMax, m.l + 30), W - 40)}" y="${y(max) - 8}" text-anchor="middle">${esc(brl(max))}</text>`;
      serie.forEach((s, i) => { svg += `<rect class="alvo" data-i="${i}" x="${m.l + slot * i}" y="${m.t}" width="${slot}" height="${ph}"/>`; });

      caixa.innerHTML = `<svg viewBox="0 0 ${W} ${H}" height="${H}" role="img" aria-label="Gráfico de faturamento. Maior valor: ${esc(brl(max))} em ${esc(serie[iMax].titulo)}.">${svg}</svg><div class="dica-grafico" hidden></div>`;
      const dica = $('.dica-grafico', caixa);
      $$('.alvo', caixa).forEach((alvo) => {
        alvo.addEventListener('pointerenter', () => {
          const i = Number(alvo.dataset.i);
          const s = serie[i];
          $$('.coluna', caixa).forEach((c) => c.classList.toggle('foco', c.dataset.i === String(i)));
          dica.innerHTML = `${esc(s.titulo)}<strong>${esc(brl(s.valor))}</strong>${s.pedidos} pedido(s)`;
          dica.hidden = false;
          const escala = caixa.clientWidth / W;
          dica.style.left = `${Math.min(Math.max((m.l + slot * i + slot / 2) * escala, 70), caixa.clientWidth - 70)}px`;
          dica.style.top = `${y(s.valor) * escala}px`;
        });
        alvo.addEventListener('pointerleave', () => {
          dica.hidden = true;
          $$('.coluna', caixa).forEach((c) => c.classList.remove('foco'));
        });
      });
    };
    desenhar();
    if (window.ResizeObserver) new ResizeObserver(() => desenhar()).observe(caixa);
  }

  // ---------------- Pedidos ----------------

  async function telaPedidos(el, acoes) {
    acoes.innerHTML = '<button type="button" class="btn btn-pequeno" id="btnNovoPedido">Novo link de pagamento</button>';
    $('#btnNovoPedido').addEventListener('click', novoPedido);
    const f = A.filtroPedidos || (A.filtroPedidos = { busca: '', status: '', de: '', ate: '' });
    el.innerHTML = `
      <div class="bloco filtros">
        <label class="f-busca">Buscar <input type="search" id="fBusca" placeholder="Nº do pedido, nome, e-mail ou CPF" value="${esc(f.busca)}"></label>
        <label>Situação <select id="fStatus"><option value="">Todas</option>${Object.entries(STATUS_PEDIDO).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select></label>
        <label>De <input type="date" id="fDe" value="${f.de}"></label>
        <label>Até <input type="date" id="fAte" value="${f.ate}"></label>
      </div>
      <div class="tabela-caixa" id="lista"><p class="carregando">Carregando…</p></div>`;
    $('#fStatus').value = f.status;
    let pagina = 1;
    let linhas = [];
    const carregar = async (mais = false) => {
      pagina = mais ? pagina + 1 : 1;
      const q = new URLSearchParams({ busca: f.busca, status: f.status, de: f.de, ate: f.ate, pagina });
      const r = await api(`pedidos&${q}`);
      const novas = r.pedidos.map((p) => `
        <tr class="clicavel" data-pedido="${p.id}">
          <td><strong>#${p.id}</strong><br><span class="fraco">${ORIGENS[p.origem]}</span></td>
          <td>${Loja.dataHora(p.criado_em)}</td>
          <td>${esc(p.cliente_nome)}<br><span class="fraco">${esc(p.cliente_email)}</span></td>
          <td class="num">${p.itens}</td>
          <td class="num">${brl(p.total)}</td>
          <td>${p.forma_pagamento ? METODOS[p.forma_pagamento] : '<span class="fraco">—</span>'}</td>
          <td>${selo(p.status, STATUS_PEDIDO[p.status])}</td>
        </tr>`);
      linhas = mais ? linhas.concat(novas) : novas;
      $('#lista').innerHTML = tabela(['Pedido', 'Data', 'Cliente', '#Itens', '#Total', 'Pagamento', 'Situação'], linhas, 'Nenhum pedido encontrado.')
        + (r.mais ? '<div class="mais"><button type="button" class="btn btn-pequeno btn-linha" id="btnMais">Carregar mais</button></div>' : '');
      if (r.mais) $('#btnMais').addEventListener('click', () => carregar(true).catch(falha));
    };
    $('#lista').addEventListener('click', (e) => {
      const tr = e.target.closest('[data-pedido]');
      if (tr) abrirPedido(Number(tr.dataset.pedido));
    });
    let t;
    $('#fBusca').addEventListener('input', (e) => {
      clearTimeout(t);
      t = setTimeout(() => { f.busca = e.target.value.trim(); carregar().catch(falha); }, 300);
    });
    $('#fStatus').addEventListener('change', (e) => { f.status = e.target.value; carregar().catch(falha); });
    $('#fDe').addEventListener('change', (e) => { f.de = e.target.value; carregar().catch(falha); });
    $('#fAte').addEventListener('change', (e) => { f.ate = e.target.value; carregar().catch(falha); });
    await carregar();
  }

  async function abrirPedido(id) {
    let p;
    try {
      p = (await api(`pedidos/${id}`)).pedido;
    } catch (e) {
      return falha(e);
    }
    const aberto = p.status === 'aguardando_pagamento';
    const podeCancelar = ['aguardando_pagamento', 'em_analise'].includes(p.status);
    const e = p.endereco;
    const c = p.cliente;
    const mensagemZap = `Olá, ${(c.nome || '').split(' ')[0]}! Segue o link para pagar o pedido #${p.id} (${brl(p.total)}): ${p.link}`;
    dialogo(`Pedido #${p.id} ${selo(p.status, STATUS_PEDIDO[p.status])}`, `
      <dl class="dados">
        <div><dt>Criado em</dt><dd>${Loja.dataHora(p.criado_em)} · ${ORIGENS[p.origem]}</dd></div>
        <div><dt>Pago em</dt><dd>${p.pago_em ? Loja.dataHora(p.pago_em) : '—'}</dd></div>
        <div><dt>Forma de pagamento</dt><dd>${p.forma_pagamento ? METODOS[p.forma_pagamento] : '—'}</dd></div>
        <div><dt>Cliente</dt><dd>${esc(c.nome || '')}<br>CPF ${esc(Loja.formatar.cpf(c.cpf))}</dd></div>
        <div><dt>Contato</dt><dd>${esc(c.email || '')}<br>${c.celular ? `<a href="${zap(c.celular, '')}" target="_blank" rel="noopener">${esc(Loja.formatar.celular(c.celular))}</a>` : ''}</dd></div>
        <div><dt>${p.precisa_entrega ? 'Entrega' : 'Endereço'}</dt><dd>${esc(`${e.rua}, ${e.numero}${e.complemento ? ` - ${e.complemento}` : ''}`)}<br>${esc(`${e.bairro} · ${e.cidade}/${e.estado} · ${Loja.formatar.cep(e.cep)}`)}</dd></div>
      </dl>

      ${aberto && p.cobranca_automatica ? `
      <div class="secao-dlg">
        <h3>Cobrança automática</h3>
        <p class="fraco">Renovação de assinatura no cartão: o Asaas (ou o Mercado Pago, nas assinaturas antigas) debita sozinho e tenta de novo se o cartão recusar. Não envie link de pagamento, para o cliente não pagar duas vezes.</p>
      </div>` : ''}
      ${aberto && !p.cobranca_automatica ? `
      <div class="secao-dlg">
        <h3>Link de pagamento</h3>
        <div class="caixa-link">
          <input readonly value="${esc(p.link)}" aria-label="Link de pagamento">
          <button type="button" class="btn btn-pequeno" data-acao="copiar">Copiar</button>
          ${c.celular ? `<a class="btn btn-pequeno btn-linha" target="_blank" rel="noopener" href="${zap(c.celular, mensagemZap)}">Enviar no WhatsApp</a>` : ''}
          <button type="button" class="btn btn-pequeno btn-linha" data-acao="email">Enviar por e-mail</button>
        </div>
      </div>` : ''}

      <div class="secao-dlg">
        <h3>Itens</h3>
        <div class="tabela-caixa">${tabela(['Item', '#Qtd.', '#Preço', '#Custo', '#Total'], p.itens.map((i) => `
          <tr><td>${esc(i.descricao)}<br><span class="fraco">${i.tipo === 'servico' ? 'Serviço' : 'Produto'} · ${esc(i.codigo)}${i.renovacao ? ` · ${RENOVACOES[i.renovacao] || i.renovacao}` : ''}</span></td>
          <td class="num">${i.quantidade}</td><td class="num">${brl(i.preco_unitario)}</td><td class="num">${brl(i.custo_unitario)}</td><td class="num">${brl(i.preco_unitario * i.quantidade)}</td></tr>`))}</div>
        <dl class="valores" style="max-width:340px;margin:14px 0 0 auto">
          <div><dt>Subtotal</dt><dd>${brl(p.subtotal)}</dd></div>
          ${p.precisa_entrega ? `<div><dt>Frete</dt><dd>${brl(p.frete)}</dd></div>` : ''}
          <div><dt>Custo dos itens</dt><dd>${brl(p.custo_total)}</dd></div>
          <div><dt>Lucro bruto</dt><dd>${brl(p.lucro_bruto)}</dd></div>
          <div class="valores-total"><dt>Total</dt><dd>${brl(p.total)}</dd></div>
        </dl>
      </div>

      <div class="secao-dlg">
        <h3>Pagamentos</h3>
        ${p.pagamentos.length ? p.pagamentos.map((pg) => `
          <div class="pagamento-linha" data-pg="${pg.id}">
            <div>
              <strong>${esc(pg.metodo_texto)}</strong>${pg.parcelas > 1 ? ` em ${pg.parcelas}x` : ''} · ${brl(pg.valor)} ${selo(pg.status, STATUS_MP[pg.status] || pg.status)}<br>
              <span class="fraco">${esc(pg.mensagem)} · ${esc(pg.gateway || 'Mercado Pago')} nº ${esc(pg.mp_id)} · ${Loja.dataHora(pg.criado_em)}</span>
              ${pg.valor_liquido !== null ? `<br><span class="fraco">Líquido recebido: ${brl(pg.valor_liquido)}${pg.valor_estornado > 0 ? ` · Estornado: ${brl(pg.valor_estornado)}` : ''}</span>` : ''}
              <div class="estorno" hidden>
                <div class="filtros" style="margin-top:10px">
                  <label>Valor do estorno (vazio = total restante) <input type="text" inputmode="decimal" placeholder="${reaisCampo(pg.valor - pg.valor_estornado)}"></label>
                  <button type="button" class="btn btn-pequeno btn-perigo" data-acao="confirmar-estorno">Confirmar estorno</button>
                </div>
              </div>
            </div>
            <div class="acoes">
              <button type="button" class="link" data-acao="atualizar">Atualizar</button>
              ${pg.status === 'approved' ? '<button type="button" class="btn btn-pequeno btn-perigo" data-acao="estornar">Estornar</button>' : ''}
            </div>
          </div>`).join('') : '<p class="fraco">Nenhuma tentativa de pagamento ainda.</p>'}
      </div>

      <div class="secao-dlg">
        <h3>Observações internas</h3>
        ${p.observacoes ? `<p class="obs">${esc(p.observacoes)}</p>` : ''}
        <div class="filtros" style="margin-top:10px">
          <label class="f-busca">Nova observação <input type="text" id="novaObs" maxlength="1000" placeholder="Ex.: enviado pelos Correios, código AB123456789BR"></label>
          <button type="button" class="btn btn-pequeno btn-linha" data-acao="obs">Adicionar</button>
        </div>
      </div>`,
    `${podeCancelar ? '<button type="button" class="btn btn-pequeno btn-perigo esquerda" data-acao="cancelar">Cancelar pedido</button>' : ''}
     <button type="button" class="btn btn-pequeno" data-fechar>Fechar</button>`);

    const acao = async (fn, ok) => {
      try {
        await fn();
        if (ok) Loja.aviso(ok, 'ok');
        await abrirPedido(id);
        if (A.tela === 'pedidos' || A.tela === 'painel') recarregarTela();
      } catch (err) {
        falha(err);
      }
    };
    $$('[data-acao]', dlg).forEach((b) => b.addEventListener('click', () => {
      const pgId = b.closest('[data-pg]') && b.closest('[data-pg]').dataset.pg;
      switch (b.dataset.acao) {
        case 'copiar':
          Loja.copiar(p.link).then((okc) => okc && Loja.aviso('Link copiado!', 'ok'));
          break;
        case 'email':
          acao(() => api(`pedidos/${id}/enviar-link`, { metodo: 'POST' }), 'Link enviado por e-mail.');
          break;
        case 'atualizar':
          acao(() => api(`pagamentos/${pgId}/atualizar`, { metodo: 'POST' }), 'Situação do pagamento atualizada.');
          break;
        case 'estornar':
          b.hidden = true;
          $('.estorno', b.closest('[data-pg]')).hidden = false;
          break;
        case 'confirmar-estorno': {
          const valor = $('.estorno input', b.closest('[data-pg]')).value.trim();
          if (!confirm(`Confirma o estorno de ${valor ? `R$ ${valor}` : 'todo o valor restante'}? O dinheiro volta para o cliente e não é possível desfazer.`)) return;
          acao(() => api(`pagamentos/${pgId}/estornar`, { metodo: 'POST', dados: { valor } }), 'Estorno solicitado.');
          break;
        }
        case 'cancelar': {
          const motivo = prompt('Motivo do cancelamento (opcional):', '');
          if (motivo === null) return;
          acao(() => api(`pedidos/${id}/cancelar`, { metodo: 'POST', dados: { motivo } }), 'Pedido cancelado.');
          break;
        }
        case 'obs': {
          const texto = $('#novaObs').value.trim();
          if (!texto) return;
          acao(() => api(`pedidos/${id}/observacao`, { metodo: 'POST', dados: { texto } }), 'Observação adicionada.');
          break;
        }
        default:
      }
    }));
  }

  /** Venda fora do site: escolhe o cliente e os itens e gera o link de pagamento. */
  async function novoPedido() {
    let catalogo = [];
    try {
      const [p, s] = await Promise.all([
        'produto' in A.loja.modulos ? api('produtos') : { produtos: [] },
        'servico' in A.loja.modulos ? api('servicos') : { servicos: [] }
      ]);
      catalogo = [
        ...p.produtos.filter((i) => i.ativo).map((i) => ({ tipo: 'produto', codigo: i.codigo_produto, titulo: i.titulo, preco: i.preco_venda, extra: `estoque ${i.estoque}` })),
        ...s.servicos.filter((i) => i.ativo).map((i) => ({ tipo: 'servico', codigo: i.codigo_servico, titulo: i.titulo, preco: i.preco_venda, extra: i.renovacao_texto, recorrente: i.recorrente }))
      ];
    } catch (e) {
      return falha(e);
    }
    let cliente = null;
    const itens = [];
    dialogo('Novo link de pagamento', `
      <div class="secao-dlg">
        <h3>1. Cliente</h3>
        <div class="campos"><label class="c-12">Buscar cliente <input type="search" id="buscaCliente" placeholder="Nome, CPF ou e-mail"></label></div>
        <div class="resultados" id="resCliente" hidden></div>
        <p id="clienteEscolhido" class="fraco" style="margin-top:8px">Nenhum cliente escolhido. Cliente novo? Cadastre antes na tela Clientes.</p>
      </div>
      <div class="secao-dlg">
        <h3>2. Itens</h3>
        <div class="filtros">
          <label class="f-busca">Item <select id="selItem"><option value="">Escolha…</option>
            ${catalogo.map((i, n) => `<option value="${n}">${esc(i.titulo)} · ${brl(i.preco)} (${esc(i.extra)})</option>`).join('')}</select></label>
          <label>Qtd. <input type="number" id="qtdItem" value="1" min="1" style="width:80px"></label>
          <button type="button" class="btn btn-pequeno btn-linha" id="btnAddItem">Adicionar</button>
        </div>
        <div class="itens-pedido" id="itensNovo" style="margin-top:12px"></div>
        <div class="campos" id="campoFim" hidden style="margin-top:12px">
          <label class="c-6">Data final da assinatura (opcional) <input type="date" id="dataFinal" min="${amanha()}"></label>
          <p class="c-12 fraco">A assinatura é encerrada nesse dia. Em branco, ela continua até ser cancelada.</p>
        </div>
      </div>
      <div class="campos"><label class="c-12 marcar"><input type="checkbox" id="enviarEmail" checked> Enviar o link de pagamento por e-mail ao cliente</label></div>`,
    '<button type="button" class="btn btn-pequeno btn-linha" data-fechar>Cancelar</button><button type="button" class="btn btn-pequeno" id="btnCriarPedido">Gerar link de pagamento</button>');

    const desenharItens = () => {
      const total = itens.reduce((s, i) => s + i.preco * i.quantidade, 0);
      $('#itensNovo').innerHTML = itens.map((i, n) => `
        <div class="linha"><span>${esc(i.titulo)}</span><span class="num">${i.quantidade} × ${brl(i.preco)}</span><button type="button" class="link" data-rem="${n}">Remover</button></div>`).join('')
        + (itens.length ? `<p class="num" style="text-align:right"><strong>Total dos itens: ${brl(total)}</strong> <span class="fraco">(o frete é somado se houver produtos)</span></p>` : '');
      $$('[data-rem]', dlg).forEach((b) => b.addEventListener('click', () => { itens.splice(Number(b.dataset.rem), 1); desenharItens(); }));
      $('#campoFim').hidden = !itens.some((i) => i.recorrente);
    };
    let t;
    $('#buscaCliente').addEventListener('input', (e) => {
      clearTimeout(t);
      const termo = e.target.value.trim();
      t = setTimeout(async () => {
        if (termo.length < 2) { $('#resCliente').hidden = true; return; }
        const r = await api(`clientes&busca=${encodeURIComponent(termo)}`).catch(falha);
        if (!r) return;
        $('#resCliente').hidden = false;
        $('#resCliente').innerHTML = r.clientes.length
          ? r.clientes.slice(0, 20).map((c) => `<button type="button" data-cpf="${c.cpf}">${esc(c.nome)} · CPF ${esc(Loja.formatar.cpf(c.cpf))} · ${esc(c.email)}</button>`).join('')
          : '<p class="vazio-tabela">Nenhum cliente encontrado.</p>';
        $$('[data-cpf]', dlg).forEach((b) => b.addEventListener('click', () => {
          cliente = r.clientes.find((c) => c.cpf === b.dataset.cpf);
          $('#clienteEscolhido').innerHTML = `Cliente: <strong>${esc(cliente.nome)}</strong> · ${esc(cliente.email)}`;
          $('#resCliente').hidden = true;
        }));
      }, 300);
    });
    $('#btnAddItem').addEventListener('click', () => {
      const n = $('#selItem').value;
      if (n === '') return;
      const i = catalogo[Number(n)];
      const qtd = Math.max(1, Number($('#qtdItem').value) || 1);
      const ja = itens.find((x) => x.tipo === i.tipo && x.codigo === i.codigo);
      if (ja) ja.quantidade += qtd;
      else itens.push({ ...i, quantidade: qtd });
      desenharItens();
    });
    $('#btnCriarPedido').addEventListener('click', async (e) => {
      if (!cliente) return Loja.aviso('Escolha o cliente.', 'erro');
      if (!itens.length) return Loja.aviso('Adicione pelo menos um item.', 'erro');
      e.target.disabled = true;
      try {
        const r = await api('pedidos/manual', {
          metodo: 'POST',
          dados: {
            cpf: cliente.cpf,
            itens: itens.map((i) => ({ tipo: i.tipo, codigo: i.codigo, quantidade: i.quantidade })),
            enviar_email: $('#enviarEmail').checked,
            data_final: itens.some((i) => i.recorrente) ? $('#dataFinal').value : ''
          }
        });
        if ($('#enviarEmail').checked && !r.email_enviado) Loja.aviso('O e-mail não pôde ser enviado. Copie o link e envie pelo WhatsApp.', 'erro');
        await abrirPedido(r.pedido.id);
        if (A.tela === 'pedidos') recarregarTela();
      } catch (err) {
        falha(err);
        e.target.disabled = false;
      }
    });
  }

  // ---------------- Clientes ----------------

  async function telaClientes(el, acoes) {
    acoes.innerHTML = '<button type="button" class="btn btn-pequeno" id="btnNovoCliente">Novo cliente</button>';
    $('#btnNovoCliente').addEventListener('click', () => formCliente(null));
    el.innerHTML = `
      <div class="bloco filtros"><label class="f-busca">Buscar <input type="search" id="fBusca" placeholder="Nome, CPF, e-mail ou celular"></label></div>
      <div class="tabela-caixa" id="lista"><p class="carregando">Carregando…</p></div>`;
    let pagina = 1;
    let linhas = [];
    const carregar = async (mais = false) => {
      pagina = mais ? pagina + 1 : 1;
      const r = await api(`clientes&busca=${encodeURIComponent($('#fBusca').value.trim())}&pagina=${pagina}`);
      const novas = r.clientes.map((c) => `
        <tr class="clicavel" data-cpf="${c.cpf}">
          <td>${esc(c.nome)}</td><td>${esc(Loja.formatar.cpf(c.cpf))}</td><td>${esc(c.email)}</td>
          <td>${esc(Loja.formatar.celular(c.celular))}</td><td>${esc(c.cidade)}/${esc(c.estado)}</td>
          <td class="num">${c.compras}</td><td class="num">${brl(c.total_gasto)}</td>
        </tr>`);
      linhas = mais ? linhas.concat(novas) : novas;
      $('#lista').innerHTML = tabela(['Nome', 'CPF', 'E-mail', 'Celular', 'Cidade', '#Compras', '#Total gasto'], linhas, 'Nenhum cliente encontrado.')
        + (r.mais ? '<div class="mais"><button type="button" class="btn btn-pequeno btn-linha" id="btnMais">Carregar mais</button></div>' : '');
      if (r.mais) $('#btnMais').addEventListener('click', () => carregar(true).catch(falha));
    };
    $('#lista').addEventListener('click', async (e) => {
      const tr = e.target.closest('[data-cpf]');
      if (!tr) return;
      try {
        const r = await api(`clientes/${tr.dataset.cpf}`);
        formCliente(r.cliente, r.pedidos);
      } catch (err) {
        falha(err);
      }
    });
    let t;
    $('#fBusca').addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => carregar().catch(falha), 300); });
    await carregar();
  }

  function formCliente(c, pedidos = []) {
    const v = c || {};
    dialogo(c ? esc(c.nome) : 'Novo cliente', `
      <form id="fCli" class="campos" novalidate>
        <label class="c-12">Nome completo <input name="nome" required maxlength="150" value="${esc(v.nome || '')}"></label>
        <label class="c-6">CPF <input name="cpf" inputmode="numeric" required value="${esc(v.cpf || '')}"></label>
        <label class="c-6">Celular <input name="celular" inputmode="tel" required value="${esc(v.celular || '')}"></label>
        <label class="c-12">E-mail <input name="email" type="email" required maxlength="190" value="${esc(v.email || '')}"></label>
        <label class="c-4">CEP <input name="cep" inputmode="numeric" required value="${esc(v.cep || '')}"></label>
        <label class="c-8">Rua <input name="rua" required maxlength="150" value="${esc(v.rua || '')}"></label>
        <label class="c-4">Número <input name="numero" required maxlength="20" value="${esc(v.numero || '')}"></label>
        <label class="c-8">Complemento <input name="complemento" maxlength="80" value="${esc(v.complemento || '')}"></label>
        <label class="c-6">Bairro <input name="bairro" required maxlength="100" value="${esc(v.bairro || '')}"></label>
        <label class="c-4">Cidade <input name="cidade" required maxlength="100" value="${esc(v.cidade || '')}"></label>
        <label class="c-2">UF <select name="estado" required><option value="">UF</option>${Loja.UFS.map((u) => `<option ${u === v.estado ? 'selected' : ''}>${u}</option>`).join('')}</select></label>
      </form>
      ${c ? `<div class="secao-dlg"><h3>Pedidos deste cliente</h3><div class="tabela-caixa">${tabela(['Pedido', 'Data', '#Total', 'Pagamento', 'Situação'], pedidos.map((p) => `
        <tr class="clicavel" data-pedido="${p.id}"><td>#${p.id}</td><td>${Loja.dataHora(p.criado_em)}</td><td class="num">${brl(p.total)}</td><td>${p.forma_pagamento ? METODOS[p.forma_pagamento] : '—'}</td><td>${selo(p.status, p.status_texto)}</td></tr>`), 'Nenhum pedido ainda.')}</div></div>` : ''}`,
    `${c ? '<button type="button" class="btn btn-pequeno btn-perigo esquerda" id="btnExcluir">Excluir</button>' : ''}
     <button type="button" class="btn btn-pequeno btn-linha" data-fechar>Cancelar</button>
     <button type="button" class="btn btn-pequeno" id="btnSalvar">Salvar</button>`);
    const form = $('#fCli');
    Loja.mascarar(form.elements.cpf, 'cpf');
    Loja.mascarar(form.elements.celular, 'celular');
    Loja.mascarar(form.elements.cep, 'cep');
    form.elements.cep.addEventListener('input', async () => {
      if (Loja.digitos(form.elements.cep.value).length !== 8) return;
      try {
        const e = await Loja.buscarCep(form.elements.cep.value);
        if (e.rua) form.elements.rua.value = e.rua;
        if (e.bairro) form.elements.bairro.value = e.bairro;
        form.elements.cidade.value = e.cidade;
        form.elements.estado.value = e.estado;
        form.elements.numero.focus();
      } catch (err) { Loja.aviso(err.message, 'erro'); }
    });
    $$('[data-pedido]', dlg).forEach((tr) => tr.addEventListener('click', () => abrirPedido(Number(tr.dataset.pedido))));
    $('#btnSalvar').addEventListener('click', async () => {
      const dados = Object.fromEntries(new FormData(form));
      if (!Loja.cpfValido(dados.cpf)) return erroDeForm(form, new Loja.Erro('CPF inválido.', 422, 'cpf'));
      try {
        await api(c ? `clientes/${c.cpf}` : 'clientes', { metodo: c ? 'PUT' : 'POST', dados });
        dlg.close();
        Loja.aviso('Cliente salvo.', 'ok');
        recarregarTela();
      } catch (err) {
        erroDeForm(form, err);
      }
    });
    if (c) {
      $('#btnExcluir').addEventListener('click', async () => {
        if (!confirm(`Excluir o cliente ${c.nome}? Só é possível se ele não tiver pedidos.`)) return;
        try {
          await api(`clientes/${c.cpf}`, { metodo: 'DELETE' });
          dlg.close();
          Loja.aviso('Cliente excluído.', 'ok');
          recarregarTela();
        } catch (err) {
          falha(err);
        }
      });
    }
  }

  // ---------------- Produtos e serviços ----------------

  const CAT = {
    produto: { rota: 'produtos', chave: 'codigo_produto', lista: 'produtos', item: 'produto', nome: 'produto', novo: 'Novo produto' },
    servico: { rota: 'servicos', chave: 'codigo_servico', lista: 'servicos', item: 'servico', nome: 'serviço', novo: 'Novo serviço' }
  };
  let categoriasCache = { produto: [], servico: [] };

  async function telaCatalogo(tipo, el, acoes) {
    const K = CAT[tipo];
    acoes.innerHTML = `<button type="button" class="btn btn-pequeno" id="btnNovo">${K.novo}</button>`;
    $('#btnNovo').addEventListener('click', () => formCatalogo(tipo, null));
    const ligado = A.loja.modulos[tipo];
    const naLoja = !A.loja.abas || A.loja.abas[tipo] !== false;
    const nomeTipo = tipo === 'produto' ? 'produtos' : 'serviços';
    el.innerHTML = `
      ${!ligado ? `<div class="alerta">${Loja.icone('alerta')}<p>O módulo de ${nomeTipo} está desligado: nada daqui aparece na loja. Ligue em <a href="#configuracoes">Configurações</a>.</p></div>`
        : !naLoja ? `<div class="alerta">${Loja.icone('alerta')}<p>A aba de ${nomeTipo} está desabilitada: nada daqui aparece na loja (os links de pagamento continuam funcionando). Habilite em <a href="#configuracoes">Configurações › Abas da loja</a>.</p></div>` : ''}
      <div class="bloco filtros"><label class="f-busca">Buscar <input type="search" id="fBusca" placeholder="Código, descrição ou categoria"></label></div>
      <div class="tabela-caixa" id="lista"><p class="carregando">Carregando…</p></div>`;
    const minimo = Number((await api('admin/configuracoes').catch(() => ({ configuracoes: {} }))).configuracoes.estoque_minimo || 0);
    const carregar = async () => {
      const r = await api(`${K.rota}&busca=${encodeURIComponent($('#fBusca').value.trim())}`);
      categoriasCache[tipo] = r.categorias;
      const itens = r[K.lista];
      $('#lista').innerHTML = tipo === 'produto'
        ? tabela(['', 'Código', 'Produto', '#Estoque', '#Custo', '#Venda', '#Margem', 'Loja'], itens.map((p) => `
          <tr class="clicavel" data-codigo="${esc(p.codigo_produto)}">
            <td>${miniatura(p.fotos[0] && p.fotos[0].url, p.titulo)}</td>
            <td>${esc(p.codigo_produto)}</td>
            <td>${esc(p.titulo)}<br><span class="fraco">${esc(p.categoria)}${p.subcategoria ? ` › ${esc(p.subcategoria)}` : ''} · ${p.fotos.length}/3 fotos</span></td>
            <td class="num ${p.estoque < 0 ? 'neg' : p.estoque <= minimo ? 'baixo' : ''}">${p.estoque}</td>
            <td class="num">${brl(p.preco_custo)}</td><td class="num">${brl(p.preco_venda)}</td>
            <td class="num">${p.margem.toLocaleString('pt-BR')}%</td>
            <td>${p.ativo ? selo('ativa', 'Visível') : selo('inativo', 'Oculto')}</td>
          </tr>`), 'Nenhum produto cadastrado. Clique em “Novo produto”.')
        : tabela(['', 'Código', 'Serviço', 'Cobrança', '#Custo', '#Venda', '#Margem', 'Loja'], itens.map((s) => `
          <tr class="clicavel" data-codigo="${esc(s.codigo_servico)}">
            <td>${miniatura(s.foto, s.titulo)}</td>
            <td>${esc(s.codigo_servico)}</td>
            <td>${esc(s.titulo)}<br><span class="fraco">${esc(s.categoria)}${s.subcategoria ? ` › ${esc(s.subcategoria)}` : ''}</span></td>
            <td>${esc(s.renovacao_texto)}</td>
            <td class="num">${brl(s.preco_custo)}</td><td class="num">${brl(s.preco_venda)}</td>
            <td class="num">${s.margem.toLocaleString('pt-BR')}%</td>
            <td>${s.ativo ? selo('ativa', 'Visível') : selo('inativo', 'Oculto')}</td>
          </tr>`), 'Nenhum serviço cadastrado. Clique em “Novo serviço”.');
    };
    $('#lista').addEventListener('click', (e) => {
      const tr = e.target.closest('[data-codigo]');
      if (tr) abrirItemCatalogo(tipo, tr.dataset.codigo);
    });
    let t;
    $('#fBusca').addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => carregar().catch(falha), 300); });
    await carregar();
  }

  async function abrirItemCatalogo(tipo, codigo) {
    try {
      const r = await api(`${CAT[tipo].rota}/${encodeURIComponent(codigo)}`);
      formCatalogo(tipo, r[CAT[tipo].item]);
    } catch (e) {
      falha(e);
    }
  }

  function formCatalogo(tipo, item) {
    const K = CAT[tipo];
    const v = item || { ativo: true, estoque: 0, recorrente: false, renovacao: 'mensal' };
    const cats = categoriasCache[tipo] || [];
    const catsUnicas = [...new Set(cats.map((c) => c.categoria))];
    const subsUnicas = [...new Set(cats.map((c) => c.subcategoria).filter(Boolean))];
    dialogo(item ? `${tipo === 'produto' ? 'Produto' : 'Serviço'} ${esc(item[K.chave])}` : K.novo, `
      <form id="fCat" class="campos" novalidate>
        <label class="c-4">Código <input name="${K.chave}" maxlength="40" required value="${esc(v[K.chave] || '')}" ${item ? 'readonly' : ''} placeholder="Ex.: ${tipo === 'produto' ? 'CAM-001' : 'CONS-01'}">
          <span class="dica">${item ? 'O código não pode ser alterado.' : 'Letras, números e hífen. Não muda depois.'}</span></label>
        <label class="c-4">Categoria <input name="categoria" list="dlCats" required maxlength="80" value="${esc(v.categoria || '')}"></label>
        <label class="c-4">Subcategoria <input name="subcategoria" list="dlSubs" maxlength="80" value="${esc(v.subcategoria || '')}"></label>
        <datalist id="dlCats">${catsUnicas.map((c) => `<option value="${esc(c)}">`).join('')}</datalist>
        <datalist id="dlSubs">${subsUnicas.map((c) => `<option value="${esc(c)}">`).join('')}</datalist>
        <label class="c-12">Descrição <textarea name="descricao" required maxlength="5000">${esc(v.descricao || '')}</textarea>
          <span class="dica">A primeira linha aparece como título na loja. As linhas seguintes são os detalhes.</span></label>
        ${tipo === 'produto' ? `<label class="c-4">Estoque (unidades) <input name="estoque" type="number" min="0" step="1" value="${Math.max(0, v.estoque || 0)}"></label>` : `
          <label class="c-4 marcar"><input type="checkbox" name="recorrente" ${v.recorrente ? 'checked' : ''}> Serviço recorrente (assinatura)</label>
          <label class="c-4">Renovação <select name="renovacao">${Object.entries(RENOVACOES).map(([k, r]) => `<option value="${k}" ${k === v.renovacao ? 'selected' : ''}>${r}</option>`).join('')}</select></label>
          <span class="c-4"></span>`}
        <label class="c-4">Preço de custo (R$) <input name="preco_custo" inputmode="decimal" value="${reaisCampo(v.preco_custo)}" placeholder="0,00"></label>
        <label class="c-4">Preço de venda (R$) <input name="preco_venda" inputmode="decimal" required value="${reaisCampo(v.preco_venda)}" placeholder="0,00"></label>
        <p class="c-4 margem-viva" id="margem"></p>
        <label class="c-12 marcar"><input type="checkbox" name="ativo" ${v.ativo ? 'checked' : ''}> Visível na loja</label>
      </form>
      <div class="secao-dlg">
        <h3>${tipo === 'produto' ? 'Fotos (até 3)' : 'Foto (1)'}</h3>
        <p class="fraco" style="margin-bottom:10px">PNG ou JPG com no máximo ${tipo === 'produto' ? '800×800' : '250×250'} pixels. Imagens maiores são reduzidas automaticamente.</p>
        <div class="fotos ${tipo === 'servico' ? 'fotos-servico' : ''}" id="fotos">${item ? '' : '<p class="fraco">Salve primeiro para poder enviar fotos.</p>'}</div>
      </div>`,
    `${item ? '<button type="button" class="btn btn-pequeno btn-perigo esquerda" id="btnExcluir">Excluir</button>' : ''}
     <button type="button" class="btn btn-pequeno btn-linha" data-fechar>Fechar</button>
     <button type="button" class="btn btn-pequeno" id="btnSalvar">${item ? 'Salvar' : 'Salvar e adicionar fotos'}</button>`);

    const form = $('#fCat');
    const margem = () => {
      const venda = numero(form.elements.preco_venda.value);
      const custo = numero(form.elements.preco_custo.value);
      $('#margem').textContent = venda > 0 ? `Lucro por venda: ${brl(venda - custo)} (margem de ${(((venda - custo) / venda) * 100).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%)` : '';
    };
    form.elements.preco_venda.addEventListener('input', margem);
    form.elements.preco_custo.addEventListener('input', margem);
    margem();
    if (tipo === 'servico') {
      const sincronizar = () => {
        const rec = form.elements.recorrente.checked;
        form.elements.renovacao.disabled = !rec;
        if (!rec) form.elements.renovacao.value = 'unica';
        else if (form.elements.renovacao.value === 'unica') form.elements.renovacao.value = 'mensal';
      };
      form.elements.recorrente.addEventListener('change', sincronizar);
      sincronizar();
    }
    if (item) desenharFotos(tipo, item);

    $('#btnSalvar').addEventListener('click', async () => {
      const d = Object.fromEntries(new FormData(form));
      d.ativo = form.elements.ativo.checked;
      if (tipo === 'servico') {
        d.recorrente = form.elements.recorrente.checked;
        d.renovacao = d.recorrente ? form.elements.renovacao.value : 'unica';
      }
      try {
        const r = await api(item ? `${K.rota}/${encodeURIComponent(item[K.chave])}` : K.rota, { metodo: item ? 'PUT' : 'POST', dados: d });
        Loja.aviso('Salvo!', 'ok');
        formCatalogo(tipo, r[K.item]);
        if (A.tela === K.rota) recarregarTela();
      } catch (err) {
        erroDeForm(form, err);
      }
    });
    if (item) {
      $('#btnExcluir').addEventListener('click', async () => {
        if (!confirm(`Excluir "${item.titulo}"? As vendas antigas continuam nos relatórios. Para só tirar da loja, desmarque "Visível na loja".`)) return;
        try {
          await api(`${K.rota}/${encodeURIComponent(item[K.chave])}`, { metodo: 'DELETE' });
          dlg.close();
          Loja.aviso('Excluído.', 'ok');
          recarregarTela();
        } catch (err) {
          falha(err);
        }
      });
    }
  }

  function desenharFotos(tipo, item) {
    const K = CAT[tipo];
    const cod = encodeURIComponent(item[K.chave]);
    const slots = tipo === 'produto'
      ? [1, 2, 3].map((pos) => ({ pos, foto: item.fotos.find((f) => f.posicao === pos) }))
      : [{ pos: 1, foto: item.foto ? { url: item.foto } : null }];
    $('#fotos').innerHTML = slots.map(({ pos, foto }) => `
      <div class="foto-slot">
        ${foto ? `<img src="../${esc(foto.url)}" alt="Foto ${pos}">
          <button type="button" class="remover" data-remover="${pos}" aria-label="Remover foto">${Loja.icone('lixeira')}</button>` : ''}
        <label>${foto ? '' : `${Loja.icone('mais')}Adicionar foto`}
          <input type="file" accept="image/png,image/jpeg" data-enviar="${pos}">
        </label>
        ${foto && tipo === 'produto' ? `<span class="posicao">${pos === 1 ? 'Principal' : `Foto ${pos}`}</span>` : ''}
      </div>`).join('');
    // Com foto, clicar na imagem troca a foto daquela posição.
    $$('.foto-slot', dlg).forEach((slot) => {
      const img = $('img', slot);
      if (img) img.addEventListener('click', () => $('input[type=file]', slot).click());
    });
    $$('[data-enviar]', dlg).forEach((input) => input.addEventListener('change', async () => {
      const arquivo = input.files[0];
      if (!arquivo) return;
      if (!/^image\/(png|jpeg)$/.test(arquivo.type)) return Loja.aviso('Envie uma imagem PNG ou JPG.', 'erro');
      const fd = new FormData();
      fd.append('foto', arquivo);
      if (tipo === 'produto') fd.append('posicao', input.dataset.enviar);
      input.closest('.foto-slot').style.opacity = '.5';
      try {
        const r = await api(tipo === 'produto' ? `${K.rota}/${cod}/fotos` : `${K.rota}/${cod}/foto`, { metodo: 'POST', arquivo: fd });
        desenharFotos(tipo, r[K.item]);
        Loja.aviso('Foto enviada.', 'ok');
        if (A.tela === K.rota) recarregarTela();
      } catch (err) {
        falha(err);
        desenharFotos(tipo, item);
      }
    }));
    $$('[data-remover]', dlg).forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Remover esta foto?')) return;
      try {
        const r = await api(tipo === 'produto' ? `${K.rota}/${cod}/fotos/${b.dataset.remover}` : `${K.rota}/${cod}/foto`, { metodo: 'DELETE' });
        desenharFotos(tipo, r[K.item]);
        if (A.tela === K.rota) recarregarTela();
      } catch (err) {
        falha(err);
      }
    }));
  }

  // ---------------- Assinaturas ----------------

  async function telaAssinaturas(el, acoes) {
    acoes.innerHTML = '<button type="button" class="btn btn-pequeno btn-linha" id="btnRenovar">Gerar cobranças de renovação agora</button>';
    $('#btnRenovar').addEventListener('click', async (e) => {
      e.target.disabled = true;
      try {
        const r = await api('assinaturas/renovacoes', { metodo: 'POST' });
        dialogo('Cobranças de renovação', `<ul>${(r.mensagens.length ? r.mensagens : ['Nenhuma assinatura precisa de cobrança agora.']).map((m) => `<li>${esc(m)}</li>`).join('')}</ul>`, '<button type="button" class="btn btn-pequeno" data-fechar>Fechar</button>');
        recarregarTela();
      } catch (err) {
        falha(err);
      } finally {
        e.target.disabled = false;
      }
    });
    const filtro = A.filtroAssinaturas || '';
    el.innerHTML = `
      <div class="bloco filtros">
        <label>Situação <select id="fStatus"><option value="">Todas</option><option value="ativa">Ativas</option><option value="atrasada">Atrasadas</option><option value="encerrada">Encerradas</option><option value="cancelada">Canceladas</option></select></label>
        <p class="fraco" style="flex:1 1 300px"><strong>Cartão automático:</strong> o Asaas (ou o Mercado Pago, nas assinaturas antigas) cobra sozinho a cada período e cada cobrança vira um pedido de renovação aqui. <strong>Link por e-mail</strong> (assinaturas antigas): antes do vencimento, o sistema envia o link para pagar com Pix, boleto ou cartão.</p>
      </div>
      <div class="tabela-caixa" id="lista"><p class="carregando">Carregando…</p></div>`;
    $('#fStatus').value = filtro;
    $('#fStatus').addEventListener('change', (e) => { A.filtroAssinaturas = e.target.value; ir(); });
    const r = await api(`assinaturas&status=${filtro}`);
    const terminou = (a) => a.status === 'cancelada' || a.status === 'encerrada';
    // Sem próxima cobrança quando ela cairia na data final ou depois (a assinatura acaba antes).
    const semProxima = (a) => a.data_final && a.proxima_cobranca >= a.data_final;
    $('#lista').innerHTML = tabela(['Cliente', 'Serviço', 'Período', '#Valor', 'Próxima cobrança', 'Termina em', 'Situação', 'Cobrança em aberto', ''], r.assinaturas.map((a) => `
      <tr>
        <td>${esc(a.cliente_nome)}<br><span class="fraco">${esc(a.cliente_email)}</span></td>
        <td>${esc(a.descricao)}<br><span class="fraco">desde ${Loja.data(a.inicio)} · pedido <a href="#" data-pedido="${a.pedido_origem}">#${a.pedido_origem}</a></span></td>
        <td>${esc(a.renovacao_texto)}<br><span class="fraco">${a.cartao_automatico ? 'Cartão automático' : 'Link por e-mail'}</span></td>
        <td class="num">${brl(a.valor)}</td>
        <td>${terminou(a) || semProxima(a) ? '<span class="fraco">—</span>' : Loja.data(a.proxima_cobranca)}</td>
        <td>${a.data_final ? Loja.data(a.data_final) : '<span class="fraco">Sem data</span>'}</td>
        <td>${selo(a.status, STATUS_ASSINATURA[a.status])}</td>
        <td>${a.pedido_renovacao ? `<a href="#" data-pedido="${a.pedido_renovacao}">#${a.pedido_renovacao}</a> ${a.renovacao_status ? selo(a.renovacao_status, STATUS_PEDIDO[a.renovacao_status]) : ''}` : '<span class="fraco">—</span>'}</td>
        <td>${terminou(a) ? '' : `${a.cartao_automatico ? '' : `<button type="button" class="link" data-cobrar="${a.id}">${a.pedido_renovacao ? 'Reenviar cobrança' : 'Cobrar agora'}</button><br>`}<button type="button" class="link" data-fim="${a.id}">Data final</button><br><button type="button" class="link" data-cancelar="${a.id}">Cancelar</button>`}</td>
      </tr>`), 'Nenhuma assinatura. Elas surgem quando um serviço recorrente é pago.');
    $('#lista').addEventListener('click', async (e) => {
      const fim = e.target.closest('[data-fim]');
      if (fim) return editarDataFinal(r.assinaturas.find((a) => a.id === Number(fim.dataset.fim)));
      const ped = e.target.closest('[data-pedido]');
      if (ped) {
        e.preventDefault();
        return abrirPedido(Number(ped.dataset.pedido));
      }
      const cobrar = e.target.closest('[data-cobrar]');
      if (cobrar) {
        try {
          const x = await api(`assinaturas/${cobrar.dataset.cobrar}/cobrar`, { metodo: 'POST' });
          Loja.aviso(x.email_enviado ? 'Cobrança enviada por e-mail.' : 'Cobrança criada, mas o e-mail não pôde ser enviado. Envie o link pelo WhatsApp.', x.email_enviado ? 'ok' : 'erro');
          await abrirPedido(x.pedido.id);
          recarregarTela();
        } catch (err) {
          falha(err);
        }
      }
      const cancelar = e.target.closest('[data-cancelar]');
      if (cancelar) {
        if (!confirm('Cancelar esta assinatura? Não serão geradas novas cobranças (no cartão automático, a assinatura também é cancelada no Asaas ou no Mercado Pago).')) return;
        try {
          await api(`assinaturas/${cancelar.dataset.cancelar}/cancelar`, { metodo: 'POST' });
          Loja.aviso('Assinatura cancelada.', 'ok');
          recarregarTela();
        } catch (err) {
          falha(err);
        }
      }
    });
  }

  /** Amanhã (data mínima para a data final de uma assinatura), no formato AAAA-MM-DD. */
  function amanha() { const d = new Date(); d.setDate(d.getDate() + 1); return iso(d); }

  /** Define, muda ou tira a data final de uma assinatura. */
  function editarDataFinal(a) {
    dialogo('Data final da assinatura', `
      <p>${esc(a.cliente_nome)} · ${esc(a.descricao)} (${esc(a.renovacao_texto.toLowerCase())})</p>
      <form id="fFim" class="campos" novalidate style="margin-top:14px">
        <label class="c-6">Termina em <input type="date" name="data_final" min="${amanha()}" value="${esc(a.data_final || '')}"></label>
        <p class="c-12 fraco">Nesse dia a assinatura é <strong>encerrada</strong>${a.cartao_automatico ? ' e a cobrança automática no cartão para' : ''}. Cobranças que cairiam nessa data ou depois não acontecem. Deixe em branco para não ter data final. Para terminar hoje, use <strong>Cancelar</strong>.</p>
      </form>`,
    '<button type="button" class="btn btn-pequeno btn-linha" data-fechar>Voltar</button><button type="button" class="btn btn-pequeno" id="btnSalvarFim">Salvar</button>');
    $('#btnSalvarFim').addEventListener('click', async (e) => {
      const f = $('#fFim');
      e.target.disabled = true;
      try {
        const x = await api(`assinaturas/${a.id}/data-final`, { metodo: 'PUT', dados: { data_final: f.elements.data_final.value } });
        dlg.close();
        Loja.aviso(x.aviso || (f.elements.data_final.value ? 'Data final salva.' : 'Data final removida.'), x.aviso ? 'erro' : 'ok');
        recarregarTela();
      } catch (err) {
        erroDeForm(f, err);
        e.target.disabled = false;
      }
    });
  }

  // ---------------- Configurações ----------------

  // ---------------- Personalização (logotipos e cores) ----------------

  /** Cores do tema: [chave, rótulo, onde aparece]. Os padrões são os mesmos do servidor (Config::PADROES). */
  const CORES = [
    ['principal', 'Cor principal', 'Botões, faixa do topo, destaque da página inicial e rodapé.'],
    ['fundo', 'Cor de fundo', 'Fundo das páginas.'],
    ['secundaria', 'Cor secundária', 'Menu, etiquetas e áreas de apoio.'],
    ['texto', 'Cor do texto', 'Textos corridos.'],
    ['realce', 'Cor de realce', 'Detalhes e parte do nome da loja.']
  ];
  const PADRAO_CORES = { principal: '#1B2D42', fundo: '#F7F6F2', secundaria: '#E5DECF', texto: '#333333', realce: '#1FA8DD' };
  const IMAGENS_MARCA = [
    ['logo', 'Logotipo', 'Para fundo claro (topo da loja). Horizontal, até 600 px.', 'logo', false],
    ['logo-escuro', 'Logotipo para fundo escuro', 'Opcional: rodapé, painel e destaque. Sem ele, o logotipo vai sobre uma etiqueta clara.', 'logo_escuro', true],
    ['icone', 'Ícone da aba', 'Opcional, quadrado. Sem ele, o ícone é gerado do logotipo.', 'icone', false]
  ];

  /** Cartões de logotipo/ícone: prévia, enviar/trocar e remover (cada envio já grava, sem precisar salvar). */
  function desenharMarca(caixa, marca) {
    caixa.innerHTML = IMAGENS_MARCA.map(([tipo, rotulo, dica, campo, escuro]) => {
      const url = marca[campo];
      return `
        <div class="marca-cartao">
          <strong>${rotulo}</strong>
          <div class="marca-previa${escuro ? ' escura' : ''}${tipo === 'icone' ? ' quadrada' : ''}">${url ? `<img src="${esc(Loja.RAIZ + url)}?v=${Date.now()}" alt="${esc(rotulo)}">` : '<span class="fraco">Sem imagem</span>'}</div>
          <span class="dica">${dica}</span>
          <div class="marca-acoes">
            <label class="btn btn-pequeno btn-linha">${url ? 'Trocar' : 'Enviar imagem'}<input type="file" accept="image/png,image/jpeg" data-marca-enviar="${tipo}" hidden></label>
            ${url ? `<button type="button" class="link" data-marca-remover="${tipo}">Remover</button>` : ''}
          </div>
        </div>`;
    }).join('');
    const atualizar = async (resp, msg) => {
      A.loja = { ...A.loja, ...resp };
      Loja.aplicarMarca(A.loja);
      desenharMarca(caixa, resp.marca);
      Loja.aviso(msg, 'ok');
    };
    $$('[data-marca-enviar]', caixa).forEach((input) => input.addEventListener('change', async () => {
      const arquivo = input.files[0];
      if (!arquivo) return;
      if (!/^image\/(png|jpeg)$/.test(arquivo.type)) return Loja.aviso('Envie uma imagem PNG ou JPG.', 'erro');
      const fd = new FormData();
      fd.append('imagem', arquivo);
      input.closest('.marca-cartao').style.opacity = '.5';
      try {
        await atualizar(await api(`admin/marca/${input.dataset.marcaEnviar}`, { metodo: 'POST', arquivo: fd }), 'Imagem enviada.');
      } catch (err) {
        falha(err);
        input.closest('.marca-cartao').style.opacity = '';
      }
    }));
    $$('[data-marca-remover]', caixa).forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Remover esta imagem?')) return;
      try {
        await atualizar(await api(`admin/marca/${b.dataset.marcaRemover}`, { metodo: 'DELETE' }), 'Imagem removida.');
      } catch (err) {
        falha(err);
      }
    }));
  }

  /** Cores: quadrado de cor e código sincronizados, com prévia ao vivo. */
  function ligarCores(f) {
    const previa = $('#previaCores');
    const pintar = () => {
      const v = (k) => f.elements[`cor_${k}`].value;
      previa.style.setProperty('--pc-principal', v('principal'));
      previa.style.setProperty('--pc-fundo', v('fundo'));
      previa.style.setProperty('--pc-secundaria', v('secundaria'));
      previa.style.setProperty('--pc-texto', v('texto'));
      previa.style.setProperty('--pc-realce', v('realce'));
    };
    $$('[data-cor]', f).forEach((seletor) => {
      const texto = f.elements[seletor.dataset.cor];
      seletor.addEventListener('input', () => { texto.value = seletor.value.toUpperCase(); pintar(); });
      texto.addEventListener('input', () => {
        if (/^#[0-9a-fA-F]{6}$/.test(texto.value)) { seletor.value = texto.value; pintar(); }
      });
    });
    $('#btnCoresPadrao').addEventListener('click', () => {
      Object.entries(PADRAO_CORES).forEach(([k, cor]) => {
        f.elements[`cor_${k}`].value = cor;
        $(`[data-cor="cor_${k}"]`, f).value = cor;
      });
      pintar();
      Loja.aviso('Cores padrão aplicadas na prévia. Clique em Salvar configurações para confirmar.', 'info');
    });
    pintar();
  }

  /** Dados da empresa: rótulo CPF/CNPJ e endereço pelo CEP. */
  function ligarEmpresa(f) {
    f.elements.empresa_tipo.addEventListener('change', () => {
      $('#rotuloDoc').textContent = f.elements.empresa_tipo.value === 'pf' ? 'CPF' : 'CNPJ';
    });
    f.elements.empresa_cep.addEventListener('input', async () => {
      const cep = Loja.digitos(f.elements.empresa_cep.value);
      if (cep.length !== 8) return;
      try {
        const e = await Loja.buscarCep(cep);
        if (e.rua) f.elements.empresa_rua.value = e.rua;
        if (e.bairro) f.elements.empresa_bairro.value = e.bairro;
        f.elements.empresa_cidade.value = e.cidade;
        f.elements.empresa_uf.value = e.estado;
        (e.rua ? f.elements.empresa_numero : f.elements.empresa_rua).focus();
      } catch { /* CEP não encontrado: preenche à mão */ }
    });
  }

  async function telaConfiguracoes(el) {
    const [r, adm] = await Promise.all([api('admin/configuracoes'), api('admin/administradores')]);
    const c = r.configuracoes;
    const campo = (nome, rotulo, extra = '', classe = 'c-6') => `<label class="${classe}">${rotulo} <input name="${nome}" value="${esc(c[nome] ?? '')}" ${extra}></label>`;
    const dinheiro = (nome, rotulo, dica) => `<label class="c-4">${rotulo} <input name="${nome}" inputmode="decimal" value="${reaisCampo(c[nome])}"><span class="dica">${dica}</span></label>`;
    const inteiro = (nome, rotulo, min, max, dica) => `<label class="c-4">${rotulo} <input name="${nome}" type="number" min="${min}" max="${max}" value="${esc(c[nome])}"><span class="dica">${dica}</span></label>`;
    const secreto = (nome, rotulo) => `<label class="c-12">${rotulo} <input name="${nome}" autocomplete="off" placeholder="${c[nome] ? `Atual: ${esc(c[nome])} (deixe em branco para manter)` : 'Cole aqui'}"></label>`;
    el.innerHTML = `
      <form id="fConfig" class="config-grade" novalidate>
        <section class="bloco">
          <h2>Loja</h2>
          <p class="bloco-sub">Textos e contatos que aparecem na página da loja.</p>
          <div class="campos">
            ${campo('loja_nome', 'Nome da loja', 'required maxlength="80"')}
            ${campo('loja_url', 'Endereço da loja', 'placeholder="https://www.odinfocus.com.br/loja/"')}
            ${campo('loja_titulo', 'Título principal', 'maxlength="150"', 'c-12')}
            <label class="c-12">Subtítulo <textarea name="loja_subtitulo" maxlength="300" style="min-height:70px">${esc(c.loja_subtitulo)}</textarea></label>
            <label class="c-12">Sobre a loja <textarea name="loja_sobre" maxlength="2000">${esc(c.loja_sobre)}</textarea></label>
            ${campo('aviso_topo', 'Aviso da faixa do topo', 'maxlength="150"', 'c-12')}
            ${campo('loja_email', 'E-mail de contato', 'type="email"', 'c-4')}
            ${campo('loja_whatsapp', 'WhatsApp (com DDD)', 'inputmode="tel"', 'c-4')}
            ${campo('loja_instagram', 'Instagram', 'placeholder="@odinfocus"', 'c-4')}
            ${campo('loja_telefone', 'Telefone fixo (com DDD)', 'inputmode="tel" placeholder="Opcional"', 'c-4')}
            ${campo('loja_facebook', 'Facebook', 'placeholder="Endereço da página (opcional)"', 'c-8')}
          </div>
        </section>

        <section class="bloco" id="blocoIdentidade">
          <h2>Personalização · Logotipos</h2>
          <p class="bloco-sub">Imagens PNG ou JPG. Elas aparecem na loja, no painel e nos e-mails. Sem logotipo, a loja usa a inicial do nome.</p>
          <div class="marca-grade" id="marcaGrade"></div>
          <div class="campos" style="margin-top:14px">
            <label class="c-12 marcar"><input type="checkbox" name="marca_mostrar_nome" ${c.marca_mostrar_nome !== '0' ? 'checked' : ''}> Mostrar o nome da loja ao lado do logotipo</label>
          </div>
        </section>

        <section class="bloco">
          <h2>Personalização · Cores</h2>
          <p class="bloco-sub">A paleta vale para a loja, o painel e os e-mails. Clique no quadrado para escolher a cor ou digite o código (#RRGGBB).</p>
          <div class="campos cores-grade">
            ${CORES.map(([k, rotulo, dica]) => `
              <label class="c-4 cor-campo">${rotulo}
                <span class="cor-linha"><input type="color" data-cor="cor_${k}" value="${esc(c[`cor_${k}`] || PADRAO_CORES[k])}"><input name="cor_${k}" value="${esc(c[`cor_${k}`] || PADRAO_CORES[k])}" maxlength="7" pattern="#[0-9A-Fa-f]{6}"></span>
                <span class="dica">${dica}</span>
              </label>`).join('')}
          </div>
          <div class="previa-cores" id="previaCores" aria-hidden="true">
            <div class="pc-topo"><span class="pc-logo"></span><span class="pc-nome">${esc(c.loja_nome || 'Minha Loja')}</span></div>
            <div class="pc-corpo"><strong>Título da loja</strong><p>Texto de exemplo com um <span class="pc-realce">destaque</span>.</p><span class="pc-botao">Comprar</span><span class="pc-etiqueta">Categoria</span></div>
          </div>
          <button type="button" class="link" id="btnCoresPadrao">Voltar às cores padrão</button>
        </section>

        <section class="bloco">
          <h2>Dados da empresa</h2>
          <p class="bloco-sub">Aparecem no rodapé da loja e nos e-mails. A lei do comércio eletrônico (Decreto 7.962/2013) exige nome, CPF/CNPJ e endereço de quem vende.</p>
          <div class="campos">
            <label class="c-4">Tipo <select name="empresa_tipo"><option value="pj" ${c.empresa_tipo !== 'pf' ? 'selected' : ''}>Pessoa jurídica (CNPJ)</option><option value="pf" ${c.empresa_tipo === 'pf' ? 'selected' : ''}>Pessoa física (CPF)</option></select></label>
            <label class="c-4"><span id="rotuloDoc">${c.empresa_tipo === 'pf' ? 'CPF' : 'CNPJ'}</span> <input name="empresa_documento" inputmode="numeric" value="${esc(c.empresa_documento || '')}"></label>
            ${campo('empresa_ie', 'Inscrição estadual', 'maxlength="30" placeholder="Opcional"', 'c-4')}
            ${campo('empresa_razao_social', 'Razão social / nome completo', 'maxlength="150"', 'c-8')}
            ${campo('empresa_responsavel', 'Responsável', 'maxlength="120"', 'c-4')}
            <label class="c-4">CEP <input name="empresa_cep" inputmode="numeric" value="${esc(c.empresa_cep || '')}"></label>
            ${campo('empresa_rua', 'Rua', 'maxlength="150"', 'c-8')}
            ${campo('empresa_numero', 'Número', 'maxlength="20"', 'c-4')}
            ${campo('empresa_complemento', 'Complemento', 'maxlength="80"', 'c-4')}
            ${campo('empresa_bairro', 'Bairro', 'maxlength="100"', 'c-4')}
            ${campo('empresa_cidade', 'Cidade', 'maxlength="100"', 'c-8')}
            <label class="c-4">Estado <select name="empresa_uf"><option value="">UF</option>${Loja.UFS.map((uf) => `<option ${c.empresa_uf === uf ? 'selected' : ''}>${uf}</option>`).join('')}</select></label>
          </div>
        </section>

        <section class="bloco">
          <h2>Vendas e frete</h2>
          <p class="bloco-sub">O frete é cobrado só em pedidos com produtos. Deixe 0 para não cobrar.</p>
          <div class="campos">
            ${dinheiro('frete_valor', 'Frete fixo (R$)', 'Valor por pedido com produtos.')}
            ${dinheiro('frete_gratis_acima', 'Frete grátis acima de (R$)', '0 = nunca grátis.')}
            ${inteiro('max_parcelas', 'Parcelas no cartão (máx.)', 1, 24, 'Os juros seguem sua conta do Mercado Pago.')}
            ${inteiro('dias_expiracao_pedido', 'Cancelar pedidos sem pagamento após (dias)', 1, 60, 'Boletos ainda no prazo são mantidos.')}
            ${inteiro('dias_antecedencia_renovacao', 'Enviar cobrança de renovação (dias antes)', 0, 30, 'Para serviços recorrentes.')}
            ${inteiro('estoque_minimo', 'Alerta de estoque baixo (unidades)', 0, 100000, 'Aparece no painel financeiro.')}
          </div>
        </section>

        ${r.tecnico ? `
        <section class="bloco">
          <h2>Mercado Pago</h2>
          <p class="bloco-sub">Pix, boleto, cartão de crédito e débito são processados pelo Mercado Pago.</p>
          <ol class="passo-a-passo">
            <li>Entre em <a href="https://www.mercadopago.com.br/developers/panel/app" target="_blank" rel="noopener">Mercado Pago Developers › Suas integrações</a> e crie uma aplicação (Pagamentos on-line › Checkout Transparente).</li>
            <li>Em <strong>Credenciais de produção</strong>, copie a <strong>Public Key</strong> e o <strong>Access Token</strong> e cole abaixo.</li>
            <li>Em <strong>Webhooks</strong>, cole o endereço abaixo, marque o evento <strong>Pagamentos</strong>, salve e copie a <strong>assinatura secreta</strong>.</li>
            <li>Para receber Pix, cadastre uma chave Pix na sua conta do Mercado Pago.</li>
          </ol>
          <div class="copiavel" style="margin:14px 0"><code id="urlWebhook">${esc(r.webhook_url)}</code><button type="button" class="btn btn-pequeno btn-linha" data-copiar="#urlWebhook">Copiar</button></div>
          <div class="campos">
            <label class="c-12">Public Key <input name="mp_public_key" autocomplete="off" value="${esc(c.mp_public_key)}" placeholder="APP_USR-..."></label>
            ${secreto('mp_access_token', 'Access Token')}
            ${secreto('mp_webhook_secret', 'Assinatura secreta do webhook')}
          </div>
        </section>

        <section class="bloco">
          <h2>Mercado Pago · Serviços</h2>
          <p class="bloco-sub">Aplicação separada (na mesma conta do Mercado Pago) para os pedidos de serviços. Sem ela, os serviços usam a aplicação acima. As <strong>assinaturas</strong> usam o Asaas (abaixo) quando ele estiver configurado.</p>
          <ol class="passo-a-passo">
            <li>Na aplicação de serviços, em <strong>Credenciais de produção</strong>, copie a <strong>Public Key</strong> e o <strong>Access Token</strong> e cole abaixo.</li>
            <li>Em <strong>Webhooks</strong>, cole o endereço abaixo e marque os eventos <strong>Pagamentos</strong> e <strong>Planos e assinaturas</strong> (assinaturas e pagamentos recorrentes). Salve e copie a <strong>assinatura secreta</strong>.</li>
          </ol>
          <div class="copiavel" style="margin:14px 0"><code id="urlWebhookServ">${esc(r.webhook_url_servicos)}</code><button type="button" class="btn btn-pequeno btn-linha" data-copiar="#urlWebhookServ">Copiar</button></div>
          <div class="campos">
            <label class="c-12">Public Key <input name="mp_serv_public_key" autocomplete="off" value="${esc(c.mp_serv_public_key)}" placeholder="APP_USR-..."></label>
            ${secreto('mp_serv_access_token', 'Access Token')}
            ${secreto('mp_serv_webhook_secret', 'Assinatura secreta do webhook')}
          </div>
        </section>

        <section class="bloco">
          <h2>Asaas · Assinaturas no cartão</h2>
          <p class="bloco-sub">Serviços recorrentes são cobrados pelo Asaas com <strong>renovação automática no cartão de crédito</strong>: o primeiro período na hora e depois a cada mês, trimestre, semestre ou ano. Se o cartão recusar, o Asaas tenta de novo no dia do vencimento. Assinaturas antigas do Mercado Pago continuam como estão até terminarem.</p>
          <ol class="passo-a-passo">
            <li>Comece pelo ambiente de <strong>teste</strong>: crie uma conta em <a href="https://sandbox.asaas.com" target="_blank" rel="noopener">sandbox.asaas.com</a>. Para valer, use a conta de <a href="https://www.asaas.com" target="_blank" rel="noopener">asaas.com</a> e mude o ambiente para Produção.</li>
            <li>No Asaas, em <strong>Integrações › Chaves de API</strong>, gere uma chave e cole abaixo.</li>
            <li>Em <strong>Integrações › Webhooks</strong>, crie um webhook com o endereço abaixo, API <strong>v3</strong>, fila de sincronização ativada e um <strong>token de autenticação</strong> (cole o mesmo token abaixo). Marque os eventos de <strong>cobranças</strong>.</li>
          </ol>
          <div class="copiavel" style="margin:14px 0"><code id="urlWebhookAsaas">${esc(r.webhook_url_asaas)}</code><button type="button" class="btn btn-pequeno btn-linha" data-copiar="#urlWebhookAsaas">Copiar</button></div>
          <div class="campos">
            <label class="c-4">Ambiente
              <select name="asaas_ambiente">
                <option value="sandbox" ${c.asaas_ambiente !== 'producao' ? 'selected' : ''}>Teste (sandbox)</option>
                <option value="producao" ${c.asaas_ambiente === 'producao' ? 'selected' : ''}>Produção</option>
              </select>
            </label>
            ${secreto('asaas_api_key', 'Chave de API')}
            ${secreto('asaas_webhook_token', 'Token de autenticação do webhook')}
          </div>
        </section>
        ` : `
        <section class="bloco">
          <h2>Pagamentos</h2>
          <p class="bloco-sub">${r.pagamentos_configurados ? 'Os pagamentos (Mercado Pago e Asaas) já estão configurados.' : 'Os pagamentos ainda não foram configurados.'} As chaves de pagamento são mantidas pelo <strong>suporte técnico</strong> da loja.</p>
        </section>`}

        <section class="bloco">
          <h2>Formulário de contato (EmailJS)</h2>
          <p class="bloco-sub">As mensagens da página <strong>Fale conosco</strong> chegam no seu e-mail pelo EmailJS. Sem as três chaves, a página mostra só WhatsApp, e-mail e Instagram.</p>
          <ol class="passo-a-passo">
            <li>Em <a href="https://dashboard.emailjs.com/admin" target="_blank" rel="noopener">EmailJS › Email Services</a>, copie o <strong>Service ID</strong>.</li>
            <li>Em <strong>Email Templates</strong>, crie um modelo usando as variáveis <code>{{nome}}</code>, <code>{{email}}</code>, <code>{{telefone}}</code>, <code>{{assunto}}</code>, <code>{{mensagem}}</code> e <code>{{loja}}</code>. Em <strong>Reply To</strong>, coloque <code>{{email}}</code>. Copie o <strong>Template ID</strong>.</li>
            <li>Em <strong>Account › General</strong>, copie a <strong>Public Key</strong>.</li>
          </ol>
          <div class="campos">
            ${campo('emailjs_service_id', 'Service ID', 'autocomplete="off" placeholder="service_..."', 'c-4')}
            ${campo('emailjs_template_id', 'Template ID', 'autocomplete="off" placeholder="template_..."', 'c-4')}
            ${campo('emailjs_public_key', 'Public Key', 'autocomplete="off"', 'c-4')}
          </div>
        </section>

        <section class="bloco">
          <h2>Abas da loja</h2>
          <p class="bloco-sub">Desabilitada, a aba some da loja por completo: menu, página inicial, categorias, busca, rodapé e carrinho. O cadastro continua aqui no painel e você ainda pode vender por <strong>Novo link de pagamento</strong>.</p>
          <div class="campos">
            ${r.modulos.map((m) => `<label class="c-6 marcar"><input type="checkbox" name="aba_${m.tipo}" ${m.aba ? 'checked' : ''}> Mostrar a aba <strong>${esc(m.nome)}</strong> na loja</label>`).join('')}
          </div>
        </section>

        <section class="bloco">
          <h2>Módulos</h2>
          <p class="bloco-sub">Cada módulo funciona sozinho. Desligado, some da loja e não aceita novas vendas (nem por link), mas os dados e as vendas antigas continuam aqui.</p>
          <div class="campos">
            ${r.modulos.map((m) => `<label class="c-6 marcar"><input type="checkbox" name="modulo_${m.tipo}" ${m.ligado ? 'checked' : ''}> ${esc(m.nome)}</label>`).join('')}
          </div>
        </section>

        <div><button type="submit" class="btn">Salvar configurações</button></div>
      </form>

      <section class="bloco">
        <h2>Rotina diária</h2>
        <p class="bloco-sub">Cancela pedidos sem pagamento e envia as cobranças de renovação. Última execução: ${r.ultima_tarefa_diaria ? Loja.dataHora(r.ultima_tarefa_diaria) : 'nunca'}.</p>
        <p class="fraco">No cPanel, em <strong>Trabalhos Cron</strong>, crie uma tarefa “Uma vez por dia” com o comando:</p>
        <div class="copiavel" style="margin:10px 0 14px"><code id="cmdCron">${esc(r.comando_cron)}</code><button type="button" class="btn btn-pequeno btn-linha" data-copiar="#cmdCron">Copiar</button></div>
        <button type="button" class="btn btn-pequeno btn-linha" id="btnTarefas">Executar agora</button>
      </section>

      <section class="bloco">
        <h2>Administradores</h2>
        <p class="bloco-sub">Pessoas com acesso a este painel. <strong>Técnico</strong>: instala e mantém a loja (vê as chaves de pagamento). <strong>Administrador</strong>: cuida da loja no dia a dia.</p>
        <div class="tabela-caixa">${tabela(['Nome', 'E-mail', 'Perfil', 'Último acesso', ''], adm.administradores.map((a) => `
          <tr><td>${esc(a.nome)}</td><td>${esc(a.email)}</td><td>${a.perfil === 'tecnico' ? 'Técnico' : 'Administrador'}</td><td>${a.ultimo_acesso ? Loja.dataHora(a.ultimo_acesso) : '—'}</td>
          <td>${a.id === A.admin.id ? '<span class="fraco">você</span>' : (a.perfil === 'tecnico' && !r.tecnico ? '' : `<button type="button" class="link" data-remover-adm="${a.id}">Remover</button>`)}</td></tr>`))}</div>
        <form id="fAdm" class="campos" style="margin-top:16px" novalidate>
          <label class="c-4">Nome <input name="nome" required></label>
          <label class="c-4">E-mail <input name="email" type="email" required></label>
          <label class="c-4">Senha (mín. 8) <input name="senha" type="password" minlength="8" required autocomplete="new-password"></label>
          ${r.tecnico ? '<label class="c-4">Perfil <select name="perfil"><option value="administrador">Administrador</option><option value="tecnico">Técnico</option></select></label>' : ''}
          <div class="c-12"><button type="submit" class="btn btn-pequeno btn-linha">Adicionar administrador</button></div>
        </form>
      </section>

      <section class="bloco">
        <h2>Minha senha</h2>
        <form id="fSenha" class="campos" novalidate>
          <label class="c-4">Senha atual <input name="senha_atual" type="password" required autocomplete="current-password"></label>
          <label class="c-4">Nova senha <input name="senha_nova" type="password" minlength="8" required autocomplete="new-password"></label>
          <label class="c-4">Repita a nova senha <input name="senha_repetida" type="password" minlength="8" required autocomplete="new-password"></label>
          <div class="c-12"><button type="submit" class="btn btn-pequeno btn-linha">Alterar senha</button></div>
        </form>
      </section>`;

    $$('[data-copiar]', el).forEach((b) => b.addEventListener('click', async () => {
      if (await Loja.copiar($(b.dataset.copiar).textContent)) Loja.aviso('Copiado!', 'ok');
    }));
    desenharMarca($('#marcaGrade'), r.identidade.marca);
    ligarCores($('#fConfig'));
    ligarEmpresa($('#fConfig'));

    $('#fConfig').addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = e.target;
      const d = Object.fromEntries(new FormData(f));
      d.marca_mostrar_nome = f.elements.marca_mostrar_nome.checked;
      r.modulos.forEach((m) => {
        d[`modulo_${m.tipo}`] = f.elements[`modulo_${m.tipo}`].checked;
        d[`aba_${m.tipo}`] = f.elements[`aba_${m.tipo}`].checked;
      });
      try {
        await api('admin/configuracoes', { metodo: 'PUT', dados: d });
        await carregarLoja();
        Loja.aviso('Configurações salvas.', 'ok');
        ir();
      } catch (err) {
        erroDeForm(f, err);
      }
    });

    $('#btnTarefas').addEventListener('click', async (e) => {
      e.target.disabled = true;
      try {
        const x = await api('admin/tarefas', { metodo: 'POST' });
        dialogo('Rotina diária', `<ul>${x.mensagens.map((m) => `<li>${esc(m)}</li>`).join('')}</ul>`, '<button type="button" class="btn btn-pequeno" data-fechar>Fechar</button>');
      } catch (err) {
        falha(err);
      } finally {
        e.target.disabled = false;
      }
    });

    $('#fAdm').addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        await api('admin/administradores', { metodo: 'POST', dados: Object.fromEntries(new FormData(e.target)) });
        Loja.aviso('Administrador adicionado.', 'ok');
        ir();
      } catch (err) {
        erroDeForm(e.target, err);
      }
    });
    $$('[data-remover-adm]', el).forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Remover o acesso desta pessoa ao painel?')) return;
      try {
        await api(`admin/administradores/${b.dataset.removerAdm}`, { metodo: 'DELETE' });
        ir();
      } catch (err) {
        falha(err);
      }
    }));

    $('#fSenha').addEventListener('submit', async (e) => {
      e.preventDefault();
      const d = Object.fromEntries(new FormData(e.target));
      if (d.senha_nova !== d.senha_repetida) return erroDeForm(e.target, new Loja.Erro('As duas senhas novas não são iguais.', 422, 'senha_repetida'));
      try {
        await api('admin/senha', { metodo: 'PUT', dados: d });
        e.target.reset();
        Loja.aviso('Senha alterada.', 'ok');
      } catch (err) {
        erroDeForm(e.target, err.campo === 'senha' ? new Loja.Erro(err.message, err.status, 'senha_nova') : err);
      }
    });
  }

  // ---------------- Início ----------------

  (async () => {
    try {
      const r = await Loja.api('admin/sessao');
      if (r.admin) await entrar(r.admin);
      else mostrarLogin();
    } catch (e) {
      mostrarLogin(e.status === 503 ? 'A loja ainda não foi instalada. Abra o instalar.php.' : e.message);
    }
  })();
})();
