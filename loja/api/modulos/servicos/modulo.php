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

    // Página de pagamento: contrata a assinatura no Asaas com o cartão de crédito (renovação automática).
    $r->publica('POST', 'assinaturas/asaas', fn() => $this->assinarNoAsaas());

    $r->admin('GET', 'assinaturas', fn() => $this->assinaturas());
    $r->admin('POST', 'assinaturas/renovacoes', fn() => ['mensagens' => $this->tarefasDiarias()]);
    $r->admin('POST', 'assinaturas/{id}/cancelar', fn($id) => $this->cancelarAssinatura((int)$id));
    $r->admin('PUT', 'assinaturas/{id}/data-final', fn($id) => $this->alterarDataFinal((int)$id));
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
    if (!Modulos::naLoja($this->tipo())) return ['servicos' => []];
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
         WHERE id = ? AND status NOT IN ('cancelada', 'encerrada')",
        [$meses, $item['referencia']]
      );
      return;
    }
    Banco::executar(
      'INSERT INTO assinaturas (cliente_cpf, codigo_servico, descricao, renovacao, valor, inicio, proxima_cobranca, data_final, pedido_origem, mp_assinatura)
       VALUES (?, ?, ?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? MONTH), ?, ?, ?)',
      [$pedido['cliente_cpf'], $item['codigo'], $item['descricao'], $item['renovacao'], $item['preco_unitario'], $meses,
        $pedido['assinatura_data_final'] ?? null, $pedido['id'], $pedido['mp_assinatura'] ?? null]
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
           status = IF(status IN ('cancelada', 'encerrada'), status, IF(proxima_cobranca < CURDATE(), 'atrasada', 'ativa'))
         WHERE id = ?",
        [$meses, $ref]
      );
    } else {
      // Primeiro pagamento estornado: a assinatura acaba, inclusive a cobrança automática no cartão.
      Banco::executar("UPDATE assinaturas SET status = 'cancelada', cancelada_em = NOW() WHERE id = ? AND pedido_origem = ?", [$ref, $pedido['id']]);
      $this->encerrarNoGateway($pedido['mp_assinatura'] ?? null);
    }
  }

  /** Renovação cancelada ou expirada: libera a assinatura para gerar uma nova cobrança. */
  public function aoCancelar(array $item, array $pedido): void
  {
    if ($pedido['origem'] === 'renovacao' && $item['referencia']) {
      Banco::executar('UPDATE assinaturas SET pedido_renovacao = NULL WHERE id = ? AND pedido_renovacao = ?', [$item['referencia'], $pedido['id']]);
      return;
    }
    // Pedido de assinatura cancelado sem pagamento: para a cobrança automática.
    $this->encerrarNoGateway($pedido['mp_assinatura'] ?? null);
  }

  private function encerrarNoGateway(?string $mpAssinatura): void
  {
    if (!$mpAssinatura) return;
    try {
      $this->cancelarNoGateway($mpAssinatura);
    } catch (Throwable $e) {
      error_log("Não foi possível cancelar a assinatura {$mpAssinatura} no gateway: " . $e->getMessage());
    }
  }

  /**
   * Cancela a cobrança automática no Asaas. Assinatura antiga do Mercado Pago: termina só aqui
   * (o administrador cancela também no site do Mercado Pago; o painel avisa).
   */
  private function cancelarNoGateway(string $id): void
  {
    if (!Asaas::ehAssinatura($id)) {
      error_log("Assinatura antiga do Mercado Pago {$id} encerrada só na loja: cancele também no site do Mercado Pago.");
      return;
    }
    try {
      Asaas::cancelarAssinatura($id);
    } catch (ErroApi $e) {
      // Já removida ou desativada no Asaas (ex.: passou da data final): não há mais o que cancelar.
      $s = Asaas::consultarAssinatura($id);
      if (empty($s['deleted']) && ($s['status'] ?? '') === 'ACTIVE') throw $e;
    }
  }

  // ---------------- Assinaturas no cartão (Asaas) ----------------

  /**
   * Cria a assinatura no Asaas com o cartão digitado na página de pagamento. O Asaas valida o cartão
   * e cobra o primeiro período na hora; se o banco recusar, nada é criado e o cliente pode tentar outro
   * cartão. Depois, o Asaas renova sozinho a cada período e cada cobrança chega pelo webhook.
   * Os dados do cartão só passam por aqui a caminho do Asaas: não são gravados nem vão para o log.
   */
  private function assinarNoAsaas(): array
  {
    $d = Http::entrada();
    $p = Pedidos::peloToken($d['pedido_id'] ?? 0, $d['token'] ?? '');
    $item = Pedidos::itemAssinatura($p);
    if (!$item) throw new ErroApi('Este pedido não é de assinatura.', 422);
    if ($p['status'] !== 'aguardando_pagamento') throw new ErroApi('Este pedido não está aguardando pagamento.', 409);
    if (!empty($p['mp_assinatura'])) throw new ErroApi('A assinatura deste pedido já foi criada. Aguarde a confirmação da primeira cobrança.', 409);
    if (!Asaas::configurado()) throw new ErroApi('Pagamentos indisponíveis no momento. Fale com a loja.', 503);
    $ciclo = Asaas::CICLOS[$item['renovacao']] ?? null;
    if (!$ciclo) throw new ErroApi('Período da assinatura inválido.', 422);

    $cartao = Cartao::validar(is_array($d['cartao'] ?? null) ? $d['cartao'] : []);
    Cartao::limitarTentativas((int)$p['id']);

    $c = Clientes::buscar($p['cliente_cpf']);
    $corpo = [
      'customer' => Asaas::cliente($c),
      'billingType' => 'CREDIT_CARD',
      'value' => (float)$item['preco_unitario'],
      'nextDueDate' => date('Y-m-d'), // o primeiro período é cobrado agora
      'cycle' => $ciclo,
      'description' => $this->nomeDaAssinatura($item),
      'externalReference' => (string)$p['id'],
    ] + Cartao::paraAsaas($cartao, $c); // titular: o Asaas compara com os dados do banco emissor
    // Data final definida no painel: o Asaas não gera cobranças a partir dela.
    $fim = Asaas::ultimoVencimento($p['assinatura_data_final'] ?? null);
    if ($fim) $corpo['endDate'] = $fim;

    try {
      $sub = Asaas::criarAssinatura($corpo);
    } catch (ErroApi $e) {
      Banco::executar(
        "UPDATE pedidos SET observacoes = CONCAT_WS('\n', observacoes, ?) WHERE id = ?",
        [date('d/m/Y H:i') . ' - Asaas não criou a assinatura: ' . mb_substr($e->getMessage(), 0, 300), $p['id']]
      );
      throw Cartao::recusa($e);
    } finally {
      unset($corpo, $cartao);
    }
    if (empty($sub['id'])) throw new ErroApi('O Asaas não confirmou a assinatura. Tente de novo.', 502);
    Banco::executar(
      "UPDATE pedidos SET mp_assinatura = ?, observacoes = CONCAT_WS('\n', observacoes, ?) WHERE id = ?",
      [(string)$sub['id'], date('d/m/Y H:i') . ' - Assinatura criada no Asaas: ' . $sub['id'], $p['id']]
    );
    // O primeiro período já foi cobrado: registra agora (o webhook confirma de novo depois).
    $this->sincronizarAssinaturaCartao((string)$sub['id']);
    return ['pedido' => Pedidos::publico(Pedidos::carregar((int)$p['id']))];
  }

  /** Aviso do Asaas sobre uma cobrança de assinatura: registra no pedido certo (primeiro período ou renovação). */
  public function cobrancaAsaas(string $evento, array $cobranca): void
  {
    $sub = (string)($cobranca['subscription'] ?? '');
    if (!Asaas::ehAssinatura($sub) || !Banco::valor('SELECT id FROM pedidos WHERE mp_assinatura = ?', [$sub])) return;
    // Cobrança futura ainda não processada (o Asaas gera antes do vencimento): nada a registrar.
    if (($cobranca['status'] ?? '') === 'PENDING' && $evento !== 'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED') return;
    $this->registrarCobranca($sub, Asaas::comoPagamento($cobranca, $evento));
  }

  /** Assinatura removida ou desativada direto no painel do Asaas: termina aqui também. */
  public function assinaturaAsaasEncerrada(string $id): void
  {
    $n = Banco::executar(
      "UPDATE assinaturas SET status = IF(data_final IS NOT NULL AND data_final <= CURDATE() + INTERVAL 1 DAY, 'encerrada', 'cancelada'),
         cancelada_em = NOW()
       WHERE mp_assinatura = ? AND status NOT IN ('cancelada', 'encerrada')",
      [$id]
    );
    if ($n) error_log("Assinatura {$id} terminou no Asaas.");
  }

  /**
   * Nome da assinatura no Asaas (aparece para o cliente), com no máximo 60 caracteres.
   * Ex.: "Consultoria mensal (Mensal) - Minha Loja"; se não couber, o nome do serviço é encurtado.
   */
  private function nomeDaAssinatura(array $item): string
  {
    $limite = 60;
    $periodo = ' (' . self::RENOVACOES[$item['renovacao']] . ')';
    $loja = ' - ' . Config::get('loja_nome');
    $servico = trim((string)$item['descricao']);
    if (mb_strlen($servico . $periodo . $loja) <= $limite) return $servico . $periodo . $loja;
    if (mb_strlen($servico . $periodo) <= $limite) return $servico . $periodo;
    return rtrim(mb_substr($servico, 0, $limite - mb_strlen($periodo) - 1)) . '…' . $periodo;
  }

  /**
   * Consulta as cobranças da assinatura no Asaas (reserva para avisos do webhook que se perderam).
   * Assinaturas antigas do Mercado Pago não são mais consultadas.
   */
  public function sincronizarAssinaturaCartao(string $pre): void
  {
    if (!Asaas::ehAssinatura($pre)) return;
    try {
      foreach (Asaas::cobrancasDaAssinatura($pre) as $c) {
        if (($c['status'] ?? '') === 'PENDING' || !empty($c['deleted'])) continue; // ainda não processada
        $this->registrarCobranca($pre, Asaas::comoPagamento($c));
      }
    } catch (Throwable $e) {
      error_log("Não foi possível consultar as cobranças da assinatura {$pre}: " . $e->getMessage());
    }
  }

  /**
   * Primeira cobrança: vai para o pedido em que o cliente assinou.
   * Seguintes: cada período ganha um pedido de renovação (já existente em aberto ou criado agora).
   */
  private function registrarCobranca(string $pre, array $mp, string $app = Asaas::APP): void
  {
    $existente = Banco::valor('SELECT pedido_id FROM pagamentos WHERE mp_id = ?', [(string)$mp['id']]);
    if ($existente) {
      Pedidos::registrarPagamento((int)$existente, $mp, $app);
      return;
    }
    $origem = Banco::um('SELECT id, status FROM pedidos WHERE mp_assinatura = ? ORDER BY id LIMIT 1', [$pre]);
    if (!$origem) {
      error_log("Cobrança {$mp['id']} de assinatura desconhecida ({$pre}).");
      return;
    }
    if ($origem['status'] !== 'pago') {
      Pedidos::registrarPagamento((int)$origem['id'], $mp, $app);
      return;
    }
    $a = Banco::um('SELECT id, pedido_renovacao FROM assinaturas WHERE mp_assinatura = ?', [$pre]);
    if (!$a) {
      error_log("Cobrança {$mp['id']}: assinatura {$pre} não encontrada no banco.");
      return;
    }
    $aberto = $a['pedido_renovacao'] ? Banco::um('SELECT id, status FROM pedidos WHERE id = ?', [$a['pedido_renovacao']]) : null;
    $pedidoId = ($aberto && in_array($aberto['status'], ['aguardando_pagamento', 'em_analise'], true))
      ? (int)$aberto['id']
      : (int)$this->criarPedidoRenovacao((int)$a['id'])['id'];
    Pedidos::registrarPagamento($pedidoId, $mp, $app);
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
    if (in_array($status, ['ativa', 'atrasada', 'cancelada', 'encerrada'], true)) {
      $sql .= ' WHERE a.status = ?';
      $params[] = $status;
    }
    $lista = Banco::todos($sql . ' ORDER BY FIELD(a.status, \'atrasada\', \'ativa\', \'encerrada\', \'cancelada\'), a.proxima_cobranca LIMIT 1000', $params);
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
      'cartao_automatico' => !empty($a['mp_assinatura']),
      'data_final' => $a['data_final'],
    ], $lista)];
  }

  private function cancelarAssinatura(int $id): array
  {
    $a = Banco::um('SELECT * FROM assinaturas WHERE id = ?', [$id]);
    if (!$a) throw new ErroApi('Assinatura não encontrada.', 404);
    $this->finalizar($a, 'cancelada', 'assinatura cancelada');
    return ['ok' => true, 'aviso' => $this->avisoAntiga($a, 'Cancele também no site do Mercado Pago para parar as cobranças no cartão do cliente.')];
  }

  /** Assinatura no cartão criada no Mercado Pago, antes da troca para o Asaas: a loja não fala mais com ele. */
  private function avisoAntiga(array $a, string $texto): ?string
  {
    return $a['mp_assinatura'] && !Asaas::ehAssinatura($a['mp_assinatura']) ? "Assinatura antiga do Mercado Pago. {$texto}" : null;
  }

  /**
   * Termina a assinatura: "cancelada" (pelo painel) ou "encerrada" (chegou a data final).
   * No cartão, cancela primeiro no Asaas. Se falhar, nada muda aqui, para o cliente não
   * continuar sendo cobrado com a assinatura marcada como terminada.
   */
  private function finalizar(array $a, string $status, string $motivo): void
  {
    if (in_array($a['status'], ['cancelada', 'encerrada'], true)) return;
    if ($a['mp_assinatura']) $this->cancelarNoGateway($a['mp_assinatura']);
    Banco::executar('UPDATE assinaturas SET status = ?, cancelada_em = NOW() WHERE id = ?', [$status, $a['id']]);
    if ($a['pedido_renovacao']) {
      try {
        Pedidos::cancelar((int)$a['pedido_renovacao'], $motivo);
      } catch (ErroApi $e) {
        // Renovação já paga ou cancelada: nada a fazer.
      }
    }
  }

  /**
   * Define, muda ou tira (vazio) a data final. Nessa data a assinatura é encerrada pela rotina diária;
   * no cartão, a data também é enviada ao Asaas para ele parar de cobrar.
   */
  private function alterarDataFinal(int $id): array
  {
    $a = Banco::um('SELECT * FROM assinaturas WHERE id = ?', [$id]);
    if (!$a) throw new ErroApi('Assinatura não encontrada.', 404);
    if (in_array($a['status'], ['cancelada', 'encerrada'], true)) throw new ErroApi('Esta assinatura já terminou.', 422);
    $data = Pedidos::validarDataFinal(Http::entrada()['data_final'] ?? null);
    Banco::executar('UPDATE assinaturas SET data_final = ? WHERE id = ?', [$data, $id]);

    $aviso = $this->avisoAntiga($a, 'Altere a data também no site do Mercado Pago.');
    if ($a['mp_assinatura'] && !$aviso) {
      try {
        Asaas::alterarAssinatura($a['mp_assinatura'], ['endDate' => Asaas::ultimoVencimento($data)]);
      } catch (Throwable $e) {
        error_log("Não foi possível alterar a data final da assinatura {$a['mp_assinatura']}: " . $e->getMessage());
        $aviso = $data
          ? 'Data salva. O Asaas não aceitou a alteração agora, mas a loja encerra a assinatura nessa data mesmo assim.'
          : 'Data removida aqui, mas o Asaas não aceitou a alteração: ele pode parar de cobrar na data final antiga.';
      }
    }
    return ['ok' => true, 'aviso' => $aviso];
  }

  private function cobrarAgora(int $id): array
  {
    $a = Banco::um('SELECT * FROM assinaturas WHERE id = ?', [$id]);
    if (!$a) throw new ErroApi('Assinatura não encontrada.', 404);
    if (in_array($a['status'], ['cancelada', 'encerrada'], true)) throw new ErroApi('Esta assinatura já terminou.', 422);
    if ($a['mp_assinatura']) throw new ErroApi('Esta assinatura é renovada automaticamente no cartão de crédito.', 422);
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
    $p = $this->criarPedidoRenovacao($id);
    if (!$p) throw new ErroApi('Esta assinatura já tem uma cobrança em aberto.', 409);
    $a = Banco::um('SELECT descricao FROM assinaturas WHERE id = ?', [$id]);
    return [$p, Pedidos::enviarLink($p, "Renovação: {$a['descricao']}")];
  }

  /**
   * Pedido de renovação de um período da assinatura.
   * - Assinatura antiga (link de pagamento): usa o preço atual do serviço; null se já houver cobrança em aberto.
   * - Assinatura no cartão: usa o valor contratado no Asaas e reaproveita a renovação em aberto,
   *   para as novas tentativas de cobrança do mesmo período caírem no mesmo pedido.
   */
  private function criarPedidoRenovacao(int $id): ?array
  {
    return Banco::transacao(function () use ($id) {
      $a = Banco::um('SELECT * FROM assinaturas WHERE id = ? FOR UPDATE', [$id]);
      if (!$a) return null;
      $automatica = !empty($a['mp_assinatura']);
      if ($a['pedido_renovacao']) {
        if (!$automatica) return null;
        $aberto = Pedidos::carregar((int)$a['pedido_renovacao']);
        if ($aberto && in_array($aberto['status'], ['aguardando_pagamento', 'em_analise'], true)) return $aberto;
      }
      // Assinatura no cartão cancelada ainda recebe a cobrança que o Asaas já tinha feito.
      if (in_array($a['status'], ['cancelada', 'encerrada'], true) && !$automatica) return null;
      $cliente = Clientes::buscar($a['cliente_cpf']);
      $s = Banco::um('SELECT preco_venda, preco_custo FROM servicos WHERE codigo_servico = ?', [$a['codigo_servico']]);
      $preco = $automatica ? $a['valor'] : ($s['preco_venda'] ?? $a['valor']);
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
  }

  public function tarefasDiarias(): array
  {
    $log = [];
    // Data final chegou: encerra (no cartão, cancela também no Asaas).
    foreach (Banco::todos("SELECT * FROM assinaturas WHERE status IN ('ativa', 'atrasada') AND data_final IS NOT NULL AND data_final <= CURDATE()") as $a) {
      try {
        $this->finalizar($a, 'encerrada', 'assinatura encerrada na data final');
        $log[] = "Assinatura #{$a['id']}: encerrada na data final (" . date('d/m/Y', strtotime($a['data_final'])) . ').';
      } catch (Throwable $e) {
        $log[] = "Assinatura #{$a['id']}: não foi possível encerrar agora ({$e->getMessage()}). Tenta de novo amanhã.";
      }
    }
    // Cobrança marcada para a data final ou depois dela não acontece: não conta como atraso nem gera renovação.
    $antesDoFim = '(data_final IS NULL OR proxima_cobranca < data_final)';
    // No cartão, o Asaas cobra no dia dele e tenta de novo se falhar: só marca atraso após 3 dias.
    $n = Banco::executar(
      "UPDATE assinaturas SET status = 'atrasada'
       WHERE status = 'ativa' AND {$antesDoFim} AND proxima_cobranca < IF(mp_assinatura IS NULL, CURDATE(), DATE_SUB(CURDATE(), INTERVAL 3 DAY))"
    );
    if ($n) $log[] = "{$n} assinatura(s) com pagamento atrasado.";
    $dias = max(0, (int)Config::get('dias_antecedencia_renovacao'));
    // Links de renovação só para as assinaturas antigas; as do cartão são cobradas pelo Asaas.
    $vencendo = Banco::todos(
      "SELECT id FROM assinaturas WHERE status IN ('ativa', 'atrasada') AND pedido_renovacao IS NULL AND mp_assinatura IS NULL
         AND {$antesDoFim} AND proxima_cobranca <= DATE_ADD(CURDATE(), INTERVAL {$dias} DAY)"
    );
    // Assinaturas no cartão vencidas: confere no Asaas se alguma cobrança chegou sem aviso.
    $cartao = Banco::todos(
      "SELECT DISTINCT mp_assinatura FROM assinaturas WHERE status IN ('ativa', 'atrasada') AND mp_assinatura IS NOT NULL AND proxima_cobranca <= CURDATE()"
    );
    foreach ($cartao as ['mp_assinatura' => $pre]) $this->sincronizarAssinaturaCartao((string)$pre);
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
           AND (a.data_final IS NULL OR a.proxima_cobranca < a.data_final)
         ORDER BY a.proxima_cobranca LIMIT 20"
      )),
    ];
  }
};
