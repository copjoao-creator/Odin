/* Fonte de dados simulada, para testar o ODIN sem conectar ao Instagram. */
Odin.Demo = (() => {
  const KEY = 'odin.demo.state';

  const CAPTIONS = [
    'Bastidores do nosso dia! O que vocês querem ver no próximo vídeo? 👇 #bastidores #rotina',
    '5 dicas que ninguém te conta para começar do jeito certo. Salve para não esquecer! #dicas #aprenda #tutorial',
    'Novidade chegando… 👀 #lancamento',
    'Antes e depois: arrasta pro lado ➡️ #transformacao #resultado #antesedepois',
    'Obrigado por 5 mil seguidores! Vocês são incríveis ❤️',
    'Qual dessas opções você escolheria? Comenta aqui! #enquete #opiniao',
    'Tutorial rápido em 30 segundos ⏱️ #tutorial #passoapasso #dica #aprenda #reels #viral #fyp #explore #instagood #trend #brasil #conteudo #criador #marketing #socialmedia #engajamento',
    'Um dia comum por aqui ☀️',
    'Checklist completo para você salvar e usar depois ✅ #checklist #produtividade',
    'Respondendo às perguntas mais frequentes da semana #perguntas #faq',
    'Promo relâmpago só hoje! Link na bio 🔗 #promo',
    'Erro comum que eu cometia e como corrigi. Você também faz isso? #erros #aprendizado'
  ];
  const TYPES = ['REELS', 'CAROUSEL_ALBUM', 'IMAGE', 'REELS', 'CAROUSEL_ALBUM', 'REELS', 'REELS', 'IMAGE', 'CAROUSEL_ALBUM', 'VIDEO', 'IMAGE', 'REELS'];
  const HOURS = [19, 12, 8, 20, 18, 13, 21, 7, 19, 11, 9, 20];

  let state = null;
  const rnd = (a, b) => a + Math.random() * (b - a);
  const inc = (x) => Math.max(0, Math.round(x * Math.random() * 2));

  function create() {
    const now = Date.now();
    const posts = CAPTIONS.map((caption, i) => {
      const ageDays = i === 0 ? 0.08 : i * 2.6 + rnd(0, 1.5);
      const d = new Date(now - ageDays * 864e5);
      if (i > 0) d.setHours(HOURS[i], Math.floor(rnd(0, 59)));
      const type = TYPES[i];
      const mult = type === 'REELS' ? 1.9 : type === 'CAROUSEL_ALBUM' ? 1.3 : 1;
      const maturity = Math.min(1, 0.25 + ageDays / 3);
      const reach = Math.round(rnd(900, 2400) * mult * maturity);
      const likes = Math.round(reach * rnd(0.04, 0.09));
      return {
        id: `demo_${i + 1}`,
        caption,
        type,
        permalink: 'https://www.instagram.com/',
        thumb: null,
        hue: Math.round(rnd(0, 360)),
        timestamp: d.toISOString(),
        metrics: {
          reach,
          views: Math.round(reach * rnd(1.3, type === 'REELS' ? 2.6 : 1.6)),
          likes,
          comments: Math.round(likes * rnd(0.03, caption.includes('?') ? 0.16 : 0.06)),
          shares: Math.round(likes * rnd(0.02, type === 'REELS' ? 0.2 : 0.08)),
          saved: Math.round(likes * rnd(0.03, /salv|checklist|dicas/i.test(caption) ? 0.35 : 0.08))
        }
      };
    });
    return {
      account: { username: 'sua_marca', followers: 5480, mediaCount: 214, picture: null },
      posts,
      viral: null,
      viralTicks: 0
    };
  }

  function tick() {
    const now = Date.now();
    if (!state.viral && Math.random() < 0.15) {
      const candidates = state.posts.slice(0, 5);
      state.viral = candidates[Math.floor(Math.random() * candidates.length)].id;
      state.viralTicks = 3 + Math.floor(Math.random() * 4);
    }

    for (const p of state.posts) {
      const ageH = (now - new Date(p.timestamp)) / 36e5;
      let heat = 1 / (1 + ageH / 18);
      if (p.type === 'REELS') heat *= 1.6;
      if (p.id === state.viral) heat *= 7;
      if (Math.random() < 0.35 && p.id !== state.viral) continue; // nem todo post muda a cada leitura

      const m = p.metrics;
      const r = inc(40 * heat);
      m.reach += r;
      m.views += r + inc(25 * heat);
      m.likes += inc(3.2 * heat);
      m.comments += Math.random() < 0.5 * heat ? inc(1.2 * heat) : 0;
      m.shares += Math.random() < 0.4 * heat ? inc(1.1 * heat) : 0;
      m.saved += Math.random() < 0.45 * heat ? inc(1.3 * heat) : 0;
    }

    if (state.viral && --state.viralTicks <= 0) state.viral = null;
    state.account.followers += Math.round(rnd(-1, 4));
  }

  async function fetchAll() {
    if (!state) state = Odin.store.get(KEY, null);
    if (!state) state = create();
    else tick();
    Odin.store.set(KEY, state);
    await new Promise((r) => setTimeout(r, 250)); // simula latência da rede
    return JSON.parse(JSON.stringify({ account: state.account, posts: state.posts }));
  }

  function reset() {
    state = null;
    Odin.store.remove(KEY);
  }

  return { fetchAll, reset };
})();
