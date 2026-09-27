<?php
/*
 * Módulo de produtos (mercadorias).
 * Tabelas próprias (tabelas.sql), rotas próprias e baixa de estoque quando o pedido é pago.
 * Pode ser desligado no painel sem afetar o módulo de serviços.
 */
return new class implements ModuloCatalogo {
  private const MAX_FOTOS = 3;
  private const TAMANHO_FOTO = 800;
  private const PASTA = 'produtos';

  public function tipo(): string
  {
    return 'produto';
  }

  public function nome(): string
  {
    return 'Produtos';
  }

  public function arquivoSql(): string
  {
    return __DIR__ . '/tabelas.sql';
  }

  public function rotas(Roteador $r): void
  {
    $r->publica('GET', 'produtos/vitrine', fn() => $this->vitrine());
    $r->admin('GET', 'produtos', fn() => $this->listar());
    $r->admin('POST', 'produtos', fn() => $this->criar());
    $r->admin('GET', 'produtos/{codigo}', fn($c) => ['produto' => $this->completo($this->buscar($c))]);
    $r->admin('PUT', 'produtos/{codigo}', fn($c) => $this->alterar($c));
    $r->admin('DELETE', 'produtos/{codigo}', fn($c) => $this->excluir($c));
    $r->admin('POST', 'produtos/{codigo}/fotos', fn($c) => $this->enviarFoto($c));
    $r->admin('DELETE', 'produtos/{codigo}/fotos/{posicao}', fn($c, $pos) => $this->excluirFoto($c, (int)$pos));
  }

  // ---------------- Consultas ----------------

  private function buscar(string $codigo): array
  {
    $p = Banco::um('SELECT * FROM produtos WHERE codigo_produto = ?', [strtoupper($codigo)]);
    if (!$p) throw new ErroApi('Produto não encontrado.', 404);
    return $p;
  }

  private function fotos(array $codigos): array
  {
    if (!$codigos) return [];
    $marcas = implode(',', array_fill(0, count($codigos), '?'));
    $porProduto = [];
    foreach (Banco::todos("SELECT * FROM produto_fotos WHERE codigo_produto IN ({$marcas}) ORDER BY posicao", $codigos) as $f) {
      $porProduto[$f['codigo_produto']][] = [
        'posicao' => (int)$f['posicao'],
        'url' => Imagem::url(self::PASTA, $f['arquivo']),
        'largura' => (int)$f['largura'],
        'altura' => (int)$f['altura'],
      ];
    }
    return $porProduto;
  }

  /** Dados para a loja: sem preço de custo. */
  private function vitrine(): array
  {
    if (!Modulos::ligado($this->tipo())) return ['produtos' => []];
    $lista = Banco::todos('SELECT * FROM produtos WHERE ativo = 1 ORDER BY categoria, subcategoria, descricao');
    $fotos = $this->fotos(array_column($lista, 'codigo_produto'));
    return ['produtos' => array_map(function ($p) use ($fotos) {
      [$titulo, $detalhes] = Modulos::tituloEDetalhes($p['descricao']);
      return [
        'codigo' => $p['codigo_produto'],
        'categoria' => $p['categoria'],
        'subcategoria' => $p['subcategoria'],
        'titulo' => $titulo,
        'detalhes' => $detalhes,
        'preco' => (float)$p['preco_venda'],
        'estoque' => max(0, (int)$p['estoque']),
        'fotos' => array_column($fotos[$p['codigo_produto']] ?? [], 'url'),
      ];
    }, $lista)];
  }

  private function completo(array $p, ?array $fotos = null): array
  {
    $venda = (float)$p['preco_venda'];
    $custo = (float)$p['preco_custo'];
    return [
      'codigo_produto' => $p['codigo_produto'],
      'categoria' => $p['categoria'],
      'subcategoria' => $p['subcategoria'],
      'descricao' => $p['descricao'],
      'titulo' => Modulos::tituloEDetalhes($p['descricao'])[0],
      'estoque' => (int)$p['estoque'],
      'preco_custo' => $custo,
      'preco_venda' => $venda,
      'margem' => $venda > 0 ? round(($venda - $custo) / $venda * 100, 1) : 0,
      'ativo' => (bool)$p['ativo'],
      'fotos' => $fotos ?? $this->fotos([$p['codigo_produto']])[$p['codigo_produto']] ?? [],
      'criado_em' => $p['criado_em'],
    ];
  }

  private function listar(): array
  {
    $busca = trim((string)($_GET['busca'] ?? ''));
    $sql = 'SELECT * FROM produtos';
    $params = [];
    if ($busca !== '') {
      $sql .= ' WHERE codigo_produto LIKE ? OR descricao LIKE ? OR categoria LIKE ? OR subcategoria LIKE ?';
      $params = array_fill(0, 4, '%' . $busca . '%');
    }
    $lista = Banco::todos($sql . ' ORDER BY categoria, subcategoria, codigo_produto LIMIT 1000', $params);
    $fotos = $this->fotos(array_column($lista, 'codigo_produto'));
    return [
      'produtos' => array_map(fn($p) => $this->completo($p, $fotos[$p['codigo_produto']] ?? []), $lista),
      'categorias' => Banco::todos('SELECT DISTINCT categoria, subcategoria FROM produtos ORDER BY categoria, subcategoria'),
    ];
  }

  // ---------------- Cadastro ----------------

  private function validar(array $d, bool $novo): array
  {
    $v = [
      'categoria' => Validacao::texto($d, 'categoria', 'Categoria', 80),
      'subcategoria' => Validacao::texto($d, 'subcategoria', 'Subcategoria', 80, false),
      'descricao' => Validacao::textoLongo($d, 'descricao', 'Descrição', 5000),
      'estoque' => Validacao::inteiro($d['estoque'] ?? 0, 'estoque', 'Estoque', 0, 9999999),
      'preco_custo' => Validacao::dinheiro($d['preco_custo'] ?? '', 'preco_custo', 'Preço de custo', false),
      'preco_venda' => Validacao::dinheiro($d['preco_venda'] ?? '', 'preco_venda', 'Preço de venda'),
      'ativo' => Validacao::booleano($d['ativo'] ?? true) ? 1 : 0,
    ];
    if ((float)$v['preco_venda'] <= 0) throw new ErroApi('O preço de venda precisa ser maior que zero.', 422, ['campo' => 'preco_venda']);
    if ($novo) $v = ['codigo_produto' => Validacao::codigo($d['codigo_produto'] ?? '', 'codigo_produto', 'Código do produto')] + $v;
    return $v;
  }

  private function criar(): array
  {
    $v = $this->validar(Http::entrada(), true);
    try {
      Banco::executar(
        'INSERT INTO produtos (codigo_produto, categoria, subcategoria, descricao, estoque, preco_custo, preco_venda, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        array_values($v)
      );
    } catch (PDOException $e) {
      if (Banco::duplicado($e)) throw new ErroApi("Já existe um produto com o código {$v['codigo_produto']}.", 409, ['campo' => 'codigo_produto']);
      throw $e;
    }
    return ['produto' => $this->completo($this->buscar($v['codigo_produto']))];
  }

  /** O código não muda depois de criado: ele identifica o produto nos pedidos. */
  private function alterar(string $codigo): array
  {
    $p = $this->buscar($codigo);
    $v = $this->validar(Http::entrada(), false);
    Banco::executar(
      'UPDATE produtos SET categoria = ?, subcategoria = ?, descricao = ?, estoque = ?, preco_custo = ?, preco_venda = ?, ativo = ? WHERE codigo_produto = ?',
      array_merge(array_values($v), [$p['codigo_produto']])
    );
    return ['produto' => $this->completo($this->buscar($p['codigo_produto']))];
  }

  private function excluir(string $codigo): array
  {
    $p = $this->buscar($codigo);
    $arquivos = Banco::todos('SELECT arquivo FROM produto_fotos WHERE codigo_produto = ?', [$p['codigo_produto']]);
    Banco::executar('DELETE FROM produtos WHERE codigo_produto = ?', [$p['codigo_produto']]);
    foreach ($arquivos as $f) Imagem::apagar(self::PASTA, $f['arquivo']);
    return ['ok' => true];
  }

  /** Envia a foto numa posição (1 a 3). Sem posição, usa a primeira livre. */
  private function enviarFoto(string $codigo): array
  {
    $p = $this->buscar($codigo);
    $ocupadas = array_column(Banco::todos('SELECT posicao, arquivo FROM produto_fotos WHERE codigo_produto = ?', [$p['codigo_produto']]), 'arquivo', 'posicao');
    $pos = Http::entrada()['posicao'] ?? '';
    if ($pos === '' || $pos === null) {
      $pos = 0;
      for ($i = 1; $i <= self::MAX_FOTOS; $i++) if (!isset($ocupadas[$i])) { $pos = $i; break; }
      if (!$pos) throw new ErroApi('Cada produto pode ter até 3 fotos. Exclua uma para enviar outra.', 422);
    } else {
      $pos = Validacao::inteiro($pos, 'posicao', 'Posição da foto', 1, self::MAX_FOTOS);
    }
    $img = Imagem::salvar($_FILES['foto'] ?? null, self::PASTA, self::TAMANHO_FOTO);
    Banco::executar(
      'INSERT INTO produto_fotos (codigo_produto, posicao, arquivo, largura, altura) VALUES (?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE arquivo = VALUES(arquivo), largura = VALUES(largura), altura = VALUES(altura)',
      [$p['codigo_produto'], $pos, $img['arquivo'], $img['largura'], $img['altura']]
    );
    if (isset($ocupadas[$pos])) Imagem::apagar(self::PASTA, $ocupadas[$pos]);
    return ['produto' => $this->completo($p)];
  }

  private function excluirFoto(string $codigo, int $posicao): array
  {
    $p = $this->buscar($codigo);
    $arquivo = Banco::valor('SELECT arquivo FROM produto_fotos WHERE codigo_produto = ? AND posicao = ?', [$p['codigo_produto'], $posicao]);
    if ($arquivo) {
      Banco::executar('DELETE FROM produto_fotos WHERE codigo_produto = ? AND posicao = ?', [$p['codigo_produto'], $posicao]);
      Imagem::apagar(self::PASTA, $arquivo);
    }
    return ['produto' => $this->completo($p)];
  }

  // ---------------- Vendas ----------------

  public function itemParaVenda(string $codigo, int $quantidade): array
  {
    $p = Banco::um('SELECT * FROM produtos WHERE codigo_produto = ? AND ativo = 1', [$codigo]);
    if (!$p) throw new ErroApi("O produto {$codigo} não está mais disponível.", 422);
    $titulo = Modulos::tituloEDetalhes($p['descricao'])[0];
    $estoque = (int)$p['estoque'];
    if ($estoque < $quantidade) {
      throw new ErroApi($estoque > 0 ? "Temos apenas {$estoque} unidade(s) de \"{$titulo}\"." : "\"{$titulo}\" está esgotado.", 422);
    }
    return ['descricao' => $titulo, 'preco' => $p['preco_venda'], 'custo' => $p['preco_custo'], 'entrega' => true, 'renovacao' => null];
  }

  public function aoAprovar(array $item, array $pedido): void
  {
    Banco::executar('UPDATE produtos SET estoque = estoque - ? WHERE codigo_produto = ?', [(int)$item['quantidade'], $item['codigo']]);
  }

  public function aoReverter(array $item, array $pedido): void
  {
    Banco::executar('UPDATE produtos SET estoque = estoque + ? WHERE codigo_produto = ?', [(int)$item['quantidade'], $item['codigo']]);
  }

  public function aoCancelar(array $item, array $pedido): void
  {
  }

  public function tarefasDiarias(): array
  {
    return [];
  }

  public function resumo(string $de, string $ate): array
  {
    $minimo = (int)Config::get('estoque_minimo');
    $baixo = Banco::todos(
      'SELECT codigo_produto, descricao, estoque FROM produtos WHERE ativo = 1 AND estoque <= ? ORDER BY estoque, codigo_produto LIMIT 30',
      [$minimo]
    );
    $totais = Banco::um('SELECT COUNT(*) AS ativos, COALESCE(SUM(GREATEST(estoque, 0) * preco_custo), 0) AS capital FROM produtos WHERE ativo = 1');
    return [
      'produtos_ativos' => (int)$totais['ativos'],
      'capital_em_estoque' => (float)$totais['capital'],
      'estoque_baixo' => array_map(fn($p) => [
        'codigo' => $p['codigo_produto'],
        'titulo' => Modulos::tituloEDetalhes($p['descricao'])[0],
        'estoque' => (int)$p['estoque'],
      ], $baixo),
    ];
  }
};
