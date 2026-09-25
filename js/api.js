/*
 * Cliente da Instagram Graph API (oficial da Meta).
 * Requer conta Profissional (Empresa ou Criador de conteúdo) e um token de acesso.
 * Documentação: https://developers.facebook.com/docs/instagram-platform
 */
Odin.API = (() => {
  // Métricas de insights por mídia. Métricas não suportadas para um tipo são descobertas e ignoradas.
  const INSIGHT_METRICS = ['reach', 'views', 'saved', 'shares', 'total_interactions'];
  const unsupported = { REELS: new Set(), FEED: new Set() };

  class ApiError extends Error {
    constructor(message, code = 0, auth = false) {
      super(message);
      this.code = code;
      this.auth = auth;
    }
  }

  async function get(cfg, path, params = {}) {
    const qs = new URLSearchParams({ ...params, access_token: cfg.token.trim() });
    let res;
    try {
      res = await fetch(`https://${cfg.apiHost}/${cfg.apiVersion}/${path}?${qs}`);
    } catch {
      throw new ApiError('Falha de rede ao acessar a API do Instagram. Verifique sua conexão.');
    }
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.error) {
      const err = data.error || {};
      const code = err.code || res.status;
      if ([4, 17, 32, 613].includes(code)) {
        throw new ApiError('Limite de chamadas da API atingido. Aumente o intervalo ou reduza o número de publicações monitoradas.', code);
      }
      const auth = code === 190 || code === 102 || (err.type === 'OAuthException' && [10, 200].includes(code));
      throw new ApiError(auth
        ? `Token inválido ou sem permissão: ${err.message || ''}`
        : (err.message || `Erro HTTP ${res.status}`), code, auth);
    }
    return data;
  }

  function parseInsights(json) {
    const out = {};
    for (const d of json.data || []) {
      out[d.name] = d.values?.[0]?.value ?? d.total_value?.value ?? 0;
    }
    return out;
  }

  async function mediaInsights(cfg, media) {
    const kind = media.media_product_type === 'REELS' ? 'REELS' : 'FEED';
    const skip = unsupported[kind];
    const wanted = INSIGHT_METRICS.filter((m) => !skip.has(m));
    if (!wanted.length) return {};

    try {
      return parseInsights(await get(cfg, `${media.id}/insights`, { metric: wanted.join(',') }));
    } catch (e) {
      if (e.auth) throw e;
      if (e.code !== 100) return {}; // ex.: post anterior à conversão para conta profissional

      // Alguma métrica não é aceita: consulta uma a uma e memoriza as não suportadas.
      const out = {};
      for (const metric of wanted) {
        try {
          Object.assign(out, parseInsights(await get(cfg, `${media.id}/insights`, { metric })));
        } catch (e2) {
          if (e2.auth) throw e2;
          if (e2.code === 100 && /metric/i.test(e2.message)) skip.add(metric);
        }
      }
      return out;
    }
  }

  async function pool(items, size, fn) {
    const results = new Array(items.length);
    let next = 0;
    const workers = Array.from({ length: Math.min(size, items.length) }, async () => {
      while (next < items.length) {
        const i = next++;
        results[i] = await fn(items[i]);
      }
    });
    await Promise.all(workers);
    return results;
  }

  async function fetchAll(cfg) {
    if (!cfg.token || !cfg.token.trim()) {
      throw new ApiError('Informe o token de acesso do Instagram em Configurações.', 0, true);
    }
    const uid = (cfg.accountId || 'me').trim();

    const acc = await get(cfg, uid, { fields: 'id,username,followers_count,media_count,profile_picture_url' });
    const list = await get(cfg, `${uid}/media`, {
      fields: 'id,caption,media_type,media_product_type,permalink,thumbnail_url,media_url,timestamp,like_count,comments_count',
      limit: String(cfg.postsToWatch)
    });
    const media = list.data || [];
    const insights = await pool(media, 4, (m) => mediaInsights(cfg, m));

    return {
      account: {
        username: acc.username,
        followers: acc.followers_count || 0,
        mediaCount: acc.media_count || 0,
        picture: acc.profile_picture_url || null
      },
      posts: media.map((m, i) => {
        const ins = insights[i] || {};
        const type = m.media_product_type === 'REELS' ? 'REELS' : m.media_type;
        return {
          id: m.id,
          caption: m.caption || '',
          type,
          permalink: m.permalink,
          thumb: m.media_type === 'VIDEO' ? m.thumbnail_url : m.media_url,
          timestamp: m.timestamp,
          metrics: {
            views: ins.views || 0,
            reach: ins.reach || 0,
            likes: m.like_count || 0,
            comments: m.comments_count || 0,
            shares: ins.shares || 0,
            saved: ins.saved || 0
          }
        };
      })
    };
  }

  return { fetchAll, ApiError };
})();
