/* Plataforma: tela "Entrar" (login único) e criação/recuperação da senha do administrador master. */
(() => {
  const $ = (s, el = document) => el.querySelector(s);
  const telas = { entrar: $('#fEntrar'), recuperar: $('#fRecuperar'), codigo: $('#fCodigo'), escolher: $('#vEscolher') };
  let emailRecuperacao = '';

  function mostrar(nome, erro = '') {
    Object.entries(telas).forEach(([k, el]) => { el.hidden = k !== nome; });
    const el = telas[nome];
    const caixa = $('[data-erro]', el);
    if (caixa) {
      caixa.textContent = erro;
      caixa.hidden = !erro;
    }
    const primeiro = el.querySelector('input');
    if (primeiro) primeiro.focus();
  }

  function erro(form, e) {
    const caixa = $('[data-erro]', form);
    caixa.textContent = e.message;
    caixa.hidden = false;
    if (e.campo && form.elements[e.campo]) form.elements[e.campo].focus();
  }

  const ir = (url) => { location.href = url; };

  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-ir]');
    if (!b) return;
    if (b.dataset.ir === 'recuperar') telas.recuperar.elements.email.value = telas.entrar.elements.email.value || emailRecuperacao;
    mostrar(b.dataset.ir);
  });

  telas.entrar.addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    const btn = $('button[type="submit"]', f);
    btn.disabled = true;
    try {
      const r = await Loja.api('plataforma/entrar', { metodo: 'POST', dados: { email: f.elements.email.value, senha: f.elements.senha.value } });
      if (r.destino === 'escolher') {
        $('#listaLojas').innerHTML = r.lojas.map((l) => `<a class="btn btn-largo btn-linha" href="${Loja.esc(l.url)}">${Loja.esc(l.nome)}</a>`).join('');
        mostrar('escolher');
      } else {
        ir(r.url);
      }
    } catch (err) {
      // Primeiro acesso do master: ainda não tem senha, então vai direto para "criar a senha".
      if (err.status === 409) {
        telas.recuperar.elements.email.value = f.elements.email.value;
        mostrar('recuperar', err.message);
        $('[data-erro]', telas.recuperar).classList.add('aviso-info');
      } else {
        erro(f, err);
      }
    } finally {
      btn.disabled = false;
    }
  });

  telas.recuperar.addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    const btn = $('button[type="submit"]', f);
    btn.disabled = true;
    try {
      emailRecuperacao = f.elements.email.value.trim();
      const r = await Loja.api('plataforma/codigo', { metodo: 'POST', dados: { email: emailRecuperacao, finalidade: 'senha' } });
      $('#msgCodigo').textContent = r.mensagem;
      telas.codigo.reset();
      mostrar('codigo');
    } catch (err) {
      erro(f, err);
    } finally {
      btn.disabled = false;
    }
  });

  telas.codigo.addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    if (f.elements.senha.value !== f.elements.senha2.value) return erro(f, { message: 'As senhas não conferem.', campo: 'senha2' });
    const btn = $('button[type="submit"]', f);
    btn.disabled = true;
    try {
      await Loja.api('plataforma/senha', { metodo: 'POST', dados: { email: emailRecuperacao, codigo: f.elements.codigo.value, senha: f.elements.senha.value } });
      ir('master/');
    } catch (err) {
      erro(f, err);
    } finally {
      btn.disabled = false;
    }
  });

  // Já está logado como master: oferece ir direto para o painel.
  (async () => {
    try {
      const s = await Loja.api('plataforma/sessao');
      if (s.master) {
        const aviso = document.createElement('p');
        aviso.className = 'entrar-nota';
        aviso.innerHTML = `Você já está conectado como <strong>${Loja.esc(s.master.email)}</strong>. <a href="master/">Ir para o painel master</a>`;
        telas.entrar.prepend(aviso);
      }
    } catch { /* sem conexão: o formulário continua funcionando */ }
    mostrar('entrar');
  })();
})();
