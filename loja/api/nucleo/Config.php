<?php
/**
 * Configurações da loja.
 * - config.php (criado pelo instalar.php): acesso ao banco de dados.
 * - tabela "configuracoes": tudo o que se altera pelo painel (textos da loja, frete, Asaas, módulos).
 */
final class Config
{
  /** Valores usados enquanto o administrador não altera nada no painel. */
  public const PADROES = [
    // Textos neutros: cada loja troca pelos seus em Configurações › Loja.
    'loja_nome' => 'Minha Loja',
    'loja_url' => '',
    'loja_titulo' => 'Bem-vindo à nossa loja',
    'loja_subtitulo' => 'Produtos e serviços selecionados, pagamento seguro e atendimento de verdade, do pedido à entrega.',
    'loja_sobre' => 'Conte aqui a história da sua empresa, o que você oferece e por que os clientes podem confiar em você.',
    'aviso_topo' => 'Pagamento seguro · Pix, boleto e cartões',
    'loja_email' => '',
    'loja_whatsapp' => '',
    'loja_telefone' => '',
    'loja_instagram' => '',
    'loja_facebook' => '',
    // Identidade visual: logotipos enviados pelo painel (pasta uploads/marca) e cores da loja, do painel e dos e-mails.
    'marca_logo' => '',
    'marca_logo_escuro' => '',
    'marca_icone' => '',
    'marca_icone_auto' => '1', // "1": o ícone da aba é gerado do logotipo; "0": foi enviado à parte
    'marca_mostrar_nome' => '1',
    // Paleta do Tênis de Mesa para Todos: botões verdes, fundo azul-marinho, bordas ciano, textos celeste, destaques laranja.
    'cor_principal' => '#00592D',
    'cor_fundo' => '#172C46',
    'cor_secundaria' => '#80FFFF',
    'cor_texto' => '#B4ECFC',
    'cor_realce' => '#F45900',
    // Cores por área (fundo e texto). Vazio = a área segue as cores básicas acima (ver Config::AREAS_COR).
    'cor_faixa_fundo' => '', 'cor_faixa_texto' => '',
    'cor_cabecalho_fundo' => '', 'cor_cabecalho_texto' => '',
    'cor_menu_fundo' => '', 'cor_menu_texto' => '',
    'cor_botao_fundo' => '', 'cor_botao_texto' => '',
    'cor_destaque_fundo' => '', 'cor_destaque_texto' => '',
    'cor_sobre_fundo' => '', 'cor_sobre_texto' => '',
    'cor_rodape_fundo' => '', 'cor_rodape_texto' => '',
    // Dados da empresa (aparecem no rodapé da loja e nos e-mails; exigidos pelo Decreto 7.962/2013).
    'empresa_tipo' => 'pj',
    'empresa_documento' => '',
    'empresa_razao_social' => '',
    'empresa_ie' => '',
    'empresa_responsavel' => '',
    'empresa_cep' => '',
    'empresa_rua' => '',
    'empresa_numero' => '',
    'empresa_complemento' => '',
    'empresa_bairro' => '',
    'empresa_cidade' => '',
    'empresa_uf' => '',
    'emailjs_public_key' => '',
    'emailjs_service_id' => '',
    'emailjs_template_id' => '',
    'frete_valor' => '0.00',
    'frete_gratis_acima' => '0.00',
    'max_parcelas' => '12', // parcelas sem juros no cartão de crédito (até 12)
    'dias_expiracao_pedido' => '7',
    'dias_antecedencia_renovacao' => '5',
    'estoque_minimo' => '5',
    // Asaas: todos os pagamentos (Pix, boleto, cartão de crédito e assinaturas com renovação automática).
    'asaas_ambiente' => 'sandbox',
    'asaas_api_key' => '',
    'asaas_webhook_token' => '',
    'modulo_produto' => '1',
    'modulo_servico' => '1',
    // Abas da loja: "0" esconde a aba (e tudo daquele tipo) da loja, sem desligar o módulo no painel.
    'aba_produto' => '1',
    'aba_servico' => '1',
  ];

  /** Chaves secretas: nunca voltam inteiras para o navegador. */
  public const SECRETAS = ['asaas_api_key', 'asaas_webhook_token'];

  /** Configurações de pagamento: só o perfil "tecnico" vê e altera (a loja é entregue com elas prontas). */
  public const TECNICAS = ['asaas_ambiente', 'asaas_api_key', 'asaas_webhook_token'];

  /** Chaves do Mercado Pago, usado até a troca para o Asaas (a migração v6 apaga do banco). */
  public const ANTIGAS = ['mp_public_key', 'mp_access_token', 'mp_webhook_secret', 'mp_serv_public_key', 'mp_serv_access_token', 'mp_serv_webhook_secret'];

  /** Paleta padrão até a v7 (a migração v8 troca pela do Tênis de Mesa para Todos nas lojas que não a mudaram). */
  public const CORES_ANTIGAS = ['principal' => '#1B2D42', 'fundo' => '#F7F6F2', 'secundaria' => '#E5DECF', 'texto' => '#333333', 'realce' => '#1FA8DD'];

