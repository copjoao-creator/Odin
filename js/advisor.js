/*
 * Conselheiro do ODIN: analisa o desempenho e propõe ações para aumentar
 * alcance e engajamento em cada rede, e compara as redes entre si.
 * As regras usam somente os dados monitorados.
 */
Odin.Advisor = (() => {
  const PRIO = { alta: 0, media: 1, baixa: 2 };
  const DAYS = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
  const PERIODS = [
    { name: 'madrugada (0h–6h)', when: 'de madrugada', from: 0, to: 6 },
    { name: 'manhã (6h–12h)', when: 'pela manhã', from: 6, to: 12 },
    { name: 'tarde (12h–18h)', when: 'à tarde', from: 12, to: 18 },
    { name: 'noite (18h–24h)', when: 'à noite', from: 18, to: 24 }
  ];

  /* Orientações específicas de cada rede. */
  const PLAYBOOK = {
    instagram: {
      hot: 'Compartilhe nos Stories com enquete ou caixa de perguntas, fixe no perfil e responda aos comentários nos próximos 60 minutos. Se tiver verba, este é o melhor post para impulsionar.',
      maxGap: 3, min7: 3,
      freq: 'Contas que publicam de 3 a 5 vezes por semana, e fazem Stories diários, costumam crescer de forma mais consistente.',
      reachRatio: 0.3,
      reach: 'Use Reels com um gancho forte nos 3 primeiros segundos, áudios em alta e posts em colaboração (Collab) com perfis do mesmo nicho.',
      tagsMax: 12,
      tags: 'Prefira de 3 a 5 hashtags bem específicas do nicho; palavras-chave na legenda também ajudam a busca do Instagram.',
      share: 'envie para quem precisa ver isto'
    },
    tiktok: {
      hot: 'Responda aos comentários com vídeo (“Responder com vídeo”), fixe o vídeo no perfil e grave uma continuação enquanto ele está sendo distribuído na página Para Você. Se quiser acelerar, use o Promote.',
      maxGap: 2, min7: 4,
      freq: 'No TikTok a consistência pesa muito: publique pelo menos 4 a 7 vídeos por semana, de preferência em horários parecidos.',
      reachRatio: 0.5,
      reach: 'Prenda a atenção no primeiro segundo, use sons em alta e texto na tela, e prefira vídeos mais curtos, que têm maior taxa de visualização completa.',
      tagsMax: 8,
      tags: 'Use de 3 a 5 hashtags do nicho e escreva palavras-chave na descrição e na tela: o TikTok também funciona como buscador.',
      share: 'manda pra alguém que precisa ver isso'
    },
    youtube: {
      hot: 'Responda e fixe um comentário, inclua o vídeo em uma playlist e na tela final dos outros vídeos, e divulgue-o na aba Comunidade. Se for um Short, vincule-o a um vídeo longo relacionado.',
      maxGap: 7, min7: 1,
      freq: 'Canais com frequência previsível (por exemplo, 1 vídeo longo por semana e 2 a 4 Shorts) crescem de forma mais consistente.',
      reachRatio: 0.15,
      reach: 'Otimize título e thumbnail para aumentar a taxa de cliques, e use Shorts para levar público novo aos vídeos longos.',
      tagsMax: 15,
      tags: 'Use de 1 a 3 hashtags na descrição (as primeiras aparecem acima do título). Com mais de 15, o YouTube ignora todas.',
      share: 'compartilhe com quem vai gostar'
    }
  };

  const has = (pf, metric) => Odin.PLATFORMS[pf].metrics.includes(metric);
  const eng = (p) => Odin.engagement(p.metrics);
  const base = (p, followers) => p.metrics.reach || p.metrics.views || followers || 0;
  const rate = (p, followers) => {
    const b = base(p, followers);
    return b ? (eng(p) / b) * 100 : 0;
  };
  const avg = (arr) => (arr.length ? arr.reduce((a, b) => a + b, 0) / arr.length : 0);
  const hashtags = (c) => (String(c).match(/#[\p{L}\p{N}_]+/gu) || []).length;
  const hasCTA = (c) => /\?|comenta|coment[ae]|marque|marca|salv[ea]|compartilh|link na bio|arrasta|conta pra|inscrev/i.test(c);
  const ageDays = (p) => (Date.now() - new Date(p.timestamp)) / 864e5;
  const q = (p) => `“${Odin.esc(Odin.snippet(p.title || p.caption, 40))}”`;
  const label = (pf) => Odin.PLATFORMS[pf].label;

  /** Formato usado para comparar desempenho: tipo de mídia, ou faixa de duração no TikTok. */
  function formatOf(p) {
    if (p.platform !== 'tiktok') return Odin.TYPE_LABELS[p.type] || p.type;
    if (p.duration <= 30) return 'vídeos de até 30 s';
    if (p.duration <= 60) return 'vídeos de 30 a 60 s';
    return 'vídeos de mais de 1 min';
  }

  function groupAvg(posts, keyFn, valFn) {
    const g = {};
    for (const p of posts) {
      const k = keyFn(p);
      (g[k] || (g[k] = [])).push(valFn(p));
    }
    return Object.entries(g)
      .filter(([, v]) => v.length >= 2)
      .map(([k, v]) => ({ k, avg: avg(v), n: v.length }))
      .sort((a, b) => b.avg - a.avg);
  }

  /** Recomendações de uma rede. group = { platform, account, posts } */
  function platformTips({ platform: pf, account, posts }, momentumOf) {
    const tips = [];
    if (!posts.length) return tips;
    const pb = PLAYBOOK[pf];
    const followers = account?.followers || 0;
    const add = (prio, title, text) => tips.push({ prio, title, text, platform: pf });

    // 1. Publicação ganhando tração agora
    const ranked = posts.map((p) => ({ p, m: momentumOf(p.uid) })).sort((a, b) => b.m.engagement - a.m.engagement);
    const hot = ranked[0];
    if (hot && hot.m.engagement >= 5) {
      add('alta', 'Aproveite a publicação em alta',
        `${q(hot.p)} ganhou +${Odin.fmt(hot.m.engagement)} interações e +${Odin.fmt(hot.m.views)} visualizações na última hora. ${pb.hot}`);
    }

    // 2. Comentários novos para responder
    const newComments = ranked.reduce((s, r) => s + r.m.comments, 0);
    if (newComments > 0) {
      add('alta', `Responda ${newComments} comentário${newComments > 1 ? 's' : ''} novo${newComments > 1 ? 's' : ''}`,
        'Respostas rápidas (de preferência com uma pergunta de volta) prolongam a conversa e sinalizam relevância ao algoritmo. Curta e fixe o melhor comentário.');
    }

    // 3. Frequência de publicação
    const newest = [...posts].sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp))[0];
    const gap = ageDays(newest);
    const last7 = posts.filter((p) => ageDays(p) <= 7).length;
    if (gap >= pb.maxGap) {
      add('alta', 'Volte a publicar',
        `Sua última publicação foi há ${Math.floor(gap)} dias. Longos intervalos reduzem a distribuição das próximas. ${pb.freq}`);
    } else if (last7 < pb.min7) {
      add('media', 'Aumente a frequência',
        `Você publicou ${last7} vez${last7 === 1 ? '' : 'es'} nos últimos 7 dias. ${pb.freq}`);
    }

    // 4. Melhor formato
    const byFormat = groupAvg(posts, formatOf, (p) => rate(p, followers));
    if (byFormat.length >= 2) {
      const best = byFormat[0], worst = byFormat[byFormat.length - 1];
      if (best.avg > worst.avg * 1.2) {
        add('media', `Priorize o formato “${best.k}”`,
          `O formato “${best.k}” tem taxa média de engajamento de ${Odin.pct(best.avg)}, contra ${Odin.pct(worst.avg)} em “${worst.k}”. Direcione a maior parte da produção para ele.`);
      }
    }

    // 5. Melhor horário e dia
    const hourOf = (p) => new Date(p.timestamp).getHours();
    const byPeriod = groupAvg(posts, (p) => PERIODS.findIndex((x) => hourOf(p) >= x.from && hourOf(p) < x.to), eng);
    const byDay = groupAvg(posts, (p) => new Date(p.timestamp).getDay(), eng);
    if (byPeriod.length >= 2) {
      const bp = PERIODS[byPeriod[0].k];
      const dayTxt = byDay.length >= 2 ? ` O melhor dia até agora é ${DAYS[byDay[0].k]}.` : '';
      add('media', `Publique ${bp.when}`,
        `Suas publicações no período da ${bp.name} têm em média ${Odin.fmt(Math.round(byPeriod[0].avg))} interações, o melhor período entre os que você já testou.${dayTxt}`);
    }

    // 6. Chamada para ação
    const withCTA = posts.filter((p) => hasCTA(p.caption));
    const withoutCTA = posts.filter((p) => !hasCTA(p.caption));
    const cCTA = avg(withCTA.map((p) => p.metrics.comments));
    const cNo = avg(withoutCTA.map((p) => p.metrics.comments));
    if (withCTA.length >= 2 && withoutCTA.length >= 2 && cCTA > cNo * 1.2) {
      add('media', 'Use chamadas para ação',
        `Publicações com pergunta ou CTA recebem em média ${Odin.fmt(Math.round(cCTA))} comentários, contra ${Odin.fmt(Math.round(cNo))} sem CTA. Termine sempre com uma pergunta simples ou um pedido direto.`);
    } else if (withCTA.length < posts.length / 2) {
      add('baixa', 'Termine com uma pergunta',
        'Menos da metade das suas publicações convida o público a interagir. Perguntas fáceis de responder aumentam os comentários.');
    }

    // 7. Hashtags
    const tagAvg = avg(posts.map((p) => hashtags(p.caption)));
    if (tagAvg > pb.tagsMax) {
      add('baixa', 'Reduza as hashtags', `Você usa em média ${Math.round(tagAvg)} hashtags. ${pb.tags}`);
    } else if (tagAvg < 1) {
      add('baixa', 'Adicione hashtags e palavras-chave', pb.tags);
    }

    // 8. Compartilhamentos e salvamentos
    const totEng = posts.reduce((s, p) => s + eng(p), 0) || 1;
    if (has(pf, 'shares')) {
      const shareRatio = posts.reduce((s, p) => s + p.metrics.shares, 0) / totEng;
      if (shareRatio < 0.05) {
        add('media', 'Crie conteúdo compartilhável',
          `Só ${Odin.pct(shareRatio * 100)} das interações são compartilhamentos, o sinal que mais leva conteúdo a pessoas novas. Teste temas identificáveis, humor do nicho, opiniões fortes e frases como “${pb.share}”.`);
      }
    }
    if (has(pf, 'saved')) {
      const saveRatio = posts.reduce((s, p) => s + p.metrics.saved, 0) / totEng;
      if (saveRatio < 0.08) {
        add('media', 'Crie conteúdo para salvar',
          `Salvamentos representam ${Odin.pct(saveRatio * 100)} das interações. Carrosséis educativos, checklists, tutoriais e listas são os formatos mais salvos.`);
      }
    }

    // 9. Alcance x público
    if (followers) {
      const recent = posts.filter((p) => ageDays(p) >= 1 && ageDays(p) <= 30);
      const ratio = avg(recent.map((p) => (p.metrics.reach || p.metrics.views) / followers));
      if (recent.length >= 2 && ratio < pb.reachRatio) {
        add('media', 'Amplie o alcance',
          `Suas publicações chegam em média a ${Odin.pct(ratio * 100)} do número de ${Odin.PLATFORMS[pf].audience}. ${pb.reach}`);
      }
    }

    // 10. Conteúdo que pode ser reaproveitado
    const top = [...posts].sort((a, b) => eng(b) - eng(a))[0];
    if (top && ageDays(top) > 30) {
      add('baixa', 'Reaproveite seu melhor conteúdo',
        `${q(top)} é sua publicação com mais engajamento e tem ${Math.floor(ageDays(top))} dias. Faça uma “parte 2” ou uma nova versão com outro gancho.`);
    }

    return tips;
  }

  /** Recomendações que comparam as redes. */
  function crossTips(groups) {
    const tips = [];
    const active = groups.filter((g) => g.posts.length >= 2);
    if (active.length < 2) return tips;

    // Rede com audiência mais engajada
    const rates = active
      .map((g) => ({ pf: g.platform, r: avg(g.posts.map((p) => rate(p, g.account?.followers))) }))
      .sort((a, b) => b.r - a.r);
    const best = rates[0], worst = rates[rates.length - 1];
    if (best.r > worst.r * 1.3) {
      tips.push({
        prio: 'media', platform: null,
        title: `Sua audiência mais engajada está no ${label(best.pf)}`,
        text: `Taxa média de engajamento: ${rates.map((x) => `${label(x.pf)} ${Odin.pct(x.r)}`).join(' · ')}. Estreie os conteúdos novos no ${label(best.pf)} e use a reação do público lá para decidir o que adaptar para as outras redes.`
      });
    }

    // Conteúdo campeão recente para adaptar às outras redes
    const recent = active.flatMap((g) => g.posts
      .filter((p) => ageDays(p) <= 14)
      .map((p) => ({ p, pf: g.platform, r: rate(p, g.account?.followers), avgR: avg(g.posts.map((x) => rate(x, g.account?.followers))) })));
    const champ = recent.filter((x) => x.r > x.avgR * 1.3).sort((a, b) => b.r / b.avgR - a.r / a.avgR)[0];
    if (champ) {
      const targets = active.map((g) => g.platform).filter((pf) => pf !== champ.pf)
        .map((pf) => ({ instagram: 'Reels no Instagram', tiktok: 'vídeo no TikTok', youtube: 'Short no YouTube' }[pf]));
      tips.push({
        prio: 'alta', platform: null,
        title: 'Leve o conteúdo campeão para as outras redes',
        text: `${q(champ.p)} (${label(champ.pf)}) está com ${Odin.pct(champ.r)} de engajamento, bem acima da média da rede. Adapte-o como ${targets.join(' e ')}: vídeos verticais curtos funcionam nas três e multiplicam o alcance sem precisar de um roteiro novo.`
      });
    }

    return tips;
  }

  /**
   * Recomendações gerais. groups = [{ platform, account, posts }].
   * Com mais de uma rede, inclui as comparações entre redes.
   */
  function general(groups, momentumOf, limit = 12) {
    const tips = [...crossTips(groups), ...groups.flatMap((g) => platformTips(g, momentumOf))];
    return tips.sort((a, b) => PRIO[a.prio] - PRIO[b.prio]).slice(0, limit);
  }

  /** Recomendações específicas para uma publicação. */
  function forPost(post, group, momentumOf) {
    const pf = post.platform;
    const pb = PLAYBOOK[pf];
    const tips = [];
    const add = (prio, title, text) => tips.push({ prio, title, text });
    const m = post.metrics;
    const followers = group.account?.followers || 0;
    const mo = momentumOf(post.uid);
    const age = ageDays(post);
    const myRate = rate(post, followers);
    const avgRate = avg(group.posts.map((p) => rate(p, followers)));

    if (mo.engagement >= 5) add('alta', 'Está em alta: amplifique agora', pb.hot);
    if (mo.comments > 0) {
      add('alta', `Responda aos ${mo.comments} comentário(s) recente(s)`,
        'Responder na primeira hora aumenta a chance de a conversa continuar e a publicação ser redistribuída.');
    }
    if (m.likes > 20 && m.comments / m.likes < 0.02) {
      add('media', 'Estimule comentários',
        'Há muitas curtidas para poucos comentários. Fixe um comentário seu com uma pergunta direta para puxar a conversa.');
    }
    if (has(pf, 'shares') && m.shares === 0 && age > 0.5) {
      add('media', 'Peça compartilhamentos', `Nenhum compartilhamento até agora. Acrescente “${pb.share}” na legenda ou no vídeo.`);
    }
    if (has(pf, 'saved') && m.likes > 20 && m.saved / m.likes < 0.05) {
      add('baixa', 'Dê motivo para salvar',
        'Poucos salvamentos. Em publicações parecidas, entregue algo prático (passo a passo, lista, dica acionável) e peça “salve para depois”.');
    }
    if (followers && age < 2 && (m.reach || m.views) < followers * pb.reachRatio) {
      add('media', 'Alcance inicial baixo', pb.reach);
    }
    if (hashtags(post.caption) === 0) add('baixa', 'Sem hashtags', pb.tags);
    if (pf === 'youtube') {
      if (String(post.title || '').length < 30) {
        add('baixa', 'Título curto', 'Títulos com a palavra-chave principal e uma promessa clara aumentam cliques e aparições na busca.');
      }
      if (post.type === 'LONG' && age > 14 && myRate >= avgRate) {
        add('media', 'Corte em Shorts', 'Este vídeo longo tem bom engajamento. Extraia de 2 a 3 trechos como Shorts vinculados a ele para trazer público novo.');
      }
    } else if (String(post.caption).length < 40) {
      add('baixa', 'Legenda muito curta', 'Legendas com contexto, história ou uma dica aumentam o tempo de permanência e os comentários.');
    }
    if (avgRate && myRate > avgRate * 1.3) {
      add('media', 'Replique a fórmula',
        `A taxa de engajamento (${Odin.pct(myRate)}) está bem acima da sua média no ${label(pf)} (${Odin.pct(avgRate)}). Faça mais conteúdos com o mesmo tema, gancho e formato, e adapte este para as outras redes.`);
    } else if (avgRate && myRate < avgRate * 0.7 && age > 1) {
      add('baixa', 'Abaixo da média',
        `A taxa de ${Odin.pct(myRate)} está abaixo da sua média no ${label(pf)} (${Odin.pct(avgRate)}). Numa nova versão, teste outra capa, outro gancho inicial ou outro horário.`);
    }
    if (!tips.length) add('baixa', 'Desempenho estável', 'Nenhuma ação urgente. O ODIN avisa quando houver novos picos.');
    return tips.sort((a, b) => PRIO[a.prio] - PRIO[b.prio]);
  }

  return { general, forPost, rate };
})();
