/* Loja Odin Focus: utilidades compartilhadas pela vitrine, pela página de pagamento e pelo painel */
const Loja = window.Loja || (window.Loja = {});

/** Pasta da API relativa à página (o painel usa "../api/"). */
Loja.API = (document.currentScript && document.currentScript.dataset.api) || 'api/';

Loja.Erro = class extends Error {
  constructor(message, status = 0, campo = null) {
    super(message);
    this.status = status;
    this.campo = campo;
  }
};

/** Chama a API. `rota` pode levar parâmetros: "pedidos/1/publico&t=abc". */
Loja.api = async (rota, { metodo = 'GET', dados, arquivo, csrf } = {}) => {
  const opcoes = { method: metodo, headers: {}, credentials: 'same-origin' };
  if (csrf) opcoes.headers['X-CSRF-Token'] = csrf;
  if (arquivo) {
    opcoes.body = arquivo;
  } else if (dados !== undefined) {
    opcoes.headers['Content-Type'] = 'application/json';
    opcoes.body = JSON.stringify(dados);
  }
  let resp;
  try {
    resp = await fetch(`${Loja.API}?r=${rota}`, opcoes);
  } catch {
    throw new Loja.Erro('Sem conexão com o servidor. Verifique sua internet e tente de novo.');
  }
  const json = await resp.json().catch(() => ({}));
  if (!resp.ok) throw new Loja.Erro(json.erro || `Erro ${resp.status} no servidor.`, resp.status, json.campo || null);
  return json;
};

Loja.esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const fmtBRL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
Loja.brl = (n) => fmtBRL.format(Number(n) || 0);

// Data sem horário ("2027-03-31") é lida como meia-noite local; sem isso o navegador usa UTC e,
// no Brasil, mostraria o dia anterior.
Loja.data = (s) => (s ? new Date(/^\d{4}-\d{2}-\d{2}$/.test(String(s)) ? `${s}T00:00:00` : String(s).replace(' ', 'T')).toLocaleDateString('pt-BR') : '—');
Loja.dataHora = (s) => (s ? new Date(String(s).replace(' ', 'T')).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '—');

Loja.digitos = (s) => String(s ?? '').replace(/\D+/g, '');

Loja.cpfValido = (valor) => {
  const cpf = Loja.digitos(valor);
  if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
  for (let t = 9; t < 11; t++) {
    let soma = 0;
    for (let i = 0; i < t; i++) soma += Number(cpf[i]) * (t + 1 - i);
    if (Number(cpf[t]) !== ((10 * soma) % 11) % 10) return false;
  }
  return true;
};

Loja.formatar = {
  cpf: (v) => Loja.digitos(v).slice(0, 11).replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2'),
  cep: (v) => Loja.digitos(v).slice(0, 8).replace(/(\d{5})(\d)/, '$1-$2'),
  celular: (v) => Loja.digitos(v).slice(0, 11).replace(/^(\d{2})(\d)/, '($1) $2').replace(/(\d{5})(\d{1,4})$/, '$1-$2')
};

/** Aplica a máscara enquanto a pessoa digita. */
Loja.mascarar = (input, tipo) => {
  const f = Loja.formatar[tipo];
  input.addEventListener('input', () => { input.value = f(input.value); });
  if (input.value) input.value = f(input.value);
};

/** Endereço pelo CEP (ViaCEP, serviço público e gratuito). */
Loja.buscarCep = async (cep) => {
  const c = Loja.digitos(cep);
  if (c.length !== 8) throw new Loja.Erro('CEP incompleto.');
  const resp = await fetch(`https://viacep.com.br/ws/${c}/json/`).catch(() => null);
  const d = resp && resp.ok ? await resp.json() : null;
  if (!d || d.erro) throw new Loja.Erro('CEP não encontrado. Preencha o endereço manualmente.');
  return { rua: d.logradouro || '', bairro: d.bairro || '', cidade: d.localidade || '', estado: d.uf || '' };
};

Loja.UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

Loja.SUFIXO_RENOVACAO = { mensal: '/mês', trimestral: '/trimestre', semestral: '/semestre', anual: '/ano' };

