/*
 * Motor de monitoramento: guarda o histórico de leituras de cada publicação,
 * calcula variações e gera alertas quando o engajamento aumenta.
 *
 * Cada publicação é identificada por um uid "rede:fonte:id" (ex.: "tiktok:real:7301..."),
 * e cada combinação "rede:fonte" é um escopo independente.
 */
Odin.Monitor = (() => {
  const KEY_HISTORY = 'odin.history.v2';
  const KEY_ALERTS = 'odin.alerts.v2';
  const MAX_POINTS = 500;
  const MAX_ALERTS = 150;
  const K = Odin.METRICS;

  let history = Odin.store.get(KEY_HISTORY, {}); // uid -> [[t, views, reach, likes, comments, shares, saved], ...]
  let alerts = Odin.store.get(KEY_ALERTS, []);

  const toArr = (t, m) => [t, ...K.map((k) => m[k] || 0)];
  const toObj = (a) => Object.fromEntries(K.map((k, i) => [k, a[i + 1]]));
  const scopeOf = (uid) => uid.split(':').slice(0, 2).join(':');

  function save() {
    Odin.store.set(KEY_HISTORY, history);
    Odin.store.set(KEY_ALERTS, alerts);
  }

  /**
   * Registra uma nova leitura de um escopo e devolve os alertas novos.
   * thr = { pct, views, likes, ... } (gatilhos da rede).
   */
  function ingest(scope, posts, now, thr) {
    const fresh = [];

    for (const p of posts) {
      const h = history[p.uid] || (history[p.uid] = []);
      const prev = h.length ? toObj(h[h.length - 1]) : null;
      h.push(toArr(now, p.metrics));
      if (h.length > MAX_POINTS) h.splice(0, h.length - MAX_POINTS);
      if (!prev) continue; // primeira leitura: apenas linha de base

      const changes = [];
      let spike = false;
      for (const k of K) {
        if (!thr[k]) continue; // métrica não disponível nesta rede
        const from = prev[k], to = p.metrics[k] || 0, d = to - from;
        if (d <= 0) continue;
        const pct = from >= 20 ? (d / from) * 100 : 0;
        if (d >= thr[k] || (pct >= thr.pct && d >= 2)) {
          changes.push({ k, d, from, to });
          if (d >= thr[k] * 3 || (from >= 20 && pct >= thr.pct * 3)) spike = true;
        }
      }
      if (changes.length) {
        fresh.push({
          id: `${p.uid}_${now}`,
          t: now,
          uid: p.uid,
          platform: p.platform,
          caption: p.title || p.caption,
          thumb: p.thumb,
          type: p.type,
          hue: p.hue,
          spike,
          changes
        });
      }
    }

    // Descarta histórico de publicações do escopo que saíram da lista monitorada.
    const ids = new Set(posts.map((p) => p.uid));
    for (const uid of Object.keys(history)) {
      if (scopeOf(uid) === scope && !ids.has(uid)) delete history[uid];
    }

    alerts = [...fresh.reverse(), ...alerts].slice(0, MAX_ALERTS);
    save();
    return fresh;
  }

  /** Remove histórico e alertas de escopos que não estão mais ativos. */
  function keepScopes(active) {
    const set = new Set(active);
    for (const uid of Object.keys(history)) if (!set.has(scopeOf(uid))) delete history[uid];
    alerts = alerts.filter((a) => set.has(scopeOf(a.uid)));
    save();
  }

  /** Crescimento de cada métrica na janela (padrão: última hora). */
  function momentum(uid, now = Date.now(), windowMs = 36e5) {
    const h = history[uid];
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

  function series(uid, key) {
    const h = history[uid] || [];
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

  return { ingest, keepScopes, momentum, series, getAlerts, clearAlerts, reset };
})();
