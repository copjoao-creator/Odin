<?php
/**
 * Cliente da API do Asaas, usada nas assinaturas de serviços cobradas automaticamente no cartão de crédito.
 * O Asaas valida o cartão ao criar a assinatura, cobra o primeiro período na hora e depois renova sozinho
 * a cada período (com novas tentativas no dia do vencimento, se o cartão recusar).
 * As compras avulsas (Pix, boleto e cartão) continuam no Mercado Pago.
 * Documentação: https://docs.asaas.com
 *
 * Os dados do cartão só passam por aqui para serem enviados ao Asaas: nunca são gravados nem registrados em log.
 */
final class Asaas
{
  /** Nome da "aplicação" gravado nos pagamentos que vêm do Asaas (coluna pagamentos.app). */
  public const APP = 'asaas';

  /** Ciclos do Asaas para cada renovação da loja. */
  public const CICLOS = ['mensal' => 'MONTHLY', 'trimestral' => 'QUARTERLY', 'semestral' => 'SEMIANNUALLY', 'anual' => 'YEARLY'];

  public static function configurado(): bool
  {
    return Config::get('asaas_api_key') !== '';
  }

  /** Assinaturas do Asaas têm id "sub_…"; as do Mercado Pago, não. */
  public static function ehAssinatura(?string $id): bool
  {
    return is_string($id) && strpos($id, 'sub_') === 0;
  }

  private static function base(): string
  {
    $teste = Config::arquivo()['asaas_api_base'] ?? '';
    if ($teste !== '') return rtrim($teste, '/');
    return Config::get('asaas_ambiente') === 'producao' ? 'https://api.asaas.com/v3' : 'https://api-sandbox.asaas.com/v3';
  }