/** Armazenamento local (carrinho). Nunca quebra a página se o navegador bloquear. */
Loja.guardar = {
  ler(chave, padrao) {
    try {
      const v = localStorage.getItem(chave);
      return v ? JSON.parse(v) : padrao;
    } catch {
      return padrao;
    }
  },
  gravar(chave, valor) {
    try {
      localStorage.setItem(chave, JSON.stringify(valor));
    } catch { /* navegador sem armazenamento: segue sem salvar */ }
  }
};

Loja.copiar = async (texto) => {
  try {
    await navigator.clipboard.writeText(texto);
    return true;
  } catch {
    const t = document.createElement('textarea');
    t.value = texto;
    document.body.appendChild(t);
    t.select();
    const ok = document.execCommand('copy');
    t.remove();
    return ok;
  }
};

/** Canais de atendimento cadastrados no painel (WhatsApp, e-mail, Instagram). */
Loja.contatos = (l) => {
  const lista = [];
  if (l.whatsapp) lista.push({ icone: 'conversa', titulo: 'WhatsApp', texto: Loja.formatar.celular(l.whatsapp.replace(/^55/, '')), url: `https://wa.me/${l.whatsapp}` });
  if (l.email) lista.push({ icone: 'email', titulo: 'E-mail', texto: l.email, url: `mailto:${l.email}` });
  if (l.instagram) {
    const user = l.instagram.replace(/^@/, '').replace(/^https?:\/\/(www\.)?instagram\.com\//, '').replace(/\/$/, '');
    lista.push({ icone: 'camera', titulo: 'Instagram', texto: `@${user}`, url: `https://instagram.com/${user}` });
  }
  return lista;
};

/**
 * Endereço da página Fale conosco, com assunto e número do pedido já preenchidos se informados.
 * Todos os links de contato da loja passam por aqui.
 */
Loja.linkContato = ({ assunto, pedido } = {}) => {
  const q = new URLSearchParams();
  if (assunto) q.set('assunto', assunto);
  if (pedido) q.set('pedido', pedido);
  const qs = q.toString();
  return `contato.html${qs ? `?${qs}` : ''}`;
};

/** Itens do rodapé "Atendimento": todos levam à página Fale conosco. */
Loja.rodapeContatos = (l) => ['<li><a href="contato.html">Fale conosco</a></li>']
  .concat(Loja.contatos(l).map((c) => `<li><a href="contato.html">${Loja.esc(c.titulo)}: ${Loja.esc(c.texto)}</a></li>`))
  .join('');

/** Ícones em linha (traço herda a cor do texto). */
const ICONES = {
  carrinho: '<path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.5L20.5 8H6.2"/><circle cx="10" cy="20" r="1.3"/><circle cx="17" cy="20" r="1.3"/>',
  busca: '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.3-4.3"/>',
  conversa: '<path d="M20 11.5a8 8 0 0 1-11.8 7L4 20l1.5-4A8 8 0 1 1 20 11.5Z"/><path d="M9 10.5h.01M12 10.5h.01M15 10.5h.01"/>',
  escudo: '<path d="M12 3 5 6v5c0 4.4 3 8.3 7 10 4-1.7 7-5.6 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4"/>',
  cartao: '<rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="M3 10h18M7 15h4"/>',
  caminhao: '<path d="M3 6h11v10H3zM14 10h4l3 3v3h-7"/><circle cx="7" cy="17.5" r="1.6"/><circle cx="17.5" cy="17.5" r="1.6"/>',
  pix: '<path d="m12 3 4 4-4 4-4-4 4-4ZM12 13l4 4-4 4-4-4 4-4ZM3 12l4-4 4 4-4 4-4-4ZM13 12l4-4 4 4-4 4-4-4Z"/>',
  boleto: '<path d="M4 6v12M7 6v12M10 6v12M12 6v12M15 6v12M18 6v12M20 6v12"/>',
  fechar: '<path d="M6 6l12 12M18 6 6 18"/>',
  mais: '<path d="M12 5v14M5 12h14"/>',
  menos: '<path d="M5 12h14"/>',
  lixeira: '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
  check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
  relogio: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
  email: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 6.5 8.5 6.5 8.5-6.5"/>',
  camera: '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><path d="M17.3 6.7h.01"/>',
  seta: '<path d="M5 12h14M13 6l6 6-6 6"/>',
  menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
  estrela: '<path d="m12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9L12 3.5Z"/>',
  pacote: '<path d="M21 8 12 3 3 8v8l9 5 9-5V8Z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
  alerta: '<path d="M12 3 2 20h20L12 3Z"/><path d="M12 10v4M12 17h.01"/>'
};
Loja.icone = (nome, classe = 'ico') =>
  `<svg class="${classe}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONES[nome] || ''}</svg>`;

/**
 * Marca Odin Focus (obturador de foco com a pupila laranja no centro). Mesmo desenho de
 * img/odin-focus-simbolo.svg. Cada cópia na página recebe ids próprios para o degradê e o recorte.
 */
let marcaSeq = 0;
Loja.marca = (classe = 'marca') => {
  const id = `of${++marcaSeq}`;
  return `<svg class="${classe}" viewBox="0 0 100 100" aria-hidden="true"><defs><clipPath id="${id}-c"><circle cx="50" cy="50" r="46"/></clipPath><radialGradient id="${id}-p" cx=".4" cy=".4"><stop offset="0" stop-color="#FFD27A"/><stop offset=".6" stop-color="#F45900"/><stop offset="1" stop-color="#B83A00"/></radialGradient></defs><g clip-path="url(#${id}-c)"><polygon points="50,50 51.21,35.7 118.26,8.63 121.31,86.69 63.67,41.63 62.99,42.5" fill="#80FFFF"/><polygon points="50,50 62.99,43.9 119.96,88.42 53.88,130.1 64.08,57.65 62.99,57.5" fill="#45D8F0"/><polygon points="50,50 61.78,58.2 51.7,129.8 -17.43,93.41 50.41,66.02 50,65" fill="#1FA8DD"/><polygon points="50,50 48.79,64.3 -18.26,91.37 -21.31,13.31 36.33,58.37 37.01,57.5" fill="#2A62B8"/><polygon points="50,50 37.01,56.1 -19.96,11.58 46.12,-30.1 35.92,42.35 37.01,42.5" fill="#1C86CF"/><polygon points="50,50 38.22,41.8 48.3,-29.8 117.43,6.59 49.59,33.98 50,35" fill="#3CC4EA"/></g><polygon points="50,35 62.99,42.5 62.99,57.5 50,65 37.01,57.5 37.01,42.5" fill="#0E1A2B"/><circle cx="50" cy="50" r="7.5" fill="url(#${id}-p)"/><circle cx="52.6" cy="47.4" r="2.2" fill="#fff"/></svg>`;
};

/** Nome da loja no logotipo: primeira palavra forte e o restante na cor da marca (ex.: ODIN FOCUS). */
Loja.nomeLogo = (el, nome) => {
  if (!el) return;
  const [primeira = '', ...resto] = String(nome || '').toUpperCase().trim().split(/\s+/);
  el.innerHTML = Loja.esc(primeira) + (resto.length ? ` <span class="realce">${Loja.esc(resto.join(' '))}</span>` : '');
};

/**
 * Avisos rápidos no canto da tela. Com uma janela (dialog) aberta, como o checkout, o aviso vai
 * para dentro dela: o que fica fora de um dialog modal aparece atrás dele e o cliente não vê.
 * Avisos de erro ficam mais tempo na tela.
 */
Loja.aviso = (texto, tipo = 'info') => {
  let caixa = document.getElementById('avisos');
  if (!caixa) {
    caixa = document.createElement('div');
    caixa.id = 'avisos';
    caixa.className = 'avisos';
    caixa.setAttribute('aria-live', 'polite');
  }
  const janela = [...document.querySelectorAll('dialog[open]')].pop();
  const destino = janela || document.body;
  if (caixa.parentNode !== destino) destino.appendChild(caixa);
  const el = document.createElement('div');
  el.className = `aviso aviso-${tipo}`;
  el.textContent = texto;
  caixa.appendChild(el);
  const tempo = tipo === 'erro' ? 9000 : 3800;
  setTimeout(() => el.classList.add('saindo'), tempo);
  setTimeout(() => el.remove(), tempo + 500);
};
