<?php
/**
 * Cliente da API do Asaas, que processa todos os pagamentos da loja:
 * - compras: Pix (QR code na própria página), boleto e cartão de crédito à vista ou parcelado (sem juros);
 * - assinaturas de serviços: renovação automática no cartão de crédito a cada período.
 * Documentação: https://docs.asaas.com
 *
 * Os dados do cartão só passam por aqui para serem enviados ao Asaas: nunca são gravados nem registrados em log.
 */
final class Asaas
{
  /** Nome do gateway gravado nos pagamentos (coluna pagamentos.app). */
  public const APP = 'asaas';
  /** Pagamentos feitos pelo Mercado Pago antes da troca para o Asaas: ficam só no histórico. */
  public const APPS_ANTIGOS = ['loja', 'servicos'];

  /** Ciclos do Asaas para cada renovação da loja. */
  public const CICLOS = ['mensal' => 'MONTHLY', 'trimestral' => 'QUARTERLY', 'semestral' => 'SEMIANNUALLY', 'anual' => 'YEARLY'];

  /** Prazo do boleto e do Pix, em dias a partir de hoje. */
  public const DIAS_BOLETO = 3;
  public const DIAS_PIX = 1;
  /** Parcelas no cartão: até 12 (aceito por todas as bandeiras no Asaas) e cada uma de pelo menos R$ 5,00. */
  public const MAX_PARCELAS = 12;
  public const PARCELA_MINIMA = 5.0;

  public static function configurado(): bool
  {
    return Config::get('asaas_api_key') !== '';
  }

  /** Assinaturas do Asaas têm id "sub_…". */
  public static function ehAssinatura(?string $id): bool
  {
    return is_string($id) && strpos($id, 'sub_') === 0;
  }

