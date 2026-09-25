/*
 * Motor de monitoramento: guarda o histórico de leituras de cada publicação,
 * calcula variações e gera alertas quando o engajamento aumenta.
 */
Odin.Monitor = (() => {
  const MAX_POINTS = 500;
  const MAX_ALERTS = 100;
  const K = Odin.METRICS;

  let mode = null;
  let history = {}; // postId -> [[t, views, reach, likes, comments, shares, saved], ...]
  let alerts = [];

  const toArr = (t, m) => [t, ...K.map((k) => m[k] || 0)];
  const toObj = (a) => Object.fromEntries(K.map((k, i) => [k, a[i + 1]]));

  function use(m) {
    if (mode === m) return;
    mode = m;
    history = Odin.store.get(`odin.history.${m}`, {});
    alerts = Odin.store.get(`odin.alerts.${m}`, []);
  }

  function save() {
    Odin.store.set(`odin.history.${mode}`, history);
    Odin.store.set(`odin.alerts.${mode}`, alerts);
  }

  /** Registra uma nova leitura e devolve os alertas novos. */
  function ingest(posts, now, thr) {
    const fresh = [];

    for (const p of posts) {
      const h = history[p.id] || (history[p.id] = []);
      const prev = h.length ? toObj(h[h.length - 1]) : null;
      h.push(toArr(now, p.metrics));
      if (h.length > MAX_POINTS) h.splice(0, h.length - MAX_POINTS);
      if (!prev) continue; // primeira leitura: apenas linha de base

      const changes = [];
      let spike = false;
      for (const k of K) {
        const from = prev[k], to = p.metrics[k] || 0, d = to - from;
        if (d <= 0) continue;
        const pct = from >= 20 ? (d / from) * 100 : 0;
        const byAbs = d >= thr[k];
        const byPct = pct >= thr.pct && d >= 2;
        if (byAbs || byPct) {
          changes.push({ k, d, from, to });
          if (d >= thr[k] * 3 || (from >= 20 && pct >= thr.pct * 3)) spike = true;
        }
      }
      if (changes.length) {
        fresh.push({
          id: `${p.id}_${now}`,
          t: now,
          postId: p.id,
          caption: p.caption,
          thumb: p.thumb,
          type: p.type,
          hue: p.hue,
          spike,
          changes
        });
      }
    }

    // Descarta histórico de publicações que saíram da lista monitorada.
    const ids = new Set(posts.map((p) => p.id));
    for (const id of Object.keys(history)) if (!ids.has(id)) delete history[id];

    alerts = [...fresh.reverse(), ...alerts].slice(0, MAX_ALERTS);
    save();
    return fresh;
  }

  /** Crescimento de cada métrica na janela (padrão: última hora). */
  function momentum(postId, now = Date.now(), windowMs = 36e5) {
    const h = history[postId];
    const zero = Object.fromEntries(K.map((k) => [k, 0]));
    if (!h || h.length < 2) return { ...zero, engagement: 0 };
    const last = toObj(h[h.length - 1]);
    let base = h[0];
    for (const pt of h) {
      if (pt[0] <= now - windowMs) base = pt;
      else break;
    }
    const b = toObj(base);
    const d = Object.fromEntries(K.map((k) => [k, Math.max(0, last[k] - b[k])]));
    d.engagement = Odin.engagement(d);
    return d;
  }

  function series(postId, key) {
    const h = history[postId] || [];
    if (key === 'engagement') return h.map((a) => Odin.engagement(toObj(a)));
    const i = K.indexOf(key) + 1;
    return h.map((a) => a[i]);
  }

  function getAlerts() { return alerts; }

  function clearAlerts() {
    alerts = [];
    save();
  }

  function reset() {
    history = {};
    alerts = [];
    save();
  }

  return { use, ingest, momentum, series, getAlerts, clearAlerts, reset };
})();
