<?php
/* Login do painel, configurações da loja e administradores. */
return function (Roteador $r) {
  $r->publica('POST', 'admin/entrar', function () {
    $d = Http::entrada();
    return ['admin' => Auth::entrar((string)($d['email'] ?? ''), (string)($d['senha'] ?? ''))];
  });

  $r->publica('POST', 'admin/sair', function () {
    Auth::sair();
    return ['ok' => true];
  });

  // O painel chama ao abrir: diz se há alguém logado e entrega o token CSRF.
  $r->publica('GET', 'admin/sessao', function () {
    $a = Auth::admin();
    if (!$a) return ['admin' => null];
    Tarefas::executarSeAtrasada();
    return ['admin' => $a + ['csrf' => Auth::csrf()]];
  });

  $r->admin('GET', 'admin/configuracoes', function () {
    $c = Config::todas();
    foreach (Config::SECRETAS as $k) $c[$k] = Config::mascarar($c[$k]);
    $modulos = [];
    foreach (Modulos::instalados() as $m) $modulos[] = ['tipo' => $m->tipo(), 'nome' => $m->nome(), 'ligado' => Modulos::ligado($m->tipo())];
    $cron = (PHP_OS_FAMILY === 'Windows' ? 'php ' : '/usr/local/bin/php ') . realpath(API_RAIZ . '/cron.php');
    return [
      'configuracoes' => $c,
      'modulos' => $modulos,
      'webhook_url' => Http::urlLoja() . 'api/?r=webhook/mercadopago',
      'webhook_url_servicos' => Http::urlLoja() . 'api/?r=webhook/mercadopago&app=servicos',
      'comando_cron' => $cron,
      'ultima_tarefa_diaria' => $c['ultima_tarefa_diaria'] ?? null,
    ];
  });

  $r->admin('PUT', 'admin/configuracoes', function () {
    $d = Http::entrada();
    $v = [];
    $textos = [
      'loja_nome' => ['Nome da loja', 80], 'loja_titulo' => ['Título principal', 150], 'loja_subtitulo' => ['Subtítulo', 300],
      'aviso_topo' => ['Aviso do topo', 150], 'loja_instagram' => ['Instagram', 60],
    ];
    foreach ($textos as $k => [$rotulo, $max]) if (array_key_exists($k, $d)) $v[$k] = Validacao::texto($d, $k, $rotulo, $max, $k === 'loja_nome');
    if (array_key_exists('loja_sobre', $d)) $v['loja_sobre'] = Validacao::textoLongo($d, 'loja_sobre', 'Sobre a loja', 2000, false);
    if (array_key_exists('loja_email', $d)) $v['loja_email'] = trim((string)$d['loja_email']) === '' ? '' : Validacao::email($d['loja_email'], 'loja_email');
    if (array_key_exists('loja_whatsapp', $d)) {
      $w = Validacao::digitos($d['loja_whatsapp']);
      if ($w !== '' && strlen($w) < 10) throw new ErroApi('WhatsApp: informe DDD + número.', 422, ['campo' => 'loja_whatsapp']);
      $v['loja_whatsapp'] = ($w !== '' && strlen($w) <= 11) ? '55' . $w : $w;
    }
    if (array_key_exists('loja_url', $d)) {
      $u = trim((string)$d['loja_url']);
      if ($u !== '' && !preg_match('#^https?://[^\s/]+(/.*)?$#', $u)) throw new ErroApi('Endereço da loja inválido (ex.: https://www.odinfocus.com.br/loja/).', 422, ['campo' => 'loja_url']);
      $v['loja_url'] = $u === '' ? '' : rtrim($u, '/') . '/';
    }
    foreach (['frete_valor' => 'Frete', 'frete_gratis_acima' => 'Frete grátis acima de'] as $k => $rotulo) {
      if (array_key_exists($k, $d)) $v[$k] = Validacao::dinheiro($d[$k], $k, $rotulo, false);
    }
    $inteiros = ['max_parcelas' => ['Parcelas', 1, 24], 'dias_expiracao_pedido' => ['Dias para expirar', 1, 60], 'dias_antecedencia_renovacao' => ['Antecedência da renovação', 0, 30], 'estoque_minimo' => ['Estoque mínimo', 0, 100000]];
    foreach ($inteiros as $k => [$rotulo, $min, $max]) if (array_key_exists($k, $d)) $v[$k] = (string)Validacao::inteiro($d[$k], $k, $rotulo, $min, $max);
    foreach (['mp_public_key', 'mp_access_token', 'mp_webhook_secret', 'mp_serv_public_key', 'mp_serv_access_token', 'mp_serv_webhook_secret'] as $k) {
      if (!array_key_exists($k, $d)) continue;
      $s = trim((string)$d[$k]);
      if ($s === '' || strpos($s, '…') !== false || strpos($s, '•') !== false) continue; // não mexe (campo mascarado)
      if (!preg_match('/^[A-Za-z0-9_\-]{8,300}$/', $s)) throw new ErroApi('Chave do Mercado Pago inválida: copie e cole sem espaços.', 422, ['campo' => $k]);
      $v[$k] = $s;
    }
    foreach (['emailjs_public_key' => 'Public Key', 'emailjs_service_id' => 'Service ID', 'emailjs_template_id' => 'Template ID'] as $k => $rotulo) {
      if (!array_key_exists($k, $d)) continue;
      $s = trim((string)$d[$k]);
      if ($s !== '' && !preg_match('/^[A-Za-z0-9_\-]{3,100}$/', $s)) throw new ErroApi("EmailJS: $rotulo inválido. Copie e cole sem espaços.", 422, ['campo' => $k]);
      $v[$k] = $s;
    }
    foreach (Modulos::instalados() as $m) {
      $k = 'modulo_' . $m->tipo();
      if (array_key_exists($k, $d)) $v[$k] = Validacao::booleano($d[$k]) ? '1' : '0';
    }
    Config::salvar($v);
    return ['ok' => true];
  });

  $r->admin('PUT', 'admin/senha', function () {
    $a = Auth::exigirAdmin();
    $d = Http::entrada();
    $hash = Banco::valor('SELECT senha_hash FROM administradores WHERE id = ?', [$a['id']]);
    if (!password_verify((string)($d['senha_atual'] ?? ''), (string)$hash)) throw new ErroApi('Senha atual incorreta.', 422, ['campo' => 'senha_atual']);
    $nova = Auth::validarSenha((string)($d['senha_nova'] ?? ''));
    Banco::executar('UPDATE administradores SET senha_hash = ? WHERE id = ?', [password_hash($nova, PASSWORD_DEFAULT), $a['id']]);
    return ['ok' => true];
  });

  $r->admin('GET', 'admin/administradores', fn() => [
    'administradores' => Banco::todos('SELECT id, nome, email, ultimo_acesso, criado_em FROM administradores ORDER BY nome'),
  ]);

  $r->admin('POST', 'admin/administradores', function () {
    $d = Http::entrada();
    $nome = Validacao::texto($d, 'nome', 'Nome', 100);
    $email = Validacao::email($d['email'] ?? '');
    $senha = Auth::validarSenha((string)($d['senha'] ?? ''));
    try {
      Banco::executar('INSERT INTO administradores (nome, email, senha_hash) VALUES (?, ?, ?)', [$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]);
    } catch (PDOException $e) {
      if (Banco::duplicado($e)) throw new ErroApi('Já existe um administrador com este e-mail.', 409, ['campo' => 'email']);
      throw $e;
    }
    return ['ok' => true];
  });

  $r->admin('DELETE', 'admin/administradores/{id}', function ($id) {
    $a = Auth::exigirAdmin();
    if ((int)$id === (int)$a['id']) throw new ErroApi('Você não pode excluir o seu próprio acesso.', 422);
    Banco::executar('DELETE FROM administradores WHERE id = ?', [(int)$id]);
    return ['ok' => true];
  });

  $r->admin('POST', 'admin/tarefas', fn() => ['mensagens' => Tarefas::executar()]);
};
