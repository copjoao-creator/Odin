/* ODIN: interface, ciclo de monitoramento e notificações */
(() => {
  const $ = (s) => document.querySelector(s);

  const DEFAULTS = {
    mode: 'demo',
    apiHost: 'graph.instagram.com',
    apiVersion: 'v23.0',
    accountId: 'me',
    token: '',
    interval: 300,
    demoInterval: 10,
    postsToWatch: 12,
    thresholds: { views: 100, reach: 50, likes: 10, comments: 3, shares: 3, saved: 3, pct: 10 },
    desktop: false,
    sound: true
  };

  const saved = Odin.store.get('odin.config', {});
  let cfg = { ...DEFAULTS, ...saved, thresholds: { ...DEFAULTS.thresholds, ...(saved.thresholds || {}) } };

  const state = {
    account: null,
    posts: [],
    paused: false,
    loading: false,
    timer: null,
    lastUpdate: null,
    error: null,
    flashed: new Set()
  };

  const momentumOf = (id) => Odin.Monitor.momentum(id);
  const DEMO_MSG = 'Modo demonstração: os dados são simulados. Em Configurações, conecte sua conta profissional do Instagram.';

  // ---------------- Ciclo de monitoramento ----------------

  async function cycle() {
    if (state.loading) return;
    state.loading = true;
    renderStatus();
    Odin.Monitor.use(cfg.mode);

    try {
      const source = cfg.mode === 'demo' ? Odin.Demo : Odin.API;
      const { account, posts } = await source.fetchAll(cfg);
      const now = Date.now();
      const fresh = Odin.Monitor.ingest(posts, now, cfg.thresholds);

      state.account = account;
      state.posts = posts;
      state.lastUpdate = now;
      state.error = null;
      state.flashed = new Set(fresh.map((a) => a.postId));
      if (cfg.mode === 'demo') showBanner(DEMO_MSG, true); else hideBanner();
      render();
      notify(fresh);
    } catch (e) {
      console.error('[ODIN]', e);
      state.error = e;
      showBanner(e.message);
      if (e.auth) {
        state.paused = true;
        if (!cfg.token) openSettings();
      }
    } finally {
      state.loading = false;
      renderStatus();
      schedule();
    }
  }

  function intervalMs() {
    return cfg.mode === 'demo' ? cfg.demoInterval * 1000 : Math.max(60, cfg.interval) * 1000;
  }

  function schedule() {
    clearTimeout(state.timer);
    if (!state.paused) state.timer = setTimeout(cycle, intervalMs());
  }

  // ---------------- Notificações ----------------

  function describe(alert) {
    return alert.changes.map((c) => `+${Odin.fmt(c.d)} ${Odin.LABELS[c.k].toLowerCase()}`).join(', ');
  }

  function notify(fresh) {
    if (!fresh.length) return;
    if (cfg.sound) beep(fresh.some((a) => a.spike));

    fresh.slice(0, 3).forEach((a) => toast(a));

    if (cfg.desktop && 'Notification' in window && Notification.permission === 'granted') {
      if (fresh.length > 3) {
        new Notification('ODIN · Engajamento em alta', {
          body: `${fresh.length} publicações tiveram aumento de engajamento.`,
          tag: 'odin-summary'
        });
      } else {
        fresh.forEach((a) => {
          const n = new Notification(a.spike ? '🔥 ODIN · Pico de engajamento' : 'ODIN · Engajamento em alta', {
            body: `${Odin.snippet(a.caption, 50)}\n${describe(a)}`,
            tag: a.postId
          });
          n.onclick = () => { window.focus(); openPost(a.postId); };
        });
      }
    }

    if (document.hidden) document.title = `(${fresh.length}) ODIN · Monitor do Instagram`;
  }

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) document.title = 'ODIN · Monitor do Instagram';
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
    el.innerHTML = `<strong>${a.spike ? '🔥 Pico de engajamento' : '📈 Engajamento em alta'}</strong>
      <span>${Odin.esc(Odin.snippet(a.caption, 45))}</span><br>
      <span class="up">${Odin.esc(describe(a))}</span>`;
    el.onclick = () => openPost(a.postId);
    $('#toasts').appendChild(el);
    setTimeout(() => el.remove(), 7000);
  }

  // ---------------- Renderização ----------------

  function render() {
    renderKPIs();
    renderPosts();
    renderAlerts();
    renderAdvice();
  }

  function renderStatus() {
    const pill = $('#statusPill');
    let cls = 'pill', txt;
    if (state.error) { cls += ' error'; txt = 'Erro'; }
    else if (state.paused) { cls += ' paused'; txt = 'Pausado'; }
    else if (cfg.mode === 'demo') { cls += ' demo'; txt = 'Demonstração ao vivo'; }
    else { cls += ' live'; txt = `Ao vivo · @${state.account?.username || '…'}`; }
    if (state.loading) txt += ' · lendo…';
    pill.className = cls;
    pill.textContent = txt;
    $('#lastUpdate').textContent = state.lastUpdate ? `Atualizado ${new Date(state.lastUpdate).toLocaleTimeString('pt-BR')}` : '';
    $('#btnPause').textContent = state.paused ? 'Retomar' : 'Pausar';
  }

  function renderKPIs() {
    const tot = Object.fromEntries(Odin.METRICS.map((k) => [k, 0]));
    const hour = Object.fromEntries(Odin.METRICS.map((k) => [k, 0]));
    for (const p of state.posts) {
      const mo = momentumOf(p.id);
      for (const k of Odin.METRICS) { tot[k] += p.metrics[k] || 0; hour[k] += mo[k]; }
    }
    const rates = state.posts.map((p) => Odin.Advisor.rate(p, state.account?.followers));
    const avgRate = rates.length ? rates.reduce((a, b) => a + b, 0) / rates.length : 0;
    const engHour = Odin.engagement(hour);

    const card = (label, value, delta) => `<div class="kpi">
      <div class="label">${label}</div>
      <div class="value">${value}</div>
      <div class="delta ${delta && delta.startsWith('+') && delta !== '+0' ? 'up' : ''}">${delta || '&nbsp;'}</div>
    </div>`;

    $('#kpis').innerHTML = [
      card('Seguidores', Odin.fmt(state.account?.followers), state.account ? `@${Odin.esc(state.account.username)}` : ''),
      card('Visualizações', Odin.fmt(tot.views), `+${Odin.fmt(hour.views)} na última hora`),
      card('Alcance', Odin.fmt(tot.reach), `+${Odin.fmt(hour.reach)} na última hora`),
      card('Interações', Odin.fmt(Odin.engagement(tot)), `+${Odin.fmt(engHour)} na última hora`),
      card('Comentários', Odin.fmt(tot.comments), `+${Odin.fmt(hour.comments)} na última hora`),
      card('Compartilhamentos', Odin.fmt(tot.shares), `+${Odin.fmt(hour.shares)} na última hora`),
      card('Taxa de engajamento', Odin.pct(avgRate), `média de ${state.posts.length} publicações`)
    ].join('');
  }

  function sortedPosts() {
    const by = $('#sortBy').value;
    const list = state.posts.map((p) => ({ p, mo: momentumOf(p.id) }));
    const cmp = {
      momentum: (a, b) => b.mo.engagement - a.mo.engagement || new Date(b.p.timestamp) - new Date(a.p.timestamp),
      recent: (a, b) => new Date(b.p.timestamp) - new Date(a.p.timestamp),
      engagement: (a, b) => Odin.engagement(b.p.metrics) - Odin.engagement(a.p.metrics),
      reach: (a, b) => b.p.metrics.reach - a.p.metrics.reach
    }[by];
    return list.sort(cmp);
  }

  function renderPosts() {
    const el = $('#posts');
    if (!state.posts.length) {
      el.innerHTML = '<p class="empty">Nenhuma publicação encontrada ainda.</p>';
      return;
    }
    const maxMo = Math.max(...state.posts.map((p) => momentumOf(p.id).engagement));
    el.innerHTML = sortedPosts().map(({ p, mo }) => {
      const hot = mo.engagement >= 5 && mo.engagement === maxMo;
      const metrics = Odin.METRICS.map((k) => `<div class="metric">
          <div class="m-label">${Odin.SHORT[k]}</div>
          <div class="m-value">${Odin.fmt(p.metrics[k])}${mo[k] ? `<span class="m-delta">+${Odin.fmt(mo[k])}</span>` : ''}</div>
        </div>`).join('');
      return `<article class="post${hot ? ' hot' : ''}${state.flashed.has(p.id) ? ' flash' : ''}" data-id="${Odin.esc(p.id)}" tabindex="0">
        <div class="thumb">
          ${Odin.thumbHTML(p)}
          <span class="badge">${Odin.TYPE_LABELS[p.type] || p.type}</span>
          ${hot ? '<span class="badge hot-badge">🔥 EM ALTA</span>' : ''}
        </div>
        <div class="post-body">
          <div class="caption">${Odin.esc(p.caption || 'Sem legenda')}</div>
          <div class="post-date">Publicado ${Odin.ago(new Date(p.timestamp))} · ${Odin.pct(Odin.Advisor.rate(p, state.account?.followers))} de engajamento</div>
          <div class="metrics">${metrics}</div>
          ${Odin.sparkline(Odin.Monitor.series(p.id, 'engagement'), { color: hot ? '#f29d4b' : '#d9a94a' })}
        </div>
      </article>`;
    }).join('');
  }

  function renderAlerts() {
    const list = Odin.Monitor.getAlerts();
    const el = $('#alerts');
    if (!list.length) {
      el.innerHTML = '<li class="empty">Nenhum alerta ainda. O ODIN avisa assim que alguma publicação ganhar engajamento.</li>';
      return;
    }
    el.innerHTML = list.slice(0, 40).map((a) => `<li class="alert${a.spike ? ' spike' : ''}${state.lastUpdate === a.t ? ' new' : ''}" data-id="${Odin.esc(a.postId)}">
      <div class="a-thumb">${Odin.thumbHTML({ id: a.postId, thumb: a.thumb, type: a.type, hue: a.hue })}</div>
      <div style="min-width:0">
        <div class="a-title">${a.spike ? '🔥 Pico de engajamento' : '📈 Engajamento em alta'} <span class="muted">· ${Odin.ago(a.t)}</span></div>
        <div class="a-text">${Odin.esc(Odin.snippet(a.caption, 60))}</div>
        <div class="a-changes">${Odin.esc(describe(a))}</div>
      </div>
    </li>`).join('');
  }

  const tipHTML = (t) => `<li class="tip ${t.prio}">
      <div class="tip-head"><span class="tip-title">${t.title}</span><span class="tip-prio">${t.prio === 'media' ? 'média' : t.prio}</span></div>
      <div class="tip-text">${t.text}</div>
    </li>`;

  function renderAdvice() {
    const tips = Odin.Advisor.general(state.posts, state.account, momentumOf);
    $('#advice').innerHTML = tips.length ? tips.map(tipHTML).join('') : '<li class="empty">Aguardando dados…</li>';
  }

  // ---------------- Detalhe da publicação ----------------

  function openPost(id) {
    const p = state.posts.find((x) => x.id === id);
    if (!p) return;
    const mo = momentumOf(p.id);
    const colors = { views: '#5b8def', reach: '#8b7cf6', likes: '#ef5b8a', comments: '#3ecf8e', shares: '#f29d4b', saved: '#d9a94a' };
    const charts = Odin.METRICS.map((k) => `<div class="chart">
        <div class="c-top"><span class="c-label">${Odin.LABELS[k]}</span>
          <span><span class="c-value">${Odin.fmt(p.metrics[k])}</span> ${mo[k] ? `<span class="up" style="font-size:12px">+${Odin.fmt(mo[k])}/h</span>` : ''}</span>
        </div>
        ${Odin.sparkline(Odin.Monitor.series(p.id, k), { color: colors[k], height: 50 })}
      </div>`).join('');
    const tips = Odin.Advisor.forPost(p, state.posts, state.account, momentumOf);

    const dlg = $('#postDlg');
    dlg.innerHTML = `
      <button class="btn detail-close" data-close>Fechar</button>
      <h2>${Odin.TYPE_ICONS[p.type] || ''} ${Odin.TYPE_LABELS[p.type] || p.type} · ${new Date(p.timestamp).toLocaleString('pt-BR')}</h2>
      <div class="detail-head">
        <div class="thumb">${Odin.thumbHTML(p)}</div>
        <div>
          <div class="detail-caption">${Odin.esc(p.caption || 'Sem legenda')}</div>
          <p class="muted">Taxa de engajamento: <strong>${Odin.pct(Odin.Advisor.rate(p, state.account?.followers))}</strong> ·
            ${p.permalink ? `<a href="${Odin.esc(p.permalink)}" target="_blank" rel="noopener" style="color:var(--gold)">Abrir no Instagram ↗</a>` : ''}</p>
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

  function openSettings() {
    form.mode.value = cfg.mode;
    form.apiHost.value = cfg.apiHost;
    form.apiVersion.value = cfg.apiVersion;
    form.accountId.value = cfg.accountId;
    form.token.value = cfg.token;
    form.interval.value = cfg.interval;
    form.postsToWatch.value = cfg.postsToWatch;
    for (const k of [...Odin.METRICS, 'pct']) form[`thr_${k}`].value = cfg.thresholds[k];
    form.desktop.checked = cfg.desktop;
    form.sound.checked = cfg.sound;
    toggleRealFields();
    $('#settingsDlg').showModal();
  }

  function toggleRealFields() {
    $('#realFields').disabled = form.mode.value !== 'real';
  }
  form.querySelectorAll('input[name=mode]').forEach((r) => r.addEventListener('change', toggleRealFields));

  form.desktop.addEventListener('change', async () => {
    if (form.desktop.checked && 'Notification' in window && Notification.permission !== 'granted') {
      const p = await Notification.requestPermission();
      if (p !== 'granted') {
        form.desktop.checked = false;
        alert('O navegador bloqueou as notificações. Libere a permissão nas configurações do site.');
      }
    }
  });

  form.addEventListener('submit', (ev) => {
    ev.preventDefault();
    const num = (v, d) => (Number.isFinite(+v) && +v > 0 ? +v : d);
    const next = {
      ...cfg,
      mode: form.mode.value,
      apiHost: form.apiHost.value,
      apiVersion: form.apiVersion.value.trim() || DEFAULTS.apiVersion,
      accountId: form.accountId.value.trim() || 'me',
      token: form.token.value.trim(),
      interval: Math.max(60, num(form.interval.value, DEFAULTS.interval)),
      postsToWatch: Math.min(50, Math.round(num(form.postsToWatch.value, DEFAULTS.postsToWatch))),
      thresholds: Object.fromEntries([...Odin.METRICS, 'pct'].map((k) => [k, num(form[`thr_${k}`].value, DEFAULTS.thresholds[k])])),
      desktop: form.desktop.checked,
      sound: form.sound.checked
    };

    if (next.mode === 'real' && next.apiHost === 'graph.facebook.com' && next.accountId === 'me') {
      alert('No login do Facebook, informe o ID numérico da conta profissional do Instagram (veja o README).');
      return;
    }

    const modeChanged = next.mode !== cfg.mode;
    cfg = next;
    Odin.store.set('odin.config', cfg);
    $('#settingsDlg').close();

    state.paused = false;
    state.error = null;
    if (modeChanged) { state.posts = []; state.account = null; render(); }
    cycle();
  });

  $('#btnCancelSettings').onclick = () => $('#settingsDlg').close();

  $('#btnResetData').onclick = () => {
    if (!confirm('Apagar o histórico de leituras e alertas deste modo?')) return;
    Odin.Monitor.use(cfg.mode);
    Odin.Monitor.reset();
    if (cfg.mode === 'demo') Odin.Demo.reset();
    $('#settingsDlg').close();
    state.posts = [];
    render();
    cycle();
  };

  // ---------------- Eventos ----------------

  function showBanner(msg, info = false) {
    const b = $('#banner');
    b.textContent = msg;
    b.className = `banner${info ? ' info' : ''}`;
    b.hidden = false;
  }
  function hideBanner() { $('#banner').hidden = true; }

  $('#btnRefresh').onclick = () => { state.error = null; cycle(); };
  $('#btnPause').onclick = () => {
    state.paused = !state.paused;
    if (!state.paused) cycle(); else { clearTimeout(state.timer); renderStatus(); }
  };
  $('#btnSettings').onclick = openSettings;
  $('#btnClearAlerts').onclick = () => { Odin.Monitor.clearAlerts(); renderAlerts(); };
  $('#sortBy').onchange = renderPosts;

  const openFromEvent = (ev) => {
    const el = ev.target.closest('[data-id]');
    if (el) openPost(el.dataset.id);
  };
  $('#posts').addEventListener('click', openFromEvent);
  $('#posts').addEventListener('keydown', (ev) => { if (ev.key === 'Enter') openFromEvent(ev); });
  $('#alerts').addEventListener('click', openFromEvent);

  // Atualiza os tempos relativos ("há 3 min") a cada minuto.
  setInterval(() => { if (state.posts.length) { renderAlerts(); renderStatus(); } }, 60000);

  // ---------------- Início ----------------
  Odin.Monitor.use(cfg.mode);
  render();
  renderStatus();
  cycle();
})();
