/*
 * Fonte: TikTok Display API (oficial do TikTok for Developers).
 * Requer um app registrado, login OAuth do usuário e os escopos
 * user.info.basic, user.info.profile, user.info.stats e video.list.
 * Documentação: https://developers.tiktok.com/doc/display-api-overview
 *
 * A API do TikTok não aceita chamadas diretas do navegador (CORS), por isso as
 * requisições passam por uma ponte (proxy) no servidor do ODIN (/api/tiktok/...):
 * servidor.ps1 no computador ou api/tiktok.php na hospedagem com PHP.
 * O token de acesso vale 24 h; com o refresh token, o ODIN o renova sozinho.
 */
Odin.Sources.tiktok = (() => {
  const PROXY = 'api/tiktok'; // relativo: funciona na raiz do domínio ou numa subpasta
  const SCOPES = 'user.info.basic,user.info.profile,user.info.stats,video.list';
  const Err = Odin.SourceError;

  function requireServer() {
    if (location.protocol === 'file:') {
      throw new Err('O TikTok precisa de um servidor: abra o ODIN pelo site publicado ou rode servidor.ps1 e abra http://localhost:8787.', 0, true);
    }
  }

  async function call(path, { method = 'GET', token, form, json } = {}) {
    requireServer();
    const headers = {};
    let body;
    if (token) headers.Authorization = `Bearer ${token}`;
    if (form) { headers['Content-Type'] = 'application/x-www-form-urlencoded'; body = new URLSearchParams(form).toString(); }
    if (json) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(json); }
    let res;
    try {
      res = await fetch(`${PROXY}${path}`, { method, headers, body });
    } catch {
      throw new Err('Não foi possível falar com a ponte do TikTok no servidor do ODIN (servidor.ps1 ou api/tiktok.php).');
    }
    const data = await res.json().catch(() => ({}));
    if (data.error?.code === 'proxy_error') throw new Err(`O servidor do ODIN não conseguiu acessar o TikTok: ${data.error.message || res.status}`);
    if (!res.ok && !data.error && !data.access_token) throw new Err(`A ponte do TikTok no servidor respondeu ${res.status}. Confira se a pasta api/ foi enviada.`);
    return { res, data };
  }

  /** Troca o código de autorização (ou o refresh token) por tokens de acesso. */
  async function token(p, params) {
    const { data } = await call('/v2/oauth/token/', {
      method: 'POST',
      form: { client_key: p.clientKey.trim(), client_secret: p.clientSecret.trim(), ...params }
    });
    if (!data.access_token) {
      throw new Err(`TikTok recusou a autenticação: ${data.error_description || data.error || 'resposta inválida'}`, 0, true);
    }
    return {
      accessToken: data.access_token,
      refreshToken: data.refresh_token,
      expiresAt: Date.now() + (data.expires_in || 86400) * 1000
    };
  }

  const canRefresh = (p) => p.refreshToken && p.clientKey && p.clientSecret;

  async function refresh(p, ctx) {
    const t = await token(p, { grant_type: 'refresh_token', refresh_token: p.refreshToken.trim() });
    Object.assign(p, t);
    ctx.update(t);
  }

  async function api(p, ctx, path, opts, retried = false) {
    const { data } = await call(path, { ...opts, token: p.accessToken.trim() });
    const code = data.error?.code;
    if (code && code !== 'ok') {
      if (code === 'access_token_invalid' && canRefresh(p) && !retried) {
        await refresh(p, ctx);
        return api(p, ctx, path, opts, true);
      }
      const auth = ['access_token_invalid', 'scope_not_authorized', 'scope_permission_missed'].includes(code);
      if (code === 'rate_limit_exceeded') throw new Err('Limite de chamadas da API do TikTok atingido. Aumente o intervalo.', code);
      throw new Err(auth
        ? `Token do TikTok inválido ou sem permissão (${code}). Gere um novo em Configurações.`
        : `TikTok: ${data.error.message || code}`, code, auth);
    }
    return data.data || {};
  }

  async function fetchAll(p, ctx) {
    if (!p.accessToken || !p.accessToken.trim()) {
      throw new Err('Conecte sua conta do TikTok em Configurações.', 0, true);
    }
    if (p.expiresAt && p.expiresAt < Date.now() + 60000 && canRefresh(p)) await refresh(p, ctx);

    const user = (await api(p, ctx, '/v2/user/info/?fields=open_id,username,display_name,follower_count,video_count')).user || {};

    const fields = 'id,title,video_description,create_time,cover_image_url,share_url,view_count,like_count,comment_count,share_count,duration';
    const videos = [];
    let cursor;
    while (videos.length < ctx.postsToWatch) {
      const body = { max_count: Math.min(20, ctx.postsToWatch - videos.length) };
      if (cursor) body.cursor = cursor;
      const page = await api(p, ctx, `/v2/video/list/?fields=${fields}`, { method: 'POST', json: body });
      videos.push(...(page.videos || []));
      if (!page.has_more || !page.videos?.length) break;
      cursor = page.cursor;
    }

    return {
      account: {
        username: user.username || user.display_name || 'tiktok',
        followers: user.follower_count || 0,
        mediaCount: user.video_count || 0
      },
      posts: videos.map((v) => ({
        id: v.id,
        caption: v.video_description || v.title || '',
        type: 'TIKTOK',
        duration: v.duration || 0,
        permalink: v.share_url,
        thumb: v.cover_image_url,
        timestamp: new Date((v.create_time || 0) * 1000).toISOString(),
        metrics: {
          views: v.view_count || 0,
          likes: v.like_count || 0,
          comments: v.comment_count || 0,
          shares: v.share_count || 0
        }
      }))
    };
  }

  /*
   * PKCE (obrigatório em apps Desktop do TikTok): um "verificador" aleatório fica guardado
   * no navegador e o TikTok recebe só o "desafio" (SHA-256 do verificador, em hexadecimal,
   * como pede a documentação do TikTok para Desktop). Só se aplica ao retorno em
   * localhost; no site publicado (app Web, https) o TikTok não usa PKCE.
   */
  const isDesktopRedirect = (uri) => /^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?\//i.test(uri.trim());
  const PKCE_KEY = 'odin.tiktok.pkce';

  async function createPkce() {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
    const bytes = crypto.getRandomValues(new Uint8Array(64));
    const verifier = Array.from(bytes, (b) => chars[b % chars.length]).join('');
    const hash = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier));
    const challenge = Array.from(new Uint8Array(hash), (b) => b.toString(16).padStart(2, '0')).join('');
    Odin.store.set(PKCE_KEY, verifier);
    return challenge;
  }

  async function authorizeUrl(p) {
    const qs = new URLSearchParams({
      client_key: p.clientKey.trim(),
      scope: SCOPES,
      response_type: 'code',
      redirect_uri: p.redirectUri.trim(),
      state: 'odin'
    });
    if (isDesktopRedirect(p.redirectUri)) {
      qs.set('code_challenge', await createPkce());
      qs.set('code_challenge_method', 'S256');
    } else {
      Odin.store.remove(PKCE_KEY);
    }
    return `https://www.tiktok.com/v2/auth/authorize/?${qs}`;
  }

  /** Aceita o código puro ou a URL inteira de retorno (…?code=XYZ&state=odin). */
  async function exchangeCode(p, input) {
    const raw = input.trim();
    const code = raw.includes('code=') ? new URL(raw, location.href).searchParams.get('code') : decodeURIComponent(raw);
    if (!code) throw new Err('Cole o código (ou a URL de retorno) gerado pelo TikTok.');
    const params = { grant_type: 'authorization_code', code, redirect_uri: p.redirectUri.trim() };
    const verifier = Odin.store.get(PKCE_KEY, null);
    if (verifier) params.code_verifier = verifier;
    const t = await token(p, params);
    Odin.store.remove(PKCE_KEY); // cada verificador vale para um único login
    return t;
  }

  return { fetchAll, authorizeUrl, exchangeCode };
})();
