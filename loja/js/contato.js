/*
 * Página Fale conosco (contato.html).
 * A mensagem é enviada pelo EmailJS direto do navegador, com as chaves cadastradas em
 * Painel › Configurações › Formulário de contato. Sem as chaves, mostra só os outros canais.
 */
(() => {
  const $ = (s) => document.querySelector(s);
  const esc = Loja.esc;
  const EMAILJS_SDK = 'https://cdn.jsdelivr.net/npm/@emailjs/browser@4/dist/email.min.js';
  const ASSUNTOS = ['Dúvida sobre um produto', 'Dúvida sobre um serviço', 'Meu pedido ou pagamento', 'Entrega', 'Outro assunto'];
  const abertoEm = Date.now();

  document.querySelectorAll('[data-marca]').forEach((el) => { el.innerHTML = Loja.marca(); });
  $('#ano').textContent = new Date().getFullYear();

  function carregarSdk() {
    if (window.emailjs) return Promise.resolve();
    return new Promise((ok, falha) => {
      const s = document.createElement('script');
      s.src = EMAILJS_SDK;
      s.onload = ok;
      s.onerror = () => falha(new Error('Não foi possível carregar o serviço de envio.'));
      document.head.appendChild(s);
    });
  }

  /** Dados da empresa (razão social, CPF/CNPJ, endereço e responsável) abaixo dos canais. */
  function renderEmpresa(l) {
    const e = l.empresa || {};
    const linhas = [
      e.razao_social ? `<strong>${esc(e.razao_social)}</strong>` : '',
      e.documento ? `${esc(e.rotulo_documento)} ${esc(e.documento)}` : '',
      e.endereco ? esc(e.endereco) : '',
      e.responsavel ? `Responsável: ${esc(e.responsavel)}` : ''
    ].filter(Boolean);
    if (!linhas.length) return false;
    const caixa = $('#empresaDados');
    caixa.innerHTML = `<h3>Empresa</h3><p>${linhas.join('<br>')}</p>`;
    caixa.hidden = false;
    $('#canais').hidden = false;
    return true;
  }

  function renderCanais(l) {
    const temEmpresa = renderEmpresa(l);
    const canais = Loja.contatos(l);
    if (!canais.length) {
      if (temEmpresa) $('#tituloCanais').hidden = true;
      return;
    }
    // Com o formulário ativo, o card E-mail leva ao formulário desta página em vez de abrir o mailto:.
    const porFormulario = (c) => c.icone === 'email' && l.emailjs;
    $('#listaCanais').innerHTML = canais.map((c) => `
      <a class="canal" ${porFormulario(c) ? 'href="#fContato" data-ir-form' : `href="${esc(c.url)}" target="_blank" rel="noopener"`}>
        ${Loja.icone(c.icone, 'ico')}<span><strong>${esc(c.titulo)}</strong>${esc(c.texto)}</span>
      </a>`).join('');
    const irForm = $('[data-ir-form]');
    if (irForm) {
      irForm.addEventListener('click', (e) => {
        e.preventDefault();
        const f = $('#fContato');
        if (!f) return;
        f.scrollIntoView({ behavior: 'smooth', block: 'start' });
        f.elements.nome.focus({ preventScroll: true });
      });
    }
    $('#canais').hidden = false;
  }

  function renderForm(l) {
    $('#alvoForm').innerHTML = `
      <form id="fContato" class="campos" novalidate>
        <label class="c-12">Nome <input name="nome" autocomplete="name" required maxlength="100"></label>
        <label class="c-6">E-mail <input name="email" type="email" autocomplete="email" required maxlength="150"></label>
        <label class="c-6">Telefone / WhatsApp (opcional) <input name="telefone" inputmode="tel" autocomplete="tel" maxlength="16"></label>
        <label class="c-12">Assunto
          <select name="assunto">${ASSUNTOS.map((a) => `<option>${esc(a)}</option>`).join('')}</select>
        </label>
        <label class="c-12">Mensagem <textarea name="mensagem" required minlength="10" maxlength="2000" rows="6"></textarea></label>
        <label class="isca" aria-hidden="true">Não preencha <input name="site" tabindex="-1" autocomplete="off"></label>
        <div class="c-12" id="erroContato" role="alert"></div>
        <div class="c-12"><button type="submit" class="btn btn-largo">Enviar mensagem</button></div>
        <p class="c-12 nota">Respondemos pelo e-mail informado, normalmente em até 1 dia útil.</p>
      </form>`;
    const f = $('#fContato');
    Loja.mascarar(f.elements.telefone, 'celular');
    // Links de contato da loja podem trazer o assunto e o número do pedido (Loja.linkContato).
    const q = new URLSearchParams(location.search);
    const assunto = q.get('assunto');
    if (assunto && ASSUNTOS.includes(assunto)) f.elements.assunto.value = assunto;
    const pedido = Loja.digitos(q.get('pedido'));
    if (pedido) f.elements.mensagem.value = `Pedido #${pedido}: `;
    f.addEventListener('submit', (e) => { e.preventDefault(); enviar(f, l); });
  }

  function validar(f) {
    const erros = [];
    const marcar = (nome, ok) => { f.elements[nome].classList.toggle('invalido', !ok); return ok; };
    if (!marcar('nome', f.elements.nome.value.trim().length >= 2)) erros.push('Informe seu nome.');
    if (!marcar('email', /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(f.elements.email.value.trim()))) erros.push('Informe um e-mail válido.');
    const tel = Loja.digitos(f.elements.telefone.value);
    if (!marcar('telefone', tel === '' || tel.length >= 10)) erros.push('Telefone: informe DDD + número, ou deixe em branco.');
    if (!marcar('mensagem', f.elements.mensagem.value.trim().length >= 10)) erros.push('Escreva sua mensagem (pelo menos 10 caracteres).');
    return erros;
  }

  async function enviar(f, l) {
    const erro = $('#erroContato');
    erro.innerHTML = '';
    // Robôs costumam preencher o campo escondido ou enviar em menos de 3 segundos.
    if (f.elements.site.value || Date.now() - abertoEm < 3000) return concluido(l);
    const erros = validar(f);
    if (erros.length) {
      erro.innerHTML = `<p class="form-erro">${erros.map(esc).join('<br>')}</p>`;
      f.querySelector('.invalido')?.focus();
      return;
    }
    const botao = f.querySelector('button[type="submit"]');
    botao.disabled = true;
    botao.textContent = 'Enviando…';
    try {
      await carregarSdk();
      const dados = {
        nome: f.elements.nome.value.trim(),
        email: f.elements.email.value.trim(),
        telefone: f.elements.telefone.value.trim() || 'não informado',
        assunto: f.elements.assunto.value,
        mensagem: f.elements.mensagem.value.trim(),
        loja: l.nome
      };
      await window.emailjs.send(l.emailjs.service_id, l.emailjs.template_id, {
        ...dados,
        // Mesmos dados com os nomes do modelo padrão "Contact Us" do EmailJS.
        name: dados.nome,
        title: dados.assunto,
        subject: `[${dados.loja}] ${dados.assunto} - ${dados.nome}`,
        message: `${dados.mensagem}\n\nTelefone: ${dados.telefone}\nE-mail: ${dados.email}`,
        phone: dados.telefone,
        reply_to: dados.email,
        time: new Date().toLocaleString('pt-BR')
      }, { publicKey: l.emailjs.public_key });
      concluido(l);
    } catch (e) {
      console.error('EmailJS', e);
      const canal = l.whatsapp ? ' ou fale pelo WhatsApp' : (l.email ? ` ou escreva para ${esc(l.email)}` : '');
      erro.innerHTML = `<p class="form-erro">Não conseguimos enviar sua mensagem agora. Tente de novo em alguns minutos${canal}.</p>`;
      botao.disabled = false;
      botao.textContent = 'Enviar mensagem';
    }
  }

  function concluido(l) {
    $('#alvoForm').innerHTML = `
      <div class="pg-resultado">
        <span class="pg-selo">${Loja.icone('check', 'ico ico-grande')}</span>
        <h3>Mensagem enviada!</h3>
        <p>Obrigado por falar com a ${esc(l.nome)}. Vamos responder no e-mail informado o quanto antes.</p>
        <a class="btn" href="./">Voltar à loja</a>
      </div>`;
  }

  (async () => {
    let l;
    try {
      ({ loja: l } = await Loja.api('loja'));
    } catch (e) {
      $('#alvoForm').innerHTML = `<p class="pg-alerta">${esc(e.message)}</p>`;
      return;
    }
    document.title = `Fale conosco · ${l.nome}`;
    Loja.aplicarMarca(l);
    Loja.aplicarRodape(l);
    Loja.nomeLogo($('#logoNome'), l.nome);
    renderCanais(l);
    if (l.emailjs) {
      renderForm(l);
    } else if (Loja.contatos(l).length) {
      $('#alvoForm').innerHTML = '<p class="contato-sem-form">Escolha um dos canais ao lado para falar com a gente.</p>';
    } else {
      $('#alvoForm').innerHTML = '<p class="contato-sem-form">Nossos canais de atendimento estarão disponíveis em breve.</p>';
    }
  })();
})();
