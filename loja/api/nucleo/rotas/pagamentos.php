<?php
/* Pagamentos pelo Mercado Pago: criação (Pix, boleto, crédito, débito), webhook, estorno e reconsulta. */
return function (Roteador $r) {
  // Recebe os dados do Payment Brick (formulário do Mercado Pago) e cria o pagamento.
  // O valor cobrado é sempre o total do pedido gravado no banco.
  $r->publica('POST', 'pagamentos', function () {
    $d = Http::entrada();
    $p = Pedidos::peloToken($d['pedido_id'] ?? 0, $d['token'] ?? '');
    if ($p['status'] === 'pago') throw new ErroApi('Este pedido já está pago.', 409);
    if ($p['status'] === 'em_analise') throw new ErroApi('Já existe um pagamento em análise para este pedido. Aguarde a confirmação por e-mail.', 409);
    if (in_array($p['status'], ['cancelado', 'estornado'], true)) throw new ErroApi('Este pedido foi cancelado. Faça um novo pedido na loja.', 409);
    $app = Pedidos::app($p);
    if (!MercadoPago::configurado($app)) throw new ErroApi('Pagamentos indisponíveis no momento. Fale com a loja.', 503);
    if (Pedidos::cobrancaAutomatica($p)) throw new ErroApi('Esta renovação é debitada automaticamente no seu cartão. Não é preciso pagar por aqui.', 422);
    if (Pedidos::itemAssinatura($p)) throw new ErroApi('Assinaturas são pagas com cartão de crédito, com cobrança automática. Recarregue a página.', 422);

    $f = is_array($d['dados'] ?? null) ? $d['dados'] : [];
    $metodo = (string)($f['payment_method_id'] ?? '');
    if (!preg_match('/^[a-z0-9_]{2,40}$/', $metodo)) throw new ErroApi('Escolha uma forma de pagamento.', 422);

    $c = Clientes::buscar($p['cliente_cpf']);
    [$primeiro, $ultimo] = Clientes::nomes($c['nome']);
    $pf = is_array($f['payer'] ?? null) ? $f['payer'] : [];
    $doc = is_array($pf['identification'] ?? null) ? $pf['identification'] : [];
    $endereco = [
      'zip_code' => $p['cep'],
      'street_name' => $p['rua'],
      'street_number' => $p['numero'],
      'neighborhood' => $p['bairro'],
      'city' => $p['cidade'],
      'federal_unit' => $p['estado'],
    ];
    if (is_array($pf['address'] ?? null)) {
      foreach ($endereco as $k => $v) if (isset($pf['address'][$k]) && is_scalar($pf['address'][$k]) && $pf['address'][$k] !== '') $endereco[$k] = mb_substr((string)$pf['address'][$k], 0, 150);
    }
    $payer = [
      'email' => filter_var($pf['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: $c['email'],
      'first_name' => mb_substr(trim((string)($pf['first_name'] ?? '')) ?: $primeiro, 0, 100),
      'last_name' => mb_substr(trim((string)($pf['last_name'] ?? '')) ?: $ultimo, 0, 100),
      'identification' => [
        'type' => in_array($doc['type'] ?? '', ['CPF', 'CNPJ'], true) ? $doc['type'] : 'CPF',
        'number' => Validacao::digitos($doc['number'] ?? '') ?: $c['cpf'],
      ],
      'address' => $endereco,
    ];

    $corpo = [
      'transaction_amount' => (float)$p['total'],
      'description' => mb_substr(Config::get('loja_nome') . ' - Pedido #' . $p['id'], 0, 250),
      'payment_method_id' => $metodo,
      'external_reference' => (string)$p['id'],
      'payer' => $payer,
      'additional_info' => [
        'items' => array_map(fn($i) => [
          'id' => $i['codigo'],
          'title' => $i['descricao'],
          'category_id' => $i['tipo'] === 'servico' ? 'services' : 'others',
          'quantity' => (int)$i['quantidade'],
          'unit_price' => (float)$i['preco_unitario'],
        ], $p['itens']),
        'payer' => [
          'first_name' => $primeiro,
          'last_name' => $ultimo,
          'phone' => ['area_code' => substr($c['celular'], 0, 2), 'number' => substr($c['celular'], 2)],
        ],
      ],
    ];
    $fatura = substr((string)preg_replace('/[^A-Z0-9]/', '', strtoupper(Config::get('loja_nome'))), 0, 13);
    if ($fatura !== '') $corpo['statement_descriptor'] = $fatura;
    if (!empty($f['token'])) {
      $maxParcelas = max(1, (int)Config::get('max_parcelas'));
      $corpo['token'] = (string)$f['token'];
      $corpo['installments'] = max(1, min((int)($f['installments'] ?? 1), $maxParcelas));
      if (!empty($f['issuer_id'])) $corpo['issuer_id'] = is_numeric($f['issuer_id']) ? (int)$f['issuer_id'] : (string)$f['issuer_id'];
    }
    // O Mercado Pago só avisa endereços públicos com HTTPS.
    $url = Http::urlLoja();
    if (strpos($url, 'https://') === 0) $corpo['notification_url'] = $url . 'api/?r=webhook/mercadopago';

    $pg = Pedidos::registrarPagamento((int)$p['id'], MercadoPago::criarPagamento($corpo, $app), $app);

    // Cliente trocou a forma de pagamento: invalida o Pix/boleto anterior para não pagar duas vezes.
    if ($pg['status'] !== 'rejected') {
      foreach ($p['pagamentos'] as $antigo) {
        if ($antigo['status'] === 'pending' && $antigo['mp_id'] !== $pg['mp_id']) {
          try {
            Pedidos::registrarPagamento((int)$p['id'], MercadoPago::cancelar($antigo['mp_id'], $antigo['app']), $antigo['app']);
          } catch (Throwable $e) {
            error_log("Não foi possível cancelar o pagamento anterior {$antigo['mp_id']}: " . $e->getMessage());
          }
        }
      }
    }
    // Boleto gerado: envia também para o e-mail do cadastro do cliente.
    if ($pg['metodo'] === 'boleto' && $pg['status'] === 'pending') Pedidos::enviarBoleto($p, $pg);
    return ['pedido' => Pedidos::publico(Pedidos::carregar((int)$p['id'])), 'pagamento' => Pedidos::resumoPagamento($pg)];
  });

  // Aviso do Mercado Pago de que um pagamento (ou uma assinatura) mudou. Os dados são sempre
  // consultados de novo na API com o nosso token, então um aviso falso não consegue marcar nada como pago.
  // A aplicação de serviços usa o mesmo endereço com &app=servicos.
  $webhook = function () {
    $q = [];
    foreach (explode('&', (string)($_SERVER['QUERY_STRING'] ?? '')) as $parte) {
      if ($parte === '') continue;
      [$k, $v] = explode('=', $parte, 2) + [1 => ''];
      $q[urldecode($k)] = urldecode($v);
    }
    $corpo = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $tipo = $q['type'] ?? $q['topic'] ?? $corpo['type'] ?? $corpo['topic'] ?? '';
    $id = (string)($q['data.id'] ?? $corpo['data']['id'] ?? $q['id'] ?? '');
    $app = MercadoPago::appEfetiva(($q['app'] ?? '') === 'servicos' ? 'servicos' : 'loja');
    $assinaturas = ['subscription_preapproval', 'subscription_authorized_payment'];
    if (!in_array($tipo, array_merge(['payment'], $assinaturas), true) || !preg_match('/^[A-Za-z0-9]{1,40}$/', $id)) return ['ok' => true, 'ignorado' => true];
    if (!MercadoPago::assinaturaValida($id, $app)) throw new ErroApi('Assinatura do aviso inválida.', 401);

    if (in_array($tipo, $assinaturas, true)) {
      $servicos = Modulos::doItem('servico');
      if ($app !== 'servicos' || !$servicos || !method_exists($servicos, 'avisoAssinaturaMp')) return ['ok' => true, 'ignorado' => true];
      $servicos->avisoAssinaturaMp($tipo, $id);
      return ['ok' => true];
    }

    if (!ctype_digit($id)) return ['ok' => true, 'ignorado' => true];
    $mp = MercadoPago::consultar($id, $app);
    // Cobranças de assinatura: o pedido certo (primeiro pagamento ou renovação) é decidido pelo módulo de serviços.
    $servicos = Modulos::doItem('servico');
    if ($app === 'servicos' && $servicos && method_exists($servicos, 'pagamentoDeAssinaturaMp') && $servicos->pagamentoDeAssinaturaMp($mp)) {
      return ['ok' => true];
    }
    $pedidoId = (int)($mp['external_reference'] ?? 0);
    if (!$pedidoId || !Banco::valor('SELECT id FROM pedidos WHERE id = ?', [$pedidoId])) return ['ok' => true, 'ignorado' => true];
    Pedidos::registrarPagamento($pedidoId, $mp, $app);
    return ['ok' => true];
  };
  $r->publica('POST', 'webhook/mercadopago', $webhook);
  $r->publica('GET', 'webhook/mercadopago', $webhook);

  // Aviso do Asaas (cobranças das assinaturas no cartão). Autenticado pelo token do cabeçalho
  // "asaas-access-token"; mesmo assim a cobrança é consultada de novo na API antes de gravar.
  $r->publica('POST', 'webhook/asaas', function () {
    if (!Asaas::avisoValido()) throw new ErroApi('Token do aviso inválido.', 401);
    $corpo = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $evento = (string)($corpo['event'] ?? '');
    $servicos = Modulos::doItem('servico');
    if (!$servicos || !method_exists($servicos, 'cobrancaAsaas')) return ['ok' => true, 'ignorado' => true];

    // Assinatura removida ou desativada direto no painel do Asaas.
    if (in_array($evento, ['SUBSCRIPTION_DELETED', 'SUBSCRIPTION_INACTIVATED'], true)) {
      $sub = (string)($corpo['subscription']['id'] ?? '');
      if (Asaas::ehAssinatura($sub)) $servicos->assinaturaAsaasEncerrada($sub);
      return ['ok' => true];
    }
    // Só os eventos que mudam a situação de uma cobrança já processada; os outros (criada, vista,
    // removida etc.) são respondidos com "ok" para não travar a fila de avisos do Asaas.
    $relevantes = [
      'PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED', 'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED', 'PAYMENT_OVERDUE',
      'PAYMENT_AWAITING_RISK_ANALYSIS', 'PAYMENT_APPROVED_BY_RISK_ANALYSIS', 'PAYMENT_REPROVED_BY_RISK_ANALYSIS',
      'PAYMENT_REFUNDED', 'PAYMENT_PARTIALLY_REFUNDED', 'PAYMENT_REFUND_IN_PROGRESS', 'PAYMENT_RESTORED',
      'PAYMENT_CHARGEBACK_REQUESTED', 'PAYMENT_CHARGEBACK_DISPUTE', 'PAYMENT_AWAITING_CHARGEBACK_REVERSAL',
    ];
    $id = (string)($corpo['payment']['id'] ?? '');
    if (!in_array($evento, $relevantes, true) || !preg_match('/^pay_[A-Za-z0-9]{1,40}$/', $id)) return ['ok' => true, 'ignorado' => true];
    $servicos->cobrancaAsaas($evento, Asaas::consultar($id));
    return ['ok' => true];
  });

  /** Consulta o pagamento no gateway que o criou (Mercado Pago ou Asaas), no formato gravado pela loja. */
  $consultar = fn(array $pg): array => $pg['app'] === Asaas::APP
    ? Asaas::comoPagamento(Asaas::consultar($pg['mp_id']))
    : MercadoPago::consultar($pg['mp_id'], $pg['app']);

  // Estorno total (sem valor) ou parcial (com valor) de um pagamento aprovado.
  $r->admin('POST', 'pagamentos/{id}/estornar', function ($id) use ($consultar) {
    $pg = Banco::um('SELECT * FROM pagamentos WHERE id = ?', [(int)$id]);
    if (!$pg) throw new ErroApi('Pagamento não encontrado.', 404);
    if ($pg['status'] !== 'approved') throw new ErroApi('Só pagamentos aprovados podem ser estornados.', 422);
    $d = Http::entrada();
    $valor = isset($d['valor']) && $d['valor'] !== '' && $d['valor'] !== null ? Validacao::dinheiro($d['valor'], 'valor', 'Valor do estorno') : null;
    $disponivel = (float)$pg['valor'] - (float)$pg['valor_estornado'];
    if ($valor !== null && ((float)$valor <= 0 || (float)$valor > $disponivel + 0.001)) {
      throw new ErroApi('O valor do estorno deve ser maior que zero e no máximo ' . Pedidos::brl($disponivel) . '.', 422, ['campo' => 'valor']);
    }
    if ($pg['app'] === Asaas::APP) {
      // A loja só acompanha o estorno total das cobranças do Asaas; o parcial é feito no painel do Asaas.
      if ($valor !== null && (float)$valor < $disponivel - 0.001) {
        throw new ErroApi('Cobranças do Asaas são estornadas pelo valor total aqui. Para estorno parcial, use o painel do Asaas.', 422, ['campo' => 'valor']);
      }
      Asaas::estornar($pg['mp_id']);
    } else {
      MercadoPago::estornar($pg['mp_id'], $valor, $pg['app']);
    }
    Pedidos::registrarPagamento((int)$pg['pedido_id'], $consultar($pg), $pg['app']);
    return ['ok' => true];
  });

  $r->admin('POST', 'pagamentos/{id}/atualizar', function ($id) use ($consultar) {
    $pg = Banco::um('SELECT * FROM pagamentos WHERE id = ?', [(int)$id]);
    if (!$pg) throw new ErroApi('Pagamento não encontrado.', 404);
    Pedidos::registrarPagamento((int)$pg['pedido_id'], $consultar($pg), $pg['app']);
    return ['ok' => true];
  });
};
