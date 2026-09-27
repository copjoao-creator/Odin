/* Loja Odin Focus: vitrine, carrinho e checkout */
(() => {
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const esc = Loja.esc;
  const brl = Loja.brl;

  const estado = {
    loja: null,
    produtos: [],
    servicos: [],
    categoria: '',
    subcategoria: '',
    busca: '',
    ordem: 'padrao',
    carrinho: Loja.guardar.ler('loja.carrinho', []),
    pedido: null,
    carrinhoDoPedido: ''
  };

  $$('[data-icone]').forEach((el) => { el.innerHTML = Loja.icone(el.dataset.icone); });
  $$('[data-marca]').forEach((el) => { el.innerHTML = Loja.marca(); });
  $('#ano').textContent = new Date().getFullYear();

  // ---------------- Catálogo ----------------

  const lista = (tipo) => (tipo === 'produto' ? estado.produtos : estado.servicos);
  const acharItem = (tipo, codigo) => lista(tipo).find((i) => i.codigo === codigo);
  const fotoDe = (i) => (i.fotos ? i.fotos[0] : i.foto) || null;
  const limite = (tipo, i) => (tipo === 'servico' ? (i.recorrente ? 1 : 99) : i.estoque);
  const centavos = (v) => Math.round(Number(v) * 100);

  const imagem = (url, alt, classe = '') => (url
    ? `<img src="${esc(url)}" alt="${esc(alt)}" loading="lazy" class="${classe}">`
    : `<span class="sem-foto ${classe}" role="img" aria-label="${esc(alt)}">${Loja.marca()}</span>`);

  const precoHtml = (i) => (i.recorrente
    ? `${brl(i.preco)}<small>${Loja.SUFIXO_RENOVACAO[i.renovacao] || ''}</small>`
    : brl(i.preco));

  const parcelasHtml = () => {
    const n = estado.loja ? estado.loja.max_parcelas : 12;
    return n > 1 ? `<p class="card-parcela">Parcele em até ${n}x no cartão</p>` : '';
  };

  async function iniciar() {
    try {
      const [l, p, s] = await Promise.all([
        Loja.api('loja'),
        Loja.api('produtos/vitrine').catch(() => ({ produtos: [] })),
        Loja.api('servicos/vitrine').catch(() => ({ servicos: [] }))
      ]);
      estado.loja = l.loja;
      estado.produtos = p.produtos || [];
      estado.servicos = s.servicos || [];
    } catch (e) {
      $('#gradeProdutos').innerHTML = `<p class="vazio">${e.status === 503 ? 'Nossa loja está sendo preparada. Volte em breve!' : esc(e.message)}</p>`;
      $('#servicos').hidden = true;
      return;
    }
    aplicarLoja();
    validarCarrinho();
    renderMenu();
    renderCategorias();
    renderChips();
    renderProdutos();
    renderServicos();
    renderCarrinho();
  }

  function aplicarLoja() {
    const l = estado.loja;
    document.title = `${l.nome} · Loja oficial`;
    $('#avisoTopo').textContent = l.aviso_topo || '';
    $('#logoNome').textContent = l.nome.toUpperCase();
    $('#rodapeNome').textContent = l.nome.toUpperCase();
    $('#rodapeCopy').textContent = l.nome;
    $('#heroSobretitulo').textContent = l.nome;
    $('#heroTitulo').textContent = l.titulo;
    $('#heroSubtitulo').textContent = l.subtitulo;
    $('#sobreTitulo').textContent = l.nome;
    $('#sobreTexto').textContent = l.sobre;
    $('#rodapeSobre').textContent = l.sobre;
    if (l.max_parcelas > 1) $('#benParcelas').textContent = `Parcele em até ${l.max_parcelas}x`;
    if (l.frete_gratis_acima > 0) {
      const t = `Frete grátis acima de ${brl(l.frete_gratis_acima)}`;
      $('#benFrete').textContent = t;
      $('#benFreteSub').textContent = 'Envio para todo o Brasil';
      $('#avisoFrete').textContent = t;
      $('#avisoFrete').hidden = false;
    }

    // Contatos (a página Fale conosco fica em contato.html)
    $('#rodapeContatos').innerHTML = Loja.rodapeContatos(l);
    // Produtos e Serviços aparecem sempre (vazios, mostram "em breve"); só somem com o módulo desligado no painel.
    if (l.modulos.produto === false) esconder('produtos');
    if (l.modulos.servico === false) esconder('servicos');
  }

  function esconder(secao) {
    $(`#${secao}`).hidden = true;
    $$(`a[href="#${secao}"]`).forEach((a) => { a.hidden = true; });
  }

  function categoriasDeProdutos() {
    const mapa = new Map();
    for (const p of estado.produtos) {
      if (!mapa.has(p.categoria)) mapa.set(p.categoria, { nome: p.categoria, itens: 0, foto: null, subs: new Set() });
      const c = mapa.get(p.categoria);
      c.itens++;
      if (!c.foto && p.fotos.length) c.foto = p.fotos[0];
      if (p.subcategoria) c.subs.add(p.subcategoria);
    }
    return [...mapa.values()];
  }

  function renderMenu() {
    const cats = categoriasDeProdutos().slice(0, 6);
    const ancora = $('#menu a[data-fixo="produtos"]');
    $$('#menu a[data-cat]').forEach((a) => a.remove());
    cats.reverse().forEach((c) => {
      const a = document.createElement('a');
      a.href = '#produtos';
      a.dataset.cat = c.nome;
      a.textContent = c.nome;
      ancora.after(a);
    });
  }

  function renderCategorias() {
    const cats = categoriasDeProdutos();
    if (cats.length < 2) return;
    $('#categorias').hidden = false;
    $('#gradeCategorias').innerHTML = cats.map((c) => `
      <a class="categoria" href="#produtos" data-cat="${esc(c.nome)}">
        <span class="categoria-foto">${imagem(c.foto, c.nome)}</span>
        <span class="categoria-nome">${esc(c.nome)}</span>
        <span class="categoria-qtd">${c.itens} ${c.itens === 1 ? 'item' : 'itens'}</span>
      </a>`).join('');
  }

  function renderChips() {
    const cats = categoriasDeProdutos();
    if (cats.length < 2 && !(cats[0] && cats[0].subs.size > 1)) {
      $('#chips').innerHTML = '';
      return;
    }
    const chip = (rotulo, cat, sub, ativo) =>
      `<button type="button" class="chip${ativo ? ' ativo' : ''}" data-chip-cat="${esc(cat)}" data-chip-sub="${esc(sub)}" aria-pressed="${ativo}">${esc(rotulo)}</button>`;
    let html = chip('Todos', '', '', !estado.categoria);
    html += cats.map((c) => chip(c.nome, c.nome, '', estado.categoria === c.nome && !estado.subcategoria)).join('');
    const atual = cats.find((c) => c.nome === estado.categoria);
    if (atual && atual.subs.size > 1) {
      html += `<span class="chips-sep" aria-hidden="true"></span>`
        + [...atual.subs].map((s) => chip(s, atual.nome, s, estado.subcategoria === s)).join('');
    }
    $('#chips').innerHTML = html;
  }

  function filtrar(itens) {
    const termo = estado.busca.trim().toLowerCase();
    let r = itens;
    if (termo) {
      const partes = termo.normalize('NFD').replace(/[̀-ͯ]/g, '').split(/\s+/);
      r = r.filter((i) => {
        const alvo = `${i.titulo} ${i.detalhes} ${i.categoria} ${i.subcategoria} ${i.codigo}`.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
        return partes.every((p) => alvo.includes(p));
      });
    }
    return r;
  }

  function ordenar(itens) {
    const r = [...itens];
    if (estado.ordem === 'menor') r.sort((a, b) => a.preco - b.preco);
    if (estado.ordem === 'maior') r.sort((a, b) => b.preco - a.preco);
    if (estado.ordem === 'nome') r.sort((a, b) => a.titulo.localeCompare(b.titulo, 'pt-BR'));
    return r;
  }

  function renderProdutos() {
    let itens = filtrar(estado.produtos);
    if (estado.categoria) itens = itens.filter((p) => p.categoria === estado.categoria);
    if (estado.subcategoria) itens = itens.filter((p) => p.subcategoria === estado.subcategoria);
    itens = ordenar(itens);

    $('#tituloProdutos').textContent = estado.busca ? `Resultados para “${estado.busca}”` : (estado.subcategoria || estado.categoria || 'Produtos');
    $('#subProdutos').textContent = `${itens.length} ${itens.length === 1 ? 'produto' : 'produtos'}${estado.categoria && estado.subcategoria ? ` em ${estado.categoria}` : ''}`;

    if (!itens.length) {
      $('#gradeProdutos').innerHTML = `<p class="vazio">${estado.produtos.length ? 'Nenhum produto encontrado. Tente outra busca ou categoria.' : 'Novos produtos em breve.'}</p>`;
      return;
    }
    $('#gradeProdutos').innerHTML = itens.map((p) => {
      const esgotado = p.estoque <= 0;
      const k = `produto|${esc(p.codigo)}`;
      return `
        <article class="card">
          <button type="button" class="card-foto" data-abrir="${k}" aria-label="Ver detalhes de ${esc(p.titulo)}">
            ${imagem(p.fotos[0], p.titulo)}
            ${esgotado ? '<span class="selo selo-esgotado">Esgotado</span>' : p.estoque <= 3 ? `<span class="selo">Últimas ${p.estoque}</span>` : ''}
          </button>
          <div class="card-corpo">
            <p class="card-cat">${esc(p.subcategoria || p.categoria)}</p>
            <h3 class="card-titulo"><a href="#produtos" data-abrir="${k}">${esc(p.titulo)}</a></h3>
            <p class="card-preco">${precoHtml(p)}</p>
            ${parcelasHtml()}
            <button type="button" class="btn btn-card" data-add="${k}" ${esgotado ? 'disabled' : ''}>${esgotado ? 'Esgotado' : 'Adicionar ao carrinho'}</button>
          </div>
        </article>`;
    }).join('');
  }

  function renderServicos() {
    const itens = ordenar(filtrar(estado.servicos));
    if (!itens.length) {
      $('#gradeServicos').innerHTML = `<p class="vazio">${estado.servicos.length ? 'Nenhum serviço encontrado para essa busca.' : 'Novos serviços em breve.'}</p>`;
      return;
    }
    $('#gradeServicos').innerHTML = itens.map((s) => {
      const k = `servico|${esc(s.codigo)}`;
      return `
        <article class="card-servico">
          <button type="button" class="servico-foto" data-abrir="${k}" aria-label="Ver detalhes de ${esc(s.titulo)}">${imagem(s.foto, s.titulo)}</button>
          <div class="servico-corpo">
            <p class="card-cat">${esc(s.subcategoria || s.categoria)}</p>
            <h3 class="card-titulo"><a href="#servicos" data-abrir="${k}">${esc(s.titulo)}</a></h3>
            ${s.detalhes ? `<p class="servico-detalhes">${esc(s.detalhes)}</p>` : ''}
            <div class="servico-rodape">
              <div>
                <span class="selo-renovacao${s.recorrente ? ' recorrente' : ''}">${s.recorrente ? `Assinatura ${esc(s.renovacao_texto.toLowerCase())}` : 'Pagamento único'}</span>
                <p class="card-preco">${precoHtml(s)}</p>
              </div>
              <button type="button" class="btn" data-add="${k}">${s.recorrente ? 'Assinar' : 'Contratar'}</button>
            </div>
          </div>
        </article>`;
    }).join('');
  }

  // ---------------- Detalhe do item ----------------

  const dlgItem = $('#dlgItem');

  function abrirItem(tipo, codigo) {
    const i = acharItem(tipo, codigo);
    if (!i) return;
    const fotos = tipo === 'produto' ? i.fotos : (i.foto ? [i.foto] : []);
    const max = limite(tipo, i);
    const esgotado = tipo === 'produto' && i.estoque <= 0;
    let estoqueTexto = '';
    if (tipo === 'produto') estoqueTexto = esgotado ? 'Esgotado no momento' : i.estoque <= 3 ? `Últimas ${i.estoque} unidades` : 'Em estoque';
    dlgItem.innerHTML = `
      <button type="button" class="btn-icone dlg-fechar" data-fechar aria-label="Fechar">${Loja.icone('fechar')}</button>
      <div class="item">
        <div class="item-galeria${tipo === 'servico' ? ' item-galeria-servico' : ''}">
          <div class="item-foto" id="itemFoto">${imagem(fotos[0], i.titulo)}</div>
          ${fotos.length > 1 ? `<div class="item-miniaturas">${fotos.map((f, n) => `<button type="button" class="${n ? '' : 'ativa'}" data-foto="${esc(f)}" aria-label="Foto ${n + 1}">${imagem(f, `${i.titulo} (${n + 1})`)}</button>`).join('')}</div>` : ''}
        </div>
        <div class="item-info">
          <p class="card-cat">${esc(i.categoria)}${i.subcategoria ? ` › ${esc(i.subcategoria)}` : ''}</p>
          <h2>${esc(i.titulo)}</h2>
          <p class="item-preco">${precoHtml(i)}</p>
          ${parcelasHtml()}
          ${i.detalhes ? `<div class="item-detalhes">${esc(i.detalhes)}</div>` : ''}
          ${tipo === 'servico' && i.recorrente ? `<p class="item-nota">${Loja.icone('relogio')} Assinatura ${esc(i.renovacao_texto.toLowerCase())}: cobrança automática no cartão de crédito a cada período. Cancele quando quiser falando com a loja.</p>` : ''}
          ${estoqueTexto ? `<p class="item-estoque${esgotado ? ' esgotado' : ''}">${estoqueTexto}</p>` : ''}
          <div class="item-comprar">
            ${max > 1 ? `<div class="qtd" data-max="${max}"><button type="button" data-qtd="-1" aria-label="Diminuir">${Loja.icone('menos')}</button><input type="number" value="1" min="1" max="${max}" aria-label="Quantidade"><button type="button" data-qtd="1" aria-label="Aumentar">${Loja.icone('mais')}</button></div>` : ''}
            <button type="button" class="btn btn-largo" data-add-dlg="${tipo}|${esc(i.codigo)}" ${esgotado ? 'disabled' : ''}>${esgotado ? 'Esgotado' : tipo === 'servico' ? (i.recorrente ? 'Assinar' : 'Contratar') : 'Adicionar ao carrinho'}</button>
          </div>
        </div>
      </div>`;
    dlgItem.showModal();
  }

  dlgItem.addEventListener('click', (e) => {
    if (e.target === dlgItem || e.target.closest('[data-fechar]')) return dlgItem.close();
    const mini = e.target.closest('[data-foto]');
    if (mini) {
      $('#itemFoto').innerHTML = imagem(mini.dataset.foto, '');
      $$('.item-miniaturas button', dlgItem).forEach((b) => b.classList.toggle('ativa', b === mini));
    }
    const q = e.target.closest('[data-qtd]');
    if (q) {
      const input = $('.qtd input', dlgItem);
      const max = Number($('.qtd', dlgItem).dataset.max);
      input.value = Math.min(max, Math.max(1, Number(input.value) + Number(q.dataset.qtd)));
    }
    const add = e.target.closest('[data-add-dlg]');
    if (add) {
      const [tipo, codigo] = add.dataset.addDlg.split('|');
      const input = $('.qtd input', dlgItem);
      dlgItem.close();
      adicionar(tipo, codigo, input ? Math.max(1, Number(input.value) || 1) : 1);
    }
  });

  // ---------------- Carrinho ----------------

  const salvarCarrinho = () => Loja.guardar.gravar('loja.carrinho', estado.carrinho);

  /** Remove itens que saíram do catálogo e ajusta quantidades ao estoque atual. */
  function validarCarrinho() {
    estado.carrinho = estado.carrinho.filter((c) => {
      const i = acharItem(c.tipo, c.codigo);
      if (!i) return false;
      c.quantidade = Math.min(Math.max(1, Number(c.quantidade) || 1), limite(c.tipo, i));
      return c.quantidade > 0;
    });
    salvarCarrinho();
  }

  /**
   * Produtos e serviços são pagos em pedidos separados, e cada assinatura vai sozinha no pedido
   * (a mesma regra é conferida no servidor). Devolve o motivo de não poder adicionar, ou ''.
   */
  function conflitoNoCarrinho(tipo, codigo, item) {
    const outros = estado.carrinho.filter((c) => !(c.tipo === tipo && c.codigo === codigo));
    if (!outros.length) return '';
    if (outros.some((c) => c.tipo !== tipo) && (tipo === 'servico' || outros.some((c) => c.tipo === 'servico'))) {
      return 'Produtos e serviços são pagos em pedidos separados. Finalize o pedido que está no carrinho e depois adicione este item.';
    }
    const assinatura = (c) => { const i = acharItem(c.tipo, c.codigo); return c.tipo === 'servico' && i && i.recorrente; };
    if ((tipo === 'servico' && item.recorrente) || outros.some(assinatura)) {
      return 'Assinaturas são contratadas uma por pedido. Finalize o pedido que está no carrinho e depois adicione este item.';
    }
    return '';
  }

  function adicionar(tipo, codigo, qtd = 1) {
    const i = acharItem(tipo, codigo);
    if (!i) return;
    const conflito = conflitoNoCarrinho(tipo, codigo, i);
    if (conflito) {
      Loja.aviso(conflito, 'erro');
      abrirCarrinho();
      return;
    }
    const max = limite(tipo, i);
    const linha = estado.carrinho.find((c) => c.tipo === tipo && c.codigo === codigo);
    const atual = linha ? linha.quantidade : 0;
    if (atual >= max) {
      Loja.aviso(tipo === 'servico' ? 'Esta assinatura já está no carrinho.' : `Você já adicionou todo o estoque disponível (${max}).`, 'erro');
      abrirCarrinho();
      return;
    }
    const soma = Math.min(qtd, max - atual);
    if (soma < qtd) Loja.aviso(`Adicionamos ${soma}: é o que temos em estoque.`, 'erro');
    if (linha) linha.quantidade += soma;
    else estado.carrinho.push({ tipo, codigo, quantidade: soma });
    salvarCarrinho();
    renderCarrinho();
    abrirCarrinho();
  }

  function totais() {
    let sub = 0;
    let n = 0;
    let entrega = false;
    for (const c of estado.carrinho) {
      const i = acharItem(c.tipo, c.codigo);
      if (!i) continue;
      sub += centavos(i.preco) * c.quantidade;
      n += c.quantidade;
      if (c.tipo === 'produto') entrega = true;
    }
    const l = estado.loja || { frete_valor: 0, frete_gratis_acima: 0 };
    const gratis = centavos(l.frete_gratis_acima);
    const frete = entrega && !(gratis > 0 && sub >= gratis) ? centavos(l.frete_valor) : 0;
    return { subtotal: sub / 100, frete: frete / 100, total: (sub + frete) / 100, entrega, n, faltaGratis: entrega && gratis > 0 && sub < gratis ? (gratis - sub) / 100 : 0, gratis: gratis / 100 };
  }

  function renderCarrinho() {
    const t = totais();
    $('#contador').textContent = t.n;
    $('#contador').classList.toggle('zerado', !t.n);
    $('#totalTopo').textContent = brl(t.subtotal);

    if (!estado.carrinho.length) {
      $('#carrinhoItens').innerHTML = `<div class="carrinho-vazio">${Loja.icone('carrinho', 'ico ico-grande')}<p>Seu carrinho está vazio.</p><button type="button" class="btn" data-fechar-carrinho>Ver produtos</button></div>`;
      $('#carrinhoRodape').innerHTML = '';
      return;
    }
    $('#carrinhoItens').innerHTML = estado.carrinho.map((c) => {
      const i = acharItem(c.tipo, c.codigo);
      const max = limite(c.tipo, i);
      const k = `${c.tipo}|${esc(c.codigo)}`;
      return `
        <div class="linha-carrinho">
          <span class="linha-foto">${imagem(fotoDe(i), i.titulo)}</span>
          <div class="linha-info">
            <p class="linha-titulo">${esc(i.titulo)}</p>
            <p class="linha-preco">${precoHtml(i)}</p>
            <div class="linha-acoes">
              ${max > 1 ? `<div class="qtd qtd-mini"><button type="button" data-mudar="${k}" data-delta="-1" aria-label="Diminuir">${Loja.icone('menos')}</button><span>${c.quantidade}</span><button type="button" data-mudar="${k}" data-delta="1" aria-label="Aumentar" ${c.quantidade >= max ? 'disabled' : ''}>${Loja.icone('mais')}</button></div>` : '<span class="linha-unica">1 unidade</span>'}
              <button type="button" class="link" data-remover="${k}">Remover</button>
            </div>
          </div>
          <strong class="linha-total">${brl((centavos(i.preco) * c.quantidade) / 100)}</strong>
        </div>`;
    }).join('');

    const barra = t.faltaGratis
      ? `<div class="frete-meta"><p>Faltam <strong>${brl(t.faltaGratis)}</strong> para o frete grátis</p><span class="barra"><span style="width:${Math.min(100, (1 - t.faltaGratis / t.gratis) * 100).toFixed(0)}%"></span></span></div>`
      : (t.entrega && t.gratis > 0 ? '<div class="frete-meta ok"><p>Você ganhou <strong>frete grátis</strong>!</p></div>' : '');
    $('#carrinhoRodape').innerHTML = `
      ${barra}
      <dl class="valores">
        <div><dt>Subtotal</dt><dd>${brl(t.subtotal)}</dd></div>
        <div><dt>Frete</dt><dd>${t.entrega ? (t.frete ? brl(t.frete) : 'Grátis') : 'Não se aplica'}</dd></div>
        <div class="valores-total"><dt>Total</dt><dd>${brl(t.total)}</dd></div>
      </dl>
      <button type="button" class="btn btn-largo" id="btnFinalizar">Finalizar compra</button>
      <button type="button" class="link link-centro" data-fechar-carrinho>Continuar comprando</button>`;
  }

  const gaveta = $('#gaveta');
  const fundo = $('#gavetaFundo');
  function abrirCarrinho() {
    fundo.hidden = false;
    gaveta.classList.add('aberta');
    gaveta.setAttribute('aria-hidden', 'false');
    document.body.classList.add('travado');
    gaveta.focus();
  }
  function fecharCarrinho() {
    gaveta.classList.remove('aberta');
    gaveta.setAttribute('aria-hidden', 'true');
    fundo.hidden = true;
    document.body.classList.remove('travado');
  }

  gaveta.addEventListener('click', (e) => {
    const mudar = e.target.closest('[data-mudar]');
    if (mudar) {
      const [tipo, codigo] = mudar.dataset.mudar.split('|');
      const c = estado.carrinho.find((x) => x.tipo === tipo && x.codigo === codigo);
      const i = acharItem(tipo, codigo);
      c.quantidade = Math.min(limite(tipo, i), Math.max(1, c.quantidade + Number(mudar.dataset.delta)));
      salvarCarrinho();
      renderCarrinho();
      return;
    }
    const rem = e.target.closest('[data-remover]');
    if (rem) {
      const [tipo, codigo] = rem.dataset.remover.split('|');
      estado.carrinho = estado.carrinho.filter((x) => !(x.tipo === tipo && x.codigo === codigo));
      salvarCarrinho();
      renderCarrinho();
      return;
    }
    if (e.target.closest('[data-fechar-carrinho]')) return fecharCarrinho();
    if (e.target.closest('#btnFinalizar')) abrirCheckout();
  });
  $('#btnCarrinho').addEventListener('click', abrirCarrinho);
  $('#btnFecharCarrinho').addEventListener('click', fecharCarrinho);
  fundo.addEventListener('click', fecharCarrinho);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && gaveta.classList.contains('aberta')) fecharCarrinho(); });

  // ---------------- Checkout ----------------

  const dlg = $('#dlgCheckout');
  const form = $('#formCliente');
  const alvoPagamento = $('#etapaPagamento');

  const selUf = form.elements.estado;
  Loja.UFS.forEach((uf) => selUf.add(new Option(uf, uf)));
  Loja.mascarar(form.elements.cpf, 'cpf');
  Loja.mascarar(form.elements.celular, 'celular');
  Loja.mascarar(form.elements.cep, 'cep');

  // ---------------- CPF primeiro: cliente com cadastro não digita tudo de novo ----------------
  // cadastro: 'buscando' | 'encontrado' (usa os dados do cadastro) | 'novo' (formulário) | null (sem CPF ainda)
  let cadastro = null;
  let cpfConsultado = '';
  const btnContinuar = $('#btnContinuar');

  function modoCliente(modo, resumo) {
    cadastro = modo;
    const achado = $('#cadastroAchado');
    achado.hidden = modo !== 'encontrado';
    $('#camposCliente').hidden = modo !== 'novo';
    btnContinuar.textContent = modo === 'encontrado' ? 'Continuar com estes dados' : 'Continuar para o pagamento';
    if (modo === 'encontrado') {
      achado.innerHTML = `
        <p class="cadastro-titulo">${Loja.icone('check')} Encontramos seu cadastro</p>
        <p><strong>${esc(resumo.nome)}</strong><br>${esc(resumo.email)} · ${esc(resumo.celular)}<br>${esc(resumo.endereco)}</p>
        <button type="button" class="link" id="btnAtualizarDados">Atualizar meus dados</button>`;
      $('#btnAtualizarDados').addEventListener('click', () => {
        modoCliente('novo');
        $('#dicaCpf').textContent = 'Preencha seus dados atualizados. Use o mesmo e-mail do cadastro.';
        form.elements.nome.focus();
      });
    }
  }

  async function identificarCpf() {
    const cpf = Loja.digitos(form.elements.cpf.value);
    const dica = $('#dicaCpf');
    if (cpf.length !== 11) {
      if (cadastro) modoCliente(null);
      cpfConsultado = '';
      dica.textContent = 'Comece pelo CPF. Se você já comprou aqui, usamos o seu cadastro.';
      return;
    }
    if (cpf === cpfConsultado) return;
    if (!Loja.cpfValido(cpf)) {
      modoCliente(null);
      dica.textContent = 'CPF inválido. Confira os números.';
      return;
    }
    cpfConsultado = cpf;
    cadastro = 'buscando';
    dica.textContent = 'Procurando seu cadastro…';
    try {
      const r = await Loja.api('clientes/identificar', { metodo: 'POST', dados: { cpf } });
      if (Loja.digitos(form.elements.cpf.value) !== cpf) return; // o CPF mudou enquanto consultava
      if (r.cadastrado) {
        modoCliente('encontrado', r.resumo);
        dica.textContent = 'Confira se são os seus dados.';
        btnContinuar.focus();
      } else {
        modoCliente('novo');
        dica.textContent = 'Primeira compra? Preencha seus dados.';
        form.elements.nome.focus();
      }
    } catch (err) {
      // Sem resposta (ou limite de consultas): segue pelo formulário normal.
      modoCliente('novo');
      dica.textContent = err.status === 429 ? err.message : 'Preencha seus dados.';
    }
  }
  form.elements.cpf.addEventListener('input', identificarCpf);

  form.elements.cep.addEventListener('input', async () => {
    const cep = Loja.digitos(form.elements.cep.value);
    if (cep.length !== 8) return;
    const dica = $('#dicaCep');
    dica.textContent = 'Buscando endereço…';
    try {
      const e = await Loja.buscarCep(cep);
      if (e.rua) form.elements.rua.value = e.rua;
      if (e.bairro) form.elements.bairro.value = e.bairro;
      form.elements.cidade.value = e.cidade;
      form.elements.estado.value = e.estado;
      dica.textContent = 'Endereço encontrado. Confira e informe o número.';
      (e.rua ? form.elements.numero : form.elements.rua).focus();
    } catch (err) {
      dica.textContent = err.message;
    }
  });

  function etapa(n) {
    $$('#etapas li').forEach((li, i) => {
      li.classList.toggle('ativa', i === n - 1);
      li.classList.toggle('feita', i < n - 1);
    });
  }

  function renderResumo() {
    const p = estado.pedido;
    let linhas;
    let rodape;
    if (p) {
      linhas = p.itens.map((i) => `<li><span>${i.quantidade}× ${esc(i.descricao)}</span><span>${brl(i.preco_unitario * i.quantidade)}</span></li>`).join('');
      rodape = { subtotal: p.subtotal, frete: p.frete, total: p.total, entrega: p.precisa_entrega };
    } else {
      linhas = estado.carrinho.map((c) => {
        const i = acharItem(c.tipo, c.codigo);
        return `<li><span>${c.quantidade}× ${esc(i.titulo)}</span><span>${brl((centavos(i.preco) * c.quantidade) / 100)}</span></li>`;
      }).join('');
      rodape = totais();
    }
    $('#resumo').innerHTML = `
      <h3>${p ? `Pedido #${p.id}` : 'Resumo do pedido'}</h3>
      <ul class="resumo-itens">${linhas}</ul>
      <dl class="valores">
        <div><dt>Subtotal</dt><dd>${brl(rodape.subtotal)}</dd></div>
        <div><dt>Frete</dt><dd>${rodape.entrega ? (rodape.frete ? brl(rodape.frete) : 'Grátis') : 'Não se aplica'}</dd></div>
        <div class="valores-total"><dt>Total</dt><dd>${brl(rodape.total)}</dd></div>
      </dl>
      <p class="resumo-seguro">${Loja.icone('escudo')} Compra protegida pelo Mercado Pago</p>`;
  }

  function abrirCheckout() {
    if (!estado.carrinho.length) return;
    fecharCarrinho();
    const atual = JSON.stringify(estado.carrinho);
    if (!(estado.pedido && estado.carrinhoDoPedido === atual && estado.pedido.status === 'aguardando_pagamento')) estado.pedido = null;
    const soServicos = estado.carrinho.every((c) => c.tipo === 'servico');
    $('#tituloEndereco').textContent = soServicos ? 'Endereço de cobrança' : 'Endereço de entrega';
    $('#erroCliente').hidden = true;
    renderResumo();
    dlg.showModal();
    if (estado.pedido) mostrarPagamento();
    else mostrarFormulario();
  }

  function mostrarFormulario() {
    etapa(1);
    form.hidden = false;
    alvoPagamento.hidden = true;
    (cadastro === 'novo' ? form.elements.nome : form.elements.cpf).focus();
  }

  function mostrarPagamento() {
    etapa(2);
    form.hidden = true;
    alvoPagamento.hidden = false;
    renderResumo();
    LojaPagamento.montar(alvoPagamento, {
      loja: estado.loja,
      pedido: estado.pedido,
      aoMudar: pedidoMudou,
      voltar: { texto: 'Voltar à loja', acao: fecharCheckout }
    });
    if (estado.pedido.status === 'pago') etapa(3);
  }

  function pedidoMudou(pedido, pagamento) {
    estado.pedido = pedido;
    const concluiu = pedido.status !== 'aguardando_pagamento' || (pagamento && pagamento.status !== 'rejected');
    if (concluiu && estado.carrinho.length) {
      estado.carrinho = [];
      estado.carrinhoDoPedido = '';
      salvarCarrinho();
      renderCarrinho();
    }
    if (pedido.status === 'pago' || pedido.status === 'em_analise') etapa(3);
    renderResumo();
  }

  function fecharCheckout() {
    LojaPagamento.desmontar();
    if (dlg.open) dlg.close();
  }

  dlg.addEventListener('close', () => {
    LojaPagamento.desmontar();
    if (estado.pedido && estado.pedido.status !== 'aguardando_pagamento') estado.pedido = null;
  });
  $('#btnFecharCheckout').addEventListener('click', fecharCheckout);

  function erroNoCampo(msg, campo) {
    const caixa = $('#erroCliente');
    caixa.textContent = msg;
    caixa.hidden = false;
    $$('.invalido', form).forEach((el) => el.classList.remove('invalido'));
    const el = campo && form.elements[campo];
    if (el) {
      el.classList.add('invalido');
      el.focus();
    }
  }

  function validarLocal(d) {
    if (d.nome.trim().split(/\s+/).length < 2) return ['Informe nome e sobrenome.', 'nome'];
    if (!Loja.cpfValido(d.cpf)) return ['CPF inválido. Confira os números.', 'cpf'];
    if (!/^[1-9]{2}9\d{8}$/.test(Loja.digitos(d.celular))) return ['Celular inválido: DDD + número com 9 dígitos.', 'celular'];
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(d.email.trim())) return ['E-mail inválido.', 'email'];
    if (Loja.digitos(d.cep).length !== 8) return ['CEP inválido.', 'cep'];
    for (const [c, r] of [['rua', 'a rua'], ['numero', 'o número'], ['bairro', 'o bairro'], ['cidade', 'a cidade'], ['estado', 'o estado']]) {
      if (!String(d[c] || '').trim()) return [`Informe ${r}.`, c];
    }
    return null;
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const dados = Object.fromEntries(new FormData(form));
    if (!Loja.cpfValido(dados.cpf)) return erroNoCampo('Informe um CPF válido.', 'cpf');
    if (cadastro === null || cadastro === 'buscando') return identificarCpf();
    const usarCadastro = cadastro === 'encontrado';
    if (!usarCadastro) {
      const problema = validarLocal(dados);
      if (problema) return erroNoCampo(...problema);
    }
    $('#erroCliente').hidden = true;
    const btn = btnContinuar;
    const texto = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Criando seu pedido…';
    try {
      const corpo = usarCadastro
        ? { cliente: { cpf: dados.cpf }, usar_cadastro: true, itens: estado.carrinho }
        : { cliente: dados, itens: estado.carrinho };
      const r = await Loja.api('pedidos', { metodo: 'POST', dados: corpo });
      estado.pedido = r.pedido;
      estado.carrinhoDoPedido = JSON.stringify(estado.carrinho);
      mostrarPagamento();
    } catch (err) {
      // CPF do cadastro sumiu (ou erro no cadastro): volta para o formulário completo.
      if (usarCadastro && err.status === 404) modoCliente('novo');
      erroNoCampo(err.message, err.campo);
    } finally {
      btn.disabled = false;
      btn.textContent = texto;
    }
  });

  // ---------------- Navegação, busca e filtros ----------------

  function irPara(id) {
    const el = document.getElementById(id);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  document.addEventListener('click', (e) => {
    const abrir = e.target.closest('[data-abrir]');
    if (abrir) {
      e.preventDefault();
      const [tipo, codigo] = abrir.dataset.abrir.split('|');
      return abrirItem(tipo, codigo);
    }
    const add = e.target.closest('[data-add]');
    if (add) {
      const [tipo, codigo] = add.dataset.add.split('|');
      return adicionar(tipo, codigo, 1);
    }
    const cat = e.target.closest('[data-cat]');
    if (cat) {
      e.preventDefault();
      estado.categoria = cat.dataset.cat;
      estado.subcategoria = '';
      renderChips();
      renderProdutos();
      irPara('produtos');
      return;
    }
    const chip = e.target.closest('[data-chip-cat]');
    if (chip) {
      estado.categoria = chip.dataset.chipCat;
      estado.subcategoria = chip.dataset.chipSub;
      renderChips();
      renderProdutos();
    }
    const fixo = e.target.closest('#menu a[data-fixo="produtos"]');
    if (fixo) {
      estado.categoria = '';
      estado.subcategoria = '';
      renderChips();
      renderProdutos();
    }
  });

  let tempoBusca;
  $('#busca').addEventListener('input', () => {
    clearTimeout(tempoBusca);
    tempoBusca = setTimeout(() => {
      estado.busca = $('#busca').value.trim();
      renderProdutos();
      renderServicos();
    }, 200);
  });
  $('#formBusca').addEventListener('submit', (e) => {
    e.preventDefault();
    estado.busca = $('#busca').value.trim();
    estado.categoria = '';
    estado.subcategoria = '';
    renderChips();
    renderProdutos();
    renderServicos();
    irPara(filtrar(estado.produtos).length || !estado.servicos.length ? 'produtos' : 'servicos');
  });
  $('#ordem').addEventListener('change', (e) => {
    estado.ordem = e.target.value;
    renderProdutos();
  });

  iniciar();
})();
