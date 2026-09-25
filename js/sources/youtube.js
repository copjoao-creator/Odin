/*
 * Fonte: YouTube Data API v3 (oficial do Google).
 * Requer apenas uma chave de API e o ID (UC...) ou @handle do canal.
 * Documentação: https://developers.google.com/youtube/v3
 *
 * Custo de cota por leitura: 3 unidades (cota gratuita de 10.000 por dia).
 * Compartilhamentos só existem na YouTube Analytics API (OAuth) e não são lidos aqui.
 */
Odin.Sources.youtube = (() => {
  const BASE = 'https://www.googleapis.com/youtube/v3';
  const SHORT_MAX_SECONDS = 180; // Shorts podem ter até 3 minutos
  const Err = Odin.SourceError;

  async function get(p, path, params) {
    const qs = new URLSearchParams({ ...params, key: p.apiKey.trim() });
    let res;
    try {
      res = await fetch(`${BASE}/${path}?${qs}`);
    } catch {
      throw new Err('Falha de rede ao acessar a API do YouTube. Verifique sua conexão.');
    }
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.error) {
      const err = data.error || {};
      const reason = err.errors?.[0]?.reason || '';
      if (reason === 'quotaExceeded' || reason === 'rateLimitExceeded') {
        throw new Err('Cota diária da API do YouTube esgotada. Aumente o intervalo de atualização.', res.status);
      }
      const auth = ['keyInvalid', 'badRequest', 'forbidden', 'accessNotConfigured', 'ipRefererBlocked'].includes(reason) || res.status === 403;
      throw new Err(auth
        ? `Chave de API do YouTube inválida ou sem acesso à YouTube Data API v3: ${err.message || reason}`
        : (err.message || `Erro HTTP ${res.status}`), res.status, auth);
    }
    return data;
  }

  function channelParams(channel) {
    const c = channel.trim();
    if (/^UC[\w-]{22}$/.test(c)) return { id: c };
    const handle = c.replace(/^https?:\/\/(www\.)?youtube\.com\//, '').replace(/\/.*$/, '');
    return { forHandle: handle.startsWith('@') ? handle : `@${handle}` };
  }

  async function fetchAll(p, ctx) {
    if (!p.apiKey || !p.apiKey.trim()) throw new Err('Informe a chave de API do YouTube em Configurações.', 0, true);
    if (!p.channel || !p.channel.trim()) throw new Err('Informe o ID ou @handle do canal do YouTube em Configurações.', 0, true);

    const ch = await get(p, 'channels', { part: 'snippet,statistics,contentDetails', ...channelParams(p.channel) });
    const channel = ch.items?.[0];
    if (!channel) throw new Err(`Canal do YouTube “${p.channel}” não encontrado.`, 404, true);

    const uploads = channel.contentDetails.relatedPlaylists.uploads;
    const list = await get(p, 'playlistItems', {
      part: 'contentDetails',
      playlistId: uploads,
      maxResults: String(Math.min(50, ctx.postsToWatch))
    });
    const ids = (list.items || []).map((i) => i.contentDetails.videoId);

    const videos = ids.length
      ? (await get(p, 'videos', { part: 'snippet,statistics,contentDetails', id: ids.join(',') })).items || []
      : [];

    return {
      account: {
        username: channel.snippet.customUrl || channel.snippet.title,
        followers: +channel.statistics.subscriberCount || 0,
        mediaCount: +channel.statistics.videoCount || 0
      },
      posts: videos
        .filter((v) => v.snippet.liveBroadcastContent !== 'upcoming')
        .map((v) => {
          const duration = Odin.isoDuration(v.contentDetails.duration);
          const short = duration > 0 && duration <= SHORT_MAX_SECONDS;
          const s = v.statistics || {};
          return {
            id: v.id,
            caption: `${v.snippet.title}\n${v.snippet.description || ''}`.trim(),
            title: v.snippet.title,
            type: short ? 'SHORT' : 'LONG',
            duration,
            permalink: short ? `https://www.youtube.com/shorts/${v.id}` : `https://www.youtube.com/watch?v=${v.id}`,
            thumb: v.snippet.thumbnails?.medium?.url || v.snippet.thumbnails?.default?.url,
            timestamp: v.snippet.publishedAt,
            metrics: {
              views: +s.viewCount || 0,
              likes: +s.likeCount || 0,
              comments: +s.commentCount || 0
            }
          };
        })
    };
  }

  return { fetchAll };
})();
