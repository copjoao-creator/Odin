<?php
/* Rotas públicas da vitrine: dados da loja, criação do pedido e página de pagamento. */
return function (Roteador $r) {
  // Textos, contatos, frete e chave pública do Mercado Pago (nunca as chaves secretas).
  $r->publica('GET', 'loja', function () {
    $c = Config::todas();
    $modulos = [];
    foreach (Modulos::instalados() as $m) $modulos[$m->tipo()] = Modulos::ligado($m->tipo());
    return ['loja' => [
      'nome' => $c['loja_nome'],
      'titulo' => $c['loja_titulo'],
      'subtitulo' => $c['loja_subtitulo'],
      'sobre' => $c['loja_sobre'],
      'aviso_topo' => $c['aviso_topo'],
      'email' => $c['loja_email'],
      'whatsapp' => $c['loja_whatsapp'],
      'instagram' => $c['loja_instagram'],
      // Formulário de contato (contato.html). No EmailJS essas três chaves são públicas por natureza.
      'emailjs' => ($c['emailjs_public_key'] !== '' && $c['emailjs_service_id'] !== '' && $c['emailjs_template_id'] !== '') ? [
        'public_key' => $c['emailjs_public_key'],
        'service_id' => $c['emailjs_service_id'],
        'template_id' => $c['emailjs_template_id'],
      ] : null,
      'frete_valor' => (float)$c['frete_valor'],
      'frete_gratis_acima' => (float)$c['frete_gratis_acima'],
      'max_parcelas' => (int)$c['max_parcelas'],
      'mp_public_key' => $c['mp_public_key'],
      'pagamentos_ativos' => MercadoPago::configurado(),
      'modulos' => $modulos,
    ]];
  });

  // Checkout, 1º passo: o cliente digita o CPF. Se já tiver cadastro, devolve só um resumo mascarado.
  $r->publica('POST', 'clientes/identificar', function () {
    $cpf = Validacao::cpf(Http::entrada()['cpf'] ?? '');
    Clientes::limitarConsultas();
    $c = Clientes::buscar($cpf);
    return ['cadastrado' => (bool)$c, 'resumo' => $c ? Clientes::resumoMascarado($c) : null];
  });

  // Checkout: cria o pedido com preços e estoque do banco.
  // - Cliente novo ou "atualizar meus dados": valida o formulário e cadastra/atualiza o cliente.
  // - "Continuar com estes dados" (usar_cadastro): usa o cadastro do CPF sem alterar nada, e o
  //   pedido mostra os dados do cliente mascarados (quem digitou o CPF pode não ser o dono dele).
  $r->publica('POST', 'pedidos', function () {
    $d = Http::entrada();
    $itens = Pedidos::itensDoCarrinho($d['itens'] ?? []);
    if (Validacao::booleano($d['usar_cadastro'] ?? false)) {
      $cpf = Validacao::cpf($d['cliente']['cpf'] ?? '');
      Clientes::limitarConsultas();
      $cliente = Clientes::buscar($cpf);
      if (!$cliente) throw new ErroApi('Não encontramos cadastro com este CPF. Preencha seus dados.', 404, ['campo' => 'cpf']);
      $p = Banco::transacao(function () use ($cliente, $itens) {
        $p = Pedidos::criar($cliente, $itens, 'loja');
        Banco::executar('UPDATE pedidos SET dados_protegidos = 1 WHERE id = ?', [$p['id']]);
        return Pedidos::carregar((int)$p['id']);
      });
      return ['pedido' => Pedidos::publico($p)];
    }
    $cliente = Clientes::validar(is_array($d['cliente'] ?? null) ? $d['cliente'] : []);
    $p = Banco::transacao(function () use ($cliente, $itens) {
      Clientes::salvarDoCheckout($cliente);
      return Pedidos::criar($cliente, $itens, 'loja');
    });
    return ['pedido' => Pedidos::publico($p)];
  });

  // Página de pagamento (pagar.html) e acompanhamento do Pix. Exige o token secreto do pedido.
  $r->publica('GET', 'pedidos/{id}/publico', function ($id) {
    $p = Pedidos::peloToken($id, $_GET['t'] ?? '');
    // Consulta o Mercado Pago de novo se o cliente está esperando (Pix/cartão em análise), no máximo a cada 15 s.
    // Assinatura no cartão ainda sem cobrança registrada também é consultada (a primeira cobrança chega depois).
    if (!empty($_GET['atualizar']) && in_array($p['status'], ['aguardando_pagamento', 'em_analise'], true) && $p['pagamentos']) {
      $ultimo = $p['pagamentos'][count($p['pagamentos']) - 1];
      $quando = strtotime($ultimo['atualizado_em'] ?? $ultimo['criado_em']);
      if (time() - $quando >= 15) {
        try {
          Pedidos::atualizarPagamentosPendentes($p);
          Banco::executar('UPDATE pagamentos SET atualizado_em = NOW() WHERE id = ?', [$ultimo['id']]);
        } catch (ErroApi $e) {
          // Sem resposta do Mercado Pago agora: o webhook ou a próxima consulta resolvem.
        }
        $p = Pedidos::carregar((int)$p['id']);
      }
    } elseif (!empty($_GET['atualizar']) && $p['status'] === 'aguardando_pagamento' && !empty($p['mp_assinatura'])) {
      Pedidos::atualizarPagamentosPendentes($p); // erros ficam no error_log; a página tenta de novo
      $p = Pedidos::carregar((int)$p['id']);
    }
    return ['pedido' => Pedidos::publico($p)];
  });
};
