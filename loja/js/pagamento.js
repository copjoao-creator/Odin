/* Loja Odin Focus: etapa de pagamento (Payment Brick do Mercado Pago) e telas de resultado.
   Usada no checkout da vitrine e na página pagar.html (link de pagamento). */
(() => {
  const SDK = 'https://sdk.mercadopago.com/js/v2';
  let sdk = null;
  let controle = null;
  let espera = null;

  function carregarSdk() {
    if (window.MercadoPago) return Promise.resolve();
    if (!sdk) {
      sdk = new Promise((ok, falha) => {
        const s = document.createElement('script');
        s.src = SDK;
        s.onload = ok;
        s.onerror = () => {
          sdk = null;
          falha(new Loja.Erro('Não foi possível carregar o Mercado Pago. Verifique sua conexão e recarregue a página.'));
        };
        document.head.appendChild(s);
      });
    }
    return sdk;
  }

  async function desmontar() {
    clearTimeout(espera);
    espera = null;
    if (controle) {
      const c = controle;
      controle = null;
      try { await c.unmount(); } catch { /* já desmontado */ }
    }
  }

  const itensHtml = (pedido) => `
    <ul class="pg-itens">
      ${pedido.itens.map((i) => `<li><span>${i.quantidade}× ${Loja.esc(i.descricao)}</span><span>${Loja.brl(i.preco_unitario * i.quantidade)}</span></li>`).join('')}
      ${pedido.frete > 0 ? `<li><span>Frete</span><span>${Loja.brl(pedido.frete)}</span></li>` : ''}
      <li class="pg-itens-total"><span>Total</span><span>${Loja.brl(pedido.total)}</span></li>
    </ul>`;

  /**
   * Mostra, dentro de `alvo`, o que o pedido precisa agora: formulário de pagamento,
   * QR code do Pix, boleto, análise ou confirmação.
   * ctx: { loja, pedido, aoMudar(pedido), voltar: {texto, acao} }
   */
  async function montar(alvo, ctx) {
    await desmontar();
    const { pedido } = ctx;
    const pg = pedido.pagamento;
    if (pedido.status === 'pago') return telaAprovado(alvo, ctx);
    if (pedido.status === 'em_analise') return telaAnalise(alvo, ctx);
    if (pedido.status === 'cancelado' || pedido.status === 'estornado') return telaCancelado(alvo, ctx);
    // Serviço recorrente: só cartão de crédito, com cobrança automática a cada período.
    if (pedido.assinatura) return pedido.assinatura.criada ? telaAssinaturaCriada(alvo, ctx) : formularioAssinatura(alvo, ctx);
    if (pg && pg.status === 'pending' && (pg.pix_copia_cola || pg.link_pagamento)) return telaPendente(alvo, ctx);
    return formulario(alvo, ctx);
  }

  /** Chave pública da aplicação do Mercado Pago que cobra este pedido (loja ou serviços). */
  const chavePublica = (ctx) => ctx.pedido.mp_public_key || ctx.loja.mp_public_key;
  const pagamentosAtivos = (ctx) => (ctx.pedido.pagamentos_ativos ?? ctx.loja.pagamentos_ativos) && !!chavePublica(ctx);

  const ESTILO_BRICK = {
    theme: 'default',
    customVariables: {
      baseColor: '#1B2D42',
      textPrimaryColor: '#333333',
      formBackgroundColor: '#FFFFFF',
      inputBackgroundColor: '#FFFFFF',
      borderRadiusMedium: '6px',
      borderRadiusLarge: '8px'
    }
  };

  const PERIODO = { mensal: 'mês', trimestral: 'trimestre', semestral: 'semestre', anual: 'ano' };

  function telaSemPagamento(alvo, pedido) {
    alvo.innerHTML = `<div class="pg-resultado">${Loja.icone('alerta', 'ico ico-grande')}<h3>Pagamento online em configuração</h3>
      <p>Seu pedido <strong>#${pedido.id}</strong> foi registrado. Fale com a loja para concluir o pagamento.</p>
      <a class="btn" href="${Loja.esc(Loja.linkContato({ assunto: 'Meu pedido ou pagamento', pedido: pedido.id }))}">Fale conosco</a></div>`;
  }

  /** Assinatura: cartão de crédito (Card Payment Brick), cobrado agora e a cada período pelo Mercado Pago. */
  async function formularioAssinatura(alvo, ctx, alerta = '') {
    const { pedido } = ctx;
    if (!pagamentosAtivos(ctx)) return telaSemPagamento(alvo, pedido);
    const a = pedido.assinatura;
    const periodo = PERIODO[a.renovacao] || a.renovacao;
    const id = `brick-${Math.random().toString(36).slice(2)}`;
    alvo.innerHTML = `
      ${alerta ? `<p class="pg-alerta">${Loja.icone('alerta')} ${Loja.esc(alerta)}</p>` : ''}
      <div class="pg-total"><span>Assinatura · pedido #${pedido.id}</span><strong>${Loja.brl(a.valor)}/${Loja.esc(periodo)}</strong></div>
      <p class="pg-assinatura">${Loja.icone('relogio')} Cobrança automática no <strong>cartão de crédito</strong>: ${Loja.brl(a.valor)} agora e depois a cada ${Loja.esc(periodo)}. Para cancelar, é só falar com a loja.</p>
      <div id="${id}" class="pg-brick"><p class="carregando">Carregando o formulário do cartão…</p></div>
      <p class="pg-seguro">${Loja.icone('escudo')} Assinatura processada pelo Mercado Pago. A loja não tem acesso aos dados do seu cartão.</p>`;
    try {
      await carregarSdk();
    } catch (e) {
      document.getElementById(id).innerHTML = `<p class="pg-alerta">${Loja.esc(e.message)}</p>`;
      return;
    }
    const c = pedido.cliente;
    const mp = new window.MercadoPago(chavePublica(ctx), { locale: 'pt-BR' });
    controle = await mp.bricks().create('cardPayment', id, {
      initialization: {
        amount: a.valor,
        payer: { email: c.email, identification: { type: 'CPF', number: c.cpf } }
      },
      customization: {
        visual: { style: ESTILO_BRICK },
        paymentMethods: { maxInstallments: 1, types: { excluded: ['debit_card', 'prepaid_card'] } }
      },
      callbacks: {
        onReady: () => {},
        onError: erroDoBrick,
        onSubmit: (dados) => enviarAssinatura(alvo, ctx, dados)
      }
    });
  }

  async function enviarAssinatura(alvo, ctx, dados) {
    let r;
    try {
      r = await Loja.api('assinaturas/cartao', { metodo: 'POST', dados: { pedido_id: ctx.pedido.id, token: ctx.pedido.token, dados } });
    } catch (e) {
      Loja.aviso(e.message, 'erro');
      throw e;
    }
    // Avisa a vitrine que o pedido seguiu (esvazia o carrinho), mesmo antes da primeira cobrança.
    if (ctx.aoMudar) ctx.aoMudar(r.pedido, { status: 'pending' });
    setTimeout(() => montar(alvo, { ...ctx, pedido: r.pedido }), 0);
  }

  /** Assinatura criada: espera a primeira cobrança no cartão (normalmente alguns minutos). */
  function telaAssinaturaCriada(alvo, ctx) {
    const { pedido } = ctx;
    const pg = pedido.pagamento;
    const recusada = pg && pg.status === 'rejected';
    alvo.innerHTML = `
      <div class="pg-resultado">
        ${Loja.icone(recusada ? 'alerta' : 'relogio', 'ico ico-grande')}
        <h3>${recusada ? 'Primeira cobrança recusada' : 'Assinatura criada!'}</h3>
        <p>${recusada
          ? `${Loja.esc(pg.mensagem)} O Mercado Pago tentará cobrar de novo nos próximos dias. Se preferir trocar o cartão, fale com a loja.`
          : 'Estamos processando a primeira cobrança no seu cartão. A confirmação aparece aqui e chega por e-mail.'}</p>
        ${recusada ? '' : '<p class="pg-status"><span class="pulso"></span> Aguardando a confirmação do Mercado Pago.</p>'}
        <p class="muted">Pedido #${pedido.id}</p>
        ${voltarHtml(ctx)}
        ${ajudaHtml(pedido)}
      </div>`;
    ligarVoltar(alvo, ctx);
    acompanhar(alvo, ctx, recusada ? 60000 : 10000);
  }

  function erroDoBrick(erro) {
    // Erros graves do formulário do Mercado Pago também aparecem na tela. Os "non_critical"
    // (ex.: falha ao preencher o endereço pelo CEP) não impedem o pagamento e ficam só no console.
    console.error('Mercado Pago:', erro);
    if (!erro || erro.type !== 'critical') return;
    const detalhe = [erro.message, erro.cause].filter(Boolean).join(' · ');
    Loja.aviso(`O Mercado Pago não aceitou os dados${detalhe ? `: ${detalhe}` : '.'} Confira os dados e tente de novo.`, 'erro');
  }

  async function formulario(alvo, ctx, alerta = '') {
    const { loja, pedido } = ctx;
    if (!pagamentosAtivos(ctx)) return telaSemPagamento(alvo, pedido);
    const id = `brick-${Math.random().toString(36).slice(2)}`;
    alvo.innerHTML = `
      ${alerta ? `<p class="pg-alerta">${Loja.icone('alerta')} ${Loja.esc(alerta)}</p>` : ''}
      <div class="pg-total"><span>Total do pedido #${pedido.id}</span><strong>${Loja.brl(pedido.total)}</strong></div>
      <div id="${id}" class="pg-brick"><p class="carregando">Carregando formas de pagamento…</p></div>
      <p class="pg-seguro">${Loja.icone('escudo')} Pagamento processado pelo Mercado Pago. A loja não tem acesso aos dados do seu cartão.</p>`;
    try {
      await carregarSdk();
    } catch (e) {
      document.getElementById(id).innerHTML = `<p class="pg-alerta">${Loja.esc(e.message)}</p>`;
      return;
    }
    const c = pedido.cliente;
    const [primeiro = '', ...resto] = String(c.nome || '').trim().split(/\s+/);
    const mp = new window.MercadoPago(chavePublica(ctx), { locale: 'pt-BR' });
    controle = await mp.bricks().create('payment', id, {
      initialization: {
        amount: pedido.total,
        payer: {
          firstName: primeiro,
          lastName: resto.join(' ') || primeiro,
          email: c.email,
          identification: { type: 'CPF', number: c.cpf },
          address: {
            zipCode: c.cep, federalUnit: c.estado, city: c.cidade, neighborhood: c.bairro,
            streetName: c.rua, streetNumber: c.numero, complement: c.complemento || ''
          }
        }
      },
      customization: {
        visual: { style: ESTILO_BRICK },
        paymentMethods: {
          bankTransfer: 'all',
          creditCard: 'all',
          debitCard: 'all',
          ticket: 'all',
          maxInstallments: loja.max_parcelas || 12
        }
      },
      callbacks: {
        onReady: () => {},
        onError: erroDoBrick,
        onSubmit: ({ formData }) => enviar(alvo, ctx, formData)
      }
    });
  }

  async function enviar(alvo, ctx, formData) {
    let r;
    try {
      r = await Loja.api('pagamentos', { metodo: 'POST', dados: { pedido_id: ctx.pedido.id, token: ctx.pedido.token, dados: formData } });
    } catch (e) {
      Loja.aviso(e.message, 'erro');
      throw e;
    }
    const novo = { ...ctx, pedido: r.pedido };
    if (ctx.aoMudar) ctx.aoMudar(r.pedido, r.pagamento);
    // Troca a tela depois que o formulário do Mercado Pago terminar o envio.
    setTimeout(() => {
      if (r.pagamento.status === 'rejected') {
        desmontar().then(() => formulario(alvo, novo, r.pagamento.mensagem));
      } else {
        montar(alvo, novo);
      }
    }, 0);
  }

  function telaAprovado(alvo, ctx) {
    const { pedido } = ctx;
    alvo.innerHTML = `
      <div class="pg-resultado pg-ok">
        <span class="pg-selo">${Loja.icone('check', 'ico ico-grande')}</span>
        <h3>Pagamento aprovado!</h3>
        <p>Pedido <strong>#${pedido.id}</strong> confirmado. Enviamos os detalhes para <strong>${Loja.esc(pedido.cliente.email)}</strong>.</p>
        ${itensHtml(pedido)}
        ${voltarHtml(ctx)}
        ${ajudaHtml(pedido)}
      </div>`;
    ligarVoltar(alvo, ctx);
  }

  function telaAnalise(alvo, ctx) {
    const { pedido } = ctx;
    alvo.innerHTML = `
      <div class="pg-resultado">
        ${Loja.icone('relogio', 'ico ico-grande')}
        <h3>Pagamento em análise</h3>
        <p>${Loja.esc(pedido.pagamento ? pedido.pagamento.mensagem : 'Estamos confirmando o pagamento.')}</p>
        <p class="muted">Pedido #${pedido.id}. Você receberá a confirmação em <strong>${Loja.esc(pedido.cliente.email)}</strong>.</p>
        ${voltarHtml(ctx)}
        ${ajudaHtml(pedido)}
      </div>`;
    ligarVoltar(alvo, ctx);
    acompanhar(alvo, ctx, 20000);
  }

  function telaCancelado(alvo, ctx) {
    alvo.innerHTML = `
      <div class="pg-resultado">
        ${Loja.icone('alerta', 'ico ico-grande')}
        <h3>Pedido ${ctx.pedido.status === 'estornado' ? 'estornado' : 'cancelado'}</h3>
        <p>Este pedido não pode mais ser pago. Se ainda quiser os itens, faça um novo pedido na loja.</p>
        ${voltarHtml(ctx)}
        ${ajudaHtml(ctx.pedido)}
      </div>`;
    ligarVoltar(alvo, ctx);
  }

  function telaPendente(alvo, ctx) {
    const { pedido } = ctx;
    const pg = pedido.pagamento;
    const validade = pg.expira_em ? `<p class="muted">Válido até ${Loja.dataHora(pg.expira_em)}.</p>` : '';
    if (pg.metodo === 'pix') {
      alvo.innerHTML = `
        <div class="pg-resultado">
          <h3>Pague com Pix</h3>
          <p>No app do seu banco, escolha <strong>Pix › Ler QR code</strong> ou <strong>Pix copia e cola</strong>.</p>
          ${pg.pix_qr_base64 ? `<img class="pg-qr" src="data:image/png;base64,${Loja.esc(pg.pix_qr_base64)}" alt="QR code do Pix" width="220" height="220">` : ''}
          <div class="pg-valor">${Loja.brl(pg.valor)}</div>
          <label class="pg-copia">Pix copia e cola<textarea readonly rows="3">${Loja.esc(pg.pix_copia_cola || '')}</textarea></label>
          <button type="button" class="btn" data-copiar="${Loja.esc(pg.pix_copia_cola || '')}">Copiar código Pix</button>
          <p class="pg-status"><span class="pulso"></span> Aguardando o pagamento. A confirmação aparece aqui sozinha.</p>
          ${validade}
          <button type="button" class="link" data-outra>Pagar de outra forma</button>
        </div>`;
      acompanhar(alvo, ctx, 5000);
    } else {
      alvo.innerHTML = `
        <div class="pg-resultado">
          ${Loja.icone('boleto', 'ico ico-grande')}
          <h3>Boleto gerado</h3>
          <p>Pague até o vencimento no app do banco, internet banking ou lotérica. A confirmação leva até 3 dias úteis e chega por e-mail.</p>
          <div class="pg-valor">${Loja.brl(pg.valor)}</div>
          ${pg.link_pagamento ? `<a class="btn" href="${Loja.esc(pg.link_pagamento)}" target="_blank" rel="noopener">Abrir boleto</a>` : ''}
          ${pg.codigo_barras ? `<label class="pg-copia">Linha digitável<textarea readonly rows="2">${Loja.esc(pg.codigo_barras)}</textarea></label>
            <button type="button" class="btn btn-claro" data-copiar="${Loja.esc(pg.codigo_barras)}">Copiar linha digitável</button>` : ''}
          ${validade}
          <button type="button" class="link" data-outra>Pagar de outra forma</button>
        </div>`;
      acompanhar(alvo, ctx, 60000);
    }
    alvo.querySelectorAll('[data-copiar]').forEach((b) => b.addEventListener('click', async () => {
      if (await Loja.copiar(b.dataset.copiar)) Loja.aviso('Código copiado!', 'ok');
    }));
    alvo.querySelector('[data-outra]').addEventListener('click', async () => {
      await desmontar();
      formulario(alvo, ctx);
    });
  }

  /** Consulta o pedido de tempos em tempos até o pagamento mudar (Pix costuma confirmar em segundos). */
  function acompanhar(alvo, ctx, intervalo) {
    const inicio = Date.now();
    const antes = `${ctx.pedido.status}|${ctx.pedido.pagamento ? ctx.pedido.pagamento.status : ''}`;
    const passo = async () => {
      if (!alvo.isConnected || Date.now() - inicio > 45 * 60 * 1000) return;
      try {
        const r = await Loja.api(`pedidos/${ctx.pedido.id}/publico&t=${encodeURIComponent(ctx.pedido.token)}&atualizar=1`);
        const agora = `${r.pedido.status}|${r.pedido.pagamento ? r.pedido.pagamento.status : ''}`;
        if (agora !== antes) {
          if (ctx.aoMudar) ctx.aoMudar(r.pedido);
          montar(alvo, { ...ctx, pedido: r.pedido });
          return;
        }
      } catch { /* sem conexão agora: tenta de novo no próximo ciclo */ }
      espera = setTimeout(passo, intervalo);
    };
    espera = setTimeout(passo, intervalo);
  }

  const voltarHtml = (ctx) => (ctx.voltar ? `<button type="button" class="btn" data-voltar>${Loja.esc(ctx.voltar.texto)}</button>` : '');
  /** Link "Precisa de ajuda?" para a página Fale conosco, já com o número do pedido. */
  const ajudaHtml = (pedido) => `<p class="nota">Precisa de ajuda? <a href="${Loja.esc(Loja.linkContato({ assunto: 'Meu pedido ou pagamento', pedido: pedido.id }))}">Fale conosco</a></p>`;
  const ligarVoltar = (alvo, ctx) => {
    const b = alvo.querySelector('[data-voltar]');
    if (b) b.addEventListener('click', ctx.voltar.acao);
  };

  window.LojaPagamento = { montar, desmontar };
})();
