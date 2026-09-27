<?php
/**
 * Cliente da API de pagamentos do Mercado Pago (Checkout Transparente / Payment Brick).
 * Formas aceitas: Pix, boleto, cartão de crédito e cartão de débito.
 * Documentação: https://www.mercadopago.com.br/developers/pt/reference/payments/_payments/post
 */
final class MercadoPago
{
  /** Mensagens para o cliente conforme o motivo informado pelo Mercado Pago. */
  private const MENSAGENS = [
    'accredited' => 'Pagamento aprovado!',
    'pending_contingency' => 'Estamos processando o pagamento. Em até 2 dias úteis você receberá a confirmação por e-mail.',
    'pending_review_manual' => 'O pagamento está em análise. Em até 2 dias úteis você receberá a confirmação por e-mail.',
    'pending_waiting_payment' => 'Aguardando o pagamento.',
    'pending_waiting_transfer' => 'Aguardando o pagamento.',
    'cc_rejected_bad_filled_card_number' => 'Confira o número do cartão.',
    'cc_rejected_bad_filled_date' => 'Confira a data de validade do cartão.',
    'cc_rejected_bad_filled_security_code' => 'Confira o código de segurança (CVV) do cartão.',
    'cc_rejected_bad_filled_other' => 'Confira os dados do cartão.',
    'cc_rejected_insufficient_amount' => 'O cartão não tem limite ou saldo suficiente.',
    'cc_rejected_call_for_authorize' => 'Autorize o pagamento com o banco emissor do cartão e tente de novo.',
    'cc_rejected_card_disabled' => 'Ligue para o banco emissor para ativar o cartão.',
    'cc_rejected_duplicated_payment' => 'Você já fez um pagamento com esse valor. Se precisar pagar de novo, use outro cartão ou outra forma de pagamento.',
    'cc_rejected_high_risk' => 'O pagamento foi recusado por segurança. Tente Pix, boleto ou outro cartão.',
    'cc_rejected_max_attempts' => 'Você atingiu o limite de tentativas. Use outro cartão ou outra forma de pagamento.',
    'cc_rejected_blacklist' => 'Não foi possível processar o pagamento. Use outro cartão ou outra forma de pagamento.',
    'cc_rejected_invalid_installments' => 'O cartão não aceita esse número de parcelas.',
    'cc_rejected_card_error' => 'Não foi possível processar o pagamento com este cartão.',
    'cc_rejected_other_reason' => 'O banco emissor recusou o pagamento. Use outro cartão ou outra forma de pagamento.',
  ];

  /**
   * A loja usa duas aplicações do Mercado Pago (da mesma conta):
   * - "loja": produtos (e serviços, se a de serviços não estiver configurada);
   * - "servicos": pedidos de serviços, inclusive as assinaturas cobradas no cartão.
   */
  public const APPS = ['loja', 'servicos'];

  /** Aplicação que responde por um pedido. Sem credenciais de serviços, tudo usa a da loja. */
  public static function appEfetiva(string $app): string
  {
    if ($app === 'servicos' && Config::get('mp_serv_access_token') !== '' && Config::get('mp_serv_public_key') !== '') return 'servicos';
    return 'loja';
  }

  /** @return array{public_key: string, access_token: string, webhook_secret: string} */
  public static function credenciais(string $app = 'loja'): array
  {
    $p = self::appEfetiva($app) === 'servicos' ? 'mp_serv_' : 'mp_';
    return [
      'public_key' => Config::get($p . 'public_key'),
      'access_token' => Config::get($p . 'access_token'),
      'webhook_secret' => Config::get($p . 'webhook_secret'),
    ];
  }

  public static function configurado(string $app = 'loja'): bool
  {
    $c = self::credenciais($app);
    return $c['access_token'] !== '' && $c['public_key'] !== '';
  }

  /** Endereço da API. O config.php pode trocar (usado apenas em testes). */
  private static function base(): string
  {
    return rtrim(Config::arquivo()['mp_api_base'] ?? 'https://api.mercadopago.com', '/');
  }

