<?php
/**
 * Pedidos e o vínculo com os pagamentos (Asaas).
 * Regras: preços, frete e total são sempre calculados aqui a partir do banco; o status do
 * pedido é derivado dos pagamentos; estoque e assinaturas mudam uma única vez por pedido.
 */
final class Pedidos
{
  public const STATUS = [
    'aguardando_pagamento' => 'Aguardando pagamento',
    'em_analise' => 'Pagamento em análise',
    'pago' => 'Pago',
    'cancelado' => 'Cancelado',
    'estornado' => 'Estornado',
  ];

  // "debito" fica para os pagamentos antigos (Mercado Pago) e os recebimentos manuais (maquininha);
  // o Asaas não recebe débito digitado na loja. "dinheiro" e "transferencia": só recebimentos manuais.
  public const METODOS = [
    'pix' => 'Pix', 'boleto' => 'Boleto', 'credito' => 'Cartão de crédito', 'debito' => 'Cartão de débito',
    'dinheiro' => 'Dinheiro', 'transferencia' => 'Transferência ou depósito', 'outro' => 'Outro',
  ];

  /** Recebimento informado pela loja no painel (pagamentos.app): o dinheiro não passou pelo Asaas. */
  public const APP_MANUAL = 'manual';

  /** Formas do recebimento manual, como aparecem no painel. */
  public const FORMAS_MANUAIS = [
    'dinheiro' => 'Dinheiro',
    'pix' => 'Pix em outra conta',
    'credito' => 'Cartão de crédito (maquininha)',
    'debito' => 'Cartão de débito (maquininha)',
    'transferencia' => 'Transferência ou depósito',
    'outro' => 'Outro',
  ];

  /**
   * Mensagens para o cliente conforme o detalhe gravado no pagamento: os do Asaas
   * e, nos pagamentos antigos, os motivos do Mercado Pago.
   */
  private const MENSAGENS = [
    'confirmed' => 'Pagamento aprovado!',
    'received' => 'Pagamento recebido!',
    'received_in_cash' => 'Pagamento recebido!',
    'awaiting_risk_analysis' => 'O pagamento está em análise de segurança. Você receberá a confirmação por e-mail.',
    'overdue' => 'O prazo para pagar venceu. Escolha uma forma de pagamento para gerar um novo.',
    'refund_requested' => 'Estorno solicitado.',
    'refund_in_progress' => 'Estorno em andamento.',
    'cancelled' => 'Esta cobrança foi cancelada.',
    'manual' => 'Recebido pela loja.',
    'desfeito' => 'Recebimento desfeito (lançado por engano).',
    'accredited' => 'Pagamento aprovado!',
    'pending_contingency' => 'Estamos processando o pagamento. Em até 2 dias úteis você receberá a confirmação por e-mail.',
    'pending_review_manual' => 'O pagamento está em análise. Em até 2 dias úteis você receberá a confirmação por e-mail.',
    'cc_rejected_insufficient_amount' => 'O cartão não tem limite ou saldo suficiente.',
    'cc_rejected_call_for_authorize' => 'Autorize o pagamento com o banco emissor do cartão e tente de novo.',
    'cc_rejected_high_risk' => 'O pagamento foi recusado por segurança. Tente Pix, boleto ou outro cartão.',
    'cc_rejected_other_reason' => 'O banco emissor recusou o pagamento. Use outro cartão ou outra forma de pagamento.',
  ];

  /** Texto para o cliente a partir do status, do detalhe e da forma de pagamento. */
  public static function mensagem(string $status, ?string $detalhe, string $metodo): string
  {
    if ($detalhe !== null && isset(self::MENSAGENS[$detalhe])) return self::MENSAGENS[$detalhe];
    switch ($status) {
      case 'approved': return 'Pagamento aprovado!';
      case 'pending': return $metodo === 'boleto' ? 'Boleto gerado. Pague até o vencimento.' : 'Aguardando o pagamento.';
      case 'in_process':
      case 'authorized': return 'O pagamento está em análise. Você receberá a confirmação por e-mail.';
      case 'rejected': return 'O pagamento foi recusado. Tente outro cartão ou outra forma de pagamento.';
      case 'cancelled': return 'O pagamento foi cancelado ou expirou.';
      case 'refunded': return 'O pagamento foi estornado.';
      case 'charged_back': return 'O pagamento foi contestado junto ao cartão (chargeback).';
      default: return 'Situação do pagamento: ' . $status;
    }
  }

  public static function centavos($valor): int
  {
    return (int)round((float)$valor * 100);
  }

  public static function reais(int $centavos): string
  {
    return number_format($centavos / 100, 2, '.', '');
  }

  public static function frete(int $subtotalCentavos): int
  {
    $gratisAcima = self::centavos(Config::get('frete_gratis_acima'));
    if ($gratisAcima > 0 && $subtotalCentavos >= $gratisAcima) return 0;
    return self::centavos(Config::get('frete_valor'));
  }

