/* Loja: utilidades compartilhadas pela vitrine, pela página de pagamento e pelo painel */
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
  celular: (v) => Loja.digitos(v).slice(0, 11).replace(/^(\d{2})(\d)/, '($1) $2').replace(/(\d{5})(\d{1,4})$/, '$1-$2'),
  // Fixo (10 dígitos) ou celular (11 dígitos)
  telefone: (v) => {
    const d = Loja.digitos(v).slice(0, 11);
    return d.length === 11 ? Loja.formatar.celular(d) : d.replace(/^(\d{2})(\d)/, '($1) $2').replace(/(\d{4})(\d{1,4})$/, '$1-$2');
  },
  cnpj: (v) => Loja.digitos(v).slice(0, 14).replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d{1,2})$/, '$1-$2')
};

/** CNPJ com os dois dígitos verificadores conferidos. */
Loja.cnpjValido = (valor) => {
  const c = Loja.digitos(valor);
  if (c.length !== 14 || /^(\d)\1{13}$/.test(c)) return false;
  for (const t of [12, 13]) {
    let soma = 0;
    let peso = t - 7;
    for (let i = 0; i < t; i++) {
      soma += Number(c[i]) * peso;
      peso = peso === 2 ? 9 : peso - 1;
    }
    const dv = soma % 11 < 2 ? 0 : 11 - (soma % 11);
    if (Number(c[t]) !== dv) return false;
  }
  return true;
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

/** Canais de atendimento cadastrados no painel (WhatsApp, telefone, e-mail, Instagram, Facebook). */
Loja.contatos = (l) => {
  const lista = [];
  if (l.whatsapp) lista.push({ icone: 'conversa', titulo: 'WhatsApp', texto: Loja.formatar.celular(l.whatsapp.replace(/^55/, '')), url: `https://wa.me/${l.whatsapp}` });
  if (l.telefone) lista.push({ icone: 'telefone', titulo: 'Telefone', texto: Loja.formatar.telefone(l.telefone), url: `tel:+55${l.telefone}` });
  if (l.email) lista.push({ icone: 'email', titulo: 'E-mail', texto: l.email, url: `mailto:${l.email}` });
  if (l.instagram) {
    const user = l.instagram.replace(/^@/, '').replace(/^https?:\/\/(www\.)?instagram\.com\//, '').replace(/\/$/, '');
    lista.push({ icone: 'camera', titulo: 'Instagram', texto: `@${user}`, url: `https://instagram.com/${user}` });
  }
  if (l.facebook) {
    const pagina = l.facebook.replace(/^@/, '').replace(/^https?:\/\/(www\.|m\.)?facebook\.com\//, '').replace(/\/$/, '');
    lista.push({ icone: 'facebook', titulo: 'Facebook', texto: pagina, url: `https://facebook.com/${pagina}` });
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
  alerta: '<path d="M12 3 2 20h20L12 3Z"/><path d="M12 10v4M12 17h.01"/>',
  telefone: '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/>',
  facebook: '<rect x="3" y="3" width="18" height="18" rx="5"/><path d="M15.5 8H14a2 2 0 0 0-2 2v11M9.5 13h5"/>',
  paleta: '<path d="M12 3a9 9 0 1 0 0 18c1 0 1.6-.8 1.6-1.6 0-.9-.7-1.4-.7-2.2 0-.9.7-1.6 1.6-1.6H17a4 4 0 0 0 4-4c0-4.7-4-8.6-9-8.6Z"/><circle cx="7.5" cy="11" r="1"/><circle cx="10.5" cy="7.5" r="1"/><circle cx="15" cy="7.5" r="1"/>',
  imagem: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.8"/><path d="m4 18 5-5 4 4 3-3 4 4"/>'
};
Loja.icone = (nome, classe = 'ico') =>
  `<svg class="${classe}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONES[nome] || ''}</svg>`;

/** Pasta da loja relativa à página (o painel fica em admin/, um nível abaixo). Usada nas imagens enviadas. */
Loja.RAIZ = Loja.API.replace(/api\/?$/, '');

/** Identidade da loja (logotipos, cores e empresa), preenchida por Loja.aplicarMarca() quando os dados chegam. */
Loja.identidade = null;

/**
 * Marca da loja: o logotipo enviado no painel ou, sem ele, um monograma com a inicial do nome.
 * escuro: a marca vai sobre fundo escuro (rodapé, painel, destaque da página inicial) e usa o
 * "logotipo para fundo escuro"; sem ele, o logo normal vai sobre uma etiqueta clara.
 * Antes de os dados da loja chegarem, não desenha nada (evita piscar uma marca errada).
 */
Loja.marca = (classe = 'marca', escuro = false) => {
  const id = Loja.identidade;
  if (!id) return '';
  const m = id.marca || {};
  const src = escuro ? (m.logo_escuro || m.logo) : m.logo;
  if (src) {
    const etiqueta = escuro && !m.logo_escuro ? ' marca-fundo' : '';
    return `<img class="${classe} marca-img${etiqueta}" src="${Loja.esc(Loja.RAIZ + src)}" alt="${Loja.esc(id.nome || '')}">`;
  }
  const inicial = Loja.esc((String(id.nome || '').trim().charAt(0) || '•').toUpperCase());
  return `<svg class="${classe}" viewBox="0 0 100 100" aria-hidden="true"><circle cx="50" cy="50" r="46" fill="var(--principal)" stroke="var(--secundaria)" stroke-width="3"/><text x="50" y="50" dy=".35em" text-anchor="middle" font-family="Source Sans 3, Segoe UI, Arial, sans-serif" font-size="50" fill="var(--sobre-principal)">${inicial}</text></svg>`;
};

/** Monograma como ícone da aba do navegador (quando a loja não enviou logotipo nem ícone). */
Loja.iconeMonograma = (nome, cores) => {
  const inicial = (String(nome || '').trim().charAt(0) || '•').toUpperCase().replace(/[<>&"']/g, '');
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="${cores.principal}"/><text x="32" y="32" dy=".35em" text-anchor="middle" font-family="Arial, sans-serif" font-size="34" fill="${cores.sobre_principal || cores.fundo}">${inicial}</text></svg>`;
  return `data:image/svg+xml,${encodeURIComponent(svg)}`;
};

/**
 * Aplica a identidade recebida da API (GET loja): logotipos em todos os [data-marca], ícone da aba,
 * cor do navegador no celular e, se configurado, esconde o nome ao lado do logotipo.
 */
Loja.aplicarMarca = (l) => {
  Loja.identidade = { nome: l.nome, marca: l.marca || {}, cores: l.cores || null, empresa: l.empresa || null };
  const m = Loja.identidade.marca;
  const areas = l.areas || {};
  // Painéis (loja, master, Entrar): sempre no fundo escuro do Tênis de Mesa para Todos.
  const painel = document.body.classList.contains('painel');
  document.querySelectorAll('[data-marca]').forEach((el) => {
    // O logotipo segue a claridade do fundo onde está: o da área (Configurações › Cores por área) ou o fundo da loja.
    const area = el.closest('.cabecalho') ? 'cabecalho' : el.closest('.hero') ? 'destaque' : el.closest('.rodape') ? 'rodape' : '';
    const fundo = (area && areas[area] && areas[area].fundo) || (l.cores && l.cores.fundo);
    const escuro = painel || (fundo ? Loja.corEscura(fundo) : !!el.closest('.logo-claro, .hero'));
    el.innerHTML = Loja.marca(el.dataset.marca || 'marca', escuro);
  });
  document.body.classList.toggle('marca-sem-nome', !!m.logo && m.mostrar_nome === false);
  let icone = document.querySelector('link[rel="icon"]');
  if (!icone) {
    icone = document.createElement('link');
    icone.rel = 'icon';
    document.head.appendChild(icone);
  }
  if (m.icone) {
    icone.type = 'image/png';
    icone.href = Loja.RAIZ + m.icone;
  } else if (l.cores) {
    icone.type = 'image/svg+xml';
    icone.href = Loja.iconeMonograma(l.nome, l.cores);
  }
  const tema = document.querySelector('meta[name="theme-color"]');
  if (tema && l.cores) tema.content = painel ? '#172C46' : (areas.faixa && areas.faixa.fundo) || l.cores.fundo;
};

/** Luminância relativa (WCAG) de uma cor #RRGGBB: 0 = preto, 1 = branco. */
Loja.luminancia = (hex) => {
  const c = String(hex).replace('#', '');
  const canal = (i) => {
    const v = parseInt(c.substr(i, 2), 16) / 255;
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * canal(0) + 0.7152 * canal(2) + 0.0722 * canal(4);
};

/** Contraste entre duas cores, de 1 a 21 (WCAG). Para texto comum, o recomendado é 4,5 ou mais. */
Loja.contraste = (a, b) => {
  const [claro, escuro] = [Loja.luminancia(a), Loja.luminancia(b)].sort((x, y) => y - x);
  return (claro + 0.05) / (escuro + 0.05);
};

/** Fundo escuro: texto branco se lê melhor nele do que texto preto. */
Loja.corEscura = (hex) => Loja.contraste(hex, '#FFFFFF') > Loja.contraste(hex, '#000000');

/** Linha legal do rodapé: razão social, CPF/CNPJ e endereço (Decreto 7.962/2013). Vazia se nada foi preenchido. */
Loja.linhaLegal = (l) => {
  const e = l.empresa || {};
  return [e.razao_social, e.documento ? `${e.rotulo_documento} ${e.documento}` : '', e.endereco].filter(Boolean).join(' · ');
};

/**
 * Selo do Tênis de Mesa para Todos: o Odin Focus é o Módulo Financeiro de Clubes da plataforma.
 * formato "bloco": logotipo e três linhas (login, painéis); "linha": tudo numa linha (faixa do topo, rodapé).
 * Herda a cor do texto de onde é colocado (claro nas áreas escuras, escuro nas claras).
 */
Loja.CLUBES = { nome: 'Tênis de Mesa para Todos', modulo: 'Módulo Financeiro de Clubes', site: 'https://www.tenisdemesasp.com.br' };
Loja.seloClubes = (formato = 'bloco') => {
  const c = Loja.CLUBES;
  return `<a class="selo-clubes selo-clubes-${formato}" href="${c.site}" target="_blank" rel="noopener" title="${c.nome}">`
    + `<img src="${Loja.RAIZ}img/tenis-de-mesa-para-todos.png" alt="" width="40" height="40">`
    + `<span class="selo-clubes-texto"><small>${c.modulo}</small><strong>${c.nome}</strong><span>Odin Focus</span></span></a>`;
};

/** Crédito do sistema no rodapé da loja: o selo do Módulo Financeiro de Clubes. */
Loja.credito = () => Loja.seloClubes('linha');

/** Preenche o rodapé-base comum a todas as páginas: nome, linha legal e crédito. */
Loja.aplicarRodape = (l) => {
  const copia = document.getElementById('rodapeCopy');
  if (copia) copia.textContent = l.nome;
  const legal = document.getElementById('rodapeLegal');
  if (legal) {
    const texto = Loja.linhaLegal(l);
    legal.textContent = texto;
    legal.hidden = !texto;
  }
  const credito = document.getElementById('rodapeCredito');
  if (credito) credito.innerHTML = Loja.credito();
};

/** Nome da loja ao lado do logotipo, como foi escrito: a primeira palavra e o restante na cor de realce (ex.: Minha Loja). */
Loja.nomeLogo = (el, nome) => {
  if (!el) return;
  const [primeira = '', ...resto] = String(nome || '').trim().split(/\s+/);
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

// Selo do Tênis de Mesa para Todos em todas as telas: o HTML só marca o lugar com [data-selo-clubes].
document.querySelectorAll('[data-selo-clubes]').forEach((el) => { el.innerHTML = Loja.seloClubes(el.dataset.seloClubes || 'bloco'); });
// Ícones marcados no HTML com [data-icone] (ex.: o ícone laranja dos títulos), em qualquer tela.
document.querySelectorAll('[data-icone]:empty').forEach((el) => { el.innerHTML = Loja.icone(el.dataset.icone); });
