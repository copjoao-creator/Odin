/* ODIN: interface, ciclos de monitoramento por rede e notificações */
(() => {
  const $ = (s) => document.querySelector(s);
  const PF = Odin.PLATFORMS;
  const PF_KEYS = Odin.PLATFORM_KEYS;

  // ---------------- Configuração ----------------

  const DEFAULTS = {
    interval: 300,
    demoInterval: 10,
    postsToWatch: 12,
    desktop: false,
    sound: true,
    thresholds: {
      pct: 10,
      instagram: { views: 100, reach: 50, likes: 10, comments: 3, shares: 3, saved: 3 },
      tiktok: { views: 500, likes: 30, comments: 5, shares: 5 },
      youtube: { views: 100, likes: 10, comments: 3 }
    },
    instagram: { source: 'demo', apiHost: 'graph.instagram.com', apiVersion: 'v23.0', accountId: 'me', token: '' },
    tiktok: { source: 'demo', clientKey: '', clientSecret: '', redirectUri: '', accessToken: '', refreshToken: '', expiresAt: 0 },
    youtube: { source: 'demo', apiKey: '', channel: '' }
  };

  const clone = (o) => JSON.parse(JSON.stringify(o));
  const merge = (base, over) => {
    const out = clone(base);
    for (const [k, v] of Object.entries(over || {})) {
      out[k] = v && typeof v === 'object' && !Array.isArray(v) && typeof out[k] === 'object' ? merge(out[k], v) : v;
    }
    return out;
  };

  function loadConfig() {
    const saved = Odin.store.get('odin.config', {});
    // Migração da versão só-Instagram (campos na raiz).
    if (saved.token !== undefined || saved.mode !== undefined) {
      saved.instagram = {
        source: saved.mode === 'real' ? 'real' : 'demo',
        apiHost: saved.apiHost, apiVersion: saved.apiVersion, accountId: saved.accountId, token: saved.token
      };
      const t = saved.thresholds || {};
      saved.thresholds = { pct: t.pct, instagram: { views: t.views, reach: t.reach, likes: t.likes, comments: t.comments, shares: t.shares, saved: t.saved } };
      for (const k of ['mode', 'apiHost', 'apiVersion', 'accountId', 'token']) delete saved[k];
      const strip = (o) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== undefined));
      saved.instagram = strip(saved.instagram);
      saved.thresholds.instagram = strip(saved.thresholds.instagram);
      if (saved.thresholds.pct === undefined) delete saved.thresholds.pct;
    }
    return merge(DEFAULTS, saved);
  }

  let cfg = loadConfig();
  const saveConfig = () => Odin.store.set('odin.config', cfg);
  saveConfig();

  // ---------------- Estado ----------------

  const state = { paused: false, filter: 'all' };
  const nets = Object.fromEntries(PF_KEYS.map((pf) => [pf, {
    timer: null, loading: false, error: null, blocked: false,
    account: null, posts: [], lastUpdate: null, flashed: new Set()
  }]));

  const enabled = () => PF_KEYS.filter((pf) => cfg[pf].source !== 'off');
  const scopeOf = (pf) => `${pf}:${cfg[pf].source}`;
  const momentumOf = (uid) => Odin.Monitor.momentum(uid);
  const visible = () => enabled().filter((pf) => state.filter === 'all' || state.filter === pf);
  const allPosts = () => enabled().flatMap((pf) => nets[pf].posts);
  const visiblePosts = () => visible().flatMap((pf) => nets[pf].posts);
  const groups = () => visible().map((pf) => ({ platform: pf, account: nets[pf].account, posts: nets[pf].posts }));

  // ---------------- Ciclo de monitoramento (um por rede) ----------------

  async function cycle(pf) {
    const n = nets[pf];
    const pc = cfg[pf];
    if (n.loading || pc.source === 'off') return;
    clearTimeout(n.timer);
    n.loading = true;
    renderStatus();

    const scope = scopeOf(pf);
    try {
      const ctx = {
        postsToWatch: cfg.postsToWatch,
        update: (patch) => { Object.assign(cfg[pf], patch); saveConfig(); }
      };
      const { account, posts } = pc.source === 'demo'
        ? await Odin.Demo.fetchAll(pf, cfg.postsToWatch)
        : await Odin.Sources[pf].fetchAll({ ...pc }, ctx);
      if (scopeOf(pf) !== scope) return; // a fonte mudou durante a leitura

      for (const p of posts) {
        p.platform = pf;
        p.uid = `${scope}:${p.id}`;
      }
      const now = Date.now();
      const fresh = Odin.Monitor.ingest(scope, posts, now, { ...cfg.thresholds[pf], pct: cfg.thresholds.pct });

      Object.assign(n, { account, posts, lastUpdate: now, error: null, blocked: false, flashed: new Set(fresh.map((a) => a.uid)) });
      render();
      notify(fresh);
    } catch (e) {
      console.error(`[ODIN ${pf}]`, e);
      n.error = e;
      if (e.auth) n.blocked = true; // não insiste com credencial inválida até salvar as configurações
    } finally {
      n.loading = false;
      renderStatus();
      renderBanner();
      renderTabs();
      schedule(pf);
    }
  }

  function schedule(pf) {
    const n = nets[pf];
    clearTimeout(n.timer);
    if (state.paused || n.blocked || cfg[pf].source === 'off') return;
    const ms = cfg[pf].source === 'demo' ? cfg.demoInterval * 1000 : Math.max(60, cfg.interval) * 1000;
    n.timer = setTimeout(() => cycle(pf), ms);
  }

  /** (Re)inicia todas as redes, por exemplo após salvar as configurações. */
  function restartAll() {
    Odin.Monitor.keepScopes(enabled().map(scopeOf));
    for (const pf of PF_KEYS) {
      const n = nets[pf];
      clearTimeout(n.timer);
      n.blocked = false;
      n.error = null;
      if (cfg[pf].source === 'off' || (n.posts[0] && !n.posts[0].uid.startsWith(scopeOf(pf) + ':'))) {
        Object.assign(n, { account: null, posts: [], lastUpdate: null });
      }
    }
    if (state.filter !== 'all' && cfg[state.filter].source === 'off') state.filter = 'all';
    render();
    renderStatus();
    renderBanner();
    enabled().forEach((pf) => cycle(pf));
  }

  // ---------------- Notificações ----------------

  const describe = (a) => a.changes.map((c) => `+${Odin.fmt(c.d)} ${Odin.LABELS[c.k].toLowerCase()}`).join(', ');

  function notify(fresh) {
    if (!fresh.length) return;
    if (cfg.sound) beep(fresh.some((a) => a.spike));
    fresh.slice(0, 3).forEach(toast);

    if (cfg.desktop && 'Notification' in window && Notification.permission === 'granted') {
      if (fresh.length > 3) {
        new Notification(`ODIN · ${PF[fresh[0].platform].label}`, {
          body: `${fresh.length} publicações tiveram aumento de engajamento.`,
          tag: `odin-${fresh[0].platform}`
        });
      } else {
        fresh.forEach((a) => {
          const n = new Notification(`${a.spike ? '🔥 Pico' : '📈 Alta'} no ${PF[a.platform].label} · ODIN`, {
            body: `${Odin.snippet(a.caption, 50)}\n${describe(a)}`,
            tag: a.uid
          });
          n.onclick = () => { window.focus(); openPost(a.uid); };
        });
      }
    }
    if (document.hidden) document.title = `(${fresh.length}) ODIN · Monitor de redes sociais`;
  }

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) document.title = 'ODIN · Monitor de redes sociais';
  });

  let audioCtx;
  function beep(spike) {
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      const notes = spike ? [660, 880, 1100] : [740, 988];
      notes.forEach((f, i) => {
        const o = audioCtx.createOscillator(), g = audioCtx.createGain();
        o.type = 'sine';
        o.frequency.value = f;
        const t = audioCtx.currentTime + i * 0.12;
        g.gain.setValueAtTime(0.0001, t);
        g.gain.exponentialRampToValueAtTime(0.15, t + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t + 0.18);
        o.connect(g).connect(audioCtx.destination);
        o.start(t);
        o.stop(t + 0.2);
      });
    } catch { /* áudio indisponível */ }
  }

  function toast(a) {
    const el = document.createElement('div');
    el.className = `toast${a.spike ? ' spike' : ''}`;
    el.innerHTML = `<strong>${Odin.pfBadge(a.platform)} ${a.spike ? '🔥 Pico de engajamento' : '📈 Engajamento em alta'}</strong>
      <span>${Odin.esc(Odin.snippet(a.caption, 45))}</span><br>
      <span class="up">${Odin.esc(describe(a))}</span>`;
    el.onclick = () => openPost(a.uid);
    $('#toasts').appendChild(el);
    setTimeout(() => el.remove(), 7000);
  }

  // ---------------- Renderização ----------------

  function render() {
    renderTabs();
    renderKPIs();
    renderPosts();
    renderAlerts();
    renderAdvice();
  }

  function renderStatus() {
    const on = enabled();
    const loading = on.some((pf) => nets[pf].loading);
    const errors = on.filter((pf) => nets[pf].error);
    let cls = 'pill', txt;
    if (!on.length) { txt = 'Nenhuma rede ativa'; }
    else if (state.paused) { cls += ' paused'; txt = 'Pausado'; }
    else if (errors.length) { cls += ' error'; txt = `Erro em ${errors.map((pf) => PF[pf].label).join(', ')}`; }
    else if (on.every((pf) => cfg[pf].source === 'demo')) { cls += ' demo'; txt = 'Demonstração ao vivo'; }
    else { cls += ' live'; txt = 'Ao vivo'; }
    if (loading) txt += ' · lendo…';
    $('#statusPill').className = cls;
    $('#statusPill').textContent = txt;
    const last = Math.max(0, ...on.map((pf) => nets[pf].lastUpdate || 0));
    $('#lastUpdate').textContent = last ? `Atualizado ${new Date(last).toLocaleTimeString('pt-BR')}` : '';
    $('#btnPause').textContent = state.paused ? 'Retomar' : 'Pausar';
  }

  function renderBanner() {
    const b = $('#banner');
    const on = enabled();
    const errors = on.filter((pf) => nets[pf].error);
    const demos = on.filter((pf) => cfg[pf].source === 'demo');
    if (errors.length) {
      b.className = 'banner';
      b.innerHTML = errors.map((pf) => `<div>${Odin.pfBadge(pf)} ${Odin.esc(nets[pf].error.message)}</div>`).join('');
    } else if (!on.length) {
      b.className = 'banner info';
      b.textContent = 'Nenhuma rede ativa. Abra Configurações para ligar Instagram, TikTok ou YouTube.';
    } else if (demos.length) {
      b.className = 'banner info';
      b.innerHTML = `Modo demonstração em ${demos.map(Odin.pfBadge).join(' ')}: os dados são simulados. Em Configurações, conecte suas contas reais.`;
    } else {
      b.hidden = true;
      return;
    }
    b.hidden = false;
  }

  function renderTabs() {
    const on = enabled();
    const tab = (key, inner, extra = '') => `<button class="tab${state.filter === key ? ' active' : ''}${extra}" data-filter="${key}">${inner}</button>`;
    const items = on.map((pf) => {
      const n = nets[pf];
      const src = cfg[pf].source;
      const dot = n.error ? 'error' : src === 'demo' ? 'demo' : 'live';
      const who = n.account ? `@${Odin.esc(String(n.account.username).replace(/^@/, ''))} · ${Odin.fmt(n.account.followers)} ${PF[pf].audience}` : (n.error ? 'erro de conexão' : 'carregando…');
      return tab(pf, `<span class="dot ${dot}"></span>${Odin.pfBadge(pf)}<span class="tab-sub">${who}</span>`);
    });
    $('#tabs').innerHTML = on.length > 1 ? [tab('all', '<strong>Todas as redes</strong>'), ...items].join('') : items.join('');
  }

  function renderKPIs() {
    const nets_ = visible();
    const posts = visiblePosts();
    const metrics = Odin.METRICS.filter((k) => nets_.some((pf) => PF[pf].metrics.includes(k)));
    const tot = Object.fromEntries(Odin.METRICS.map((k) => [k, 0]));
    const hour = Object.fromEntries(Odin.METRICS.map((k) => [k, 0]));
    for (const p of posts) {
      const mo = momentumOf(p.uid);
      for (const k of Odin.METRICS) { tot[k] += p.metrics[k] || 0; hour[k] += mo[k]; }
    }
    const rates = nets_.flatMap((pf) => nets[pf].posts.map((p) => Odin.Advisor.rate(p, nets[pf].account?.followers)));
    const avgRate = rates.length ? rates.reduce((a, b) => a + b, 0) / rates.length : 0;
    const audience = nets_.reduce((s, pf) => s + (nets[pf].account?.followers || 0), 0);
    const audLabel = nets_.length === 1 ? PF[nets_[0]].audience : 'seguidores e inscritos';

    const card = (label, value, delta) => `<div class="kpi">
      <div class="label">${label}</div>
      <div class="value">${value}</div>
      <div class="delta${delta && /^\+(?!0 )/.test(delta) ? ' up' : ''}">${delta || '&nbsp;'}</div>
    </div>`;
    const hourTxt = (v) => `+${Odin.fmt(v)} na última hora`;

    const cards = [card(audLabel.charAt(0).toUpperCase() + audLabel.slice(1), Odin.fmt(audience), `${nets_.length} rede${nets_.length === 1 ? '' : 's'}`)];
    cards.push(card('Visualizações', Odin.fmt(tot.views), hourTxt(hour.views)));
    if (metrics.includes('reach')) cards.push(card('Alcance', Odin.fmt(tot.reach), hourTxt(hour.reach)));
    cards.push(card('Interações', Odin.fmt(Odin.engagement(tot)), hourTxt(Odin.engagement(hour))));
    cards.push(card('Comentários', Odin.fmt(tot.comments), hourTxt(hour.comments)));
    if (metrics.includes('shares')) cards.push(card('Compartilhamentos', Odin.fmt(tot.shares), hourTxt(hour.shares)));
    if (metrics.includes('saved')) cards.push(card('Salvamentos', Odin.fmt(tot.saved), hourTxt(hour.saved)));
    cards.push(card('Taxa de engajamento', Odin.pct(avgRate), `média de ${posts.length} publicações`));
    $('#kpis').innerHTML = cards.join('');
  }

  function sortedPosts() {
    const by = $('#sortBy').value;
    const list = visiblePosts().map((p) => ({ p, mo: momentumOf(p.uid) }));
    const time = (x) => new Date(x.p.timestamp);
    const cmp = {
      momentum: (a, b) => b.mo.engagement - a.mo.engagement || time(b) - time(a),
      recent: (a, b) => time(b) - time(a),
      engagement: (a, b) => Odin.engagement(b.p.metrics) - Odin.engagement(a.p.metrics),
      views: (a, b) => b.p.metrics.views - a.p.metrics.views
    }[by];
    return list.sort(cmp);
  }

  function renderPosts() {
    const el = $('#posts');
    const list = sortedPosts();
    if (!list.length) {
      el.innerHTML = `<p class="empty">${enabled().length ? 'Aguardando a primeira leitura…' : 'Nenhuma rede ativa.'}</p>`;
      return;
    }
    const maxMo = Math.max(...list.map((x) => x.mo.engagement));
    el.innerHTML = list.map(({ p, mo }) => {
      const hot = mo.engagement >= 5 && mo.engagement === maxMo;
      const ms = PF[p.platform].metrics;
      const metrics = ms.map((k) => `<div class="metric">
          <div class="m-label">${Odin.SHORT[k]}</div>
          <div class="m-value">${Odin.fmt(p.metrics[k])}${mo[k] ? `<span class="m-delta">+${Odin.fmt(mo[k])}</span>` : ''}</div>
        </div>`).join('');
      const flashed = nets[p.platform].flashed.has(p.uid);
      return `<article class="post${hot ? ' hot' : ''}${flashed ? ' flash' : ''}" data-uid="${Odin.esc(p.uid)}" tabindex="0">
        <div class="thumb">
          ${Odin.thumbHTML(p)}
          <span class="badge">${Odin.pfBadge(p.platform)} ${Odin.TYPE_LABELS[p.type] || p.type}</span>
          ${hot ? '<span class="badge hot-badge">🔥 EM ALTA</span>' : ''}
        </div>
        <div class="post-body">
          <div class="caption">${Odin.esc(p.title || p.caption || 'Sem legenda')}</div>
          <div class="post-date">Publicado ${Odin.ago(new Date(p.timestamp))} · ${Odin.pct(Odin.Advisor.rate(p, nets[p.platform].account?.followers))} de engajamento</div>
          <div class="metrics" style="--cols:${ms.length === 4 ? 4 : 3}">${metrics}</div>
          ${Odin.sparkline(Odin.Monitor.series(p.uid, 'engagement'), { color: hot ? '#f29d4b' : '#d9a94a' })}
        </div>
      </article>`;
    }).join('');
  }

  function renderAlerts() {
    const vis = new Set(visible());
    const list = Odin.Monitor.getAlerts().filter((a) => vis.has(a.platform));
    const el = $('#alerts');
    if (!list.length) {
      el.innerHTML = '<li class="empty">Nenhum alerta ainda. O ODIN avisa assim que alguma publicação ganhar engajamento.</li>';
      return;
    }
    const latest = Math.max(...enabled().map((pf) => nets[pf].lastUpdate || 0));
    el.innerHTML = list.slice(0, 40).map((a) => `<li class="alert${a.spike ? ' spike' : ''}${a.t === latest ? ' new' : ''}" data-uid="${Odin.esc(a.uid)}">
      <div class="a-thumb">${Odin.thumbHTML({ id: a.uid, thumb: a.thumb, type: a.type, hue: a.hue })}</div>
      <div style="min-width:0">
        <div class="a-title">${Odin.pfBadge(a.platform)} ${a.spike ? '🔥 Pico' : '📈 Em alta'} <span class="muted">· ${Odin.ago(a.t)}</span></div>
        <div class="a-text">${Odin.esc(Odin.snippet(a.caption, 60))}</div>
        <div class="a-changes">${Odin.esc(describe(a))}</div>
      </div>
    </li>`).join('');
  }

  const tipHTML = (t) => `<li class="tip ${t.prio}">
      <div class="tip-head"><span class="tip-title">${t.platform ? Odin.pfBadge(t.platform) + ' ' : ''}${t.title}</span><span class="tip-prio">${t.prio === 'media' ? 'média' : t.prio}</span></div>
      <div class="tip-text">${t.text}</div>
    </li>`;

  function renderAdvice() {
    const tips = Odin.Advisor.general(groups().filter((g) => g.posts.length), momentumOf);
    $('#advice').innerHTML = tips.length ? tips.map(tipHTML).join('') : '<li class="empty">Aguardando dados…</li>';
  }

  // ---------------- Detalhe da publicação ----------------

  function openPost(uid) {
    const p = allPosts().find((x) => x.uid === uid);
    if (!p) return;
    const pf = p.platform;
    const mo = momentumOf(p.uid);
    const colors = { views: '#5b8def', reach: '#8b7cf6', likes: '#ef5b8a', comments: '#3ecf8e', shares: '#f29d4b', saved: '#d9a94a' };
    const charts = PF[pf].metrics.map((k) => `<div class="chart">
        <div class="c-top"><span class="c-label">${Odin.LABELS[k]}</span>
          <span><span class="c-value">${Odin.fmt(p.metrics[k])}</span> ${mo[k] ? `<span class="up" style="font-size:12px">+${Odin.fmt(mo[k])}/h</span>` : ''}</span>
        </div>
        ${Odin.sparkline(Odin.Monitor.series(p.uid, k), { color: colors[k], height: 50 })}
      </div>`).join('');
    const group = { platform: pf, account: nets[pf].account, posts: nets[pf].posts };
    const tips = Odin.Advisor.forPost(p, group, momentumOf);
    const where = { instagram: 'no Instagram', tiktok: 'no TikTok', youtube: 'no YouTube' }[pf];

    const dlg = $('#postDlg');
    dlg.innerHTML = `
      <button class="btn detail-close" data-close>Fechar</button>
      <h2>${Odin.pfBadge(pf)} ${Odin.TYPE_ICONS[p.type] || ''} ${Odin.TYPE_LABELS[p.type] || p.type} · ${new Date(p.timestamp).toLocaleString('pt-BR')}</h2>
      <div class="detail-head">
        <div class="thumb">${Odin.thumbHTML(p)}</div>
        <div>
          <div class="detail-caption">${Odin.esc(p.caption || 'Sem legenda')}</div>
          <p class="muted">Taxa de engajamento: <strong>${Odin.pct(Odin.Advisor.rate(p, nets[pf].account?.followers))}</strong>
            ${p.duration ? ` · Duração: ${Math.floor(p.duration / 60)}:${String(p.duration % 60).padStart(2, '0')}` : ''} ·
            ${p.permalink ? `<a href="${Odin.esc(p.permalink)}" target="_blank" rel="noopener" style="color:var(--gold)">Abrir ${where} ↗</a>` : ''}</p>
        </div>
      </div>
      <div class="charts">${charts}</div>
      <h3 style="margin-bottom:10px">Ações propostas para esta publicação</h3>
      <ol class="advice">${tips.map(tipHTML).join('')}</ol>`;
    dlg.querySelector('[data-close]').onclick = () => dlg.close();
    dlg.showModal();
  }

  // ---------------- Configurações ----------------

  const form = $('#settingsForm');
  const getPath = (o, path) => path.split('.').reduce((x, k) => (x == null ? x : x[k]), o);
  const setPath = (o, path, v) => {
    const ks = path.split('.');
    const last = ks.pop();
    ks.reduce((x, k) => (x[k] = x[k] || {}), o)[last] = v;
  };
  const fields = () => [...form.elements].filter((el) => el.name && !('skip' in el.dataset));

  function buildThresholdGrid() {
    const head = `<div></div>${PF_KEYS.map((pf) => `<div class="thr-head">${Odin.pfBadge(pf)}</div>`).join('')}`;
    const rows = Odin.METRICS.map((k) => `<div class="thr-label">${Odin.LABELS[k]}</div>${PF_KEYS.map((pf) => PF[pf].metrics.includes(k)
      ? `<input type="number" min="1" name="thresholds.${pf}.${k}" aria-label="${Odin.LABELS[k]} no ${PF[pf].label}">`
      : '<div class="thr-na">—</div>').join('')}`).join('');
    $('#thrGrid').innerHTML = head + rows;
  }
  buildThresholdGrid();

  function toggleCreds() {
    for (const pf of PF_KEYS) {
      const src = form.elements[`${pf}.source`].value;
      form.querySelector(`.creds[data-for="${pf}"]`).disabled = src !== 'real';
      form.querySelector(`.net-state[data-for="${pf}"]`).textContent = { off: 'desligado', demo: 'demonstração', real: 'conta real' }[src];
    }
  }
  form.querySelectorAll('select.source').forEach((s) => s.addEventListener('change', toggleCreds));

  function openSettings(focusPf) {
    for (const el of fields()) {
      const v = getPath(cfg, el.name);
      if (el.type === 'checkbox') el.checked = !!v;
      else el.value = v ?? '';
    }
    $('#ttCode').value = '';
    if (!cfg.tiktok.redirectUri && /^(localhost|127\.0\.0\.1)$/.test(location.hostname)) {
      form.elements['tiktok.redirectUri'].value = `${location.origin}/`;
    }
    $('#ttStatus').textContent = cfg.tiktok.expiresAt ? `Token atual expira em ${new Date(cfg.tiktok.expiresAt).toLocaleString('pt-BR')}.` : '';
    toggleCreds();
    if (focusPf) form.querySelectorAll('details.net').forEach((d) => { d.open = !!d.querySelector(`[data-for="${focusPf}"]`); });
    $('#settingsDlg').showModal();
  }

  let pendingTikTok = null; // tokens gerados no diálogo, aplicados ao salvar

  $('#btnTtAuthorize').onclick = () => {
    const p = {
      clientKey: form.elements['tiktok.clientKey'].value.trim(),
      clientSecret: form.elements['tiktok.clientSecret'].value.trim(),
      redirectUri: form.elements['tiktok.redirectUri'].value.trim()
    };
    if (!p.clientKey || !p.redirectUri) {
      $('#ttStatus').textContent = 'Preencha a client key e a redirect URI antes de autorizar.';
      return;
    }
    if (!/^(https:\/\/[^\s/]+\.[^\s/]+|http:\/\/(localhost|127\.0\.0\.1):\d+)/i.test(p.redirectUri)) {
      $('#ttStatus').textContent = `❌ A redirect URI precisa ser um endereço completo, por exemplo ${location.origin}/ (igual ao cadastrado no app do TikTok).`;
      return;
    }

    // Retorno para este próprio ODIN: guarda as credenciais e segue o login nesta aba;
    // na volta, o ODIN troca o código pelos tokens sozinho.
    let sameOrigin = false;
    try { sameOrigin = new URL(p.redirectUri).origin === location.origin; } catch { /* URL inválida */ }
    if (sameOrigin) {
      if (!p.clientSecret) {
        $('#ttStatus').textContent = 'Preencha também a client secret antes de autorizar.';
        return;
      }
      Object.assign(cfg.tiktok, p, { source: 'real' });
      saveConfig();
      location.href = Odin.Sources.tiktok.authorizeUrl(p);
      return;
    }
    window.open(Odin.Sources.tiktok.authorizeUrl(p), '_blank', 'noopener');
    $('#ttStatus').textContent = 'Depois de autorizar, o TikTok abre a redirect URI com “?code=…” no endereço. Copie o endereço inteiro e cole no campo 2.';
  };

  /** Volta do login do TikTok (…/?code=…&state=odin): gera os tokens automaticamente. */
  async function handleTikTokReturn() {
    const qs = new URLSearchParams(location.search);
    if (qs.get('state') !== 'odin' || !(qs.get('code') || qs.get('error'))) return;
    history.replaceState(null, '', location.pathname); // tira o código da barra de endereço

    if (qs.get('error')) {
      notice(`❌ O TikTok não autorizou: ${qs.get('error_description') || qs.get('error')}`, false);
      return;
    }
    const p = cfg.tiktok;
    if (!p.clientKey || !p.clientSecret || !p.redirectUri) {
      notice('❌ Credenciais do TikTok não encontradas neste navegador. Abra o ODIN em http://localhost:8787 e autorize por lá.', false);
      return;
    }
    try {
      const t = await Odin.Sources.tiktok.exchangeCode(p, `?code=${encodeURIComponent(qs.get('code'))}`);
      Object.assign(cfg.tiktok, t, { source: 'real' });
      saveConfig();
      notice('✅ TikTok conectado! Os tokens foram gerados e salvos. O monitoramento já começou.', true);
    } catch (e) {
      notice(`❌ Não foi possível gerar os tokens do TikTok: ${e.message}`, false);
    }
  }

  function notice(msg, ok) {
    const el = document.createElement('div');
    el.className = `toast${ok ? '' : ' spike'}`;
    el.innerHTML = `<strong>${Odin.pfBadge('tiktok')} ${Odin.esc(msg)}</strong>`;
    el.onclick = () => el.remove();
    $('#toasts').appendChild(el);
    setTimeout(() => el.remove(), 15000);
  }

  $('#btnTtExchange').onclick = async () => {
    const p = {
      clientKey: form.elements['tiktok.clientKey'].value,
      clientSecret: form.elements['tiktok.clientSecret'].value,
      redirectUri: form.elements['tiktok.redirectUri'].value
    };
    $('#ttStatus').textContent = 'Gerando tokens…';
    try {
      const t = await Odin.Sources.tiktok.exchangeCode(p, $('#ttCode').value);
      form.elements['tiktok.accessToken'].value = t.accessToken;
      form.elements['tiktok.refreshToken'].value = t.refreshToken;
      pendingTikTok = t;
      $('#ttStatus').textContent = '✅ Tokens gerados. Clique em Salvar para começar a monitorar o TikTok.';
    } catch (e) {
      $('#ttStatus').textContent = `❌ ${e.message}`;
    }
  };

  form.elements.desktop.addEventListener('change', async () => {
    if (form.elements.desktop.checked && 'Notification' in window && Notification.permission !== 'granted') {
      const p = await Notification.requestPermission();
      if (p !== 'granted') {
        form.elements.desktop.checked = false;
        alert('O navegador bloqueou as notificações. Libere a permissão nas configurações do site.');
      }
    }
  });

  form.addEventListener('submit', (ev) => {
    ev.preventDefault();
    const next = clone(cfg);
    for (const el of fields()) {
      let v;
      if (el.type === 'checkbox') v = el.checked;
      else if (el.type === 'number') {
        const d = getPath(DEFAULTS, el.name);
        v = Number.isFinite(+el.value) && +el.value > 0 ? +el.value : d;
      } else v = el.value.trim();
      setPath(next, el.name, v);
    }
    next.interval = Math.max(60, next.interval);
    next.postsToWatch = Math.min(50, Math.round(next.postsToWatch));
    next.instagram.apiVersion = next.instagram.apiVersion || DEFAULTS.instagram.apiVersion;
    next.instagram.accountId = next.instagram.accountId || 'me';

    if (pendingTikTok && next.tiktok.accessToken === pendingTikTok.accessToken) next.tiktok.expiresAt = pendingTikTok.expiresAt;
    else if (next.tiktok.accessToken !== cfg.tiktok.accessToken) next.tiktok.expiresAt = 0;
    pendingTikTok = null;

    if (next.instagram.source === 'real' && next.instagram.apiHost === 'graph.facebook.com' && next.instagram.accountId === 'me') {
      alert('No login do Facebook, informe o ID numérico da conta profissional do Instagram (veja o README).');
      return;
    }
    if (next.tiktok.source === 'real' && location.protocol === 'file:') {
      alert('Para monitorar o TikTok, abra o ODIN pelo servidor local (servidor.ps1 → http://localhost:8787).');
    }

    cfg = next;
    saveConfig();
    $('#settingsDlg').close();
    state.paused = false;
    restartAll();
  });

  $('#btnCancelSettings').onclick = () => $('#settingsDlg').close();

  $('#btnResetData').onclick = () => {
    if (!confirm('Apagar o histórico de leituras e alertas de todas as redes?')) return;
    Odin.Monitor.reset();
    Odin.Demo.reset();
    $('#settingsDlg').close();
    for (const pf of PF_KEYS) Object.assign(nets[pf], { account: null, posts: [], lastUpdate: null });
    restartAll();
  };

  // ---------------- Eventos ----------------

  $('#btnRefresh').onclick = () => {
    for (const pf of enabled()) { nets[pf].blocked = false; cycle(pf); }
  };
  $('#btnPause').onclick = () => {
    state.paused = !state.paused;
    if (state.paused) PF_KEYS.forEach((pf) => clearTimeout(nets[pf].timer));
    else enabled().forEach((pf) => cycle(pf));
    renderStatus();
  };
  $('#btnSettings').onclick = () => openSettings();
  $('#btnClearAlerts').onclick = () => { Odin.Monitor.clearAlerts(); renderAlerts(); };
  $('#sortBy').onchange = renderPosts;

  $('#tabs').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-filter]');
    if (!b) return;
    state.filter = b.dataset.filter;
    render();
  });

  const openFromEvent = (ev) => {
    const el = ev.target.closest('[data-uid]');
    if (el) openPost(el.dataset.uid);
  };
  $('#posts').addEventListener('click', openFromEvent);
  $('#posts').addEventListener('keydown', (ev) => { if (ev.key === 'Enter') openFromEvent(ev); });
  $('#alerts').addEventListener('click', openFromEvent);
  $('#banner').addEventListener('click', (ev) => {
    const errPf = enabled().find((pf) => nets[pf].error && ev.target.closest('div')?.innerHTML.includes(`pf-${pf}`));
    if (errPf) openSettings(errPf);
  });

  // Atualiza os tempos relativos ("há 3 min") a cada minuto.
  setInterval(() => { if (allPosts().length) { renderAlerts(); renderStatus(); } }, 60000);

  // ---------------- Início ----------------
  handleTikTokReturn().finally(restartAll);
})();
