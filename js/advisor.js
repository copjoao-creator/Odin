/*
 * Conselheiro do ODIN: analisa o desempenho e propõe ações para
 * aumentar alcance e engajamento. As regras usam somente os dados monitorados.
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

  const eng = (p) => Odin.engagement(p.metrics);
  const rate = (p, followers) => {
    const base = p.metrics.reach || followers || 0;
    return base ? (eng(p) / base) * 100 : 0;
  };
  const avg = (arr) => (arr.length ? arr.reduce((a, b) => a + b, 0) / arr.length : 0);
  const hashtags = (c) => (String(c).match(/#[\p{L}\p{N}_]+/gu) || []).length;
  const hasCTA = (c) => /\?|comenta|coment[ae]|marque|marca|salv[ea]|compartilh|link na bio|arrasta|conta pra/i.test(c);
  const ageDays = (p) => (Date.now() - new Date(p.timestamp)) / 864e5;
  const q = (p) => `“${Odin.esc(Odin.snippet(p.caption, 40))}”`;

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

  /** Recomendações gerais para a conta. */
  function general(posts, account, momentumOf) {
    const tips = [];
    if (!posts.length) return tips;
    const followers = account?.followers || 0;

    // 1. Publicação ganhando tração agora
    const ranked = posts.map((p) => ({ p, m: momentumOf(p.id) })).sort((a, b) => b.m.engagement - a.m.engagement);
    const hot = ranked[0];
    if (hot && hot.m.engagement >= 5) {
      tips.push({
        prio: 'alta',
        title: 'Aproveite o post em alta',
        text: `${q(hot.p)} ganhou +${Odin.fmt(hot.m.engagement)} interações e +${Odin.fmt(hot.m.views)} visualizações na última hora. Compartilhe nos Stories com enquete ou caixa de perguntas, fixe no perfil e responda aos comentários nos próximos 60 minutos: o algoritmo favorece posts que já estão ganhando tração. Se tiver verba, este é o melhor post para impulsionar.`
      });
    }

    // 2. Comentários novos para responder
    const newComments = ranked.reduce((s, r) => s + r.m.comments, 0);
    if (newComments > 0) {
      tips.push({
        prio: 'alta',
        title: `Responda ${newComments} comentário${newComments > 1 ? 's' : ''} novo${newComments > 1 ? 's' : ''}`,
        text: 'Respostas rápidas (de preferência com uma pergunta de volta) dobram a conversa no post e sinalizam relevância ao algoritmo. Curta e fixe o melhor comentário.'
      });
    }

    // 3. Frequência de publicação
    const sorted = [...posts].sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp));
    const daysSinceLast = ageDays(sorted[0]);
    const last7 = posts.filter((p) => ageDays(p) <= 7).length;
    if (daysSinceLast >= 3) {
      tips.push({
        prio: 'alta',
        title: 'Volte a publicar',
        text: `Seu último post foi há ${Math.floor(daysSinceLast)} dias. Longos intervalos reduzem o alcance dos próximos posts. Publique hoje, mesmo que seja um conteúdo simples ou de bastidores.`
      });
    } else if (last7 < 3) {
      tips.push({
        prio: 'media',
        title: 'Aumente a frequência',
        text: `Você publicou ${last7} vez${last7 === 1 ? '' : 'es'} nos últimos 7 dias. Contas que publicam de 3 a 5 vezes por semana, e fazem Stories diários, costumam crescer de forma mais consistente.`
      });
    }

    // 4. Melhor formato
    const byType = groupAvg(posts, (p) => p.type, (p) => rate(p, followers));
    if (byType.length >= 2) {
      const best = byType[0], worst = byType[byType.length - 1];
      if (best.avg > worst.avg * 1.2) {
        tips.push({
          prio: 'media',
          title: `Priorize ${Odin.TYPE_LABELS[best.k] || best.k}`,
          text: `${Odin.TYPE_LABELS[best.k] || best.k} tem taxa média de engajamento de ${Odin.pct(best.avg)}, contra ${Odin.pct(worst.avg)} em ${Odin.TYPE_LABELS[worst.k] || worst.k}. Direcione a maior parte da produção para esse formato.`
        });
      }
    }

    // 5. Melhor horário e dia
    const byPeriod = groupAvg(posts, (p) => PERIODS.findIndex((x) => new Date(p.timestamp).getHours() >= x.from && new Date(p.timestamp).getHours() < x.to), eng);
    const byDay = groupAvg(posts, (p) => new Date(p.timestamp).getDay(), eng);
    if (byPeriod.length >= 2) {
      const bp = PERIODS[byPeriod[0].k];
      const dayTxt = byDay.length >= 2 ? ` O melhor dia até agora é ${DAYS[byDay[0].k]}.` : '';
      tips.push({
        prio: 'media',
        title: `Publique ${bp.when}`,
        text: `Seus posts publicados no período da ${bp.name} têm em média ${Odin.fmt(byPeriod[0].avg)} interações, o melhor período entre os que você já testou.${dayTxt}`
      });
    }

    // 6. Chamada para ação na legenda
    const withCTA = posts.filter((p) => hasCTA(p.caption));
    const withoutCTA = posts.filter((p) => !hasCTA(p.caption));
    const cCTA = avg(withCTA.map((p) => p.metrics.comments));
    const cNo = avg(withoutCTA.map((p) => p.metrics.comments));
    if (withCTA.length >= 2 && withoutCTA.length >= 2 && cCTA > cNo * 1.2) {
      tips.push({
        prio: 'media',
        title: 'Use chamadas para ação',
        text: `Posts com pergunta ou CTA na legenda recebem em média ${Odin.fmt(cCTA)} comentários, contra ${Odin.fmt(cNo)} sem CTA. Termine toda legenda com uma pergunta simples ou um pedido direto (“salve”, “marque alguém”).`
      });
    } else if (withCTA.length < posts.length / 2) {
      tips.push({
        prio: 'baixa',
        title: 'Termine legendas com uma pergunta',
        text: 'Menos da metade das suas legendas convida o público a interagir. Perguntas fáceis de responder aumentam os comentários.'
      });
    }

    // 7. Hashtags
    const tagAvg = avg(posts.map((p) => hashtags(p.caption)));
    if (tagAvg > 12) {
      tips.push({ prio: 'baixa', title: 'Reduza as hashtags', text: `Você usa em média ${Math.round(tagAvg)} hashtags. Prefira de 3 a 5 bem específicas do seu nicho; palavras-chave na legenda também ajudam a busca do Instagram.` });
    } else if (tagAvg < 1) {
      tips.push({ prio: 'baixa', title: 'Adicione hashtags e palavras-chave', text: 'Inclua de 3 a 5 hashtags específicas e palavras-chave do seu nicho na legenda para aparecer nas buscas e na aba Explorar.' });
    }

    // 8. Compartilhamentos e salvamentos
    const totEng = posts.reduce((s, p) => s + eng(p), 0) || 1;
    const shareRatio = posts.reduce((s, p) => s + p.metrics.shares, 0) / totEng;
    const saveRatio = posts.reduce((s, p) => s + p.metrics.saved, 0) / totEng;
    if (shareRatio < 0.05) {
      tips.push({ prio: 'media', title: 'Crie conteúdo compartilhável', text: `Só ${Odin.pct(shareRatio * 100)} das interações são compartilhamentos, e compartilhar é o sinal que mais leva alcance a novas pessoas. Teste conteúdos identificáveis, humor do nicho, opiniões fortes e frases do tipo “envie para quem precisa ver isto”.` });
    }
    if (saveRatio < 0.08) {
      tips.push({ prio: 'media', title: 'Crie conteúdo para salvar', text: `Salvamentos representam ${Odin.pct(saveRatio * 100)} das interações. Carrosséis educativos, checklists, tutoriais e listas são os formatos mais salvos, e salvamentos aumentam a distribuição.` });
    }

    // 9. Alcance x seguidores
    if (followers) {
      const recent = posts.filter((p) => ageDays(p) >= 1 && ageDays(p) <= 30);
      const reachRatio = avg(recent.map((p) => p.metrics.reach / followers));
      if (recent.length >= 2 && reachRatio < 0.3) {
        tips.push({ prio: 'media', title: 'Amplie o alcance', text: `Seus posts alcançam em média ${Odin.pct(reachRatio * 100)} dos seus seguidores. Use Reels com um gancho forte nos 3 primeiros segundos, áudios em alta e posts em colaboração (Collab) com perfis do mesmo nicho.` });
      }
    }

    // 10. Conteúdo que pode ser reaproveitado
    const top = [...posts].sort((a, b) => eng(b) - eng(a))[0];
    if (top && ageDays(top) > 30) {
      tips.push({ prio: 'baixa', title: 'Reaproveite seu melhor conteúdo', text: `${q(top)} é seu post com mais engajamento e foi publicado há ${Math.floor(ageDays(top))} dias. Transforme-o em Reel, carrossel ou numa “parte 2”.` });
    }

    return tips.sort((a, b) => PRIO[a.prio] - PRIO[b.prio]);
  }

  /** Recomendações específicas para uma publicação. */
  function forPost(post, posts, account, momentumOf) {
    const tips = [];
    const m = post.metrics;
    const followers = account?.followers || 0;
    const mo = momentumOf(post.id);
    const age = ageDays(post);
    const myRate = rate(post, followers);
    const avgRate = avg(posts.map((p) => rate(p, followers)));

    if (mo.engagement >= 5) {
      tips.push({ prio: 'alta', title: 'Está em alta: amplifique agora', text: 'Reposte nos Stories com um sticker interativo, responda aos comentários enquanto a conversa está ativa e considere impulsionar este post.' });
    }
    if (mo.comments > 0) {
      tips.push({ prio: 'alta', title: `Responda aos ${mo.comments} comentário(s) recente(s)`, text: 'Responder na primeira hora aumenta a chance de a conversa continuar e o post ser redistribuído.' });
    }
    if (m.likes > 20 && m.comments / m.likes < 0.02) {
      tips.push({ prio: 'media', title: 'Estimule comentários', text: 'Há muitas curtidas para poucos comentários. Fixe um comentário seu com uma pergunta ou edite a legenda para incluir uma pergunta direta.' });
    }
    if (m.shares === 0 && age > 0.5) {
      tips.push({ prio: 'media', title: 'Peça compartilhamentos', text: 'Nenhum compartilhamento até agora. Acrescente “envie para um amigo que precisa ver isto” na legenda ou nos Stories.' });
    }
    if (m.likes > 20 && m.saved / m.likes < 0.05) {
      tips.push({ prio: 'baixa', title: 'Dê motivo para salvar', text: 'Poucos salvamentos. Em próximos posts parecidos, entregue algo prático (passo a passo, lista, dica acionável) e peça “salve para depois”.' });
    }
    if (followers && age < 2 && m.reach < followers * 0.3) {
      tips.push({ prio: 'media', title: 'Alcance inicial baixo', text: 'Divulgue nos Stories com link para o post, envie para os Amigos Próximos e peça a parceiros que comentem ou compartilhem nas primeiras horas.' });
    }
    if (hashtags(post.caption) === 0) {
      tips.push({ prio: 'baixa', title: 'Sem hashtags', text: 'Edite a legenda e inclua de 3 a 5 hashtags específicas do nicho, além de palavras-chave relevantes.' });
    }
    if (String(post.caption).length < 40) {
      tips.push({ prio: 'baixa', title: 'Legenda muito curta', text: 'Legendas com contexto, história ou uma dica aumentam o tempo de permanência e os comentários.' });
    }
    if (avgRate && myRate > avgRate * 1.3) {
      tips.push({ prio: 'media', title: 'Replique a fórmula', text: `A taxa de engajamento (${Odin.pct(myRate)}) está bem acima da sua média (${Odin.pct(avgRate)}). Faça mais conteúdos com o mesmo tema, gancho e formato.${age > 30 ? ' Por ser antigo, vale republicar como Reel ou “parte 2”.' : ''}` });
    } else if (avgRate && myRate < avgRate * 0.7 && age > 1) {
      tips.push({ prio: 'baixa', title: 'Abaixo da média', text: `A taxa de ${Odin.pct(myRate)} está abaixo da sua média (${Odin.pct(avgRate)}). Em uma nova versão, teste outra capa, outro gancho inicial ou outro horário.` });
    }
    if (!tips.length) {
      tips.push({ prio: 'baixa', title: 'Desempenho estável', text: 'Nenhuma ação urgente. Continue acompanhando: o ODIN avisa quando houver novos picos.' });
    }
    return tips.sort((a, b) => PRIO[a.prio] - PRIO[b.prio]);
  }

  return { general, forPost, rate };
})();
