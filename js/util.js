/* Utilitários compartilhados do ODIN */
const Odin = window.Odin || (window.Odin = {});

Odin.METRICS = ['views', 'reach', 'likes', 'comments', 'shares', 'saved'];

Odin.LABELS = {
  views: 'Visualizações',
  reach: 'Alcance',
  likes: 'Curtidas',
  comments: 'Comentários',
  shares: 'Compartilhamentos',
  saved: 'Salvamentos'
};

Odin.SHORT = {
  views: 'Views',
  reach: 'Alcance',
  likes: 'Curtidas',
  comments: 'Coment.',
  shares: 'Compart.',
  saved: 'Salvos'
};

Odin.TYPE_LABELS = {
  REELS: 'Reels',
  CAROUSEL_ALBUM: 'Carrossel',
  IMAGE: 'Foto',
  VIDEO: 'Vídeo'
};

Odin.TYPE_ICONS = { REELS: '🎬', CAROUSEL_ALBUM: '🗂️', IMAGE: '🖼️', VIDEO: '📹' };

Odin.fmt = (n) => new Intl.NumberFormat('pt-BR', { notation: n >= 10000 ? 'compact' : 'standard', maximumFractionDigits: 1 }).format(n || 0);
Odin.pct = (n) => `${(n || 0).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%`;

Odin.engagement = (m) => (m.likes || 0) + (m.comments || 0) + (m.shares || 0) + (m.saved || 0);

Odin.esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

Odin.snippet = (s, n = 60) => {
  const t = String(s || 'Sem legenda').replace(/\s+/g, ' ').trim();
  return t.length > n ? t.slice(0, n - 1) + '…' : t;
};

Odin.ago = (t) => {
  const s = Math.round((Date.now() - t) / 1000);
  if (s < 60) return 'agora mesmo';
  const m = Math.round(s / 60);
  if (m < 60) return `há ${m} min`;
  const h = Math.round(m / 60);
  if (h < 48) return `há ${h} h`;
  return `há ${Math.round(h / 24)} dias`;
};

Odin.store = {
  get(key, fallback) {
    try {
      const v = localStorage.getItem(key);
      return v ? JSON.parse(v) : fallback;
    } catch { return fallback; }
  },
  set(key, value) {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* armazenamento cheio ou bloqueado */ }
  },
  remove(key) {
    try { localStorage.removeItem(key); } catch { /* ignora */ }
  }
};

/** Gera um SVG de linha (sparkline) a partir de uma série de números. */
Odin.sparkline = (values, { color = '#d9a94a', height = 34, fill = true } = {}) => {
  const w = 200, h = height;
  if (!values || values.length < 2) {
    return `<svg class="spark" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none"><line x1="0" y1="${h - 2}" x2="${w}" y2="${h - 2}" stroke="#243149" stroke-dasharray="4 4"/></svg>`;
  }
  const min = Math.min(...values), max = Math.max(...values);
  const range = max - min || 1;
  const pts = values.map((v, i) => [
    (i / (values.length - 1)) * w,
    h - 3 - ((v - min) / range) * (h - 6)
  ]);
  const line = pts.map((p) => p.map((x) => x.toFixed(1)).join(',')).join(' ');
  const area = `0,${h} ${line} ${w},${h}`;
  return `<svg class="spark" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none">
    ${fill ? `<polygon points="${area}" fill="${color}" opacity=".15"/>` : ''}
    <polyline points="${line}" fill="none" stroke="${color}" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round"/>
  </svg>`;
};

/** Miniatura da publicação (imagem real ou placeholder colorido). */
Odin.thumbHTML = (post) => {
  const icon = Odin.TYPE_ICONS[post.type] || '🖼️';
  const hue = post.hue ?? (parseInt(String(post.id).slice(-4), 36) % 360);
  const ph = `<div class="ph" style="background:linear-gradient(135deg,hsl(${hue} 55% 30%),hsl(${(hue + 60) % 360} 55% 18%))">${icon}</div>`;
  if (!post.thumb) return ph;
  return `<img src="${Odin.esc(post.thumb)}" alt="" loading="lazy" referrerpolicy="no-referrer"
    onerror="this.replaceWith(Object.assign(document.createElement('div'),{className:'ph',textContent:'${icon}'}))">`;
};
