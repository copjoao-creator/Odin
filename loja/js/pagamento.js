/* Loja: etapa de pagamento (Asaas: Pix, boleto e cartão de crédito) e telas de resultado.
   Usada no checkout da vitrine e na página pagar.html (link de pagamento). */
(() => {
  let espera = null;

  /** Para a consulta automática do pedido (ao trocar de tela ou fechar o checkout). */
  async function desmontar() {
    clearTimeout(espera);
    espera = null;
  }

  const itensHtml = (pedido) => `
    <ul class="pg-itens">
      ${pedido.itens.map((i) => `<li><span>${i.quantidade}× ${Loja.esc(i.descricao)}</span><span>${Loja.brl(i.preco_unitario * i.quantidade)}</span></li>`).join('')}
      ${pedido.frete > 0 ? `<li><span>Frete</span><span>${Loja.brl(pedido.frete)}</span></li>` : ''}
      <li class="pg-itens-total"><span>Total</span><span>${Loja.brl(pedido.total)}</span></li>
    </ul>`;

  /**
   * Mostra, dentro de `alvo`, o que o pedido precisa agora: formas de pagamento,
   * QR code do Pix, boleto, análise ou confirmação.
   * ctx: { loja, pedido, aoMudar(pedido, pagamento), voltar: {texto, acao} }
   */
  async function montar(alvo, ctx) {
    await desmontar();
    const { pedido } = ctx;
    const pg = pedido.pagamento;
    if (pedido.status === 'pago') return telaAprovado(alvo, ctx);
    if (pedido.status === 'em_analise') return telaAnalise(alvo, ctx);
    if (pedido.status === 'cancelado' || pedido.status === 'estornado') return telaCancelado(alvo, ctx);
    // Renovação de assinatura no cartão: o Asaas debita sozinho; não há o que pagar aqui.
    if (pedido.cobranca_automatica) return telaCobrancaAutomatica(alvo, ctx);
    // Serviço recorrente: só cartão de crédito, com cobrança automática a cada período.
    if (pedido.assinatura) return pedido.assinatura.criada ? telaAssinaturaCriada(alvo, ctx) : formularioAssinatura(alvo, ctx);
    if (pg && pg.status === 'pending' && (pg.pix_copia_cola || pg.link_pagamento)) return telaPendente(alvo, ctx);
    // Pix ou boleto vencido: escolhe de novo, com o aviso.
    return formulario(alvo, ctx, pg && pg.status === 'rejected' ? pg.mensagem : '');
  }

  const pagamentosAtivos = (ctx) => !!(ctx.pedido.pagamentos_ativos ?? ctx.loja.pagamentos_ativos);

  const PERIODO = { mensal: 'mês', trimestral: 'trimestre', semestral: 'semestre', anual: 'ano' };
  const SEGURO = `<p class="pg-seguro">${Loja.icone('escudo')} Pagamento processado pelo Asaas, com conexão segura. A loja não guarda os dados do seu cartão.</p>`;

  function telaSemPagamento(alvo, pedido) {
    alvo.innerHTML = `<div class="pg-resultado">${Loja.icone('alerta', 'ico ico-grande')}<h3>Pagamento online em configuração</h3>
      <p>Seu pedido <strong>#${pedido.id}</strong> foi registrado. Fale com a loja para concluir o pagamento.</p>
      <a class="btn" href="${Loja.esc(Loja.linkContato({ assunto: 'Meu pedido ou pagamento', pedido: pedido.id }))}">Fale conosco</a></div>`;
  }

  const alertaHtml = (alerta) => (alerta ? `<p class="pg-alerta">${Loja.icone('alerta')} ${Loja.esc(alerta)}</p>` : '');

  /**
   * Escolha da forma de pagamento. Pix e boleto são gerados com os dados do cadastro do cliente
   * (o boleto também vai para o e-mail do cadastro); o cartão abre o formulário da loja.
   */
  function formulario(alvo, ctx, alerta = '') {
    const { pedido } = ctx;
    if (!pagamentosAtivos(ctx)) return telaSemPagamento(alvo, pedido);
    const c = pedido.cliente;
    const parcelas = pedido.max_parcelas || 1;
    alvo.innerHTML = `
      ${alertaHtml(alerta)}
      <div class="pg-total"><span>Total do pedido #${pedido.id}</span><strong>${Loja.brl(pedido.total)}</strong></div>
      ${c.protegido ? `<p class="pg-assinatura">${Loja.icone('check')}<span>Usaremos os dados do seu cadastro (${Loja.esc(c.nome)}, ${Loja.esc(c.cidade)}/${Loja.esc(c.estado)}).</span></p>` : ''}
      <div class="pg-opcoes">
        <button type="button" class="pg-opcao" data-forma="pix">${Loja.icone('pix')}<span><strong>Pix</strong>Aprovação na hora</span></button>
        <button type="button" class="pg-opcao" data-forma="boleto">${Loja.icone('boleto')}<span><strong>Boleto</strong>Enviado também para ${Loja.esc(c.email)}</span></button>
        <button type="button" class="pg-opcao" data-forma="cartao">${Loja.icone('cartao')}<span><strong>Cartão de crédito</strong>${parcelas > 1 ? `Em até ${parcelas}x sem juros` : 'À vista'}</span></button>
      </div>
      ${SEGURO}`;
    alvo.querySelectorAll('[data-forma]').forEach((b) => b.addEventListener('click', async () => {
      if (b.dataset.forma === 'cartao') return formularioCompra(alvo, ctx);
      alvo.querySelectorAll('[data-forma]').forEach((x) => { x.disabled = true; });
      b.querySelector('strong').textContent = b.dataset.forma === 'pix' ? 'Gerando o Pix…' : 'Gerando o boleto…';
      try {
        await pagar(alvo, ctx, { forma: b.dataset.forma });
      } catch (e) {
        formulario(alvo, ctx, e.message);
      }
    }));
  }

  /** Cria o pagamento (Pix, boleto ou cartão) e mostra a tela seguinte. Erros sobem para quem chamou. */
  async function pagar(alvo, ctx, dados) {
    const r = await Loja.api('pagamentos', { metodo: 'POST', dados: { pedido_id: ctx.pedido.id, token: ctx.pedido.token, ...dados } });
    if (ctx.aoMudar) ctx.aoMudar(r.pedido, r.pagamento);
    montar(alvo, { ...ctx, pedido: r.pedido });
  }

  /** Compra no cartão de crédito: à vista ou parcelado sem juros. */
  function formularioCompra(alvo, ctx, alerta = '') {
    const { pedido } = ctx;
    formularioCartao(alvo, ctx, {
      alerta,
      topo: `<div class="pg-total"><span>Total do pedido #${pedido.id}</span><strong>${Loja.brl(pedido.total)}</strong></div>`,
      total: pedido.total,
      parcelas: pedido.max_parcelas || 1,
      rotulo: (n) => (n > 1 ? `Pagar ${Loja.brl(pedido.total)} em ${n}x` : `Pagar ${Loja.brl(pedido.total)}`),
      enviar: (cartao, parcelas) => pagar(alvo, ctx, { forma: 'cartao', cartao, parcelas }),
      outras: () => formulario(alvo, ctx)
    });
  }

  /**
   * Assinatura: cartão de crédito, cobrado agora e renovado automaticamente pelo Asaas a cada período.
   * Se o banco recusar, nada é criado e o cliente pode tentar outro cartão.
   */
  function formularioAssinatura(alvo, ctx, alerta = '') {
    const { pedido } = ctx;
    if (!pagamentosAtivos(ctx)) return telaSemPagamento(alvo, pedido);
    const a = pedido.assinatura;
    const periodo = PERIODO[a.renovacao] || a.renovacao;
    formularioCartao(alvo, ctx, {
      alerta,
      topo: `
        <div class="pg-total"><span>Assinatura · pedido #${pedido.id}</span><strong>${Loja.brl(a.valor)}/${Loja.esc(periodo)}</strong></div>
        <p class="pg-assinatura">${Loja.icone('relogio')}<span>Renovação automática no <strong>cartão de crédito</strong>: ${Loja.brl(a.valor)} agora e depois a cada ${Loja.esc(periodo)}${a.data_final ? `, até <strong>${Loja.data(a.data_final)}</strong>, quando a assinatura termina sozinha` : ''}. Para cancelar antes, é só falar com a loja.</span></p>`,
      total: a.valor,
      parcelas: 0,
      rotulo: () => `Assinar · ${Loja.brl(a.valor)}/${periodo}`,
      enviar: async (cartao) => {
        const r = await Loja.api('assinaturas/asaas', { metodo: 'POST', dados: { pedido_id: pedido.id, token: pedido.token, cartao } });
        // Avisa a vitrine que o pedido seguiu (esvazia o carrinho).
        if (ctx.aoMudar) ctx.aoMudar(r.pedido, { status: 'pending' });
        montar(alvo, { ...ctx, pedido: r.pedido });
      }
    });
  }

  /**
   * Formulário de cartão da própria loja. Os dados seguem por conexão segura para o servidor da loja,
   * que os repassa ao Asaas sem gravar.
   * op: { alerta, topo (html), total, parcelas (0 = sem escolha), rotulo(n), enviar(cartao, n), outras() }
   */
  function formularioCartao(alvo, ctx, op) {
    const c = ctx.pedido.cliente;
    // CPF do cliente como sugestão (o mais comum é o cartão ser dele); some se o cartão for de outra pessoa.
    const cpfCliente = !c.protegido && Loja.cpfValido(c.cpf) ? Loja.formatar.cpf(c.cpf) : '';
    const opcoesParcelas = Array.from({ length: op.parcelas }, (_, i) => i + 1)
      .map((n) => `<option value="${n}">${n === 1 ? `À vista · ${Loja.brl(op.total)}` : `${n}x de ${Loja.brl(op.total / n)} sem juros`}</option>`).join('');
    alvo.innerHTML = `
      <div class="pg-alertas">${alertaHtml(op.alerta)}</div>
      ${op.topo}
      <form class="pg-cartao" novalidate>
        <div class="campos">
          <label class="c-12">Número do cartão <input name="numero" inputmode="numeric" autocomplete="cc-number" placeholder="0000 0000 0000 0000" required></label>
          <label class="c-12">Nome do titular (como está no cartão) <input name="nome" autocomplete="cc-name" maxlength="100" required></label>
          <label class="c-6">Validade <input name="validade" inputmode="numeric" autocomplete="cc-exp" placeholder="MM/AA" maxlength="5" required></label>
          <label class="c-6">Código de segurança <input name="cvv" inputmode="numeric" autocomplete="cc-csc" placeholder="CVV" maxlength="4" required></label>
          <label class="c-12">CPF do titular do cartão <input name="cpf" inputmode="numeric" placeholder="000.000.000-00" value="${Loja.esc(cpfCliente)}" required></label>
          ${op.parcelas > 1 ? `<label class="c-12">Parcelas <select name="parcelas">${opcoesParcelas}</select></label>` : ''}
          <label class="c-12 marcar"><input type="checkbox" name="outro"> O cartão é de outra pessoa</label>
        </div>
        <div class="campos pg-titular" hidden>
          <p class="c-12 pg-dica">Dados do titular do cartão, iguais aos que o banco dele tem:</p>
          <label class="c-4">CEP <input name="cep" inputmode="numeric" placeholder="00000-000"></label>
          <label class="c-4">Número do endereço <input name="numero_endereco" maxlength="20"></label>
          <label class="c-4">Celular <input name="celular" inputmode="tel" placeholder="(00) 00000-0000"></label>
        </div>
        <button type="submit" class="btn btn-largo"></button>
      </form>
      ${op.outras ? '<button type="button" class="link link-centro" data-outras>Pagar com Pix ou boleto</button>' : ''}
      ${SEGURO}`;

    const f = alvo.querySelector('form');
    const el = f.elements;
    const botao = f.querySelector('[type="submit"]');
    const parcelas = () => (el.parcelas ? Number(el.parcelas.value) : 1);
    const rotular = () => { botao.textContent = op.rotulo(parcelas()); };
    rotular();
    if (el.parcelas) el.parcelas.addEventListener('change', rotular);
    el.numero.addEventListener('input', () => { el.numero.value = Loja.digitos(el.numero.value).slice(0, 19).replace(/(\d{4})(?=\d)/g, '$1 '); });
    el.validade.addEventListener('input', () => {
      const d = Loja.digitos(el.validade.value).slice(0, 4);
      el.validade.value = d.length > 2 ? `${d.slice(0, 2)}/${d.slice(2)}` : d;
    });
    el.cvv.addEventListener('input', () => { el.cvv.value = Loja.digitos(el.cvv.value).slice(0, 4); });
    Loja.mascarar(el.cpf, 'cpf');
    Loja.mascarar(el.cep, 'cep');
    Loja.mascarar(el.celular, 'celular');
    el.outro.addEventListener('change', () => {
      alvo.querySelector('.pg-titular').hidden = !el.outro.checked;
      if (el.outro.checked && el.cpf.value === cpfCliente) el.cpf.value = '';
      if (!el.outro.checked && !el.cpf.value) el.cpf.value = cpfCliente;
    });
    const outras = alvo.querySelector('[data-outras]');
    if (outras) outras.addEventListener('click', op.outras);

    const mostrarErro = (mensagem, campo) => {
      alvo.querySelector('.pg-alertas').innerHTML = alertaHtml(mensagem);
      const input = campo && el[campo];
      if (input) {
        input.classList.add('invalido');
        input.focus();
      } else {
        alvo.querySelector('.pg-alertas').scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    };

    f.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      f.querySelectorAll('.invalido').forEach((x) => x.classList.remove('invalido'));
      const [mes = '', ano = ''] = el.validade.value.split('/');
      const outro = el.outro.checked;
      const cartao = {
        numero: Loja.digitos(el.numero.value),
        nome: el.nome.value.trim(),
        mes,
        ano,
        cvv: el.cvv.value,
        cpf: Loja.digitos(el.cpf.value),
        cep: outro ? Loja.digitos(el.cep.value) : '',
        numero_endereco: outro ? el.numero_endereco.value.trim() : '',
        celular: outro ? Loja.digitos(el.celular.value) : ''
      };
      // Conferência rápida aqui; o servidor confere tudo de novo.
      if (cartao.numero.length < 13 || !luhn(cartao.numero)) return mostrarErro('Número do cartão inválido. Confira os números digitados.', 'numero');
      if (cartao.nome.length < 3) return mostrarErro('Informe o nome do titular como está impresso no cartão.', 'nome');
      if (!/^\d{2}$/.test(mes) || +mes < 1 || +mes > 12 || !/^\d{2}$/.test(ano)) return mostrarErro('Informe a validade no formato MM/AA.', 'validade');
      if (cartao.cvv.length < 3) return mostrarErro('Informe o código de segurança (CVV).', 'cvv');
      if (!Loja.cpfValido(cartao.cpf)) return mostrarErro('CPF do titular do cartão inválido.', 'cpf');
      if (outro && (cartao.cep.length !== 8 || !cartao.numero_endereco || cartao.celular.length < 10)) {
        return mostrarErro('Preencha CEP, número do endereço e celular do titular do cartão.', cartao.cep.length !== 8 ? 'cep' : !cartao.numero_endereco ? 'numero_endereco' : 'celular');
      }

      botao.disabled = true;
      botao.textContent = 'Processando o cartão…';
      try {
        await op.enviar(cartao, parcelas());
      } catch (e) {
        botao.disabled = false;
        rotular();
        el.cvv.value = '';
        mostrarErro(e.message, e.campo);
      }
    });
  }

  /** Dígito verificador do número do cartão (algoritmo de Luhn). */
  function luhn(n) {
    let soma = 0;
    for (let i = n.length - 1, dobra = false; i >= 0; i--, dobra = !dobra) {
      let d = Number(n[i]);
      if (dobra && (d *= 2) > 9) d -= 9;
      soma += d;
    }
    return soma % 10 === 0;
  }

  function telaCobrancaAutomatica(alvo, ctx) {
    const { pedido } = ctx;
    alvo.innerHTML = `
      <div class="pg-resultado">
        ${Loja.icone('cartao', 'ico ico-grande')}
        <h3>Cobrança automática no cartão</h3>
        <p>A renovação da sua assinatura (pedido <strong>#${pedido.id}</strong>) é debitada automaticamente no cartão cadastrado. Não é preciso pagar por aqui.</p>
        <p class="muted">Se o cartão recusar, a cobrança é tentada de novo automaticamente. Para trocar o cartão, fale com a loja.</p>
        ${voltarHtml(ctx)}
        ${ajudaHtml(pedido)}
      </div>`;
    ligarVoltar(alvo, ctx);
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
          ? `${Loja.esc(pg.mensagem)} A cobrança será tentada de novo automaticamente. Se preferir trocar o cartão, fale com a loja.`
          : 'Estamos processando a primeira cobrança no seu cartão. A confirmação aparece aqui e chega por e-mail.'}</p>
        ${recusada ? '' : '<p class="pg-status"><span class="pulso"></span> Aguardando a confirmação do pagamento.</p>'}
        <p class="muted">Pedido #${pedido.id}</p>
        ${voltarHtml(ctx)}
        ${ajudaHtml(pedido)}
      </div>`;
    ligarVoltar(alvo, ctx);
    acompanhar(alvo, ctx, recusada ? 60000 : 10000);
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
    const validade = pg.expira_em ? `<p class="muted">${pg.metodo === 'boleto' ? 'Vencimento' : 'Válido até'}: ${pg.metodo === 'boleto' ? Loja.data(pg.expira_em.slice(0, 10)) : Loja.dataHora(pg.expira_em)}.</p>` : '';
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
          <p>Pague até o vencimento no app do banco, internet banking ou lotérica. Também enviamos o boleto para <strong>${Loja.esc(ctx.pedido.cliente.email)}</strong>. A confirmação leva até 3 dias úteis.</p>
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