  /**
   * Converte o carrinho [{tipo, codigo, quantidade}] em itens com preço e custo do banco.
   * $daLoja: pedido feito pela loja (e não pelo painel), que só vende os tipos com a aba visível.
   */
  public static function itensDoCarrinho($carrinho, bool $daLoja = false): array
  {
    if (!is_array($carrinho) || !$carrinho) throw new ErroApi('Seu carrinho está vazio.', 422);
    if (count($carrinho) > 50) throw new ErroApi('Carrinho com itens demais (máximo 50).', 422);
    $agrupados = [];
    foreach ($carrinho as $i) {
      if (!is_array($i)) throw new ErroApi('Item do carrinho inválido.', 422);
      $tipo = (string)($i['tipo'] ?? '');
      $codigo = Validacao::codigo($i['codigo'] ?? '', 'codigo', 'Código do item');
      $qtd = Validacao::inteiro($i['quantidade'] ?? 1, 'quantidade', 'Quantidade', 1, 999);
      $chave = $tipo . '|' . $codigo;
      $agrupados[$chave] = ['tipo' => $tipo, 'codigo' => $codigo, 'quantidade' => ($agrupados[$chave]['quantidade'] ?? 0) + $qtd];
    }
    $itens = [];
    foreach ($agrupados as $a) {
      if ($daLoja && Modulos::ligado($a['tipo']) && !Modulos::naLoja($a['tipo'])) {
        throw new ErroApi('Um item do carrinho não está mais disponível na loja. Atualize a página e confira o carrinho.', 422);
      }
      $venda = Modulos::paraVenda($a['tipo'])->itemParaVenda($a['codigo'], $a['quantidade']);
      $itens[] = $a + $venda + ['referencia' => null];
    }
    return $itens;
  }

  /**
   * Produtos e serviços vão em pedidos separados. Uma assinatura (serviço recorrente) vai sozinha
   * no pedido, porque vira uma cobrança automática no cartão com valor e período próprios.
   */
  public static function validarComposicao(array $itens, string $origem): void
  {
    $tipos = array_unique(array_column($itens, 'tipo'));
    if (in_array('servico', $tipos, true) && count($tipos) > 1) {
      throw new ErroApi('Produtos e serviços são pagos em pedidos separados. Finalize um pedido e depois faça o outro.', 422);
    }
    if ($origem === 'renovacao') return;
    $assinaturas = array_filter($itens, fn($i) => !empty($i['renovacao']));
    if ($assinaturas && count($itens) > 1) {
      throw new ErroApi('Assinaturas são contratadas uma por pedido. Finalize a assinatura e depois compre os outros itens.', 422);
    }
  }

  /** Item de assinatura do pedido (serviço recorrente contratado agora, pago no cartão automático). */
  public static function itemAssinatura(array $p): ?array
  {
    if (($p['origem'] ?? '') === 'renovacao') return null;
    foreach ($p['itens'] ?? [] as $i) if (!empty($i['renovacao'])) return $i;
    return null;
  }

  /**
   * Data final (opcional) de uma assinatura: vazia = sem fim; senão, uma data depois de hoje.
   * Devolve null (sem data) ou a data em Y-m-d.
   */
  public static function validarDataFinal($v, string $campo = 'data_final'): ?string
  {
    if ($v === null || trim((string)$v) === '') return null;
    $data = Validacao::data($v, $campo, 'Data final da assinatura');
    if ($data <= date('Y-m-d')) throw new ErroApi('A data final da assinatura precisa ser depois de hoje.', 422, ['campo' => $campo]);
    return $data;
  }

  /**
   * Renovação de assinatura no cartão: quem cobra é o Asaas, automaticamente. Esse pedido
   * não recebe link de pagamento nem pode ser pago pelo link (o cliente pagaria duas vezes).
   */
  public static function cobrancaAutomatica(array $p): bool
  {
    if (($p['origem'] ?? '') !== 'renovacao') return false;
    foreach ($p['itens'] ?? [] as $i) {
      if ($i['tipo'] === 'servico' && $i['referencia'] && Banco::valor('SELECT mp_assinatura FROM assinaturas WHERE id = ?', [$i['referencia']])) return true;
    }
    return false;
  }