  /**
   * Áreas da loja com cor própria de fundo e de texto: área => [rótulo, cor básica do fundo, cor básica do texto].
   * Sem cor própria, a área usa as cores indicadas de Config::cores() (as básicas ou as derivadas delas).
   */
  public const AREAS_COR = [
    'faixa' => ['Faixa do topo (avisos)', 'fundo', 'texto'],
    'cabecalho' => ['Cabeçalho (logotipo, busca e carrinho)', 'fundo', 'texto'],
    'menu' => ['Menu (Início, Produtos, Serviços…)', 'fundo', 'texto'],
    'botao' => ['Botões (comprar, adicionar, assinar…)', 'principal', 'sobre_principal'],
    'destaque' => ['Destaque da página inicial', 'fundo', 'texto'],
    'sobre' => ['Seção "Sobre nós"', 'fundo', 'texto'],
    'rodape' => ['Rodapé', 'fundo', 'texto'],
  ];

  /** Cores próprias de cada área (só as definidas): ['faixa' => ['fundo' => '#...', 'texto' => '#...'], ...]. */
  public static function coresAreas(): array
  {
    $r = [];
    foreach (array_keys(self::AREAS_COR) as $area) {
      foreach (['fundo', 'texto'] as $parte) {
        $v = self::get("cor_{$area}_{$parte}");
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $v)) $r[$area][$parte] = strtoupper($v);
      }
    }
    return $r;
  }

  /**
   * Cores do tema (loja e e-mails), já validadas: as cinco básicas e as derivadas delas
   * - titulo: títulos (quase branco no fundo escuro; no claro, a principal se for legível, senão o texto)
   * - sobre_principal: texto dos botões (branco ou quase preto, o que for mais legível sobre a principal)
   * - suave, linha e tom: texto secundário, linhas e fundos sutis, já misturados (para os e-mails, que não têm color-mix).
   */
  public static function cores(): array
  {
    $c = [];
    foreach (['principal', 'fundo', 'secundaria', 'texto', 'realce'] as $k) {
      $v = self::get('cor_' . $k);
      $c[$k] = preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtoupper($v) : self::PADROES['cor_' . $k];
    }
    $c['escuro'] = self::corEscura($c['fundo']);
    $c['titulo'] = $c['escuro'] ? '#FFFAFA' : (self::contraste($c['principal'], $c['fundo']) >= 4.5 ? $c['principal'] : $c['texto']);
    $c['sobre_principal'] = self::corEscura($c['principal']) ? '#FFFFFF' : '#111111';
    $c['suave'] = self::misturar($c['texto'], $c['fundo'], .72);
    $c['linha'] = self::misturar($c['secundaria'], $c['fundo'], .3);
    $c['tom'] = self::misturar($c['texto'], $c['fundo'], .08);
    return $c;
  }

  /** Luminância relativa (WCAG) de #RRGGBB: 0 = preto, 1 = branco. */
  public static function luminancia(string $hex): float
  {
    $l = [];
    foreach (str_split(substr($hex, 1, 6), 2) as $par) {
      $v = hexdec($par) / 255;
      $l[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }
    return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
  }

  /** Contraste entre duas cores, de 1 a 21 (WCAG). */
  public static function contraste(string $a, string $b): float
  {
    $x = self::luminancia($a);
    $y = self::luminancia($b);
    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
  }

  /** Fundo escuro: texto branco se lê melhor nele do que texto preto (mesma regra do Loja.corEscura do comum.js). */
  public static function corEscura(string $hex): bool
  {
    return self::contraste($hex, '#FFFFFF') > self::contraste($hex, '#000000');
  }

  /** Mistura duas cores #RRGGBB ($peso da primeira, de 0 a 1), como o color-mix do CSS. */
  public static function misturar(string $a, string $b, float $peso): string
  {
    $r = '#';
    for ($i = 1; $i < 7; $i += 2) {
      $r .= sprintf('%02X', (int)round(hexdec(substr($a, $i, 2)) * $peso + hexdec(substr($b, $i, 2)) * (1 - $peso)));
    }
    return $r;
  }

  private static ?array $arquivo = null;
  private static ?array $valores = null;

  /** Esquece as configurações lidas (ao trocar de loja na mesma execução, ex.: rotina diária). */
  public static function limparCache(): void
  {
    self::$valores = null;
  }

  public static function instalado(): bool
  {
    return is_file(ARQUIVO_CONFIG);
  }

  public static function arquivo(): array
  {
    if (self::$arquivo === null) {
      if (!self::instalado()) throw new ErroApi('A loja ainda não foi instalada. Abra o instalar.php.', 503);
      self::$arquivo = require ARQUIVO_CONFIG;
    }
    return self::$arquivo;
  }

  public static function todas(): array
  {
    if (self::$valores === null) {
      $salvos = [];
      foreach (Banco::todos('SELECT chave, valor FROM configuracoes') as $l) $salvos[$l['chave']] = $l['valor'];
      self::$valores = array_merge(self::PADROES, $salvos);
    }
    return self::$valores;
  }

  public static function get(string $chave): string
  {
    return (string)(self::todas()[$chave] ?? '');
  }

  public static function salvar(array $valores): void
  {
    $st = Banco::preparar('INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)');
    foreach ($valores as $chave => $valor) $st->execute([$chave, (string)$valor]);
    self::$valores = null;
  }

  /** Mostra só o começo e o fim de uma chave secreta (ex.: APP_USR-12…9f3a). */
  public static function mascarar(string $valor): string
  {
    if ($valor === '') return '';
    return strlen($valor) <= 12 ? str_repeat('•', 8) : substr($valor, 0, 8) . '…' . substr($valor, -4);
  }
}
