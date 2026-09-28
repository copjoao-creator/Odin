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
    'loja_nome' => 'Odin Focus',
    'loja_url' => '',
    'loja_titulo' => 'Qualidade que você sente em cada detalhe',
    'loja_subtitulo' => 'Produtos e serviços selecionados, pagamento seguro pelo Mercado Pago e atendimento de verdade, do pedido à entrega.',
    'loja_sobre' => 'A Odin Focus nasceu para oferecer produtos e serviços de alto padrão com um atendimento próximo e transparente. Cuidamos de cada pedido como se fosse nosso.',
    'aviso_topo' => 'Pagamento seguro via Mercado Pago · Pix, boleto e cartões',
    'loja_email' => '',
    'loja_whatsapp' => '',
    'loja_instagram' => '',
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
  ];

  /** Chaves secretas: nunca voltam inteiras para o navegador. */
  public const SECRETAS = ['mp_access_token', 'mp_webhook_secret', 'mp_serv_access_token', 'mp_serv_webhook_secret', 'asaas_api_key', 'asaas_webhook_token'];

  private static ?array $arquivo = null;
  private static ?array $valores = null;

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
    $st = Banco::pdo()->prepare('INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)');
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
