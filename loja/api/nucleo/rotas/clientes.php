<?php
/* Cadastro de clientes no painel. */
return function (Roteador $r) {
  $r->admin('GET', 'clientes', function () {
    $busca = trim((string)($_GET['busca'] ?? ''));
    $params = [];
    $sql = 'SELECT c.*,
              (SELECT COUNT(*) FROM pedidos p WHERE p.cliente_cpf = c.cpf AND p.status = \'pago\') AS compras,
              (SELECT COALESCE(SUM(p.total), 0) FROM pedidos p WHERE p.cliente_cpf = c.cpf AND p.status = \'pago\') AS total_gasto
            FROM clientes c';
    if ($busca !== '') {
      $digitos = Validacao::digitos($busca);
      $sql .= ' WHERE c.nome LIKE ? OR c.email LIKE ? OR c.cpf LIKE ? OR c.celular LIKE ?';
      array_push($params, "%{$busca}%", "%{$busca}%", '%' . ($digitos ?: $busca) . '%', '%' . ($digitos ?: $busca) . '%');
    }
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $lista = Banco::todos($sql . ' ORDER BY c.nome LIMIT 101 OFFSET ' . (($pagina - 1) * 100), $params);
    return [
      'clientes' => array_map(fn($c) => ['compras' => (int)$c['compras'], 'total_gasto' => (float)$c['total_gasto']] + $c, array_slice($lista, 0, 100)),
      'mais' => count($lista) > 100,
    ];
  });

  $r->admin('GET', 'clientes/{cpf}', function ($cpf) {
    $c = Clientes::buscar(Validacao::digitos($cpf));
    if (!$c) throw new ErroApi('Cliente não encontrado.', 404);
    $pedidos = Banco::todos('SELECT id, status, total, criado_em, pago_em, forma_pagamento FROM pedidos WHERE cliente_cpf = ? ORDER BY id DESC LIMIT 100', [$c['cpf']]);
    return ['cliente' => $c, 'pedidos' => array_map(fn($p) => [
      'id' => (int)$p['id'],
      'status' => $p['status'],
      'status_texto' => Pedidos::STATUS[$p['status']],
      'total' => (float)$p['total'],
      'criado_em' => $p['criado_em'],
      'pago_em' => $p['pago_em'],
      'forma_pagamento' => $p['forma_pagamento'],
    ], $pedidos)];
  });

  $r->admin('POST', 'clientes', function () {
    $c = Clientes::validar(Http::entrada());
    Clientes::inserir($c);
    return ['cliente' => Clientes::buscar($c['cpf'])];
  });

  $r->admin('PUT', 'clientes/{cpf}', function ($cpf) {
    $atual = Clientes::buscar(Validacao::digitos($cpf));
    if (!$atual) throw new ErroApi('Cliente não encontrado.', 404);
    $c = Clientes::validar(Http::entrada());
    Clientes::atualizar($atual['cpf'], $c);
    return ['cliente' => Clientes::buscar($c['cpf'])];
  });

  $r->admin('DELETE', 'clientes/{cpf}', function ($cpf) {
    try {
      $n = Banco::executar('DELETE FROM clientes WHERE cpf = ?', [Validacao::digitos($cpf)]);
    } catch (PDOException $e) {
      if (Banco::emUso($e)) throw new ErroApi('Este cliente tem pedidos ou assinaturas e não pode ser excluído (o histórico financeiro precisa ser mantido).', 409);
      throw $e;
    }
    if (!$n) throw new ErroApi('Cliente não encontrado.', 404);
    return ['ok' => true];
  });
};