  /** Compra parcelada no cartão: o Asaas cria um parcelamento (id no formato UUID) com uma cobrança por parcela. */
  public static function ehParcelamento(?string $id): bool
  {
    return is_string($id) && (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
  }

  /** Pagamento antigo, feito pelo Mercado Pago: só consulta no histórico. */
  public static function antigo(array $pg): bool
  {
    return in_array($pg['app'] ?? '', self::APPS_ANTIGOS, true);
  }

  /** Quantas parcelas o cliente pode escolher para este valor (máximo do painel, limite do Asaas e parcela mínima). */
  public static function parcelasPossiveis(float $total): int
  {
    $max = min(max(1, (int)Config::get('max_parcelas')), self::MAX_PARCELAS);
    return max(1, min($max, (int)floor($total / self::PARCELA_MINIMA + 0.0001)));
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
      // A loja envia os próprios e-mails (pedido, boleto, pagamento aprovado); evita avisos em dobro do Asaas.
      'notificationDisabled' => true,
    ]);
    if (empty($novo['id'])) throw new ErroApi('O Asaas não confirmou o cadastro do cliente. Tente de novo.', 502);
    return (string)$novo['id'];
  }

  // ---------------- Cobranças (compras) ----------------

  /**
   * Cria a cobrança. No cartão, o Asaas autoriza na hora: recusado = HTTP 400 e nada é criado.
   * Parcelado (installmentCount): devolve a 1ª parcela, com o id do parcelamento em "installment".
   */
  public static function criarCobranca(array $corpo): array
  {
    return self::requisicao('POST', '/payments', $corpo);
  }

  public static function consultar(string $id): array
  {
    return self::requisicao('GET', '/payments/' . rawurlencode($id));
  }

  /** QR code do Pix: encodedImage (PNG em base64), payload (copia e cola) e expirationDate. */
  public static function pixQrCode(string $id): array
  {
    return self::requisicao('GET', '/payments/' . rawurlencode($id) . '/pixQrCode');
  }

  /** Linha digitável do boleto. */
  public static function linhaDigitavel(string $id): ?string
  {
    $r = self::requisicao('GET', '/payments/' . rawurlencode($id) . '/identificationField');
    return ($r['identificationField'] ?? '') !== '' ? (string)$r['identificationField'] : null;
  }

  /** Remove uma cobrança ainda não paga (Pix ou boleto): ela deixa de poder ser paga. */
  public static function remover(string $id): void
  {
    self::requisicao('DELETE', '/payments/' . rawurlencode($id));
  }

  /** Estorno total (sem valor) ou parcial. */
  public static function estornar(string $id, ?string $valor = null): array
  {
    return self::requisicao('POST', '/payments/' . rawurlencode($id) . '/refund', $valor !== null ? ['value' => (float)$valor] : []);
  }

  // ---------------- Parcelamentos (cartão parcelado) ----------------

  public static function consultarParcelamento(string $id): array
  {
    return self::requisicao('GET', '/installments/' . rawurlencode($id));
  }

  /** Cobranças (parcelas) do parcelamento. */
  public static function cobrancasDoParcelamento(string $id): array
  {
    $r = self::requisicao('GET', '/installments/' . rawurlencode($id) . '/payments?limit=100');
    return is_array($r['data'] ?? null) ? $r['data'] : [];
  }

  /** Estorna todas as parcelas. */
  public static function estornarParcelamento(string $id): array
  {
    return self::requisicao('POST', '/installments/' . rawurlencode($id) . '/refund', []);
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

  // ---------------- Formato gravado pela loja ----------------

  /** Situação atual de um pagamento gravado (cobrança "pay_…" ou parcelamento), consultada no Asaas. */
  public static function pagamento(string $id, string $evento = ''): array
  {
    if (self::ehParcelamento($id)) return self::comoParcelamento(self::consultarParcelamento($id), self::cobrancasDoParcelamento($id));
    return self::comoPagamento(self::consultar($id), $evento);
  }

  /**
   * Converte uma cobrança do Asaas para o formato da tabela "pagamentos".
   * status: approved, pending, in_process, rejected, cancelled, refunded ou charged_back.
   * $evento: evento do webhook, que às vezes diz mais que o status (ex.: cartão recusado).
   * Campos null não apagam o que já foi gravado (ex.: o QR code do Pix, que vem de outra consulta).
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
    if (!empty($c['deleted'])) {
      $status = 'cancelled';
      $detalhe = 'cancelled';
    }
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
    $valor = (float)($c['value'] ?? 0);
    $estornado = 0.0;
    if ($status === 'refunded') {
      $estornado = $valor;
    } elseif (is_array($c['refunds'] ?? null)) {
      foreach ($c['refunds'] as $e) if (($e['status'] ?? '') !== 'CANCELLED') $estornado += (float)($e['value'] ?? 0);
    }
    $tipo = (string)($c['billingType'] ?? '');
    $data = $c['confirmedDate'] ?? $c['clientPaymentDate'] ?? $c['paymentDate'] ?? null;
    return [
      'id' => (string)$c['id'],
      'status' => $status,
      'status_detalhe' => $detalhe,
      'metodo' => ['CREDIT_CARD' => 'credito', 'DEBIT_CARD' => 'debito', 'PIX' => 'pix', 'BOLETO' => 'boleto'][$tipo] ?? 'outro',
      'bandeira' => isset($c['creditCard']['creditCardBrand']) ? strtolower((string)$c['creditCard']['creditCardBrand']) : null,
      'valor' => $valor,
      'valor_liquido' => isset($c['netValue']) && (float)$c['netValue'] > 0 ? (float)$c['netValue'] : null,
      'valor_estornado' => min($valor, $estornado),
      'parcelas' => 1,
      'link_pagamento' => $tipo === 'BOLETO' ? ($c['bankSlipUrl'] ?? $c['invoiceUrl'] ?? null) : null,
      'expira_em' => $tipo === 'BOLETO' && !empty($c['dueDate']) ? $c['dueDate'] . ' 23:59:59' : null,
      'aprovado_em' => $status === 'approved' && $data ? self::momento((string)$data) : null,
    ];
  }

  /** Compra parcelada: o parcelamento inteiro vira um só pagamento, com a soma das parcelas. */
  public static function comoParcelamento(array $parcelamento, array $cobrancas): array
  {
    $lista = array_map(fn($c) => self::comoPagamento($c), $cobrancas);
    $st = array_column($lista, 'status');
    $todas = fn(string $s) => $st && count(array_filter($st, fn($x) => $x === $s)) === count($st);
    if (in_array('charged_back', $st, true)) $status = 'charged_back';
    elseif ($todas('refunded')) $status = 'refunded';
    elseif (in_array('approved', $st, true)) $status = 'approved';
    elseif (in_array('in_process', $st, true)) $status = 'in_process';
    elseif ($todas('cancelled')) $status = 'cancelled';
    elseif (in_array('rejected', $st, true)) $status = 'rejected';
    else $status = 'pending';
    $principal = $lista[0] ?? null;
    foreach ($lista as $l) if ($l['status'] === $status) { $principal = $l; break; }
    $liquido = array_sum(array_map(fn($l) => (float)($l['valor_liquido'] ?? 0), $lista));
    $aprovados = array_filter(array_column($lista, 'aprovado_em'));
    return [
      'id' => (string)$parcelamento['id'],
      'status' => $status,
      'status_detalhe' => $principal['status_detalhe'] ?? strtolower($status),
      'metodo' => 'credito',
      'bandeira' => $principal['bandeira'] ?? null,
      'valor' => $lista ? array_sum(array_column($lista, 'valor')) : (float)($parcelamento['value'] ?? 0),
      'valor_liquido' => $liquido > 0 ? $liquido : null,
      'valor_estornado' => array_sum(array_column($lista, 'valor_estornado')),
      'parcelas' => (int)($parcelamento['installmentCount'] ?? count($lista)) ?: 1,
      'link_pagamento' => null,
      'expira_em' => null,
      'aprovado_em' => $aprovados ? min($aprovados) : null,
    ];
  }

  /** O Asaas informa só a data: hoje vale a hora atual; dias anteriores, meio-dia (para os relatórios por dia). */
  private static function momento(string $data): string
  {
    $dia = substr($data, 0, 10);
    return $dia === date('Y-m-d') ? date('Y-m-d H:i:s') : $dia . ' 12:00:00';
  }

  /** Confere o token que o Asaas envia no cabeçalho "asaas-access-token" de cada aviso (webhook). */
  public static function avisoValido(): bool
  {
    $token = Config::get('asaas_webhook_token');
    if ($token === '') return false;
    return hash_equals($token, (string)($_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? ''));
  }
}
