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
    if (!MercadoPago::configurado()) throw new ErroApi('Pagamentos indisponíveis no momento. Fale com a loja.', 503);

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

    $pg = Pedidos::registrarPagamento((int)$p['id'], MercadoPago::criarPagamento($corpo));

    // Cliente trocou a forma de pagamento: invalida o Pix/boleto anterior para não pagar duas vezes.
    if ($pg['status'] !== 'rejected') {
      foreach ($p['pagamentos'] as $antigo) {
        if ($antigo['status'] === 'pending' && $antigo['mp_id'] !== $pg['mp_id']) {
          try {
            Pedidos::registrarPagamento((int)$p['id'], MercadoPago::cancelar($antigo['mp_id']));
          } catch (Throwable $e) {
            error_log("Não foi possível cancelar o pagamento anterior {$antigo['mp_id']}: " . $e->getMessage());
          }
        }
      }
    }
    return ['pedido' => Pedidos::publico(Pedidos::carregar((int)$p['id'])), 'pagamento' => Pedidos::resumoPagamento($pg)];
  });

  // Aviso do Mercado Pago de que um pagamento mudou. Os dados são sempre consultados
  // de novo na API com o nosso token, então um aviso falso não consegue marcar nada como pago.
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
    if ($tipo !== 'payment' || !preg_match('/^\d{1,30}$/', $id)) return ['ok' => true, 'ignorado' => true];
    if (!MercadoPago::assinaturaValida($id)) throw new ErroApi('Assinatura do aviso inválida.', 401);

    $mp = MercadoPago::consultar($id);
    $pedidoId = (int)($mp['external_reference'] ?? 0);
    if (!$pedidoId || !Banco::valor('SELECT id FROM pedidos WHERE id = ?', [$pedidoId])) return ['ok' => true, 'ignorado' => true];
    Pedidos::registrarPagamento($pedidoId, $mp);
    return ['ok' => true];
  };
  $r->publica('POST', 'webhook/mercadopago', $webhook);
  $r->publica('GET', 'webhook/mercadopago', $webhook);

  // Estorno total (sem valor) ou parcial (com valor) de um pagamento aprovado.
  $r->admin('POST', 'pagamentos/{id}/estornar', function ($id) {
    $pg = Banco::um('SELECT * FROM pagamentos WHERE id = ?', [(int)$id]);
    if (!$pg) throw new ErroApi('Pagamento não encontrado.', 404);
    if ($pg['status'] !== 'approved') throw new ErroApi('Só pagamentos aprovados podem ser estornados.', 422);
    $d = Http::entrada();
    $valor = isset($d['valor']) && $d['valor'] !== '' && $d['valor'] !== null ? Validacao::dinheiro($d['valor'], 'valor', 'Valor do estorno') : null;
    $disponivel = (float)$pg['valor'] - (float)$pg['valor_estornado'];
    if ($valor !== null && ((float)$valor <= 0 || (float)$valor > $disponivel + 0.001)) {
      throw new ErroApi('O valor do estorno deve ser maior que zero e no máximo ' . Pedidos::brl($disponivel) . '.', 422, ['campo' => 'valor']);
    }
    MercadoPago::estornar($pg['mp_id'], $valor);
    Pedidos::registrarPagamento((int)$pg['pedido_id'], MercadoPago::consultar($pg['mp_id']));
    return ['ok' => true];
  });

  $r->admin('POST', 'pagamentos/{id}/atualizar', function ($id) {
    $pg = Banco::um('SELECT * FROM pagamentos WHERE id = ?', [(int)$id]);
    if (!$pg) throw new ErroApi('Pagamento não encontrado.', 404);
    Pedidos::registrarPagamento((int)$pg['pedido_id'], MercadoPago::consultar($pg['mp_id']));
    return ['ok' => true];
  });
};
