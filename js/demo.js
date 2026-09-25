/* Fonte de dados simulada, para testar o ODIN sem conectar às redes sociais. */
Odin.Demo = (() => {
  const HOURS = [19, 12, 8, 20, 18, 13, 21, 7, 19, 11, 9, 20];

  /*
   * Perfil de cada rede: legendas, formatos e ritmo de crescimento.
   * viewRate = visualizações novas por leitura; likeRate = curtidas por visualização.
   */
  const PROFILES = {
    instagram: {
      username: 'sua_marca', followers: 5480, spacing: 2.6, viewRate: 65, likeRate: 0.08,
      posts: [
        ['REELS', 'Bastidores do nosso dia! O que vocês querem ver no próximo vídeo? 👇 #bastidores #rotina'],
        ['CAROUSEL_ALBUM', '5 dicas que ninguém te conta para começar do jeito certo. Salve para não esquecer! #dicas #aprenda #tutorial'],
        ['IMAGE', 'Novidade chegando… 👀 #lancamento'],
        ['REELS', 'Antes e depois: arrasta pro lado ➡️ #transformacao #resultado #antesedepois'],
        ['CAROUSEL_ALBUM', 'Obrigado por 5 mil seguidores! Vocês são incríveis ❤️'],
        ['REELS', 'Qual dessas opções você escolheria? Comenta aqui! #enquete #opiniao'],
        ['REELS', 'Tutorial rápido em 30 segundos ⏱️ #tutorial #passoapasso #dica #aprenda #reels #viral #fyp #explore #instagood #trend #brasil #conteudo #criador #marketing #socialmedia #engajamento'],
        ['IMAGE', 'Um dia comum por aqui ☀️'],
        ['CAROUSEL_ALBUM', 'Checklist completo para você salvar e usar depois ✅ #checklist #produtividade'],
        ['VIDEO', 'Respondendo às perguntas mais frequentes da semana #perguntas #faq'],
        ['IMAGE', 'Promo relâmpago só hoje! Link na bio 🔗 #promo'],
        ['REELS', 'Erro comum que eu cometia e como corrigi. Você também faz isso? #erros #aprendizado']
      ]
    },
    tiktok: {
      username: 'sua_marca', followers: 12840, spacing: 1.7, viewRate: 380, likeRate: 0.09,
      posts: [
        ['TIKTOK', 'POV: você descobriu o truque que muda tudo 😱 #fyp #dica #truque', 18],
        ['TIKTOK', 'Parte 2 do vídeo que vocês pediram! #parte2 #fy', 42],
        ['TIKTOK', 'Testei a trend e olha no que deu 😂 #trend #humor', 15],
        ['TIKTOK', '3 erros que todo iniciante comete. Qual você já cometeu? #dicas #aprenda', 58],
        ['TIKTOK', 'Rotina de manhã em 30 segundos ☀️ #rotina #vlog', 30],
        ['TIKTOK', 'Respondendo o comentário de vocês 💬 #respondendo', 64],
        ['TIKTOK', 'Isso aqui ninguém te conta… salva pra depois #segredo #dica', 24],
        ['TIKTOK', 'Bastidores de um dia de gravação 🎬', 95],
        ['TIKTOK', 'Qual você escolhe: A ou B? Comenta! #desafio #enquete', 12],
        ['TIKTOK', 'Tutorial rapidinho 👇 #tutorial #passoapasso #fyp #viral #foryou #tiktokbrasil #dicas #aprenda #hack', 38]
      ]
    },
    youtube: {
      username: '@SuaMarca', followers: 3210, spacing: 3.2, viewRate: 45, likeRate: 0.045,
      posts: [
        ['SHORT', 'Você sabia disso? 🤯 #shorts #curiosidades', 35],
        ['LONG', 'Guia completo para iniciantes (passo a passo)\nTudo o que você precisa saber para começar. #tutorial', 1140],
        ['SHORT', 'Dica rápida que economiza horas #shorts #dica', 28],
        ['LONG', 'Respondendo às perguntas de vocês! Qual pergunta fica para o próximo? #perguntas', 780],
        ['SHORT', 'O erro mais comum 👇 #shorts', 45],
        ['LONG', 'Testei por 30 dias e o resultado me surpreendeu', 960],
        ['SHORT', 'Antes e depois #shorts #transformacao', 22],
        ['LONG', 'Os 10 melhores truques que aprendi este ano', 1320],
        ['LONG', 'Vlog: um dia comigo nos bastidores', 640],
        ['SHORT', 'Qual é a sua opinião? Comenta aí! #shorts', 30]
      ]
    }
  };

  const TYPE_BOOST = { REELS: 1.9, CAROUSEL_ALBUM: 1.3, IMAGE: 1, VIDEO: 1.1, TIKTOK: 1, SHORT: 1.5, LONG: 0.7 };
  const state = {};
  const rnd = (a, b) => a + Math.random() * (b - a);
  const inc = (x) => Math.max(0, Math.round(x * Math.random() * 2));
  const key = (pf) => `odin.demo.${pf}`;

  function create(pf) {
    const prof = PROFILES[pf];
    const now = Date.now();
    const posts = prof.posts.map(([type, caption, duration], i) => {
      const ageDays = i === 0 ? 0.08 : i * prof.spacing + rnd(0, 1.2);
      const d = new Date(now - ageDays * 864e5);
      if (i > 0) d.setHours(HOURS[i % HOURS.length], Math.floor(rnd(0, 59)));
      const maturity = Math.min(1, 0.25 + ageDays / 3);
      const views = Math.round(prof.viewRate * 30 * rnd(0.6, 1.6) * TYPE_BOOST[type] * maturity);
      const likes = Math.round(views * prof.likeRate * rnd(0.6, 1.3));
      const m = {
        views,
        reach: 0,
        likes,
        comments: Math.round(likes * rnd(0.03, caption.includes('?') ? 0.16 : 0.06)),
        shares: 0,
        saved: 0
      };
      if (pf !== 'youtube') m.shares = Math.round(likes * rnd(0.02, type === 'REELS' || type === 'TIKTOK' ? 0.18 : 0.08));
      if (pf === 'instagram') {
        m.reach = Math.round(views * rnd(0.55, 0.75));
        m.saved = Math.round(likes * rnd(0.03, /salv|checklist|dicas/i.test(caption) ? 0.35 : 0.08));
      }
      return {
        id: `demo_${pf}_${i + 1}`,
        caption,
        title: pf === 'youtube' ? caption.split('\n')[0] : undefined,
        type,
        duration: duration || 0,
        permalink: { instagram: 'https://www.instagram.com/', tiktok: 'https://www.tiktok.com/', youtube: 'https://www.youtube.com/' }[pf],
        thumb: null,
        hue: Math.round(rnd(0, 360)),
        timestamp: d.toISOString(),
        metrics: m
      };
    });
    return { account: { username: prof.username, followers: prof.followers, mediaCount: posts.length * 18 }, posts, viral: null, viralTicks: 0 };
  }

  function tick(pf) {
    const s = state[pf], prof = PROFILES[pf];
    const now = Date.now();
    if (!s.viral && Math.random() < 0.15) {
      const candidates = s.posts.slice(0, 5);
      s.viral = candidates[Math.floor(Math.random() * candidates.length)].id;
      s.viralTicks = 3 + Math.floor(Math.random() * 4);
    }

    for (const p of s.posts) {
      const ageH = (now - new Date(p.timestamp)) / 36e5;
      let heat = (1 / (1 + ageH / 18)) * TYPE_BOOST[p.type];
      if (p.id === s.viral) heat *= 7;
      if (Math.random() < 0.35 && p.id !== s.viral) continue; // nem todo post muda a cada leitura

      const m = p.metrics;
      const dv = inc(prof.viewRate * heat);
      const dl = dv * prof.likeRate;
      m.views += dv;
      m.likes += inc(dl);
      m.comments += Math.random() < 0.5 * heat ? inc(Math.max(0.6, dl * 0.08)) : 0;
      if (pf !== 'youtube') m.shares += Math.random() < 0.4 * heat ? inc(Math.max(0.5, dl * 0.1)) : 0;
      if (pf === 'instagram') {
        m.reach += Math.round(dv * 0.65);
        m.saved += Math.random() < 0.45 * heat ? inc(Math.max(0.5, dl * 0.12)) : 0;
      }
    }

    if (s.viral && --s.viralTicks <= 0) s.viral = null;
    s.account.followers += Math.round(rnd(-1, 4));
  }

  async function fetchAll(pf, postsToWatch = 12) {
    if (!state[pf]) state[pf] = Odin.store.get(key(pf), null);
    if (!state[pf]) state[pf] = create(pf);
    else tick(pf);
    Odin.store.set(key(pf), state[pf]);
    await new Promise((r) => setTimeout(r, 250)); // simula latência da rede
    const s = state[pf];
    return JSON.parse(JSON.stringify({ account: s.account, posts: s.posts.slice(0, postsToWatch) }));
  }

  function reset(pf) {
    for (const k of pf ? [pf] : Object.keys(PROFILES)) {
      delete state[k];
      Odin.store.remove(key(k));
    }
  }

  return { fetchAll, reset };
})();
