<?php
/**
 * Configurações da loja.
 * - config.php (criado pelo instalar.php): acesso ao banco de dados.
 * - tabela "configuracoes": tudo o que se altera pelo painel (textos da loja, frete, Mercado Pago, módulos).
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
    'cor_principal' => '#1B2D42',
    'cor_fundo' => '#F7F6F2',
    'cor_secundaria' => '#E5DECF',
    'cor_texto' => '#333333',
    'cor_realce' => '#1FA8DD',
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
    'max_parcelas' => '12',
    'dias_expiracao_pedido' => '7',
    'dias_antecedencia_renovacao' => '5',
    'estoque_minimo' => '5',
    'mp_public_key' => '',
    'mp_access_token' => '',
    'mp_webhook_secret' => '',
    // Aplicação do Mercado Pago só para serviços (pedidos de serviços e assinaturas no cartão).
    'mp_serv_public_key' => '',
    'mp_serv_access_token' => '',
    'mp_serv_webhook_secret' => '',
    // Asaas: assinaturas de serviços com renovação automática no cartão de crédito.
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
  public const SECRETAS = ['mp_access_token', 'mp_webhook_secret', 'mp_serv_access_token', 'mp_serv_webhook_secret', 'asaas_api_key', 'asaas_webhook_token'];

  /** Configurações de pagamento: só o perfil "tecnico" vê e altera (a loja é entregue com elas prontas). */
  public const TECNICAS = [
    'mp_public_key', 'mp_access_token', 'mp_webhook_secret',
    'mp_serv_public_key', 'mp_serv_access_token', 'mp_serv_webhook_secret',
    'asaas_ambiente', 'asaas_api_key', 'asaas_webhook_token',
  ];

  /** Cores do tema (loja, painel e e-mails), já validadas. */
  public static function cores(): array
  {
    $c = [];
    foreach (['principal', 'fundo', 'secundaria', 'texto', 'realce'] as $k) {
      $v = self::get('cor_' . $k);
      $c[$k] = preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtoupper($v) : self::PADROES['cor_' . $k];
    }
    return $c;
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