  /** @param array|stdClass|null $corpo */
  public static function requisicao(string $metodo, string $caminho, $corpo = null, array $cabecalhos = [], string $app = 'loja'): array
  {
    $token = self::credenciais($app)['access_token'];
    if ($token === '') throw new ErroApi('O Mercado Pago ainda não foi configurado no painel.', 503);

    $ch = curl_init(self::base() . $caminho);
    $cab = array_merge(['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'], $cabecalhos);
    curl_setopt_array($ch, [
      CURLOPT_CUSTOMREQUEST => $metodo,
      CURLOPT_HTTPHEADER => $cab,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => 40,
    ]);
    if ($corpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $resposta = curl_exec($ch);
    if ($resposta === false) {
      $erro = curl_error($ch);
      curl_close($ch);
      error_log('Mercado Pago indisponível: ' . $erro);
      throw new ErroApi('Não foi possível falar com o Mercado Pago agora. Tente de novo em instantes.', 502);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $dados = json_decode((string)$resposta, true);
    if (!is_array($dados)) $dados = [];
    if ($status >= 400) {
      $detalhe = $dados['cause'][0]['description'] ?? $dados['message'] ?? ('HTTP ' . $status);
      error_log("Mercado Pago {$metodo} {$caminho} -> {$status}: " . substr((string)$resposta, 0, 500));
      if ($status === 401 && stripos((string)$detalhe, 'live credentials') !== false) {
        throw new ErroApi('Pagamentos indisponíveis: as credenciais de produção do Mercado Pago ainda não foram ativadas. A loja precisa ativá-las no Mercado Pago Developers.', 502);
      }
      if ($status === 401) throw new ErroApi('As credenciais do Mercado Pago são inválidas. Confira o Access Token no painel.', 502);
      throw new ErroApi('O Mercado Pago recusou a operação: ' . $detalhe, $status >= 500 ? 502 : 422);
    }
    return $dados;
  }

  public static function criarPagamento(array $corpo, string $app = 'loja'): array
  {
    return self::requisicao('POST', '/v1/payments', $corpo, ['X-Idempotency-Key: ' . self::uuid()], $app);
  }

  public static function consultar(string $id, string $app = 'loja'): array
  {
    return self::requisicao('GET', '/v1/payments/' . rawurlencode($id), null, [], $app);
  }

  /** Estorno total ou parcial de um pagamento aprovado. */
  public static function estornar(string $id, ?string $valor = null, string $app = 'loja'): array
  {
    $corpo = $valor !== null ? ['amount' => (float)$valor] : new stdClass();
    return self::requisicao('POST', '/v1/payments/' . rawurlencode($id) . '/refunds', $corpo, ['X-Idempotency-Key: ' . self::uuid()], $app);
  }

  /** Cancela um Pix ou boleto ainda não pago. */
  public static function cancelar(string $id, string $app = 'loja'): array
  {
    return self::requisicao('PUT', '/v1/payments/' . rawurlencode($id), ['status' => 'cancelled'], [], $app);
  }

  // ---------------- Assinaturas (cobrança recorrente no cartão) ----------------
  // https://www.mercadopago.com.br/developers/pt/reference/subscriptions/_preapproval/post

  /** Cria a assinatura já autorizada com o cartão (token do Card Payment Brick). */
  public static function criarAssinatura(array $corpo): array
  {
    return self::requisicao('POST', '/preapproval', $corpo, ['X-Idempotency-Key: ' . self::uuid()], 'servicos');
  }

  public static function consultarAssinatura(string $id): array
  {
    return self::requisicao('GET', '/preapproval/' . rawurlencode($id), null, [], 'servicos');
  }

  /** Cancela a assinatura no Mercado Pago: não haverá novas cobranças no cartão. */
  public static function cancelarAssinatura(string $id): array
  {
    return self::requisicao('PUT', '/preapproval/' . rawurlencode($id), ['status' => 'cancelled'], [], 'servicos');
  }

  /** Altera a assinatura no Mercado Pago (usado para a data final). */
  public static function alterarAssinatura(string $id, array $corpo): array
  {
    return self::requisicao('PUT', '/preapproval/' . rawurlencode($id), $corpo, [], 'servicos');
  }

  /** Data final no formato do Mercado Pago (fim do dia, horário de Brasília). */
  public static function fimDoDia(string $data): string
  {
    return $data . 'T23:59:59.000-03:00';
  }

  /** Uma cobrança (fatura) da assinatura. Quando processada, traz o pagamento em ['payment']['id']. */
  public static function consultarFatura(string $id): array
  {
    return self::requisicao('GET', '/authorized_payments/' . rawurlencode($id), null, [], 'servicos');
  }

  /** Faturas de uma assinatura (usado quando algum aviso do webhook se perde). */
  public static function faturasDaAssinatura(string $preapprovalId): array
  {
    $r = self::requisicao('GET', '/authorized_payments/search?preapproval_id=' . rawurlencode($preapprovalId), null, [], 'servicos');
    return is_array($r['results'] ?? null) ? $r['results'] : [];
  }

  /** Pix, boleto, crédito ou débito, a partir do tipo informado pelo Mercado Pago. */
  public static function metodo(array $p): string
  {
    $tipo = $p['payment_type_id'] ?? '';
    if (($p['payment_method_id'] ?? '') === 'pix' || $tipo === 'bank_transfer') return 'pix';
    if ($tipo === 'ticket') return 'boleto';
    if ($tipo === 'credit_card') return 'credito';
    if ($tipo === 'debit_card') return 'debito';
    return 'outro';
  }

  /** Texto para o cliente a partir do status, do motivo e da forma de pagamento. */
  public static function mensagem(string $status, ?string $detalhe, string $metodo): string
  {
    if ($detalhe !== null && isset(self::MENSAGENS[$detalhe])) return self::MENSAGENS[$detalhe];
    switch ($status) {
      case 'approved': return 'Pagamento aprovado!';
      case 'pending': return $metodo === 'boleto' ? 'Boleto gerado. Pague até o vencimento.' : 'Aguardando o pagamento.';
      case 'in_process':
      case 'authorized': return 'O pagamento está em análise. Você receberá a confirmação por e-mail.';
      case 'rejected': return 'O pagamento foi recusado. Tente outro cartão ou outra forma de pagamento.';
      case 'cancelled': return 'O pagamento foi cancelado ou expirou.';
      case 'refunded': return 'O pagamento foi estornado.';
      case 'charged_back': return 'O pagamento foi contestado junto ao cartão (chargeback).';
      default: return 'Situação do pagamento: ' . $status;
    }
  }

  /**
   * Confere a assinatura das notificações (webhooks) com a "assinatura secreta" do painel do Mercado Pago.
   * https://www.mercadopago.com.br/developers/pt/docs/your-integrations/notifications/webhooks
   */
  public static function assinaturaValida(string $dataId, string $app = 'loja'): bool
  {
    $segredo = self::credenciais($app)['webhook_secret'];
    if ($segredo === '') return true;
    $ts = $v1 = '';
    foreach (explode(',', (string)($_SERVER['HTTP_X_SIGNATURE'] ?? '')) as $parte) {
      [$k, $v] = array_map('trim', explode('=', $parte, 2) + [1 => '']);
      if ($k === 'ts') $ts = $v;
      if ($k === 'v1') $v1 = $v;
    }
    if ($ts === '' || $v1 === '') return false;
    $manifesto = '';
    if ($dataId !== '') $manifesto .= 'id:' . (ctype_alnum($dataId) ? strtolower($dataId) : $dataId) . ';';
    $requestId = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
    if ($requestId !== '') $manifesto .= 'request-id:' . $requestId . ';';
    $manifesto .= 'ts:' . $ts . ';';
    return hash_equals(hash_hmac('sha256', $manifesto, $segredo), $v1);
  }

  public static function uuid(): string
  {
    $b = random_bytes(16);
    $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
    $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
  }
}
