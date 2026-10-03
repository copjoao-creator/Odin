/* Plataforma Odin Focus: painel do administrador master (lojas, acessos dos lojistas e senha do master). */
(() => {
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const esc = Loja.esc;
  const brl = Loja.brl;
  const dlg = $('#dlg');
  const M = { master: null, csrf: '', tela: '' };

  $$('[data-icone]').forEach((el) => { el.innerHTML = Loja.icone(el.dataset.icone); });

  async function api(rota, opcoes = {}) {
    try {
      return await Loja.api(rota, { ...opcoes, csrf: M.csrf });
    } catch (e) {
      if (e.status === 401) location.href = '../entrar.html';
      throw e;
    }
  }

  const falha = (e) => Loja.aviso(e.message, 'erro');
  function erroDeForm(form, e) {
    falha(e);
    $$('.invalido', form).forEach((el) => el.classList.remove('invalido'));
    const el = e.campo && form.elements[e.campo];
    if (el) {
      el.classList.add('invalido');
      el.focus();
    }
  }

  function dialogo(titulo, corpo, rodape = '') {
    dlg.innerHTML = `
      <div class="dlg-cab"><h2>${titulo}</h2><button type="button" class="btn-icone" data-fechar aria-label="Fechar">${Loja.icone('fechar')}</button></div>
      <div class="dlg-corpo">${corpo}</div>
      ${rodape ? `<div class="dlg-rodape">${rodape}</div>` : ''}`;
    if (!dlg.open) dlg.showModal();
    dlg.scrollTop = 0;
  }
  dlg.addEventListener('click', (e) => {
    if (e.target === dlg || e.target.closest('[data-fechar]')) dlg.close();
  });

  const TELAS = { lojas: ['Lojas', telaLojas], conta: ['Minha conta', telaConta] };

  function ir() {
    const tela = TELAS[location.hash.slice(1)] ? location.hash.slice(1) : 'lojas';
    M.tela = tela;
    $$('#nav a').forEach((a) => a.classList.toggle('ativa', a.dataset.tela === tela));
    $('#tituloTela').textContent = TELAS[tela][0];
    $('#acoesTela').innerHTML = '';
    const el = $('#conteudo');
    el.innerHTML = '<p class="carregando">Carregando…</p>';
    TELAS[tela][1](el, $('#acoesTela')).catch((e) => { el.innerHTML = `<p class="pg-alerta">${esc(e.message)}</p>`; });
  }
  window.addEventListener('hashchange', ir);
  $('#btnSair').addEventListener('click', async () => {
    try { await api('plataforma/sair', { metodo: 'POST' }); } catch { /* já saiu */ }
    location.href = '../entrar.html';
  });

  // ---------------- Lojas ----------------

  const PERFIS = { tecnico: 'Técnico', administrador: 'Administrador' };

  async function telaLojas(el, acoes) {
    acoes.innerHTML = '<button type="button" class="btn btn-pequeno" id="btnNovaLoja">Nova loja</button>';
    $('#btnNovaLoja').addEventListener('click', () => novaLoja());
    const r = await api('plataforma/lojas');
    M.urlBase = r.url_base;
    const ativas = r.lojas.filter((l) => l.status === 'ativa').length;
    el.innerHTML = `
      <p class="bloco-sub">${r.lojas.length} loja(s) · ${ativas} ativa(s). Cada loja tem as próprias tabelas, imagens, configurações e painel. Você entra em qualquer painel com acesso total.</p>
      <div class="tabela-caixa">
        <table class="tabela">
          <thead><tr><th>Loja</th><th>Situação</th><th class="num">Pedidos pagos</th><th class="num">Faturamento 30 dias</th><th>Acessos ao painel</th><th></th></tr></thead>
          <tbody>${r.lojas.map((l) => `
            <tr>
              <td><strong>${esc(l.nome)}</strong>${l.original ? ' <span class="fraco">(original)</span>' : ''}<br><a href="${esc(l.url)}" class="fraco">${esc(l.url.replace(/^https?:\/\//, ''))}</a></td>
              <td><span class="status st-${l.status === 'ativa' ? 'ativa' : 'cancelada'}">${l.status === 'ativa' ? 'Ativa' : 'Suspensa'}</span></td>
              <td class="num">${l.erro ? '—' : l.pedidos_pagos}</td>
              <td class="num">${l.erro ? '—' : brl(l.faturamento_30d)}</td>
              <td>${l.erro ? `<span class="fraco">${esc(l.erro)}</span>` : l.administradores.map((a) => `${esc(a.email)} <span class="fraco">· ${PERFIS[a.perfil] || esc(a.perfil)}</span>`).join('<br>') || '<span class="fraco">nenhum</span>'}</td>
              <td class="acoes-loja">
                <a href="${esc(l.url)}">Abrir loja</a>
                <a href="${esc(l.url)}admin/" target="_blank" rel="noopener">Abrir painel ↗</a>
                <button type="button" class="link" data-acesso="${l.id}">Acessos</button>
                <button type="button" class="link" data-nome="${l.id}">Renomear</button>
                <button type="button" class="link" data-status="${l.id}" data-novo="${l.status === 'ativa' ? 'suspensa' : 'ativa'}">${l.status === 'ativa' ? 'Suspender' : 'Reativar'}</button>
              </td>
            </tr>`).join('')}</tbody>
        </table>
      </div>`;
    const loja = (id) => r.lojas.find((l) => l.id === Number(id));
    el.addEventListener('click', async (e) => {
      const b = e.target.closest('button');
      if (!b) return;
      if (b.dataset.acesso) return acessos(loja(b.dataset.acesso));
      if (b.dataset.nome) return renomear(loja(b.dataset.nome));
      if (b.dataset.status) {
        const l = loja(b.dataset.status);
        const suspender = b.dataset.novo === 'suspensa';
        if (suspender && !confirm(`Suspender a loja "${l.nome}"? Ela sai do ar para os clientes e o lojista não consegue entrar no painel. Os pagamentos já feitos continuam sendo registrados.`)) return;
        try {
          await api(`plataforma/lojas/${l.id}`, { metodo: 'PUT', dados: { status: b.dataset.novo } });
          Loja.aviso(suspender ? 'Loja suspensa.' : 'Loja reativada.', 'ok');
          ir();
        } catch (err) {
          falha(err);
        }
      }
    });
  }

  /** Sugestão de endereço a partir do nome: "Tênis de Mesa SP" -> "tenis-de-mesa-sp". */
  const sugerirSlug = (nome) => nome.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40).replace(/-+$/, '');

  function novaLoja() {
    const base = (M.urlBase || location.origin).replace(/^https?:\/\//, '');
    dialogo('Nova loja', `
      <form id="fLoja" class="campos" novalidate>
        <label class="c-12">Nome da loja <input name="nome" maxlength="120" required></label>
        <label class="c-12">Endereço
          <span class="endereco-loja"><span class="fraco">${esc(base)}/</span><input name="slug" maxlength="40" pattern="[a-z0-9-]+" autocomplete="off" required></span>
          <span class="dica">Letras minúsculas, números e hífen. <strong>Não pode ser mudado depois</strong> (é o endereço dos links de pagamento e dos avisos do Asaas).</span>
        </label>
        <h3 class="c-12 titulo-form">Primeiro acesso ao painel da loja</h3>
        <label class="c-6">Nome do administrador <input name="admin_nome" maxlength="100" required></label>
        <label class="c-6">E-mail <input name="admin_email" type="email" required></label>
        <label class="c-12">Senha inicial (mín. 8) <span class="endereco-loja"><input name="admin_senha" minlength="8" autocomplete="new-password" required><button type="button" class="btn btn-pequeno btn-linha" id="btnGerar">Gerar</button></span>
          <span class="dica">Passe a senha ao lojista; ele pode trocar em Configurações › Minha senha. O perfil é "Administrador": as chaves de pagamento ficam com você.</span></label>
      </form>`,
    '<button type="button" class="btn btn-pequeno btn-linha" data-fechar>Cancelar</button><button type="button" class="btn btn-pequeno" id="btnCriarLoja">Criar loja</button>');
    const f = $('#fLoja');
    let slugManual = false;
    f.elements.nome.addEventListener('input', () => { if (!slugManual) f.elements.slug.value = sugerirSlug(f.elements.nome.value); });
    f.elements.slug.addEventListener('input', () => {
      slugManual = true;
      f.elements.slug.value = f.elements.slug.value.toLowerCase().replace(/[^a-z0-9-]/g, '');
    });
    $('#btnGerar').addEventListener('click', () => {
      const c = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
      f.elements.admin_senha.value = Array.from(crypto.getRandomValues(new Uint32Array(12)), (n) => c[n % c.length]).join('');
    });
    $('#btnCriarLoja').addEventListener('click', async (e) => {
      e.target.disabled = true;
      e.target.textContent = 'Criando…';
      const dados = Object.fromEntries(new FormData(f));
      try {
        const r = await api('plataforma/lojas', { metodo: 'POST', dados });
        const l = r.loja;
        dialogo('Loja criada!', `
          <p><strong>${esc(l.nome)}</strong> já está no ar.</p>
          <dl class="resumo-loja">
            <div><dt>Loja</dt><dd><a href="${esc(l.url)}">${esc(l.url)}</a></dd></div>
            <div><dt>Painel</dt><dd><a href="${esc(l.url)}admin/" target="_blank" rel="noopener">${esc(l.url)}admin/</a></dd></div>
            <div><dt>Acesso do lojista</dt><dd>${esc(dados.admin_email)} · senha: <code>${esc(dados.admin_senha)}</code></dd></div>
          </dl>
          <p class="fraco">Próximos passos no painel da loja (você já tem acesso): personalização, cadastro de produtos/serviços e, em Configurações, a chave de pagamento (Asaas) da loja.</p>`,
        '<button type="button" class="btn btn-pequeno" data-fechar>Fechar</button>');
        if (M.tela === 'lojas') ir();
      } catch (err) {
        erroDeForm(f, err);
        e.target.disabled = false;
        e.target.textContent = 'Criar loja';
      }
    });
  }

  function acessos(l) {
    dialogo(`Acessos · ${esc(l.nome)}`, `
      <p class="bloco-sub">Quem entra no painel desta loja. Informe um e-mail que já existe para <strong>redefinir a senha</strong> (lojista que esqueceu), ou um novo para <strong>criar outro acesso</strong> (perfil Administrador).</p>
      <ul class="lista-acessos">${l.administradores.map((a) => `<li>${esc(a.nome)} · ${esc(a.email)} <span class="fraco">(${PERFIS[a.perfil] || esc(a.perfil)})</span></li>`).join('') || '<li class="fraco">Nenhum acesso.</li>'}</ul>
      <form id="fAcesso" class="campos" novalidate>
        <label class="c-6">Nome <input name="nome" maxlength="100" required></label>
        <label class="c-6">E-mail <input name="email" type="email" required></label>
        <label class="c-12">Nova senha (mín. 8) <input name="senha" minlength="8" autocomplete="new-password" required></label>
      </form>`,
    '<button type="button" class="btn btn-pequeno btn-linha" data-fechar>Fechar</button><button type="button" class="btn btn-pequeno" id="btnAcesso">Salvar acesso</button>');
    const f = $('#fAcesso');
    f.elements.email.addEventListener('change', () => {
      const a = l.administradores.find((x) => x.email === f.elements.email.value.trim().toLowerCase());
      if (a && !f.elements.nome.value) f.elements.nome.value = a.nome;
    });
    $('#btnAcesso').addEventListener('click', async () => {
      try {
        const r = await api(`plataforma/lojas/${l.id}/acesso`, { metodo: 'POST', dados: Object.fromEntries(new FormData(f)) });
        Loja.aviso(r.acao === 'senha redefinida' ? 'Senha redefinida.' : 'Acesso criado.', 'ok');
        dlg.close();
        ir();
      } catch (err) {
        erroDeForm(f, err);
      }
    });
  }

  function renomear(l) {
    dialogo('Renomear loja', `
      <form id="fNome" class="campos" novalidate>
        <label class="c-12">Nome na plataforma <input name="nome" maxlength="120" value="${esc(l.nome)}" required></label>
        <p class="c-12 fraco">É o nome nesta lista. O nome que os clientes veem é definido no painel da loja (Configurações › Loja).</p>
      </form>`,
    '<button type="button" class="btn btn-pequeno btn-linha" data-fechar>Cancelar</button><button type="button" class="btn btn-pequeno" id="btnNome">Salvar</button>');
    $('#btnNome').addEventListener('click', async () => {
      try {
        await api(`plataforma/lojas/${l.id}`, { metodo: 'PUT', dados: { nome: $('#fNome').elements.nome.value } });
        dlg.close();
        ir();
      } catch (err) {
        erroDeForm($('#fNome'), err);
      }
    });
  }

  // ---------------- Minha conta ----------------

  async function telaConta(el) {
    el.innerHTML = `
      <section class="bloco">
        <h2>Administrador master</h2>
        <p class="bloco-sub">Acesso total a todas as lojas.</p>
        <dl class="resumo-loja">
          <div><dt>E-mail de login</dt><dd>${esc(M.master.email)}</dd></div>
          <div><dt>E-mail de verificação</dt><dd>${esc(M.master.verificacao)}</dd></div>
        </dl>
      </section>
      <section class="bloco">
        <h2>Alterar a senha</h2>
        <p class="bloco-sub">Por segurança, a troca exige um código enviado ao e-mail de verificação (${esc(M.master.verificacao)}).</p>
        <button type="button" class="btn btn-pequeno btn-linha" id="btnCodigo">Enviar código</button>
        <form id="fSenha" class="campos" novalidate hidden style="margin-top:16px">
          <label class="c-4">Código recebido <input name="codigo" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required></label>
          <label class="c-4">Nova senha (mín. 8) <input name="senha" type="password" minlength="8" autocomplete="new-password" required></label>
          <label class="c-4">Repita a nova senha <input name="senha2" type="password" minlength="8" autocomplete="new-password" required></label>
          <div class="c-12"><button type="submit" class="btn btn-pequeno">Salvar nova senha</button></div>
        </form>
      </section>`;
    $('#btnCodigo').addEventListener('click', async (e) => {
      e.target.disabled = true;
      try {
        const r = await api('plataforma/codigo', { metodo: 'POST', dados: { finalidade: 'alterar' } });
        Loja.aviso(r.mensagem, 'ok');
        $('#fSenha').hidden = false;
        $('#fSenha').elements.codigo.focus();
      } catch (err) {
        falha(err);
      } finally {
        e.target.disabled = false;
      }
    });
    $('#fSenha').addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = e.target;
      if (f.elements.senha.value !== f.elements.senha2.value) return erroDeForm(f, { message: 'As senhas não conferem.', campo: 'senha2' });
      try {
        const r = await api('plataforma/senha', { metodo: 'POST', dados: { codigo: f.elements.codigo.value, senha: f.elements.senha.value } });
        if (r.csrf) M.csrf = r.csrf;
        Loja.aviso('Senha alterada. Enviamos um aviso ao e-mail de verificação.', 'ok');
        ir();
      } catch (err) {
        erroDeForm(f, err);
      }
    });
  }

  // ---------------- Início ----------------

  (async () => {
    try {
      const s = await Loja.api('plataforma/sessao');
      if (!s.master) {
        location.href = '../entrar.html';
        return;
      }
      M.master = s.master;
      M.csrf = s.csrf;
      $('#nomeAdmin').textContent = s.master.email;
      $('#app').hidden = false;
      ir();
    } catch (e) {
      document.body.innerHTML = `<p class="pg-alerta" style="margin:40px">${esc(e.message)}</p>`;
    }
  })();
})();
