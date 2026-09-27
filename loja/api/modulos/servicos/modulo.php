<?php
/*
 * Módulo de serviços.
 * Tabelas próprias (tabelas.sql), rotas próprias e assinaturas para serviços recorrentes
 * (mensal, trimestral, semestral ou anual). Pode ser desligado no painel sem afetar o de produtos.
 */
return new class implements ModuloCatalogo {
  private const TAMANHO_FOTO = 250;
  private const PASTA = 'servicos';
  private const MESES = ['mensal' => 1, 'trimestral' => 3, 'semestral' => 6, 'anual' => 12];
  private const RENOVACOES = ['unica' => 'Pagamento único', 'mensal' => 'Mensal', 'trimestral' => 'Trimestral', 'semestral' => 'Semestral', 'anual' => 'Anual'];

  public function tipo(): string
  {
    return 'servico';
  }

  public function nome(): string
  {
    return 'Serviços';
  }

  public function arquivoSql(): string
  {
    return __DIR__ . '/tabelas.sql';
  }

  public function rotas(Roteador $r): void
  {
    $r->publica('GET', 'servicos/vitrine', fn() => $this->vitrine());
    $r->admin('GET', 'servicos', fn() => $this->listar());
    $r->admin('POST', 'servicos', fn() => $this->criar());
    $r->admin('GET', 'servicos/{codigo}', fn($c) => ['servico' => $this->completo($this->buscar($c))]);
    $r->admin('PUT', 'servicos/{codigo}', fn($c) => $this->alterar($c));
    $r->admin('DELETE', 'servicos/{codigo}', fn($c) => $this->excluir($c));
    $r->admin('POST', 'servicos/{codigo}/foto', fn($c) => $this->enviarFoto($c));
    $r->admin('DELETE', 'servicos/{codigo}/foto', fn($c) => $this->excluirFoto($c));

    $r->admin('GET', 'assinaturas', fn() => $this->assinaturas());
    $r->admin('POST', 'assinaturas/renovacoes', fn() => ['mensagens' => $this->tarefasDiarias()]);
    $r->admin('POST', 'assinaturas/{id}/cancelar', fn($id) => $this->cancelarAssinatura((int)$id));
    $r->admin('POST', 'assinaturas/{id}/cobrar', fn($id) => $this->cobrarAgora((int)$id));
  }

  // ---------------- Consultas ----------------

  private function buscar(string $codigo): array
  {
    $s = Banco::um('SELECT * FROM servicos WHERE codigo_servico = ?', [strtoupper($codigo)]);
    if (!$s) throw new ErroApi('Serviço não encontrado.', 404);
    return $s;
  }

  private function vitrine(): array
  {
    if (!Modulos::ligado($this->tipo())) return ['servicos' => []];
    $lista = Banco::todos('SELECT * FROM servicos WHERE ativo = 1 ORDER BY categoria, subcategoria, descricao');
    return ['servicos' => array_map(function ($s) {
      [$titulo, $detalhes] = Modulos::tituloEDetalhes($s['descricao']);
      return [
        'codigo' => $s['codigo_servico'],
        'categoria' => $s['categoria'],
        'subcategoria' => $s['subcategoria'],
        'titulo' => $titulo,
        'detalhes' => $detalhes,
        'preco' => (float)$s['preco_venda'],
        'recorrente' => (bool)$s['recorrente'],
        'renovacao' => $s['renovacao'],
        'renovacao_texto' => self::RENOVACOES[$s['renovacao']],
        'foto' => Imagem::url(self::PASTA, $s['foto']),
      ];
    }, $lista)];
  }

  private function completo(array $s): array
  {
    $venda = (float)$s['preco_venda'];
    $custo = (float)$s['preco_custo'];
    return [
      'codigo_servico' => $s['codigo_servico'],
      'categoria' => $s['categoria'],
      'subcategoria' => $s['subcategoria'],
      'descricao' => $s['descricao'],
      'titulo' => Modulos::tituloEDetalhes($s['descricao'])[0],
      'preco_custo' => $custo,
      'preco_venda' => $venda,
      'margem' => $venda > 0 ? round(($venda - $custo) / $venda * 100, 1) : 0,
      'recorrente' => (bool)$s['recorrente'],
      'renovacao' => $s['renovacao'],
      'renovacao_texto' => self::RENOVACOES[$s['renovacao']],
      'foto' => Imagem::url(self::PASTA, $s['foto']),
      'ativo' => (bool)$s['ativo'],
      'criado_em' => $s['criado_em'],
    ];
  }

  private function listar(): array
  {
    $busca = trim((string)($_GET['busca'] ?? ''));
    $sql = 'SELECT * FROM servicos';
    $params = [];
    if ($busca !== '') {
      $sql .= ' WHERE codigo_servico LIKE ? OR descricao LIKE ? OR categoria LIKE ? OR subcategoria LIKE ?';
      $params = array_fill(0, 4, '%' . $busca . '%');
    }
    return [
      'servicos' => array_map(fn($s) => $this->completo($s), Banco::todos($sql . ' ORDER BY categoria, subcategoria, codigo_servico LIMIT 1000', $params)),
      'categorias' => Banco::todos('SELECT DISTINCT categoria, subcategoria FROM servicos ORDER BY categoria, subcategoria'),
    ];
  }

  // ---------------- Cadastro ----------------

  private function validar(array $d, bool $novo): array
  {
    $recorrente = Validacao::booleano($d['recorrente'] ?? false);
    $renovacao = (string)($d['renovacao'] ?? 'unica');
    if (!isset(self::RENOVACOES[$renovacao])) throw new ErroApi('Renovação inválida.', 422, ['campo' => 'renovacao']);
    if (!$recorrente) $renovacao = 'unica';
    if ($recorrente && $renovacao === 'unica') {
      throw new ErroApi('Serviço recorrente precisa de renovação mensal, trimestral, semestral ou anual.', 422, ['campo' => 'renovacao']);
    }
    $v = [
      'categoria' => Validacao::texto($d, 'categoria', 'Categoria', 80),
      'subcategoria' => Validacao::texto($d, 'subcategoria', 'Subcategoria', 80, false),
      'descricao' => Validacao::textoLongo($d, 'descricao', 'Descrição', 5000),
      'preco_custo' => Validacao::dinheiro($d['preco_custo'] ?? '', 'preco_custo', 'Preço de custo', false),
      'preco_venda' => Validacao::dinheiro($d['preco_venda'] ?? '', 'preco_venda', 'Preço de venda'),
      'recorrente' => $recorrente ? 1 : 0,
      'renovacao' => $renovacao,
      'ativo' => Validacao::booleano($d['ativo'] ?? true) ? 1 : 0,
    ];
    if ((float)$v['preco_venda'] <= 0) throw new ErroApi('O preço de venda precisa ser maior que zero.', 422, ['campo' => 'preco_venda']);
    if ($novo) $v = ['codigo_servico' => Validacao::codigo($d['codigo_servico'] ?? '', 'codigo_servico', 'Código do serviço')] + $v;
    return $v;
  }

  private function criar(): array
  {
    $v = $this->validar(Http::entrada(), true);
    try {
      Banco::executar(
        'INSERT INTO servicos (codigo_servico, categoria, subcategoria, descricao, preco_custo, preco_venda, recorrente, renovacao, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        array_values($v)
      );
    } catch (PDOException $e) {
      if (Banco::duplicado($e)) throw new ErroApi("Já existe um serviço com o código {$v['codigo_servico']}.", 409, ['campo' => 'codigo_servico']);
      throw $e;
    }
    return ['servico' => $this->completo($this->buscar($v['codigo_servico']))];
  }

  /** O código não muda depois de criado: ele identifica o serviço nos pedidos e assinaturas. */
  private function alterar(string $codigo): array
  {
    $s = $this->buscar($codigo);
    $v = $this->validar(Http::entrada(), false);
    Banco::executar(
      'UPDATE servicos SET categoria = ?, subcategoria = ?, descricao = ?, preco_custo = ?, preco_venda = ?, recorrente = ?, renovacao = ?, ativo = ? WHERE codigo_servico = ?',
      array_merge(array_values($v), [$s['codigo_servico']])
    );
    return ['servico' => $this->completo($this->buscar($s['codigo_servico']))];
  }

  private function excluir(string $codigo): array
  {
    $s = $this->buscar($codigo);
    $ativas = (int)Banco::valor("SELECT COUNT(*) FROM assinaturas WHERE codigo_servico = ? AND status <> 'cancelada'", [$s['codigo_servico']]);
    if ($ativas) throw new ErroApi("Este serviço tem {$ativas} assinatura(s) ativa(s). Desative o serviço em vez de excluir.", 409);
    Banco::executar('DELETE FROM servicos WHERE codigo_servico = ?', [$s['codigo_servico']]);
    Imagem::apagar(self::PASTA, $s['foto']);
    return ['ok' => true];
  }

  private function enviarFoto(string $codigo): array
  {
    $s = $this->buscar($codigo);
    $img = Imagem::salvar($_FILES['foto'] ?? null, self::PASTA, self::TAMANHO_FOTO);
    Banco::executar('UPDATE servicos SET foto = ? WHERE codigo_servico = ?', [$img['arquivo'], $s['codigo_servico']]);
    Imagem::apagar(self::PASTA, $s['foto']);
    return ['servico' => $this->completo($this->buscar($s['codigo_servico']))];
  }

  private function excluirFoto(string $codigo): array
  {
    $s = $this->buscar($codigo);
    Banco::executar('UPDATE servicos SET foto = NULL WHERE codigo_servico = ?', [$s['codigo_servico']]);
    Imagem::apagar(self::PASTA, $s['foto']);
    return ['servico' => $this->completo($this->buscar($s['codigo_servico']))];
  }

  // ---------------- Vendas ----------------

  public function itemParaVenda(string $codigo, int $quantidade): array
  {
    $s = Banco::um('SELECT * FROM servicos WHERE codigo_servico = ? AND ativo = 1', [$codigo]);
    if (!$s) throw new ErroApi("O serviço {$codigo} não está mais disponível.", 422);
    $titulo = Modulos::tituloEDetalhes($s['descricao'])[0];
    if ($s['recorrente'] && $quantidade > 1) throw new ErroApi("\"{$titulo}\" é uma assinatura: adicione apenas 1 ao carrinho.", 422);
    return [
      'descricao' => $titulo,
      'preco' => $s['preco_venda'],
      'custo' => $s['preco_custo'],
      'entrega' => false,
      'renovacao' => $s['recorrente'] ? $s['renovacao'] : null,
    ];
  }

  /** Serviço recorrente pago: cria a assinatura ou, se for renovação, avança o próximo vencimento. */
  public function aoAprovar(array $item, array $pedido): void
  {
    $meses = self::MESES[$item['renovacao'] ?? ''] ?? 0;
    if (!$meses) return;
    if ($pedido['origem'] === 'renovacao' && $item['referencia']) {
      Banco::executar(
        "UPDATE assinaturas SET proxima_cobranca = DATE_ADD(proxima_cobranca, INTERVAL ? MONTH),
           status = IF(proxima_cobranca < CURDATE(), 'atrasada', 'ativa'), pedido_renovacao = NULL
         WHERE id = ? AND status <> 'cancelada'",
        [$meses, $item['referencia']]
      );
      return;
    }
    Banco::executar(
      'INSERT INTO assinaturas (cliente_cpf, codigo_servico, descricao, renovacao, valor, inicio, proxima_cobranca, pedido_origem)
       VALUES (?, ?, ?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? MONTH), ?)',
      [$pedido['cliente_cpf'], $item['codigo'], $item['descricao'], $item['renovacao'], $item['preco_unitario'], $meses, $pedido['id']]
    );
    Banco::executar('UPDATE pedido_itens SET referencia = ? WHERE id = ?', [Banco::ultimoId(), $item['id']]);
  }

  public function aoReverter(array $item, array $pedido): void
  {
    $meses = self::MESES[$item['renovacao'] ?? ''] ?? 0;
    $ref = (int)Banco::valor('SELECT referencia FROM pedido_itens WHERE id = ?', [$item['id']]);
    if (!$meses || !$ref) return;
    if ($pedido['origem'] === 'renovacao') {
      Banco::executar(
        "UPDATE assinaturas SET proxima_cobranca = DATE_SUB(proxima_cobranca, INTERVAL ? MONTH),
           status = IF(status = 'cancelada', 'cancelada', IF(proxima_cobranca < CURDATE(), 'atrasada', 'ativa'))
         WHERE id = ?",
        [$meses, $ref]
      );
    } else {
      Banco::executar("UPDATE assinaturas SET status = 'cancelada', cancelada_em = NOW() WHERE id = ? AND pedido_origem = ?", [$ref, $pedido['id']]);
    }
  }

  /** Renovação cancelada ou expirada: libera a assinatura para gerar uma nova cobrança. */
  public function aoCancelar(array $item, array $pedido): void
  {
    if ($pedido['origem'] === 'renovacao' && $item['referencia']) {
      Banco::executar('UPDATE assinaturas SET pedido_renovacao = NULL WHERE id = ? AND pedido_renovacao = ?', [$item['referencia'], $pedido['id']]);
    }
  }

  // ---------------- Assinaturas ----------------

  private function assinaturas(): array
  {
    $status = (string)($_GET['status'] ?? '');
    $sql = 'SELECT a.*, c.nome AS cliente_nome, c.email AS cliente_email, p.status AS renovacao_status
            FROM assinaturas a
            JOIN clientes c ON c.cpf = a.cliente_cpf
            LEFT JOIN pedidos p ON p.id = a.pedido_renovacao';
    $params = [];
    if (in_array($status, ['ativa', 'atrasada', 'cancelada'], true)) {
      $sql .= ' WHERE a.status = ?';
      $params[] = $status;
    }
    $lista = Banco::todos($sql . ' ORDER BY FIELD(a.status, \'atrasada\', \'ativa\', \'cancelada\'), a.proxima_cobranca LIMIT 1000', $params);
    return ['assinaturas' => array_map(fn($a) => [
      'id' => (int)$a['id'],
      'cliente_cpf' => $a['cliente_cpf'],
      'cliente_nome' => $a['cliente_nome'],
      'cliente_email' => $a['cliente_email'],
      'codigo_servico' => $a['codigo_servico'],
      'descricao' => $a['descricao'],
      'renovacao' => $a['renovacao'],
      'renovacao_texto' => self::RENOVACOES[$a['renovacao']],
      'valor' => (float)$a['valor'],
      'status' => $a['status'],
      'inicio' => $a['inicio'],
      'proxima_cobranca' => $a['proxima_cobranca'],
      'pedido_origem' => (int)$a['pedido_origem'],
      'pedido_renovacao' => $a['pedido_renovacao'] ? (int)$a['pedido_renovacao'] : null,
      'renovacao_status' => $a['renovacao_status'],
    ], $lista)];
  }

  private function cancelarAssinatura(int $id): array
  {
    $a = Banco::um('SELECT * FROM assinaturas WHERE id = ?', [$id]);
    if (!$a) throw new ErroApi('Assinatura não encontrada.', 404);
    Banco::executar("UPDATE assinaturas SET status = 'cancelada', cancelada_em = NOW() WHERE id = ?", [$id]);
    if ($a['pedido_renovacao']) {
      try {
        Pedidos::cancelar((int)$a['pedido_renovacao'], 'assinatura cancelada');
      } catch (ErroApi $e) {
        // Renovação já paga ou cancelada: nada a fazer.
      }
    }
    return ['ok' => true];
  }

  private function cobrarAgora(int $id): array
  {
    $a = Banco::um('SELECT * FROM assinaturas WHERE id = ?', [$id]);
    if (!$a) throw new ErroApi('Assinatura não encontrada.', 404);
    if ($a['status'] === 'cancelada') throw new ErroApi('Assinatura cancelada.', 422);
    if ($a['pedido_renovacao']) {
      $p = Pedidos::carregar((int)$a['pedido_renovacao']);
      $enviado = Pedidos::enviarLink($p, "Renovação: {$a['descricao']}");
    } else {
      [$p, $enviado] = $this->gerarCobranca((int)$a['id']);
    }
    return ['pedido' => Pedidos::publico($p), 'email_enviado' => $enviado];
  }

  /** Cria o pedido de renovação e envia o link de pagamento ao cliente. */
  private function gerarCobranca(int $id): array
  {
    $p = Banco::transacao(function () use ($id) {
      $a = Banco::um('SELECT * FROM assinaturas WHERE id = ? FOR UPDATE', [$id]);
      if (!$a || $a['pedido_renovacao'] || $a['status'] === 'cancelada') return null;
      $cliente = Clientes::buscar($a['cliente_cpf']);
      $s = Banco::um('SELECT preco_venda, preco_custo FROM servicos WHERE codigo_servico = ?', [$a['codigo_servico']]);
      $preco = $s['preco_venda'] ?? $a['valor'];
      $vencimento = date('d/m/Y', strtotime($a['proxima_cobranca']));
      $p = Pedidos::criar($cliente, [[
        'tipo' => $this->tipo(),
        'codigo' => $a['codigo_servico'],
        'quantidade' => 1,
        'descricao' => "{$a['descricao']} (renovação {$vencimento})",
        'preco' => $preco,
        'custo' => $s['preco_custo'] ?? '0.00',
        'entrega' => false,
        'renovacao' => $a['renovacao'],
        'referencia' => (int)$a['id'],
      ]], 'renovacao');
      Banco::executar('UPDATE assinaturas SET pedido_renovacao = ?, valor = ? WHERE id = ?', [$p['id'], $preco, $id]);
      return $p;
    });
    if (!$p) throw new ErroApi('Esta assinatura já tem uma cobrança em aberto.', 409);
    $a = Banco::um('SELECT descricao FROM assinaturas WHERE id = ?', [$id]);
    return [$p, Pedidos::enviarLink($p, "Renovação: {$a['descricao']}")];
  }

  public function tarefasDiarias(): array
  {
    $log = [];
    $n = Banco::executar("UPDATE assinaturas SET status = 'atrasada' WHERE status = 'ativa' AND proxima_cobranca < CURDATE()");
    if ($n) $log[] = "{$n} assinatura(s) com pagamento atrasado.";
    $dias = max(0, (int)Config::get('dias_antecedencia_renovacao'));
    $vencendo = Banco::todos(
      "SELECT id FROM assinaturas WHERE status IN ('ativa', 'atrasada') AND pedido_renovacao IS NULL
         AND proxima_cobranca <= DATE_ADD(CURDATE(), INTERVAL {$dias} DAY)"
    );
    foreach ($vencendo as ['id' => $id]) {
      try {
        [$p, $enviado] = $this->gerarCobranca((int)$id);
        $log[] = "Assinatura #{$id}: cobrança de renovação gerada (pedido #{$p['id']})" . ($enviado ? ' e enviada por e-mail.' : ', mas o e-mail não pôde ser enviado.');
      } catch (Throwable $e) {
        $log[] = "Assinatura #{$id}: " . $e->getMessage();
      }
    }
    return $log;
  }

  public function resumo(string $de, string $ate): array
  {
    $ativas = Banco::um(
      "SELECT COUNT(*) AS n,
              COALESCE(SUM(valor / CASE renovacao WHEN 'mensal' THEN 1 WHEN 'trimestral' THEN 3 WHEN 'semestral' THEN 6 ELSE 12 END), 0) AS mensal
       FROM assinaturas WHERE status = 'ativa'"
    );
    return [
      'assinaturas_ativas' => (int)$ativas['n'],
      'assinaturas_atrasadas' => (int)Banco::valor("SELECT COUNT(*) FROM assinaturas WHERE status = 'atrasada'"),
      'receita_recorrente_mensal' => round((float)$ativas['mensal'], 2),
      'renovacoes_proximas' => array_map(fn($a) => [
        'id' => (int)$a['id'],
        'cliente' => $a['nome'],
        'descricao' => $a['descricao'],
        'valor' => (float)$a['valor'],
        'proxima_cobranca' => $a['proxima_cobranca'],
        'status' => $a['status'],
      ], Banco::todos(
        "SELECT a.*, c.nome FROM assinaturas a JOIN clientes c ON c.cpf = a.cliente_cpf
         WHERE a.status IN ('ativa', 'atrasada') AND a.proxima_cobranca <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
         ORDER BY a.proxima_cobranca LIMIT 20"
      )),
    ];
  }
};
