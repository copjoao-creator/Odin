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
    $tecnico = Auth::ehTecnico();
    $c = Config::todas();
    foreach (Config::SECRETAS as $k) $c[$k] = Config::mascarar($c[$k]);
    // O dono da loja (perfil "administrador") não recebe as configurações de pagamento.
    if (!$tecnico) foreach (Config::TECNICAS as $k) unset($c[$k]);
    $modulos = [];
    foreach (Modulos::instalados() as $m) {
      $modulos[] = ['tipo' => $m->tipo(), 'nome' => $m->nome(), 'ligado' => Modulos::ligado($m->tipo()), 'aba' => Config::get('aba_' . $m->tipo()) !== '0'];
    }
    $cron = (PHP_OS_FAMILY === 'Windows' ? 'php ' : '/usr/local/bin/php ') . realpath(API_RAIZ . '/cron.php');
    return [
      'configuracoes' => $c,
      'modulos' => $modulos,
      'tecnico' => $tecnico,
      'identidade' => Marca::publica(),
      'pagamentos_configurados' => MercadoPago::configurado() || Asaas::configurado(),
      'webhook_url' => $tecnico ? Http::urlLoja() . 'api/?r=webhook/mercadopago' : null,
      'webhook_url_servicos' => $tecnico ? Http::urlLoja() . 'api/?r=webhook/mercadopago&app=servicos' : null,
      'webhook_url_asaas' => $tecnico ? Http::urlLoja() . 'api/?r=webhook/asaas' : null,
      'comando_cron' => $cron,
      'ultima_tarefa_diaria' => $c['ultima_tarefa_diaria'] ?? null,
    ];
  });

  $r->admin('PUT', 'admin/configuracoes', function () {
    $d = Http::entrada();
    // Configurações de pagamento: só o perfil técnico altera (as do administrador são ignoradas).
    if (!Auth::ehTecnico()) foreach (Config::TECNICAS as $k) unset($d[$k]);
    $v = [];
    $textos = [
      'loja_nome' => ['Nome da loja', 80], 'loja_titulo' => ['Título principal', 150], 'loja_subtitulo' => ['Subtítulo', 300],
      'aviso_topo' => ['Aviso do topo', 150], 'loja_instagram' => ['Instagram', 60], 'loja_facebook' => ['Facebook', 120],
      'empresa_razao_social' => ['Razão social / nome completo', 150], 'empresa_responsavel' => ['Nome do responsável', 120],
      'empresa_ie' => ['Inscrição estadual', 30], 'empresa_rua' => ['Rua', 150], 'empresa_numero' => ['Número', 20],
      'empresa_complemento' => ['Complemento', 80], 'empresa_bairro' => ['Bairro', 100], 'empresa_cidade' => ['Cidade', 100],
    ];
    foreach ($textos as $k => [$rotulo, $max]) if (array_key_exists($k, $d)) $v[$k] = Validacao::texto($d, $k, $rotulo, $max, $k === 'loja_nome');
    // Dados da empresa: CPF (pessoa física) ou CNPJ (pessoa jurídica), CEP e UF.
    if (array_key_exists('empresa_tipo', $d)) $v['empresa_tipo'] = $d['empresa_tipo'] === 'pf' ? 'pf' : 'pj';
    if (array_key_exists('empresa_documento', $d)) {
      $doc = Validacao::digitos($d['empresa_documento']);
      $tipo = $v['empresa_tipo'] ?? Config::get('empresa_tipo');
      if ($doc !== '' && $tipo === 'pf' && !Validacao::cpfValido($doc)) throw new ErroApi('CPF da empresa inválido.', 422, ['campo' => 'empresa_documento']);
      if ($doc !== '' && $tipo !== 'pf' && !Validacao::cnpjValido($doc)) throw new ErroApi('CNPJ inválido. Confira os números.', 422, ['campo' => 'empresa_documento']);
      $v['empresa_documento'] = $doc;
    }
    if (array_key_exists('empresa_cep', $d)) {
      $cep = Validacao::digitos($d['empresa_cep']);
      if ($cep !== '' && strlen($cep) !== 8) throw new ErroApi('CEP da empresa inválido.', 422, ['campo' => 'empresa_cep']);
      $v['empresa_cep'] = $cep;
    }
    if (array_key_exists('empresa_uf', $d)) {
      $uf = strtoupper(trim((string)$d['empresa_uf']));
      if ($uf !== '' && !in_array($uf, Validacao::UFS, true)) throw new ErroApi('Estado (UF) inválido.', 422, ['campo' => 'empresa_uf']);
      $v['empresa_uf'] = $uf;
    }
    if (array_key_exists('loja_telefone', $d)) {
      $t = Validacao::digitos($d['loja_telefone']);
      if ($t !== '' && (strlen($t) < 10 || strlen($t) > 11)) throw new ErroApi('Telefone: informe DDD + número.', 422, ['campo' => 'loja_telefone']);
      $v['loja_telefone'] = $t;
    }
    // Cores do tema (#RRGGBB) e nome ao lado do logotipo.
    foreach (['principal', 'fundo', 'secundaria', 'texto', 'realce'] as $cor) {
      $k = 'cor_' . $cor;
      if (!array_key_exists($k, $d)) continue;
      $hex = strtoupper(trim((string)$d[$k]));
      if (!preg_match('/^#[0-9A-F]{6}$/', $hex)) throw new ErroApi('Cor inválida: use o formato #RRGGBB.', 422, ['campo' => $k]);
      $v[$k] = $hex;
    }
    if (array_key_exists('marca_mostrar_nome', $d)) $v['marca_mostrar_nome'] = Validacao::booleano($d['marca_mostrar_nome']) ? '1' : '0';
    if (array_key_exists('loja_sobre', $d)) $v['loja_sobre'] = Validacao::textoLongo($d, 'loja_sobre', 'Sobre a loja', 2000, false);
    if (array_key_exists('loja_email', $d)) $v['loja_email'] = trim((string)$d['loja_email']) === '' ? '' : Validacao::email($d['loja_email'], 'loja_email');
    if (array_key_exists('loja_whatsapp', $d)) {
      $w = Validacao::digitos($d['loja_whatsapp']);
      if ($w !== '' && strlen($w) < 10) throw new ErroApi('WhatsApp: informe DDD + número.', 422, ['campo' => 'loja_whatsapp']);
      $v['loja_whatsapp'] = ($w !== '' && strlen($w) <= 11) ? '55' . $w : $w;
    }
    if (array_key_exists('loja_url', $d)) {
      $u = trim((string)$d['loja_url']);
      if ($u !== '' && !preg_match('#^https?://[^\s/]+(/.*)?$#', $u)) throw new ErroApi('Endereço da loja inválido (ex.: https://www.sualoja.com.br/loja/).', 422, ['campo' => 'loja_url']);
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
    if (array_key_exists('asaas_ambiente', $d)) $v['asaas_ambiente'] = $d['asaas_ambiente'] === 'producao' ? 'producao' : 'sandbox';
    foreach (['asaas_api_key' => 'Chave de API do Asaas', 'asaas_webhook_token' => 'Token do webhook do Asaas'] as $k => $rotulo) {
      if (!array_key_exists($k, $d)) continue;
      $s = trim((string)$d[$k]);
      if ($s === '' || strpos($s, '…') !== false || strpos($s, '•') !== false) continue; // não mexe (campo mascarado)
      if (!preg_match('/^\S{8,400}$/', $s)) throw new ErroApi("{$rotulo} inválido: copie e cole sem espaços.", 422, ['campo' => $k]);
      $v[$k] = $s;
    }
    foreach (['emailjs_public_key' => 'Public Key', 'emailjs_service_id' => 'Service ID', 'emailjs_template_id' => 'Template ID'] as $k => $rotulo) {
      if (!array_key_exists($k, $d)) continue;
      $s = trim((string)$d[$k]);
      if ($s !== '' && !preg_match('/^[A-Za-z0-9_\-]{3,100}$/', $s)) throw new ErroApi("EmailJS: $rotulo inválido. Copie e cole sem espaços.", 422, ['campo' => $k]);
      $v[$k] = $s;
    }
    foreach (Modulos::instalados() as $m) {
      foreach (['modulo_', 'aba_'] as $prefixo) {
        $k = $prefixo . $m->tipo();
        if (array_key_exists($k, $d)) $v[$k] = Validacao::booleano($d[$k]) ? '1' : '0';
      }
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
    'administradores' => Banco::todos('SELECT id, nome, email, perfil, ultimo_acesso, criado_em FROM administradores ORDER BY perfil DESC, nome'),
  ]);

  // Só o técnico cria outro técnico; o dono da loja cria administradores.
  $r->admin('POST', 'admin/administradores', function () {
    $d = Http::entrada();
    $nome = Validacao::texto($d, 'nome', 'Nome', 100);
    $email = Validacao::email($d['email'] ?? '');
    $senha = Auth::validarSenha((string)($d['senha'] ?? ''));
    $perfil = ($d['perfil'] ?? '') === 'tecnico' && Auth::ehTecnico() ? 'tecnico' : 'administrador';
    try {
      Banco::executar('INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)', [$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
    } catch (PDOException $e) {
      if (Banco::duplicado($e)) throw new ErroApi('Já existe um administrador com este e-mail.', 409, ['campo' => 'email']);
      throw $e;
    }
    return ['ok' => true];
  });

  $r->admin('DELETE', 'admin/administradores/{id}', function ($id) {
    $a = Auth::exigirAdmin();
    if ((int)$id === (int)$a['id']) throw new ErroApi('Você não pode excluir o seu próprio acesso.', 422);
    $alvo = Banco::um('SELECT perfil FROM administradores WHERE id = ?', [(int)$id]);
    if (!$alvo) throw new ErroApi('Acesso não encontrado.', 404);
    if ($alvo['perfil'] === 'tecnico' && !Auth::ehTecnico($a)) throw new ErroApi('O acesso técnico só pode ser removido pelo suporte técnico.', 403);
    Banco::executar('DELETE FROM administradores WHERE id = ?', [(int)$id]);
    return ['ok' => true];
  });

  // Identidade visual: logotipo (fundo claro), logotipo para fundo escuro e ícone da aba do navegador.
  $r->admin('POST', 'admin/marca/{tipo}', function ($tipo) {
    if (!isset(Marca::IMAGENS[$tipo])) throw new ErroApi('Tipo de imagem inválido.', 404);
    $chave = Marca::IMAGENS[$tipo];
    $antigo = Config::get($chave);
    $v = [];
    if ($tipo === 'icone') {
      // Ícone enviado à parte: vira um quadrado de 180px (a imagem original não fica guardada).
      $img = Imagem::salvar($_FILES['imagem'] ?? null, Marca::PASTA, 512);
      $icone = Imagem::icone(Marca::PASTA, $img['arquivo']) ?? $img['arquivo'];
      if ($icone !== $img['arquivo']) Imagem::apagar(Marca::PASTA, $img['arquivo']);
      $v = ['marca_icone' => $icone, 'marca_icone_auto' => '0'];
    } else {
      $img = Imagem::salvar($_FILES['imagem'] ?? null, Marca::PASTA, 600);
      $v[$chave] = $img['arquivo'];
      // Sem ícone próprio, o ícone da aba acompanha o logotipo principal.
      if ($tipo === 'logo' && (Config::get('marca_icone') === '' || Config::get('marca_icone_auto') !== '0')) {
        $icone = Imagem::icone(Marca::PASTA, $img['arquivo']);
        if ($icone) {
          Imagem::apagar(Marca::PASTA, Config::get('marca_icone'));
          $v += ['marca_icone' => $icone, 'marca_icone_auto' => '1'];
        }
      }
    }
    Config::salvar($v);
    if ($antigo !== '' && $antigo !== ($v[$chave] ?? '')) Imagem::apagar(Marca::PASTA, $antigo);
    return Marca::publica();
  });

  $r->admin('DELETE', 'admin/marca/{tipo}', function ($tipo) {
    if (!isset(Marca::IMAGENS[$tipo])) throw new ErroApi('Tipo de imagem inválido.', 404);
    $chave = Marca::IMAGENS[$tipo];
    $v = [$chave => ''];
    Imagem::apagar(Marca::PASTA, Config::get($chave));
    // Sem logotipo, o ícone gerado dele também sai; o ícone volta a ser automático ao remover o próprio.
    if ($tipo === 'logo' && Config::get('marca_icone_auto') !== '0') {
      Imagem::apagar(Marca::PASTA, Config::get('marca_icone'));
      $v['marca_icone'] = '';
    }
    if ($tipo === 'icone') $v['marca_icone_auto'] = '1';
    Config::salvar($v);
    return Marca::publica();
  });

  $r->admin('POST', 'admin/tarefas', fn() => ['mensagens' => Tarefas::executar()]);
};
