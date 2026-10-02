<?php
/* Pagamentos pelo Asaas: criação (Pix, boleto e cartão de crédito), webhook, estorno e reconsulta. */
return function (Roteador $r) {
  // Página de pagamento: gera o Pix ou o boleto, ou cobra no cartão de crédito (à vista ou parcelado, sem juros).
  // O valor cobrado é sempre o total do pedido gravado no banco.
  $r->publica('POST', 'pagamentos', function () {
    $d = Http::entrada();
    $p = Pedidos::peloToken($d['pedido_id'] ?? 0, $d['token'] ?? '');
    if ($p['status'] === 'pago') throw new ErroApi('Este pedido já está pago.', 409);
    if ($p['status'] === 'em_analise') throw new ErroApi('Já existe um pagamento em análise para este pedido. Aguarde a confirmação por e-mail.', 409);
    if (in_array($p['status'], ['cancelado', 'estornado'], true)) throw new ErroApi('Este pedido foi cancelado. Faça um novo pedido na loja.', 409);
    if (!Asaas::configurado()) throw new ErroApi('Pagamentos indisponíveis no momento. Fale com a loja.', 503);
    if (Pedidos::cobrancaAutomatica($p)) throw new ErroApi('Esta renovação é debitada automaticamente no seu cartão. Não é preciso pagar por aqui.', 422);
    if (Pedidos::itemAssinatura($p)) throw new ErroApi('Assinaturas são pagas com cartão de crédito, com cobrança automática. Recarregue a página.', 422);

    $forma = (string)($d['forma'] ?? '');
    if (!in_array($forma, ['pix', 'boleto', 'cartao'], true)) throw new ErroApi('Escolha uma forma de pagamento.', 422);
    $c = Clientes::buscar($p['cliente_cpf']);
    $total = (float)$p['total'];
    $corpo = [
      'customer' => Asaas::cliente($c),
      'description' => mb_substr(Config::get('loja_nome') . ' - Pedido #' . $p['id'], 0, 500),
      'externalReference' => (string)$p['id'],
    ];

    if ($forma === 'cartao') {
      $cartao = Cartao::validar(is_array($d['cartao'] ?? null) ? $d['cartao'] : []);
      $parcelas = (int)($d['parcelas'] ?? 1);
      if ($parcelas < 1 || $parcelas > Asaas::parcelasPossiveis($total)) throw new ErroApi('Número de parcelas inválido.', 422, ['campo' => 'parcelas']);
      Cartao::limitarTentativas((int)$p['id']);
      $corpo += ['billingType' => 'CREDIT_CARD', 'dueDate' => date('Y-m-d')] + Cartao::paraAsaas($cartao, $c);
      if ($parcelas > 1) $corpo += ['installmentCount' => $parcelas, 'totalValue' => $total];
      else $corpo['value'] = $total;
      try {
        $cobranca = Asaas::criarCobranca($corpo);
      } catch (ErroApi $e) {
        // Cartão recusado: o Asaas não cria a cobrança; o cliente pode tentar outro cartão.
        Banco::executar(
          "UPDATE pedidos SET observacoes = CONCAT_WS('\n', observacoes, ?) WHERE id = ?",
          [date('d/m/Y H:i') . ' - Cartão não aprovado pelo Asaas: ' . mb_substr($e->getMessage(), 0, 300), $p['id']]
        );
        throw Cartao::recusa($e);
      } finally {
        unset($corpo, $cartao);
      }
      $parcelamento = (string)($cobranca['installment'] ?? '');
      $pg = $parcelas > 1 && Asaas::ehParcelamento($parcelamento)
        ? Asaas::pagamento($parcelamento)
        : Asaas::comoPagamento($cobranca);
    } elseif ($forma === 'pix') {
      $cobranca = Asaas::criarCobranca($corpo + ['billingType' => 'PIX', 'value' => $total, 'dueDate' => date('Y-m-d', strtotime('+' . Asaas::DIAS_PIX . ' day'))]);
      try {
        $qr = Asaas::pixQrCode((string)$cobranca['id']);
      } catch (ErroApi $e) {
        // Ex.: a conta do Asaas ainda não tem chave Pix. Remove a cobrança para não ficar uma sem QR code.
        try { Asaas::remover((string)$cobranca['id']); } catch (Throwable $e2) { /* fica no Asaas, sem uso */ }
        throw new ErroApi('Não foi possível gerar o Pix agora. Escolha outra forma de pagamento ou tente de novo mais tarde.', 502);
      }
      $pg = Asaas::comoPagamento($cobranca) + [
        'pix_copia_cola' => $qr['payload'] ?? null,
        'pix_qr_base64' => $qr['encodedImage'] ?? null,
      ];
      if (!empty($qr['expirationDate'])) $pg['expira_em'] = substr((string)$qr['expirationDate'], 0, 19);
    } else {
      $cobranca = Asaas::criarCobranca($corpo + ['billingType' => 'BOLETO', 'value' => $total, 'dueDate' => date('Y-m-d', strtotime('+' . Asaas::DIAS_BOLETO . ' days'))]);
      $pg = Asaas::comoPagamento($cobranca);
      try {
        $pg['codigo_barras'] = Asaas::linhaDigitavel((string)$cobranca['id']);
      } catch (ErroApi $e) {
        // O boleto em PDF (link) já basta; a linha digitável aparece nele.
      }
    }
    $pg = Pedidos::registrarPagamento((int)$p['id'], $pg);

    // Cliente trocou a forma de pagamento: invalida o Pix/boleto anterior para não pagar duas vezes.
    if ($pg['status'] !== 'rejected') {
      foreach ($p['pagamentos'] as $antigo) {
        if ($antigo['mp_id'] !== $pg['mp_id']) Pedidos::cancelarCobranca($antigo);
      }
    }
    // Boleto gerado: envia também para o e-mail do cadastro do cliente.
    if ($pg['metodo'] === 'boleto' && $pg['status'] === 'pending') Pedidos::enviarBoleto($p, $pg);
    return ['pedido' => Pedidos::publico(Pedidos::carregar((int)$p['id'])), 'pagamento' => Pedidos::resumoPagamento($pg)];
  });

  // Aviso do Asaas de que uma cobrança mudou. Autenticado pelo token do cabeçalho "asaas-access-token";
  // mesmo assim a cobrança é consultada de novo na API antes de gravar.
  $r->publica('POST', 'webhook/asaas', function () {
    if (!Asaas::avisoValido()) throw new ErroApi('Token do aviso inválido.', 401);
    $corpo = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $evento = (string)($corpo['event'] ?? '');
    $servicos = Modulos::doItem('servico');

    // Assinatura removida ou desativada direto no painel do Asaas.
    if (in_array($evento, ['SUBSCRIPTION_DELETED', 'SUBSCRIPTION_INACTIVATED'], true)) {
      $sub = (string)($corpo['subscription']['id'] ?? '');
      if ($servicos && method_exists($servicos, 'assinaturaAsaasEncerrada') && Asaas::ehAssinatura($sub)) $servicos->assinaturaAsaasEncerrada($sub);
      return ['ok' => true];
    }
    // Só os eventos que mudam a situação de uma cobrança; os outros (criada, vista etc.) são
    // respondidos com "ok" para não travar a fila de avisos do Asaas.
    $relevantes = [
      'PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED', 'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED', 'PAYMENT_OVERDUE', 'PAYMENT_DELETED',
      'PAYMENT_AWAITING_RISK_ANALYSIS', 'PAYMENT_APPROVED_BY_RISK_ANALYSIS', 'PAYMENT_REPROVED_BY_RISK_ANALYSIS',
      'PAYMENT_REFUNDED', 'PAYMENT_PARTIALLY_REFUNDED', 'PAYMENT_REFUND_IN_PROGRESS', 'PAYMENT_RESTORED',
      'PAYMENT_CHARGEBACK_REQUESTED', 'PAYMENT_CHARGEBACK_DISPUTE', 'PAYMENT_AWAITING_CHARGEBACK_REVERSAL',
    ];
    $aviso = is_array($corpo['payment'] ?? null) ? $corpo['payment'] : [];
    $id = (string)($aviso['id'] ?? '');
    if (!in_array($evento, $relevantes, true) || !preg_match('/^pay_[A-Za-z0-9]{1,40}$/', $id)) return ['ok' => true, 'ignorado' => true];

    // Removida no painel do Asaas: deixa de poder ser paga (uma cobrança removida nem sempre pode ser consultada).
    if ($evento === 'PAYMENT_DELETED') {
      if (Asaas::ehAssinatura((string)($aviso['subscription'] ?? ''))) return ['ok' => true, 'ignorado' => true];
      $gravado = Banco::um('SELECT * FROM pagamentos WHERE mp_id = ?', [$id]);
      if ($gravado && in_array($gravado['status'], ['pending', 'in_process'], true)) {
        Banco::executar("UPDATE pagamentos SET status = 'cancelled', status_detalhe = 'cancelled' WHERE id = ?", [$gravado['id']]);
        Pedidos::sincronizar((int)$gravado['pedido_id']);
      }
      return ['ok' => true];
    }

    $cobranca = Asaas::consultar($id);
    // Cobrança de assinatura: o módulo de serviços decide o pedido (primeiro período ou renovação).
    if (Asaas::ehAssinatura((string)($cobranca['subscription'] ?? ''))) {
      if ($servicos && method_exists($servicos, 'cobrancaAsaas')) $servicos->cobrancaAsaas($evento, $cobranca);
      return ['ok' => true];
    }

    // Compra: o pagamento gravado é a cobrança ou, no cartão parcelado, o parcelamento inteiro.
    $parcelamento = (string)($cobranca['installment'] ?? '');
    $chave = Asaas::ehParcelamento($parcelamento) ? $parcelamento : $id;
    $gravado = Banco::um('SELECT * FROM pagamentos WHERE mp_id = ?', [$chave]);
    $pedidoId = $gravado ? (int)$gravado['pedido_id'] : 0;
    if (!$pedidoId) {
      // Cobrança criada pela loja que não chegou a ser gravada (ex.: queda logo depois de criar).
      $ref = (int)($cobranca['externalReference'] ?? 0);
      if (!$ref || !Banco::valor('SELECT id FROM pedidos WHERE id = ?', [$ref])) return ['ok' => true, 'ignorado' => true];
      $pedidoId = $ref;
    }
    $pg = $chave === $id ? Asaas::comoPagamento($cobranca, $evento) : Asaas::pagamento($chave);
    Pedidos::registrarPagamento($pedidoId, $pg);
    return ['ok' => true];
  });

  $antigo = function (array $pg): void {
    if (Asaas::antigo($pg)) throw new ErroApi('Pagamento antigo, feito pelo Mercado Pago: consulte ou estorne pelo site do Mercado Pago.', 422);
  };

  // Estorno total (sem valor) ou parcial (com valor) de um pagamento aprovado.
  $r->admin('POST', 'pagamentos/{id}/estornar', function ($id) use ($antigo) {
    $pg = Banco::um('SELECT * FROM pagamentos WHERE id = ?', [(int)$id]);
    if (!$pg) throw new ErroApi('Pagamento não encontrado.', 404);
    $antigo($pg);
    if ($pg['status'] !== 'approved') throw new ErroApi('Só pagamentos aprovados podem ser estornados.', 422);
    $d = Http::entrada();
    $valor = isset($d['valor']) && $d['valor'] !== '' && $d['valor'] !== null ? Validacao::dinheiro($d['valor'], 'valor', 'Valor do estorno') : null;
    $disponivel = (float)$pg['valor'] - (float)$pg['valor_estornado'];
    if ($valor !== null && ((float)$valor <= 0 || (float)$valor > $disponivel + 0.001)) {
      throw new ErroApi('O valor do estorno deve ser maior que zero e no máximo ' . Pedidos::brl($disponivel) . '.', 422, ['campo' => 'valor']);
    }
    if (Asaas::ehParcelamento($pg['mp_id'])) {
      // Compra parcelada: o Asaas estorna todas as parcelas de uma vez.
      if ($valor !== null && (float)$valor < $disponivel - 0.001) {
        throw new ErroApi('Compras parceladas são estornadas pelo valor total aqui. Para estorno parcial, use o painel do Asaas.', 422, ['campo' => 'valor']);
      }
      Asaas::estornarParcelamento($pg['mp_id']);
    } else {
      Asaas::estornar($pg['mp_id'], $valor);
    }
    $novo = Asaas::pagamento($pg['mp_id']);
    // O estorno parcial pode levar um tempo para aparecer na consulta: conta o valor pedido agora.
    if ($valor !== null) $novo['valor_estornado'] = max((float)$novo['valor_estornado'], (float)$pg['valor_estornado'] + (float)$valor);
    Pedidos::registrarPagamento((int)$pg['pedido_id'], $novo, $pg['app']);
    return ['ok' => true];
  });

  $r->admin('POST', 'pagamentos/{id}/atualizar', function ($id) use ($antigo) {
    $pg = Banco::um('SELECT * FROM pagamentos WHERE id = ?', [(int)$id]);
    if (!$pg) throw new ErroApi('Pagamento não encontrado.', 404);
    $antigo($pg);
    Pedidos::registrarPagamento((int)$pg['pedido_id'], Asaas::pagamento($pg['mp_id']), $pg['app']);
    return ['ok' => true];
  });
};
