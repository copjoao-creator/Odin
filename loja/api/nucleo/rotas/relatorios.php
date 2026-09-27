<?php
/* Relatórios financeiros do painel e exportação para planilha (CSV). */
return function (Roteador $r) {
  /** Período pedido na tela; o padrão é o mês atual. Devolve [de, ate, inicio, fimExclusivo]. */
  $periodo = function (): array {
    $de = !empty($_GET['de']) ? Validacao::data($_GET['de'], 'de', 'Data inicial') : date('Y-m-01');
    $ate = !empty($_GET['ate']) ? Validacao::data($_GET['ate'], 'ate', 'Data final') : date('Y-m-d');
    if ($ate < $de) throw new ErroApi('A data final precisa ser depois da inicial.', 422);
    return [$de, $ate, $de . ' 00:00:00', date('Y-m-d', strtotime($ate . ' +1 day')) . ' 00:00:00'];
  };

  $totais = function (string $ini, string $fim): array {
    $p = Banco::um(
      "SELECT COUNT(*) AS pedidos, COALESCE(SUM(total), 0) AS receita, COALESCE(SUM(custo_total), 0) AS custo, COALESCE(SUM(frete), 0) AS frete
       FROM pedidos WHERE status = 'pago' AND pago_em >= ? AND pago_em < ?",
      [$ini, $fim]
    );
    $pg = Banco::um(
      "SELECT COALESCE(SUM(COALESCE(pg.valor_liquido, pg.valor)), 0) AS liquido, COALESCE(SUM(pg.valor_estornado), 0) AS estornado
       FROM pagamentos pg JOIN pedidos p ON p.id = pg.pedido_id
       WHERE pg.status = 'approved' AND p.status = 'pago' AND p.pago_em >= ? AND p.pago_em < ?",
      [$ini, $fim]
    );
    $receita = (float)$p['receita'];
    $liquido = (float)$pg['liquido'];
    $estornoParcial = (float)$pg['estornado'];
    $lucro = $liquido - $estornoParcial - (float)$p['custo'] - (float)$p['frete'];
    return [
      'pedidos_pagos' => (int)$p['pedidos'],
      'receita_bruta' => round($receita, 2),
      'taxas_mercado_pago' => round(max(0, $receita - $liquido), 2),
      'valor_liquido' => round($liquido - $estornoParcial, 2),
      'estornos_parciais' => round($estornoParcial, 2),
      'custo_itens' => round((float)$p['custo'], 2),
      'frete_cobrado' => round((float)$p['frete'], 2),
      'lucro_estimado' => round($lucro, 2),
      'margem' => $receita > 0 ? round($lucro / $receita * 100, 1) : 0,
      'ticket_medio' => $p['pedidos'] ? round($receita / (int)$p['pedidos'], 2) : 0,
    ];
  };

  $r->admin('GET', 'relatorios/resumo', function () use ($periodo, $totais) {
    [$de, $ate, $ini, $fim] = $periodo();
    $atual = $totais($ini, $fim);

    // Período anterior do mesmo tamanho, para comparação.
    $dias = (int)round((strtotime($fim) - strtotime($ini)) / 86400);
    $antIni = date('Y-m-d H:i:s', strtotime($ini . " -{$dias} days"));
    $anterior = $totais($antIni, $ini);

    $porDia = Banco::todos(
      "SELECT DATE(pago_em) AS dia, COUNT(*) AS pedidos, SUM(total) AS receita
       FROM pedidos WHERE status = 'pago' AND pago_em >= ? AND pago_em < ? GROUP BY DATE(pago_em) ORDER BY dia",
      [$ini, $fim]
    );
    $porMetodo = Banco::todos(
      "SELECT COALESCE(forma_pagamento, 'outro') AS metodo, COUNT(*) AS pedidos, SUM(total) AS receita
       FROM pedidos WHERE status = 'pago' AND pago_em >= ? AND pago_em < ? GROUP BY forma_pagamento ORDER BY receita DESC",
      [$ini, $fim]
    );
    $porTipo = Banco::todos(
      "SELECT i.tipo, SUM(i.quantidade * i.preco_unitario) AS receita, SUM(i.quantidade * (i.preco_unitario - i.custo_unitario)) AS lucro
       FROM pedido_itens i JOIN pedidos p ON p.id = i.pedido_id
       WHERE p.status = 'pago' AND p.pago_em >= ? AND p.pago_em < ? GROUP BY i.tipo",
      [$ini, $fim]
    );
    $topItens = Banco::todos(
      "SELECT i.tipo, i.codigo, MAX(i.descricao) AS descricao, SUM(i.quantidade) AS quantidade,
              SUM(i.quantidade * i.preco_unitario) AS receita, SUM(i.quantidade * (i.preco_unitario - i.custo_unitario)) AS lucro
       FROM pedido_itens i JOIN pedidos p ON p.id = i.pedido_id
       WHERE p.status = 'pago' AND p.pago_em >= ? AND p.pago_em < ?
       GROUP BY i.tipo, i.codigo ORDER BY receita DESC LIMIT 10",
      [$ini, $fim]
    );
    $aguardando = Banco::um("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS valor FROM pedidos WHERE status IN ('aguardando_pagamento', 'em_analise')");
    $estornados = Banco::um(
      "SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS valor FROM pedidos WHERE status = 'estornado' AND atualizado_em >= ? AND atualizado_em < ?",
      [$ini, $fim]
    );
    $cancelados = (int)Banco::valor("SELECT COUNT(*) FROM pedidos WHERE status = 'cancelado' AND cancelado_em >= ? AND cancelado_em < ?", [$ini, $fim]);

    $modulos = [];
    foreach (Modulos::ativos() as $m) $modulos[$m->tipo()] = $m->resumo($de, $ate);

    return [
      'periodo' => ['de' => $de, 'ate' => $ate],
      'totais' => $atual,
      'anterior' => $anterior,
      'por_dia' => array_map(fn($l) => ['dia' => $l['dia'], 'pedidos' => (int)$l['pedidos'], 'receita' => (float)$l['receita']], $porDia),
      'por_metodo' => array_map(fn($l) => [
        'metodo' => $l['metodo'], 'metodo_texto' => Pedidos::METODOS[$l['metodo']] ?? $l['metodo'],
        'pedidos' => (int)$l['pedidos'], 'receita' => (float)$l['receita'],
      ], $porMetodo),
      'por_tipo' => array_map(fn($l) => ['tipo' => $l['tipo'], 'receita' => (float)$l['receita'], 'lucro' => (float)$l['lucro']], $porTipo),
      'top_itens' => array_map(fn($l) => [
        'tipo' => $l['tipo'], 'codigo' => $l['codigo'], 'descricao' => $l['descricao'],
        'quantidade' => (int)$l['quantidade'], 'receita' => (float)$l['receita'], 'lucro' => (float)$l['lucro'],
      ], $topItens),
      'aguardando' => ['pedidos' => (int)$aguardando['n'], 'valor' => (float)$aguardando['valor']],
      'estornados' => ['pedidos' => (int)$estornados['n'], 'valor' => (float)$estornados['valor']],
      'cancelados' => $cancelados,
      'clientes_novos' => (int)Banco::valor('SELECT COUNT(*) FROM clientes WHERE criado_em >= ? AND criado_em < ?', [$ini, $fim]),
      'modulos' => $modulos,
      'mercado_pago_configurado' => MercadoPago::configurado(),
      'ultima_tarefa_diaria' => Config::get('ultima_tarefa_diaria') ?: null,
    ];
  });

  // Planilha com todos os pedidos criados no período (abre direto no Excel).
  $r->admin('GET', 'relatorios/pedidos.csv', function () use ($periodo) {
    [$de, $ate, $ini, $fim] = $periodo();
    $lista = Banco::todos(
      "SELECT p.*, c.nome, c.email,
              (SELECT COALESCE(SUM(COALESCE(pg.valor_liquido, pg.valor) - pg.valor_estornado), 0) FROM pagamentos pg WHERE pg.pedido_id = p.id AND pg.status = 'approved') AS liquido
       FROM pedidos p JOIN clientes c ON c.cpf = p.cliente_cpf
       WHERE p.criado_em >= ? AND p.criado_em < ? ORDER BY p.id",
      [$ini, $fim]
    );
    $num = fn($v) => number_format((float)$v, 2, ',', '');
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"pedidos_{$de}_a_{$ate}.csv\"");
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Pedido', 'Criado em', 'Pago em', 'Status', 'Origem', 'Cliente', 'CPF', 'E-mail', 'Forma de pagamento',
      'Subtotal', 'Frete', 'Total', 'Custo dos itens', 'Valor líquido recebido', 'Lucro estimado'], ';', '"', '');
    foreach ($lista as $p) {
      $pago = $p['status'] === 'pago';
      fputcsv($out, [
        $p['id'], $p['criado_em'], $p['pago_em'], Pedidos::STATUS[$p['status']], $p['origem'], $p['nome'], $p['cliente_cpf'], $p['email'],
        Pedidos::METODOS[$p['forma_pagamento']] ?? '', $num($p['subtotal']), $num($p['frete']), $num($p['total']), $num($p['custo_total']),
        $pago ? $num($p['liquido']) : '', $pago ? $num((float)$p['liquido'] - (float)$p['custo_total'] - (float)$p['frete']) : '',
      ], ';', '"', '');
    }
    fclose($out);
    return null;
  });
};