  /** Grava o pedido com o endereço do cliente copiado e devolve o pedido completo. */
  public static function criar(array $cliente, array $itens, string $origem): array
  {
    self::validarComposicao($itens, $origem);
    $subtotal = $custo = 0;
    $entrega = false;
    foreach ($itens as $i) {
      $subtotal += self::centavos($i['preco']) * $i['quantidade'];
      $custo += self::centavos($i['custo']) * $i['quantidade'];
      $entrega = $entrega || !empty($i['entrega']);
    }
    $frete = $entrega ? self::frete($subtotal) : 0;

    return Banco::transacao(function () use ($cliente, $itens, $origem, $subtotal, $custo, $frete, $entrega) {
      Banco::executar(
        'INSERT INTO pedidos (token, cliente_cpf, origem, subtotal, frete, total, custo_total, precisa_entrega,
           cep, rua, numero, complemento, bairro, cidade, estado)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
          bin2hex(random_bytes(16)), $cliente['cpf'], $origem,
          self::reais($subtotal), self::reais($frete), self::reais($subtotal + $frete), self::reais($custo), $entrega ? 1 : 0,
          $cliente['cep'], $cliente['rua'], $cliente['numero'], $cliente['complemento'] ?: null, $cliente['bairro'], $cliente['cidade'], $cliente['estado'],
        ]
      );
      $id = Banco::ultimoId();
      $st = Banco::preparar(
        'INSERT INTO pedido_itens (pedido_id, tipo, codigo, descricao, quantidade, preco_unitario, custo_unitario, renovacao, referencia)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
      );
      foreach ($itens as $i) {
        $st->execute([$id, $i['tipo'], $i['codigo'], mb_substr($i['descricao'], 0, 255), $i['quantidade'], $i['preco'], $i['custo'], $i['renovacao'] ?? null, $i['referencia'] ?? null]);
      }
      return self::carregar($id);
    });
  }

  public static function carregar(int $id, bool $bloquear = false): ?array
  {
    $p = Banco::um('SELECT * FROM pedidos WHERE id = ?' . ($bloquear ? ' FOR UPDATE' : ''), [$id]);
    if (!$p) return null;
    $p['itens'] = Banco::todos('SELECT * FROM pedido_itens WHERE pedido_id = ? ORDER BY id', [$id]);
    $p['pagamentos'] = Banco::todos('SELECT * FROM pagamentos WHERE pedido_id = ? ORDER BY id', [$id]);
    return $p;
  }

  /** Pedido pelo link público (número + token secreto). */
  public static function peloToken($id, $token): array
  {
    $p = self::carregar((int)$id);
    if (!$p || !is_string($token) || !hash_equals($p['token'], $token)) throw new ErroApi('Pedido não encontrado.', 404);
    return $p;
  }

  public static function link(array $p): string
  {
    return Http::urlLoja() . 'pagar.html?p=' . $p['id'] . '&t=' . $p['token'];
  }

  /** O que o cliente pode ver do pedido (página de pagamento). */
  public static function publico(array $p): array
  {
    $c = Clientes::buscar($p['cliente_cpf']);
    $ultimo = $p['pagamentos'] ? $p['pagamentos'][count($p['pagamentos']) - 1] : null;
    return [
      'id' => (int)$p['id'],
      'token' => $p['token'],
      'status' => $p['status'],
      'status_texto' => self::STATUS[$p['status']],
      'criado_em' => $p['criado_em'],
      'subtotal' => (float)$p['subtotal'],
      'frete' => (float)$p['frete'],
      'total' => (float)$p['total'],
      'precisa_entrega' => (bool)$p['precisa_entrega'],
      'itens' => array_map(fn($i) => [
        'tipo' => $i['tipo'],
        'codigo' => $i['codigo'],
        'descricao' => $i['descricao'],
        'quantidade' => (int)$i['quantidade'],
        'preco_unitario' => (float)$i['preco_unitario'],
        'renovacao' => $i['renovacao'],
      ], $p['itens']),
      'cliente' => !empty($p['dados_protegidos']) ? Clientes::mascarado($c ?? [], $p['cliente_cpf']) : [
        'nome' => $c['nome'] ?? '',
        'email' => $c['email'] ?? '',
        'cpf' => $p['cliente_cpf'],
        'celular' => $c['celular'] ?? '',
        'cep' => $p['cep'],
        'rua' => $p['rua'],
        'numero' => $p['numero'],
        'complemento' => $p['complemento'],
        'bairro' => $p['bairro'],
        'cidade' => $p['cidade'],
        'estado' => $p['estado'],
      ],
      'pagamento' => $ultimo ? self::resumoPagamento($ultimo) : null,
      'link' => self::link($p),
      // Recebimentos já registrados (ex.: parte em dinheiro informada pela loja) e o que falta pagar online.
      'recebido' => self::recebidoCentavos($p) / 100,
      'a_pagar' => self::restanteCentavos($p) / 100,
      'pagamentos_ativos' => Asaas::configurado(),
      // Parcelas sem juros que o cliente pode escolher no cartão para o valor a pagar.
      'max_parcelas' => Asaas::parcelasPossiveis(self::restanteCentavos($p) / 100),
      'cobranca_automatica' => self::cobrancaAutomatica($p),
      // Serviço recorrente: pago só no cartão de crédito, com renovação automática a cada período.
      'assinatura' => ($a = self::itemAssinatura($p)) ? [
        'renovacao' => $a['renovacao'],
        'valor' => (float)$a['preco_unitario'],
        'criada' => !empty($p['mp_assinatura']),
        'data_final' => $p['assinatura_data_final'] ?? null,
      ] : null,
    ];
  }

  public static function resumoPagamento(array $pg): array
  {
    return [
      'id' => (int)$pg['id'],
      'metodo' => $pg['metodo'],
      'metodo_texto' => self::METODOS[$pg['metodo']] ?? $pg['metodo'],
      'status' => $pg['status'],
      'mensagem' => self::mensagem($pg['status'], $pg['status_detalhe'], $pg['metodo']),
      'valor' => (float)$pg['valor'],
      'parcelas' => (int)$pg['parcelas'],
      'pix_copia_cola' => $pg['status'] === 'pending' ? $pg['pix_copia_cola'] : null,
      'pix_qr_base64' => $pg['status'] === 'pending' ? $pg['pix_qr_base64'] : null,
      'link_pagamento' => $pg['status'] === 'pending' ? $pg['link_pagamento'] : null,
      'codigo_barras' => $pg['status'] === 'pending' ? $pg['codigo_barras'] : null,
      'expira_em' => $pg['expira_em'],
    ];
  }

  /**
   * Grava (ou atualiza) um pagamento no formato de Asaas::comoPagamento() e recalcula o pedido.
   * (A coluna mp_id guarda o id do Asaas; o nome ficou dos pagamentos antigos do Mercado Pago.)
   * Ao atualizar, campos que vierem vazios não apagam os já gravados (QR code do Pix, linha digitável...),
   * o valor estornado nunca diminui e a data de aprovação é a primeira registrada.
   */
  public static function registrarPagamento(int $pedidoId, array $pg, string $app = Asaas::APP): array
  {
    $dinheiro = fn($v) => $v === null ? null : number_format((float)$v, 2, '.', '');
    $dados = [
      'pedido_id' => $pedidoId,
      'mp_id' => (string)$pg['id'],
      'app' => $app,
      'metodo' => isset(self::METODOS[$pg['metodo'] ?? '']) ? $pg['metodo'] : 'outro',
      'mp_metodo' => $pg['bandeira'] ?? null,
      'status' => (string)($pg['status'] ?? 'pending'),
      'status_detalhe' => $pg['status_detalhe'] ?? null,
      'valor' => $dinheiro($pg['valor'] ?? 0),
      'valor_liquido' => $dinheiro($pg['valor_liquido'] ?? null),
      'valor_estornado' => $dinheiro($pg['valor_estornado'] ?? 0),
      'parcelas' => max(1, (int)($pg['parcelas'] ?? 1)),
      'pix_copia_cola' => $pg['pix_copia_cola'] ?? null,
      'pix_qr_base64' => $pg['pix_qr_base64'] ?? null,
      'link_pagamento' => $pg['link_pagamento'] ?? null,
      'codigo_barras' => $pg['codigo_barras'] ?? null,
      'expira_em' => $pg['expira_em'] ?? null,
      'aprovado_em' => $pg['aprovado_em'] ?? null,
    ];
    $manter = ['valor_liquido', 'pix_copia_cola', 'pix_qr_base64', 'link_pagamento', 'codigo_barras', 'expira_em'];
    $atualizar = [];
    foreach (array_diff(array_keys($dados), ['pedido_id', 'mp_id', 'app']) as $c) {
      if (in_array($c, $manter, true)) $atualizar[] = "{$c} = COALESCE(VALUES({$c}), {$c})";
      elseif ($c === 'valor_estornado') $atualizar[] = 'valor_estornado = GREATEST(VALUES(valor_estornado), valor_estornado)';
      elseif ($c === 'aprovado_em') $atualizar[] = 'aprovado_em = COALESCE(aprovado_em, VALUES(aprovado_em))';
      else $atualizar[] = "{$c} = VALUES({$c})";
    }
    $colunas = array_keys($dados);
    Banco::executar(
      'INSERT INTO pagamentos (' . implode(', ', $colunas) . ') VALUES (' . implode(', ', array_fill(0, count($colunas), '?')) . ')
       ON DUPLICATE KEY UPDATE ' . implode(', ', $atualizar),
      array_values($dados)
    );
    self::sincronizar($pedidoId);
    return Banco::um('SELECT * FROM pagamentos WHERE mp_id = ?', [(string)$pg['id']]);
  }

  /**
   * Invalida um Pix ou boleto ainda não pago (troca de forma de pagamento ou pedido cancelado).
   * Pagamentos antigos (Mercado Pago), cartões e cobranças de assinatura ficam como estão.
   */
  public static function cancelarCobranca(array $pg): void
  {
    if ($pg['app'] !== Asaas::APP || !in_array($pg['status'], ['pending', 'in_process'], true)) return;
    if (!in_array($pg['metodo'], ['pix', 'boleto'], true) || Asaas::ehParcelamento($pg['mp_id'])) return;
    try {
      Asaas::remover($pg['mp_id']);
    } catch (Throwable $e) {
      // Já paga nesse meio-tempo? A consulta mostra; se não der para consultar, fica como está.
      error_log("Não foi possível remover a cobrança {$pg['mp_id']} no Asaas: " . $e->getMessage());
      try {
        self::registrarPagamento((int)$pg['pedido_id'], Asaas::pagamento($pg['mp_id']));
      } catch (Throwable $e2) {
        // sem resposta do Asaas agora
      }
      return;
    }
    Banco::executar("UPDATE pagamentos SET status = 'cancelled', status_detalhe = 'cancelled' WHERE id = ?", [$pg['id']]);
    self::sincronizar((int)$pg['pedido_id']);
  }

  /**
   * Recalcula o status do pedido a partir dos pagamentos e aplica (ou desfaz) os efeitos
   * dos módulos uma única vez: baixa de estoque, assinaturas etc.
   */
  public static function sincronizar(int $id): void
  {
    $aprovadoAgora = Banco::transacao(function () use ($id) {
      $p = self::carregar($id, true);
      if (!$p) return null;
      $status = array_column($p['pagamentos'], 'status');
      // Pago quando os pagamentos aprovados somam o total (um só, ou vários recebimentos parciais).
      // A forma do pedido é a do maior deles; a data, a do último.
      $aprovado = null;
      $quando = null;
      if (self::recebidoCentavos($p) >= self::centavos($p['total'])) {
        foreach ($p['pagamentos'] as $pg) {
          if ($pg['status'] !== 'approved') continue;
          if (!$aprovado || self::centavos($pg['valor']) > self::centavos($aprovado['valor'])) $aprovado = $pg;
          if ($pg['aprovado_em'] && (!$quando || $pg['aprovado_em'] > $quando)) $quando = $pg['aprovado_em'];
        }
      }

      if ($aprovado) $novo = 'pago';
      elseif (array_intersect(['refunded', 'charged_back'], $status)) $novo = 'estornado';
      elseif ($p['status'] === 'cancelado') $novo = 'cancelado';
      elseif (array_intersect(['in_process', 'authorized'], $status)) $novo = 'em_analise';
      else $novo = 'aguardando_pagamento';

      $aplicar = $novo === 'pago' && !$p['efeitos_aplicados'];
      $reverter = $novo !== 'pago' && $p['efeitos_aplicados'];
      $ultimo = $p['pagamentos'] ? $p['pagamentos'][count($p['pagamentos']) - 1] : null;
      $forma = $aprovado['metodo'] ?? $ultimo['metodo'] ?? null;

      Banco::executar(
        'UPDATE pedidos SET status = ?, forma_pagamento = ?, efeitos_aplicados = ?,
           pago_em = CASE WHEN ? = \'pago\' THEN COALESCE(pago_em, ?, NOW()) ELSE pago_em END
         WHERE id = ?',
        [$novo, $forma, ($aplicar || ($p['efeitos_aplicados'] && !$reverter)) ? 1 : 0, $novo, $quando, $id]
      );
      if ($aplicar || $reverter) {
        foreach ($p['itens'] as $item) {
          $modulo = Modulos::doItem($item['tipo']);
          if (!$modulo) continue;
          if ($aplicar) $modulo->aoAprovar($item, $p);
          else $modulo->aoReverter($item, $p);
        }
      }
      return $aplicar;
    });
    if ($aprovadoAgora) self::avisarPagamentoAprovado($id);
  }

  /** Quanto já foi recebido (soma dos pagamentos aprovados), em centavos. */
  public static function recebidoCentavos(array $p): int
  {
    $soma = 0;
    foreach ($p['pagamentos'] ?? [] as $pg) if ($pg['status'] === 'approved') $soma += self::centavos($pg['valor']);
    return $soma;
  }

  /** Quanto falta pagar (total menos o já recebido), em centavos. */
  public static function restanteCentavos(array $p): int
  {
    return max(0, self::centavos($p['total']) - self::recebidoCentavos($p));
  }

  /**
   * Recebimento feito fora da loja (dinheiro, maquininha de outra empresa, Pix em outra conta...), informado
   * no painel. Pode ser parcial: o pedido fica pago quando a soma dos recebimentos cobre o total, e o que faltar
   * pode ser pago pelo link (já com o valor restante). Pix e boleto em aberto no Asaas são cancelados, para o
   * cliente não pagar de novo o valor cheio.
   */
  public static function registrarRecebimento(int $id, array $d, array $admin): array
  {
    // O pedido fica travado enquanto confere o que falta pagar (dois cliques não registram em dobro).
    $p = Banco::transacao(fn() => self::lancarRecebimento($id, $d, $admin));
    foreach ($p['pagamentos'] as $pg) self::cancelarCobranca($pg);
    self::sincronizar($id);
    return self::carregar($id);
  }

  private static function lancarRecebimento(int $id, array $d, array $admin): array
  {
    $p = self::carregar($id, true);
    if (!$p) throw new ErroApi('Pedido não encontrado.', 404);
    if ($p['status'] === 'em_analise') throw new ErroApi('Há um pagamento em análise no Asaas para este pedido: aguarde a resposta antes de registrar um recebimento.', 422);
    if ($p['status'] !== 'aguardando_pagamento') throw new ErroApi('Só é possível registrar recebimento em pedidos aguardando pagamento.', 422);
    if (self::cobrancaAutomatica($p) || !empty($p['mp_assinatura'])) {
      throw new ErroApi('Este pedido é cobrado automaticamente no cartão pelo Asaas (assinatura). Não registre recebimento manual: o cliente pagaria duas vezes.', 422);
    }
    $forma = (string)($d['forma'] ?? '');
    if (!isset(self::FORMAS_MANUAIS[$forma])) throw new ErroApi('Escolha a forma do recebimento.', 422, ['campo' => 'forma']);
    $restante = self::restanteCentavos($p);
    $valor = self::centavos(Validacao::dinheiro($d['valor'] ?? '', 'valor', 'Valor recebido'));
    if ($valor <= 0 || $valor > $restante) {
      throw new ErroApi('O valor recebido deve ser maior que zero e no máximo ' . self::brl($restante / 100) . ' (o que falta pagar).', 422, ['campo' => 'valor']);
    }
    // Contratação de assinatura: o período é pago de uma vez (a renovação depende do pagamento inteiro).
    if (self::itemAssinatura($p) && $valor !== $restante) {
      throw new ErroApi('Na contratação de assinatura, registre o valor total do período (' . self::brl($restante / 100) . ').', 422, ['campo' => 'valor']);
    }
    $taxa = self::centavos(Validacao::dinheiro($d['taxa'] ?? '', 'taxa', 'Taxas descontadas', false));
    if ($taxa >= $valor) throw new ErroApi('As taxas descontadas devem ser menores que o valor recebido.', 422, ['campo' => 'taxa']);
    $data = Validacao::data($d['data'] ?? '', 'data', 'Data do recebimento');
    if ($data > date('Y-m-d')) throw new ErroApi('A data do recebimento não pode ser no futuro.', 422, ['campo' => 'data']);
    $obs = Validacao::texto($d, 'observacao', 'Observação', 200, false);

    Banco::executar(
      "INSERT INTO pagamentos (pedido_id, mp_id, app, metodo, status, status_detalhe, valor, valor_liquido, valor_estornado, parcelas,
         aprovado_em, observacao, registrado_por)
       VALUES (?, ?, ?, ?, 'approved', 'manual', ?, ?, 0, 1, ?, ?, ?)",
      [
        $id, 'man_' . bin2hex(random_bytes(10)), self::APP_MANUAL, $forma,
        self::reais($valor), $taxa > 0 ? self::reais($valor - $taxa) : null,
        $data === date('Y-m-d') ? date('Y-m-d H:i:s') : $data . ' 12:00:00',
        $obs !== '' ? $obs : null, mb_substr((string)($admin['nome'] ?? ''), 0, 100),
      ]
    );
    Banco::executar(
      "UPDATE pedidos SET observacoes = CONCAT_WS('\n', observacoes, ?) WHERE id = ?",
      [date('d/m/Y H:i') . ' - Recebimento manual registrado por ' . ($admin['nome'] ?? '') . ': ' . self::FORMAS_MANUAIS[$forma] . ', '
        . self::brl($valor / 100) . ' em ' . date('d/m/Y', strtotime($data)) . ($obs !== '' ? " ({$obs})" : ''), $id]
    );
    return $p;
  }

  /**
   * Desfaz um recebimento manual lançado por engano: deixa de contar (fica no histórico como cancelado)
   * e, se o pedido deixar de estar pago, o estoque e a assinatura voltam ao que eram.
   */
  public static function desfazerRecebimento(array $pg, array $admin): array
  {
    if ($pg['app'] !== self::APP_MANUAL) throw new ErroApi('Só recebimentos manuais podem ser desfeitos. Para pagamentos do Asaas, use Estornar.', 422);
    if ($pg['status'] !== 'approved' || (float)$pg['valor_estornado'] > 0) throw new ErroApi('Este recebimento não pode mais ser desfeito (já foi estornado ou desfeito).', 422);
    Banco::executar("UPDATE pagamentos SET status = 'cancelled', status_detalhe = 'desfeito' WHERE id = ?", [$pg['id']]);
    Banco::executar(
      "UPDATE pedidos SET observacoes = CONCAT_WS('\n', observacoes, ?) WHERE id = ?",
      [date('d/m/Y H:i') . ' - Recebimento manual de ' . self::brl((float)$pg['valor']) . ' desfeito por ' . ($admin['nome'] ?? ''), $pg['pedido_id']]
    );
    self::sincronizar((int)$pg['pedido_id']);
    return self::carregar((int)$pg['pedido_id']);
  }

  /**
   * Estorno de um recebimento manual: a loja devolveu o dinheiro por fora (total ou parte).
   * $valor null = todo o valor restante.
   */
  public static function estornarRecebimento(array $pg, ?string $valor, array $admin): void
  {
    $disponivel = self::centavos($pg['valor']) - self::centavos($pg['valor_estornado']);
    $estorno = $valor === null ? $disponivel : self::centavos($valor);
    $total = self::centavos($pg['valor_estornado']) + $estorno;
    $integral = $total >= self::centavos($pg['valor']);
    Banco::executar(
      'UPDATE pagamentos SET valor_estornado = ?, status = ?, status_detalhe = ? WHERE id = ?',
      [self::reais($total), $integral ? 'refunded' : 'approved', $integral ? 'refunded' : 'manual', $pg['id']]
    );
    Banco::executar(
      "UPDATE pedidos SET observacoes = CONCAT_WS('\n', observacoes, ?) WHERE id = ?",
      [date('d/m/Y H:i') . ' - Estorno de ' . self::brl($estorno / 100) . ' do recebimento manual registrado por ' . ($admin['nome'] ?? ''), $pg['pedido_id']]
    );
    self::sincronizar((int)$pg['pedido_id']);
  }

  /** Cancela um pedido ainda não pago (pelo painel ou por expiração). */
  public static function cancelar(int $id, string $motivo): array
  {
    $p = Banco::transacao(function () use ($id, $motivo) {
      $p = self::carregar($id, true);
      if (!$p) throw new ErroApi('Pedido não encontrado.', 404);
      if (!in_array($p['status'], ['aguardando_pagamento', 'em_analise'], true)) {
        throw new ErroApi('Só é possível cancelar pedidos que ainda não foram pagos.', 422);
      }
      if (self::recebidoCentavos($p) > 0) {
        throw new ErroApi('Este pedido já tem recebimento registrado. Desfaça ou estorne o recebimento antes de cancelar.', 422);
      }
      Banco::executar(
        "UPDATE pedidos SET status = 'cancelado', cancelado_em = NOW(), observacoes = CONCAT_WS('\n', observacoes, ?) WHERE id = ?",
        [date('d/m/Y H:i') . ' - Cancelado: ' . $motivo, $id]
      );
      foreach ($p['itens'] as $item) {
        $modulo = Modulos::doItem($item['tipo']);
        if ($modulo) $modulo->aoCancelar($item, $p);
      }
      return $p;
    });
    // Invalida Pix e boletos em aberto para não serem pagos depois do cancelamento.
    // (Cobranças de assinatura são encerradas pelo cancelamento da assinatura.)
    if (empty($p['mp_assinatura'])) {
      foreach ($p['pagamentos'] as $pg) self::cancelarCobranca($pg);
    }
    return self::carregar($id);
  }

  /** Consulta de novo no Asaas (caso algum aviso do webhook tenha se perdido). Pagamentos antigos ficam como estão. */
  public static function atualizarPagamentosPendentes(array $p): void
  {
    if (!Asaas::configurado()) return;
    foreach ($p['pagamentos'] as $pg) {
      if (!in_array($pg['status'], ['pending', 'in_process', 'authorized'], true) || Asaas::antigo($pg)) continue;
      self::registrarPagamento((int)$p['id'], Asaas::pagamento($pg['mp_id']));
    }
    // Assinatura no cartão ainda sem a primeira cobrança registrada: pergunta ao gateway.
    $servicos = Modulos::doItem('servico');
    if (!empty($p['mp_assinatura']) && $p['status'] !== 'pago' && $servicos && method_exists($servicos, 'sincronizarAssinaturaCartao')) {
      $servicos->sincronizarAssinaturaCartao((string)$p['mp_assinatura']);
    }
  }

  /** Cancela pedidos sem pagamento há mais dias que o configurado (mantém boletos ainda no prazo). */
  public static function expirarAntigos(): array
  {
    $dias = max(1, (int)Config::get('dias_expiracao_pedido'));
    $log = [];
    // Pedidos com parte já recebida (recebimento manual parcial) não são cancelados sozinhos.
    $ids = Banco::todos(
      "SELECT p.id FROM pedidos p WHERE p.status = 'aguardando_pagamento' AND p.criado_em < DATE_SUB(NOW(), INTERVAL {$dias} DAY)
         AND NOT EXISTS (SELECT 1 FROM pagamentos pg WHERE pg.pedido_id = p.id AND pg.status = 'approved')"
    );
    foreach ($ids as ['id' => $id]) {
      try {
        $p = self::carregar((int)$id);
        self::atualizarPagamentosPendentes($p);
        $p = self::carregar((int)$id);
        if ($p['status'] !== 'aguardando_pagamento') continue;
        $boletoNoPrazo = Banco::valor(
          "SELECT COUNT(*) FROM pagamentos WHERE pedido_id = ? AND metodo = 'boleto' AND status = 'pending' AND expira_em > NOW()",
          [$id]
        );
        if ($boletoNoPrazo) continue;
        self::cancelar((int)$id, "sem pagamento há mais de {$dias} dias");
        $log[] = "Pedido #{$id} cancelado por falta de pagamento.";
      } catch (Throwable $e) {
        $log[] = "Pedido #{$id}: " . $e->getMessage();
      }
    }
    return $log;
  }

  public static function tabelaItensHtml(array $p): string
  {
    $linhas = '';
    foreach ($p['itens'] as $i) {
      $linhas .= '<tr><td style="padding:6px 0">' . (int)$i['quantidade'] . '× ' . htmlspecialchars($i['descricao'])
        . '</td><td align="right">' . self::brl((float)$i['preco_unitario'] * (int)$i['quantidade']) . '</td></tr>';
    }
    if ((float)$p['frete'] > 0) $linhas .= '<tr><td style="padding:6px 0">Frete</td><td align="right">' . self::brl((float)$p['frete']) . '</td></tr>';
    $borda = Config::cores()['secundaria'];
    $linhas .= '<tr><td style="padding:10px 0;border-top:1px solid ' . $borda . '"><strong>Total</strong></td><td align="right" style="border-top:1px solid ' . $borda . '"><strong>'
      . self::brl((float)$p['total']) . '</strong></td></tr>';
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px">' . $linhas . '</table>';
  }

  public static function brl(float $v): string
  {
    return 'R$ ' . number_format($v, 2, ',', '.');
  }

  /** E-mail com o link de pagamento do pedido. */
  public static function enviarLink(array $p, string $titulo = ''): bool
  {
    $c = Clientes::buscar($p['cliente_cpf']);
    if (!$c) return false;
    $nome = htmlspecialchars(Clientes::nomes($c['nome'])[0]);
    $html = "<p>Olá, {$nome}!</p><p>Seu pedido <strong>#{$p['id']}</strong> está aguardando pagamento. Você pode pagar com Pix, boleto ou cartão.</p>"
      . self::tabelaItensHtml($p) . Email::botao('Pagar agora', self::link($p));
    return Email::enviar($c['email'], $titulo ?: "Pedido #{$p['id']}: pagamento pendente", $html);
  }

  /** E-mail com o boleto gerado (link, linha digitável e vencimento) para o e-mail do cadastro. */
  public static function enviarBoleto(array $p, array $pg): bool
  {
    $c = Clientes::buscar($p['cliente_cpf']);
    if (!$c || empty($pg['link_pagamento'])) return false;
    $nome = htmlspecialchars(Clientes::nomes($c['nome'])[0]);
    $vence = $pg['expira_em'] ? ' Vencimento: <strong>' . date('d/m/Y', strtotime($pg['expira_em'])) . '</strong>.' : '';
    $linha = $pg['codigo_barras']
      ? '<p style="margin:16px 0 4px;font-size:13px;color:#555">Linha digitável:</p><p style="font-family:Consolas,monospace;font-size:14px;word-break:break-all;background:' . Config::cores()['fundo'] . ';padding:10px">'
        . htmlspecialchars($pg['codigo_barras']) . '</p>'
      : '';
    $html = "<p>Olá, {$nome}!</p><p>O boleto do seu pedido <strong>#{$p['id']}</strong> no valor de <strong>" . self::brl((float)$pg['valor']) . "</strong> foi gerado.{$vence}</p>"
      . '<p>Pague no app do seu banco, internet banking ou lotérica. A confirmação leva até 3 dias úteis.</p>'
      . Email::botao('Abrir boleto', $pg['link_pagamento']) . $linha . self::tabelaItensHtml($p);
    return Email::enviar($c['email'], "Pedido #{$p['id']}: seu boleto", $html);
  }

  private static function avisarPagamentoAprovado(int $id): void
  {
    try {
      $p = self::carregar($id);
      $c = Clientes::buscar($p['cliente_cpf']);
      $nome = htmlspecialchars(Clientes::nomes($c['nome'])[0]);
      $forma = self::METODOS[$p['forma_pagamento']] ?? '';
      Email::enviar(
        $c['email'],
        "Pagamento confirmado · Pedido #{$id}",
        "<p>Olá, {$nome}!</p><p>Recebemos o pagamento do seu pedido <strong>#{$id}</strong> ({$forma}). Obrigado pela compra!</p>" . self::tabelaItensHtml($p)
      );
      $loja = Config::get('loja_email');
      if ($loja !== '') {
        Email::enviar(
          $loja,
          "Novo pedido pago #{$id} - " . self::brl((float)$p['total']),
          '<p>Cliente: ' . htmlspecialchars($c['nome']) . ' (' . htmlspecialchars($c['email']) . ')</p>' . self::tabelaItensHtml($p)
        );
      }
    } catch (Throwable $e) {
      error_log('Falha ao avisar pagamento aprovado: ' . $e->getMessage());
    }
  }
}