  /** @param array|null $corpo */
  public static function requisicao(string $metodo, string $caminho, ?array $corpo = null): array
  {
    $chave = Config::get('asaas_api_key');
    if ($chave === '') throw new ErroApi('O Asaas ainda não foi configurado no painel.', 503);

    $ch = curl_init(self::base() . $caminho);
    curl_setopt_array($ch, [
      CURLOPT_CUSTOMREQUEST => $metodo,
      CURLOPT_HTTPHEADER => [
        'access_token: ' . $chave,
        'Content-Type: application/json',
        'Accept: application/json',
        // O Asaas exige User-Agent nas chamadas à API.
        'User-Agent: OdinFocusLoja/1.0 (PHP ' . PHP_VERSION . ')',
      ],
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => 60,
    ]);
    // Corpo vazio vai como objeto JSON ({}), não como lista ([]).
    if ($corpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo === [] ? new stdClass() : $corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $resposta = curl_exec($ch);
    if ($resposta === false) {
      $erro = curl_error($ch);
      curl_close($ch);
      error_log("Asaas indisponível ({$metodo} {$caminho}): {$erro}");
      throw new ErroApi('Não foi possível falar com o Asaas agora. Tente de novo em instantes.', 502);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $dados = json_decode((string)$resposta, true);
    if (!is_array($dados)) $dados = [];
    if ($status >= 400) {
      $erros = is_array($dados['errors'] ?? null) ? $dados['errors'] : [];
      $codigo = (string)($erros[0]['code'] ?? '');
      $texto = (string)($erros[0]['description'] ?? ('HTTP ' . $status));
      // Só a resposta vai para o log (a requisição pode ter dados do cartão).
      error_log("Asaas {$metodo} {$caminho} -> {$status}: " . substr((string)$resposta, 0, 500));
      if ($status === 401) throw new ErroApi('A chave de API do Asaas é inválida ou é de outro ambiente (teste/produção). Confira no painel.', 502);
      throw new ErroApi($texto, $status >= 500 ? 502 : 422, ['codigo_asaas' => $codigo]);
    }
    return $dados;
  }

  // ---------------- Clientes ----------------

  /** Id do cliente no Asaas (procura pelo CPF; cria se não existir). */
  public static function cliente(array $c): string
  {
    $busca = self::requisicao('GET', '/customers?cpfCnpj=' . rawurlencode($c['cpf']) . '&limit=1');
    $id = (string)($busca['data'][0]['id'] ?? '');
    if ($id !== '') return $id;
    $novo = self::requisicao('POST', '/customers', [
      'name' => $c['nome'],
      'cpfCnpj' => $c['cpf'],
      'email' => $c['email'],
      'mobilePhone' => $c['celular'],
      'postalCode' => $c['cep'],
      'addressNumber' => $c['numero'],
      'complement' => $c['complemento'] ?? '',
      'externalReference' => $c['cpf'],
      // A loja envia os próprios e-mails (pedido, pagamento aprovado); evita avisos em dobro do Asaas.
      'notificationDisabled' => true,
    ]);
    if (empty($novo['id'])) throw new ErroApi('O Asaas não confirmou o cadastro do cliente. Tente de novo.', 502);
    return (string)$novo['id'];
  }

  // ---------------- Assinaturas ----------------

  /** Cria a assinatura no cartão. Autorizada: HTTP 200 e o primeiro período cobrado. Recusada: nada é criado (HTTP 400). */
  public static function criarAssinatura(array $corpo): array
  {
    return self::requisicao('POST', '/subscriptions', $corpo);
  }

  public static function consultarAssinatura(string $id): array
  {
    return self::requisicao('GET', '/subscriptions/' . rawurlencode($id));
  }

  /** Remove a assinatura: acaba a recorrência e o Asaas não gera novas cobranças. */
  public static function cancelarAssinatura(string $id): array
  {
    return self::requisicao('DELETE', '/subscriptions/' . rawurlencode($id));
  }

  public static function alterarAssinatura(string $id, array $corpo): array
  {
    return self::requisicao('PUT', '/subscriptions/' . rawurlencode($id), $corpo);
  }

  /** Cobranças já geradas pela assinatura (as futuras ainda não aparecem). */
  public static function cobrancasDaAssinatura(string $id): array
  {
    $r = self::requisicao('GET', '/subscriptions/' . rawurlencode($id) . '/payments?limit=100');
    return is_array($r['data'] ?? null) ? $r['data'] : [];
  }

  /**
   * Data final da loja no formato do Asaas. Na loja, a cobrança marcada para a data final não acontece;
   * no Asaas, "endDate" é o último vencimento permitido: por isso, um dia antes.
   */
  public static function ultimoVencimento(?string $dataFinal): ?string
  {
    return $dataFinal ? date('Y-m-d', strtotime($dataFinal . ' -1 day')) : null;
  }

  // ---------------- Cobranças ----------------

  public static function consultar(string $id): array
  {
    return self::requisicao('GET', '/payments/' . rawurlencode($id));
  }

  /** Estorno total (sem valor) ou parcial. */
  public static function estornar(string $id, ?string $valor = null): array
  {
    return self::requisicao('POST', '/payments/' . rawurlencode($id) . '/refund', $valor !== null ? ['value' => (float)$valor] : []);
  }

  /**
   * Converte uma cobrança do Asaas para o formato que a loja já grava (o mesmo do Mercado Pago),
   * para o pedido, o painel e os relatórios tratarem tudo igual.
   * $evento: evento do webhook, que às vezes diz mais que o status (ex.: cartão recusado).
   */
  public static function comoPagamento(array $c, string $evento = ''): array
  {
    $mapa = [
      'CONFIRMED' => 'approved', 'RECEIVED' => 'approved', 'RECEIVED_IN_CASH' => 'approved', 'DUNNING_RECEIVED' => 'approved',
      'REFUND_REQUESTED' => 'approved', 'REFUND_IN_PROGRESS' => 'approved',
      'PENDING' => 'pending', 'AWAITING_RISK_ANALYSIS' => 'in_process',
      'OVERDUE' => 'rejected', 'DUNNING_REQUESTED' => 'rejected',
      'REFUNDED' => 'refunded',
      'CHARGEBACK_REQUESTED' => 'charged_back', 'CHARGEBACK_DISPUTE' => 'charged_back', 'AWAITING_CHARGEBACK_REVERSAL' => 'charged_back',
    ];
    $bruto = (string)($c['status'] ?? 'PENDING');
    $status = $mapa[$bruto] ?? 'pending';
    $detalhe = strtolower($bruto);
    if (!empty($c['deleted'])) $status = 'cancelled';
    // Cartão recusado ou reprovado pela análise de risco: o status pode continuar "PENDING"/"OVERDUE".
    // (A cobrança é consultada na hora; se uma nova tentativa já aprovou, vale a aprovação.)
    if (in_array($status, ['pending', 'rejected', 'in_process'], true)) {
      if ($evento === 'PAYMENT_REPROVED_BY_RISK_ANALYSIS') {
        $status = 'rejected';
        $detalhe = 'cc_rejected_high_risk';
      } elseif ($evento === 'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED' || ($status === 'rejected' && ($c['billingType'] ?? '') === 'CREDIT_CARD')) {
        $status = 'rejected';
        $detalhe = 'cc_rejected_other_reason';
      }
    }
    $tipo = ['CREDIT_CARD' => 'credit_card', 'DEBIT_CARD' => 'debit_card', 'PIX' => 'bank_transfer', 'BOLETO' => 'ticket'][$c['billingType'] ?? ''] ?? 'credit_card';
    $data = $c['confirmedDate'] ?? $c['clientPaymentDate'] ?? $c['paymentDate'] ?? null;
    return [
      'id' => (string)$c['id'],
      'status' => $status,
      'status_detail' => $detalhe,
      'payment_type_id' => $tipo,
      'payment_method_id' => strtolower((string)($c['creditCard']['creditCardBrand'] ?? 'asaas')),
      'transaction_amount' => (float)($c['value'] ?? 0),
      'transaction_amount_refunded' => $status === 'refunded' ? (float)($c['value'] ?? 0) : 0,
      'transaction_details' => ['net_received_amount' => $c['netValue'] ?? null],
      'installments' => 1,
      'date_approved' => $status === 'approved' && $data ? $data . 'T00:00:00-03:00' : null,
    ];
  }

  /** Confere o token que o Asaas envia no cabeçalho "asaas-access-token" de cada aviso (webhook). */
  public static function avisoValido(): bool
  {
    $token = Config::get('asaas_webhook_token');
    if ($token === '') return false;
    return hash_equals($token, (string)($_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? ''));
  }
}
