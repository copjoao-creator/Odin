<?php
/**
 * Pedidos e o vínculo com os pagamentos do Mercado Pago.
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

  public const METODOS = ['pix' => 'Pix', 'boleto' => 'Boleto', 'credito' => 'Cartão de crédito', 'debito' => 'Cartão de débito', 'outro' => 'Outro'];

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

  /** Converte o carrinho [{tipo, codigo, quantidade}] em itens com preço e custo do banco. */
  public static function itensDoCarrinho($carrinho): array
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
      $venda = Modulos::paraVenda($a['tipo'])->itemParaVenda($a['codigo'], $a['quantidade']);
      $itens[] = $a + $venda + ['referencia' => null];
    }
    return $itens;
  }

  /**
   * Cada pedido é pago por uma única aplicação do Mercado Pago, então produtos e serviços
   * vão em pedidos separados. Uma assinatura (serviço recorrente) vai sozinha no pedido,
   * porque vira uma cobrança automática no cartão com valor e período próprios.
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

  /** Aplicação do Mercado Pago do pedido: "servicos" para pedidos de serviços, "loja" para o resto. */
  public static function app(array $p): string
  {
    $tipos = array_column($p['itens'] ?? [], 'tipo');
    return in_array('servico', $tipos, true) ? MercadoPago::appEfetiva('servicos') : 'loja';
  }

  /** Item de assinatura do pedido (serviço recorrente contratado agora, pago no cartão automático). */
  public static function itemAssinatura(array $p): ?array
  {
    if (($p['origem'] ?? '') === 'renovacao') return null;
    foreach ($p['itens'] ?? [] as $i) if (!empty($i['renovacao'])) return $i;
    return null;
  }

  /**
   * Quem cobra a assinatura do pedido: a já criada fica onde nasceu; uma nova vai para o Asaas
   * quando ele estiver configurado (senão, para o Mercado Pago, como antes).
   */
  public static function gatewayAssinatura(array $p): string
  {
    if (!empty($p['mp_assinatura'])) return Asaas::ehAssinatura($p['mp_assinatura']) ? 'asaas' : 'mercadopago';
    return Asaas::configurado() ? 'asaas' : 'mercadopago';
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
   * Renovação de assinatura no cartão: quem cobra é o Mercado Pago, automaticamente. Esse pedido
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
      $st = Banco::pdo()->prepare(
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
      // Chave pública da aplicação do Mercado Pago que cobra este pedido (loja ou serviços).
      'mp_public_key' => MercadoPago::credenciais(self::app($p))['public_key'],
      'pagamentos_ativos' => MercadoPago::configurado(self::app($p)),
      'cobranca_automatica' => self::cobrancaAutomatica($p),
      // Serviço recorrente: pago só no cartão de crédito, com renovação automática a cada período.
      // gateway: "asaas" (formulário de cartão da loja) ou "mercadopago" (formulário do Mercado Pago).
      'assinatura' => ($a = self::itemAssinatura($p)) ? [
        'renovacao' => $a['renovacao'],
        'valor' => (float)$a['preco_unitario'],
        'criada' => !empty($p['mp_assinatura']),
        'data_final' => $p['assinatura_data_final'] ?? null,
        'gateway' => self::gatewayAssinatura($p),
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
      'mensagem' => MercadoPago::mensagem($pg['status'], $pg['status_detalhe'], $pg['metodo']),
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
   * Grava (ou atualiza) um pagamento do Mercado Pago e recalcula o pedido.
   * $app: aplicação que criou o pagamento; se omitida, vale a do pedido (só usada na primeira gravação).
   */
  public static function registrarPagamento(int $pedidoId, array $mp, ?string $app = null): array
  {
    if ($app === null) {
      $tipos = Banco::todos('SELECT DISTINCT tipo FROM pedido_itens WHERE pedido_id = ?', [$pedidoId]);
      $app = self::app(['itens' => $tipos]);
    }
    $t = $mp['point_of_interaction']['transaction_data'] ?? [];
    $det = $mp['transaction_details'] ?? [];
    $status = (string)($mp['status'] ?? 'pending');
    $liquido = $det['net_received_amount'] ?? null;
    $dados = [
      'pedido_id' => $pedidoId,
      'mp_id' => (string)$mp['id'],
      'app' => in_array($app, array_merge(MercadoPago::APPS, [Asaas::APP]), true) ? $app : 'loja',
      'metodo' => MercadoPago::metodo($mp),
      'mp_metodo' => $mp['payment_method_id'] ?? null,
      'status' => $status,
      'status_detalhe' => $mp['status_detail'] ?? null,
      'valor' => number_format((float)($mp['transaction_amount'] ?? 0), 2, '.', ''),
      'valor_liquido' => $liquido !== null && (float)$liquido > 0 ? number_format((float)$liquido, 2, '.', '') : null,
      'valor_estornado' => number_format((float)($mp['transaction_amount_refunded'] ?? 0), 2, '.', ''),
      'parcelas' => max(1, (int)($mp['installments'] ?? 1)),
      'pix_copia_cola' => $t['qr_code'] ?? null,
      'pix_qr_base64' => $t['qr_code_base64'] ?? null,
      'link_pagamento' => $det['external_resource_url'] ?? $t['ticket_url'] ?? null,
      'codigo_barras' => $det['digitable_line'] ?? $mp['barcode']['content'] ?? null,
      'expira_em' => self::dataMp($mp['date_of_expiration'] ?? null),
      'aprovado_em' => self::dataMp($mp['date_approved'] ?? null),
    ];
    $colunas = array_keys($dados);
    $atualizar = array_map(fn($c) => "{$c} = VALUES({$c})", array_diff($colunas, ['pedido_id', 'mp_id', 'app']));
    Banco::executar(
      'INSERT INTO pagamentos (' . implode(', ', $colunas) . ') VALUES (' . implode(', ', array_fill(0, count($colunas), '?')) . ')
       ON DUPLICATE KEY UPDATE ' . implode(', ', $atualizar),
      array_values($dados)
    );
    self::sincronizar($pedidoId);
    return Banco::um('SELECT * FROM pagamentos WHERE mp_id = ?', [(string)$mp['id']]);
  }

  /** Datas do Mercado Pago (ISO 8601 com fuso) no horário de Brasília. */
  private static function dataMp(?string $iso): ?string
  {
    if (!$iso) return null;
    try {
      return (new DateTime($iso))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
      return null;
    }
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
      // Só vale como pago o pagamento aprovado que cobre o total do pedido.
      $aprovado = null;
      foreach ($p['pagamentos'] as $pg) {
        if ($pg['status'] === 'approved' && self::centavos($pg['valor']) >= self::centavos($p['total'])) $aprovado = $pg;
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
        [$novo, $forma, ($aplicar || ($p['efeitos_aplicados'] && !$reverter)) ? 1 : 0, $novo, $aprovado['aprovado_em'] ?? null, $id]
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

  /** Cancela um pedido ainda não pago (pelo painel ou por expiração). */
  public static function cancelar(int $id, string $motivo): array
  {
    $p = Banco::transacao(function () use ($id, $motivo) {
      $p = self::carregar($id, true);
      if (!$p) throw new ErroApi('Pedido não encontrado.', 404);
      if (!in_array($p['status'], ['aguardando_pagamento', 'em_analise'], true)) {
        throw new ErroApi('Só é possível cancelar pedidos que ainda não foram pagos.', 422);
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
    // (Cobranças do Asaas pertencem à assinatura; quem as encerra é o cancelamento da assinatura.)
    foreach ($p['pagamentos'] as $pg) {
      if ($pg['app'] === Asaas::APP) continue;
      if (in_array($pg['status'], ['pending', 'in_process'], true)) {
        try {
          self::registrarPagamento($id, MercadoPago::cancelar($pg['mp_id'], $pg['app']), $pg['app']);
        } catch (Throwable $e) {
          error_log("Não foi possível cancelar o pagamento {$pg['mp_id']} no Mercado Pago: " . $e->getMessage());
        }
      }
    }
    return self::carregar($id);
  }

  /** Consulta de novo no Mercado Pago ou no Asaas (caso algum aviso do webhook tenha se perdido). */
  public static function atualizarPagamentosPendentes(array $p): void
  {
    foreach ($p['pagamentos'] as $pg) {
      if (!in_array($pg['status'], ['pending', 'in_process', 'authorized'], true)) continue;
      if ($pg['app'] === Asaas::APP) {
        if (Asaas::configurado()) self::registrarPagamento((int)$p['id'], Asaas::comoPagamento(Asaas::consultar($pg['mp_id'])), Asaas::APP);
        continue;
      }
      if (!MercadoPago::configurado($pg['app'])) continue;
      self::registrarPagamento((int)$p['id'], MercadoPago::consultar($pg['mp_id'], $pg['app']), $pg['app']);
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
    $ids = Banco::todos(
      "SELECT id FROM pedidos WHERE status = 'aguardando_pagamento' AND criado_em < DATE_SUB(NOW(), INTERVAL {$dias} DAY)"
    );
    foreach ($ids as ['id' => $id]) {
      try {
        $p = self::carregar((int)$id);
        self::atualizarPagamentosPendentes($p);
        $p = self::carregar((int)$id);
        if ($p['status'] !== 'aguardando_pagamento') continue;
        $boletoNoPrazo = Banco::valor(
          "SELECT COUNT(*) FROM pagamentos WHERE pedido_id = ? AND status = 'pending' AND expira_em > NOW()",
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
    $linhas .= '<tr><td style="padding:10px 0;border-top:1px solid #E5DECF"><strong>Total</strong></td><td align="right" style="border-top:1px solid #E5DECF"><strong>'
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
      ? '<p style="margin:16px 0 4px;font-size:13px;color:#555">Linha digitável:</p><p style="font-family:Consolas,monospace;font-size:14px;word-break:break-all;background:#F7F6F2;padding:10px">'
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
