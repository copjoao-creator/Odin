<?php
/* Pedidos no painel: lista, detalhe, pedido manual (link de pagamento), cancelamento e reenvio do link. */
return function (Roteador $r) {
  $completo = function (array $p): array {
    $c = Clientes::buscar($p['cliente_cpf']);
    $receitaItens = 0.0;
    foreach ($p['itens'] as $i) $receitaItens += (float)$i['preco_unitario'] * (int)$i['quantidade'];
    return [
      'id' => (int)$p['id'],
      'status' => $p['status'],
      'status_texto' => Pedidos::STATUS[$p['status']],
      'origem' => $p['origem'],
      'cobranca_automatica' => Pedidos::cobrancaAutomatica($p),
      'criado_em' => $p['criado_em'],
      'pago_em' => $p['pago_em'],
      'cancelado_em' => $p['cancelado_em'],
      'forma_pagamento' => $p['forma_pagamento'],
      'subtotal' => (float)$p['subtotal'],
      'frete' => (float)$p['frete'],
      'total' => (float)$p['total'],
      'custo_total' => (float)$p['custo_total'],
      'lucro_bruto' => round($receitaItens - (float)$p['custo_total'], 2),
      'precisa_entrega' => (bool)$p['precisa_entrega'],
      'endereco' => array_intersect_key($p, array_flip(['cep', 'rua', 'numero', 'complemento', 'bairro', 'cidade', 'estado'])),
      'cliente' => $c ? array_intersect_key($c, array_flip(['cpf', 'nome', 'email', 'celular'])) : ['cpf' => $p['cliente_cpf']],
      'observacoes' => $p['observacoes'],
      'link' => Pedidos::link($p),
      'itens' => array_map(fn($i) => [
        'tipo' => $i['tipo'],
        'codigo' => $i['codigo'],
        'descricao' => $i['descricao'],
        'quantidade' => (int)$i['quantidade'],
        'preco_unitario' => (float)$i['preco_unitario'],
        'custo_unitario' => (float)$i['custo_unitario'],
        'renovacao' => $i['renovacao'],
      ], $p['itens']),
      'pagamentos' => array_map(fn($pg) => [
        'id' => (int)$pg['id'],
        'mp_id' => $pg['mp_id'],
        'gateway' => Asaas::antigo($pg) ? 'Mercado Pago (antigo)' : 'Asaas',
        // Pagamento antigo do Mercado Pago: só histórico (consulta e estorno no site do Mercado Pago).
        'antigo' => Asaas::antigo($pg),
        // Compra parcelada no cartão: o estorno pelo painel é sempre do valor total.
        'parcelado' => Asaas::ehParcelamento($pg['mp_id']),
        'metodo' => $pg['metodo'],
        'metodo_texto' => Pedidos::METODOS[$pg['metodo']] ?? $pg['metodo'],
        'mp_metodo' => $pg['mp_metodo'],
        'status' => $pg['status'],
        'mensagem' => Pedidos::mensagem($pg['status'], $pg['status_detalhe'], $pg['metodo']),
        'status_detalhe' => $pg['status_detalhe'],
        'valor' => (float)$pg['valor'],
        'valor_liquido' => $pg['valor_liquido'] !== null ? (float)$pg['valor_liquido'] : null,
        'valor_estornado' => (float)$pg['valor_estornado'],
        'parcelas' => (int)$pg['parcelas'],
        'link_pagamento' => $pg['link_pagamento'],
        'criado_em' => $pg['criado_em'],
        'aprovado_em' => $pg['aprovado_em'],
      ], $p['pagamentos']),
    ];
  };

  $r->admin('GET', 'pedidos', function () {
    $where = [];
    $params = [];
    $status = (string)($_GET['status'] ?? '');
    if (isset(Pedidos::STATUS[$status])) {
      $where[] = 'p.status = ?';
      $params[] = $status;
    }
    if (!empty($_GET['de'])) {
      $where[] = 'p.criado_em >= ?';
      $params[] = Validacao::data($_GET['de'], 'de', 'Data inicial') . ' 00:00:00';
    }
    if (!empty($_GET['ate'])) {
      $where[] = 'p.criado_em < DATE_ADD(?, INTERVAL 1 DAY)';
      $params[] = Validacao::data($_GET['ate'], 'ate', 'Data final');
    }
    $busca = trim((string)($_GET['busca'] ?? ''));
    if (preg_match('/^#?(\d{1,7})$/', $busca, $m)) {
      // Número curto (ou "#123"): busca pelo número do pedido.
      $where[] = 'p.id = ?';
      $params[] = (int)$m[1];
    } elseif ($busca !== '') {
      $cpf = Validacao::digitos($busca);
      $where[] = '(c.nome LIKE ? OR c.email LIKE ?' . (strlen($cpf) >= 3 ? ' OR p.cliente_cpf LIKE ?' : '') . ')';
      array_push($params, "%{$busca}%", "%{$busca}%");
      if (strlen($cpf) >= 3) $params[] = "%{$cpf}%";
    }
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $sql = 'SELECT p.id, p.status, p.origem, p.criado_em, p.pago_em, p.total, p.forma_pagamento, p.cliente_cpf, c.nome, c.email,
                   (SELECT COUNT(*) FROM pedido_itens i WHERE i.pedido_id = p.id) AS itens
            FROM pedidos p JOIN clientes c ON c.cpf = p.cliente_cpf'
      . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
      . ' ORDER BY p.id DESC LIMIT 101 OFFSET ' . (($pagina - 1) * 100);
    $lista = Banco::todos($sql, $params);
    return [
      'pedidos' => array_map(fn($p) => [
        'id' => (int)$p['id'],
        'status' => $p['status'],
        'status_texto' => Pedidos::STATUS[$p['status']],
        'origem' => $p['origem'],
        'criado_em' => $p['criado_em'],
        'pago_em' => $p['pago_em'],
        'total' => (float)$p['total'],
        'forma_pagamento' => $p['forma_pagamento'],
        'cliente_cpf' => $p['cliente_cpf'],
        'cliente_nome' => $p['nome'],
        'cliente_email' => $p['email'],
        'itens' => (int)$p['itens'],
      ], array_slice($lista, 0, 100)),
      'mais' => count($lista) > 100,
    ];
  });

  $r->admin('GET', 'pedidos/{id}', function ($id) use ($completo) {
    $p = Pedidos::carregar((int)$id);
    if (!$p) throw new ErroApi('Pedido não encontrado.', 404);
    return ['pedido' => $completo($p)];
  });

  // Venda feita fora da loja (WhatsApp, telefone...): cria o pedido e devolve o link de pagamento.
  $r->admin('POST', 'pedidos/manual', function () use ($completo) {
    $d = Http::entrada();
    $c = Clientes::buscar(Validacao::cpf($d['cpf'] ?? ''));
    if (!$c) throw new ErroApi('Cliente não encontrado. Cadastre o cliente primeiro.', 404, ['campo' => 'cpf']);
    $itens = Pedidos::itensDoCarrinho($d['itens'] ?? []);
    // Assinatura: data final opcional (encerra sozinha nessa data).
    $dataFinal = Pedidos::validarDataFinal($d['data_final'] ?? null);
    $p = Pedidos::criar($c, $itens, 'admin');
    if ($dataFinal && Pedidos::itemAssinatura($p)) {
      Banco::executar('UPDATE pedidos SET assinatura_data_final = ? WHERE id = ?', [$dataFinal, $p['id']]);
      $p = Pedidos::carregar((int)$p['id']);
    }
    $enviado = Validacao::booleano($d['enviar_email'] ?? false) ? Pedidos::enviarLink($p) : false;
    return ['pedido' => $completo($p), 'email_enviado' => $enviado];
  });

  $r->admin('POST', 'pedidos/{id}/cancelar', function ($id) use ($completo) {
    $motivo = Validacao::texto(Http::entrada(), 'motivo', 'Motivo', 200, false) ?: 'pelo administrador';
    return ['pedido' => $completo(Pedidos::cancelar((int)$id, $motivo))];
  });

  $r->admin('POST', 'pedidos/{id}/enviar-link', function ($id) {
    $p = Pedidos::carregar((int)$id);
    if (!$p) throw new ErroApi('Pedido não encontrado.', 404);
    if (!in_array($p['status'], ['aguardando_pagamento'], true)) throw new ErroApi('Este pedido não está aguardando pagamento.', 422);
    if (Pedidos::cobrancaAutomatica($p)) throw new ErroApi('Esta renovação é debitada automaticamente no cartão pelo Asaas. Não envie link: o cliente pagaria duas vezes.', 422);
    if (!Pedidos::enviarLink($p)) throw new ErroApi('Não foi possível enviar o e-mail. Copie o link e envie pelo WhatsApp.', 502);
    return ['ok' => true];
  });

  $r->admin('POST', 'pedidos/{id}/observacao', function ($id) use ($completo) {
    $texto = Validacao::textoLongo(Http::entrada(), 'texto', 'Observação', 1000);
    $n = Banco::executar(
      "UPDATE pedidos SET observacoes = CONCAT_WS('\n', observacoes, ?) WHERE id = ?",
      [date('d/m/Y H:i') . ' - ' . $texto, (int)$id]
    );
    if (!$n) throw new ErroApi('Pedido não encontrado.', 404);
    return ['pedido' => $completo(Pedidos::carregar((int)$id))];
  });
};
