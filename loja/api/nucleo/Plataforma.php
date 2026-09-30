<?php
/**
 * Plataforma Odin Focus: várias lojas independentes no mesmo sistema.
 * - Cada loja fica em odinfocus.com.br/{endereço}/, com as próprias tabelas (prefixo), imagens,
 *   configurações, personalização e painel. Só o administrador master cadastra lojas.
 * - Administrador master (admin@odinfocus.com.br): acesso total a todas as lojas. Criar, alterar e
 *   recuperar a senha dele exige um código enviado ao e-mail de verificação.
 * - "Entrar" (entrar.html): um login só; o sistema descobre se é o master ou o administrador de qual loja.
 */
final class Plataforma
{
  /** Endereço da loja original (a primeira): /loja/, tabelas sem prefixo, imagens em loja/uploads/. */
  public const SLUG_ORIGINAL = 'loja';
  public const MASTER_EMAIL = 'admin@odinfocus.com.br';
  public const MASTER_VERIFICACAO = 'copjoao@gmail.com';

  /** Endereços que nenhuma loja pode usar (pastas do site e nomes da própria plataforma). */
  public const RESERVADOS = [
    'loja', 'img', 'api', 'admin', 'entrar', 'master', 'plataforma', 'uploads', 'css', 'js', 'www', 'mail',
    'webmail', 'cpanel', 'whm', 'ftp', 'static', 'assets', 'suporte', 'ajuda', 'blog', 'app', 'cgi-bin', 'lojas',
  ];

  private const VERSAO = 1;
  private const CODIGO_MINUTOS = 15;
  private const CODIGO_TENTATIVAS = 5;
  private const LOGIN_MAX = 8;
  private const LOGIN_MINUTOS = 15;

  private static bool $instalada = false;

  // ---------------- Instalação ----------------

  /** Cria as tabelas da plataforma, registra a loja original e o administrador master (uma vez só). */
  public static function instalar(): void
  {
    if (self::$instalada) return;
    try {
      $versao = (int)Banco::valor("SELECT valor FROM plataforma_config WHERE chave = 'versao'");
    } catch (PDOException $e) {
      $versao = 0;
    }
    if ($versao < self::VERSAO) {
      $prefixo = Banco::prefixo();
      Banco::usarPrefixo('');
      Banco::executarArquivo(Banco::pdo(), __DIR__ . '/plataforma.sql');
      if (!Banco::valor('SELECT id FROM plataforma_lojas WHERE slug = ?', [self::SLUG_ORIGINAL])) {
        // A loja que já existia vira a loja 1, sem mover nada. Numa instalação nova, as tabelas dela são criadas agora.
        $existia = (bool)Banco::valor(
          "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'configuracoes'"
        );
        if (!$existia) self::criarTabelasDaLoja();
        $nome = $existia ? (string)(Banco::valor("SELECT valor FROM configuracoes WHERE chave = 'loja_nome'") ?? '') : '';
        Banco::executar(
          "INSERT INTO plataforma_lojas (id, slug, nome, prefixo, status) VALUES (1, ?, ?, '', 'ativa')",
          [self::SLUG_ORIGINAL, $nome !== '' ? $nome : 'Odin Focus']
        );
      }
      if (!Banco::valor('SELECT id FROM plataforma_admins WHERE email = ?', [self::MASTER_EMAIL])) {
        Banco::executar(
          'INSERT INTO plataforma_admins (nome, email, email_verificacao) VALUES (?, ?, ?)',
          ['Administrador Odin Focus', self::MASTER_EMAIL, self::MASTER_VERIFICACAO]
        );
      }
      self::salvarConfig('versao', (string)self::VERSAO);
      Banco::usarPrefixo($prefixo);
    }
    self::$instalada = true;
    // Guarda o endereço do site para a rotina diária (que roda sem navegador) montar os links.
    if (!empty($_SERVER['HTTP_HOST']) && self::config('url_base') === '') self::salvarConfig('url_base', self::urlDaRequisicao());
  }

  private static function config(string $chave): string
  {
    return (string)(Banco::valor('SELECT valor FROM plataforma_config WHERE chave = ?', [$chave]) ?? '');
  }

  private static function salvarConfig(string $chave, string $valor): void
  {
    Banco::executar('INSERT INTO plataforma_config (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)', [$chave, $valor]);
  }

  private static function urlDaRequisicao(): string
  {
    return (Http::https() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
  }

  /** https://www.odinfocus.com.br (sem barra no fim). */
  public static function urlBase(): string
  {
    $fixo = trim((string)(Config::arquivo()['url_base'] ?? ''));
    if ($fixo !== '') return rtrim($fixo, '/');
    if (!empty($_SERVER['HTTP_HOST'])) return self::urlDaRequisicao();
    try {
      return rtrim(self::config('url_base'), '/');
    } catch (Throwable $e) {
      return '';
    }
  }

  /** Tabelas de uma loja nova: núcleo + cada módulo (com o prefixo da loja ativa). */
  private static function criarTabelasDaLoja(): void
  {
    Banco::executarArquivo(Banco::pdo(), API_RAIZ . '/nucleo/tabelas.sql');
    foreach (Modulos::instalados() as $m) Banco::executarArquivo(Banco::pdo(), $m->arquivoSql());
  }

  // ---------------- Lojas ----------------

  /** Loja do endereço acessado: odinfocus.com.br/{endereço}/... (a pasta /loja/ é a loja original). */
  public static function lojaDaRequisicao(): array
  {
    $caminho = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $slug = strtolower(explode('/', trim($caminho, '/'))[0] ?? '');
    $loja = $slug !== '' ? Banco::um('SELECT * FROM plataforma_lojas WHERE slug = ?', [$slug]) : null;
    if (!$loja) throw new ErroApi('Loja não encontrada.', 404);
    return $loja;
  }

  public static function lojas(?string $status = null): array
  {
    return $status
      ? Banco::todos('SELECT * FROM plataforma_lojas WHERE status = ? ORDER BY id', [$status])
      : Banco::todos('SELECT * FROM plataforma_lojas ORDER BY id');
  }

  public static function loja(int $id): array
  {
    $l = Banco::um('SELECT * FROM plataforma_lojas WHERE id = ?', [$id]);
    if (!$l) throw new ErroApi('Loja não encontrada.', 404);
    return $l;
  }

  /** Endereço válido: letras minúsculas, números e hífen (3 a 40), fora dos reservados e das pastas do site. */
  public static function validarSlug($v): string
  {
    $slug = strtolower(trim((string)$v));
    if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/', $slug) || strpos($slug, '--') !== false) {
      throw new ErroApi('Endereço inválido: use de 3 a 40 letras minúsculas, números e hífen (ex.: tenis-de-mesa).', 422, ['campo' => 'slug']);
    }
    if (in_array($slug, self::RESERVADOS, true) || file_exists(dirname(LOJA_RAIZ) . '/' . $slug)) {
      throw new ErroApi('Este endereço é reservado. Escolha outro.', 422, ['campo' => 'slug']);
    }
    if (Banco::valor('SELECT id FROM plataforma_lojas WHERE slug = ?', [$slug])) {
      throw new ErroApi('Já existe uma loja com este endereço.', 409, ['campo' => 'slug']);
    }
    return $slug;
  }

  /**
   * Cadastra uma loja nova (só o master): tabelas próprias, pasta de imagens, nome e o primeiro
   * acesso ao painel dela (perfil "administrador": cuida da loja; as chaves de pagamento ficam com o master).
   */
  public static function criarLoja(array $d): array
  {
    $nome = Validacao::texto($d, 'nome', 'Nome da loja', 120);
    $slug = self::validarSlug($d['slug'] ?? '');
    $admNome = Validacao::texto($d, 'admin_nome', 'Nome do administrador da loja', 100);
    $admEmail = Validacao::email($d['admin_email'] ?? '', 'admin_email');
    $admSenha = Auth::validarSenha((string)($d['admin_senha'] ?? ''));

    $id = (int)Banco::valor('SELECT COALESCE(MAX(id), 0) + 1 FROM plataforma_lojas');
    $prefixo = 'l' . $id . '_';
    if (Banco::valor('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?', [$prefixo . '%'])) {
      throw new ErroApi('Já existem tabelas com o prefixo ' . $prefixo . ' no banco. Fale com o suporte técnico.', 409);
    }
    Banco::executar("INSERT INTO plataforma_lojas (id, slug, nome, prefixo, status) VALUES (?, ?, ?, ?, 'ativa')", [$id, $slug, $nome, $prefixo]);
    $loja = self::loja($id);
    try {
      LojaAtual::usar($loja);
      self::criarTabelasDaLoja();
      Migracoes::aplicar();
      Config::salvar(['loja_nome' => $nome]);
      Banco::executar(
        "INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, 'administrador')",
        [$admNome, $admEmail, password_hash($admSenha, PASSWORD_DEFAULT)]
      );
      $pasta = LojaAtual::uploads();
      if (!is_dir($pasta)) @mkdir($pasta, 0755, true);
    } catch (Throwable $e) {
      self::desfazerLoja($loja);
      throw $e;
    } finally {
      Banco::usarPrefixo('');
      Config::limparCache();
    }
    return $loja;
  }

  /** Se o cadastro falhar no meio: apaga as tabelas criadas e o registro (nunca a loja original). */
  private static function desfazerLoja(array $loja): void
  {
    if ($loja['prefixo'] === '' || !preg_match('/^l[0-9]+_$/', $loja['prefixo'])) return;
    try {
      Banco::pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
      foreach (Banco::TABELAS_DA_LOJA as $t) Banco::pdo()->exec('DROP TABLE IF EXISTS `' . $loja['prefixo'] . $t . '`');
      Banco::pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
      Banco::executar('DELETE FROM plataforma_lojas WHERE id = ?', [$loja['id']]);
    } catch (Throwable $e) {
      error_log('Falha ao desfazer a loja ' . $loja['slug'] . ': ' . $e->getMessage());
    }
  }

  /** Resumo de uma loja para o painel master (lido nas tabelas dela). */
  public static function resumoLoja(array $l): array
  {
    $r = [
      'id' => (int)$l['id'],
      'slug' => $l['slug'],
      'nome' => $l['nome'],
      'status' => $l['status'],
      'criado_em' => $l['criado_em'],
      'url' => rtrim(self::urlBase(), '/') . '/' . $l['slug'] . '/',
      'original' => $l['slug'] === self::SLUG_ORIGINAL,
      'pedidos_pagos' => 0,
      'faturamento_30d' => 0.0,
      'administradores' => [],
    ];
    $prefixo = Banco::prefixo();
    try {
      LojaAtual::usar($l);
      $r['pedidos_pagos'] = (int)Banco::valor("SELECT COUNT(*) FROM pedidos WHERE status = 'pago'");
      $r['faturamento_30d'] = (float)Banco::valor("SELECT COALESCE(SUM(total), 0) FROM pedidos WHERE status = 'pago' AND pago_em >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
      $r['administradores'] = Banco::todos('SELECT nome, email, perfil FROM administradores ORDER BY nome');
    } catch (Throwable $e) {
      $r['erro'] = 'Não foi possível ler os dados desta loja.';
    } finally {
      Banco::usarPrefixo($prefixo);
      Config::limparCache();
    }
    return $r;
  }

  /** Cria ou redefine um acesso ao painel da loja (o master também resolve "esqueci a senha" dos lojistas). */
  public static function definirAcessoDaLoja(array $loja, array $d): array
  {
    $nome = Validacao::texto($d, 'nome', 'Nome', 100);
    $email = Validacao::email($d['email'] ?? '');
    $senha = Auth::validarSenha((string)($d['senha'] ?? ''));
    $prefixo = Banco::prefixo();
    try {
      LojaAtual::usar($loja);
      $id = Banco::valor('SELECT id FROM administradores WHERE email = ?', [$email]);
      if ($id) {
        Banco::executar('UPDATE administradores SET nome = ?, senha_hash = ? WHERE id = ?', [$nome, password_hash($senha, PASSWORD_DEFAULT), $id]);
        return ['ok' => true, 'acao' => 'senha redefinida'];
      }
      Banco::executar(
        "INSERT INTO administradores (nome, email, senha_hash, perfil) VALUES (?, ?, ?, 'administrador')",
        [$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]
      );
      return ['ok' => true, 'acao' => 'acesso criado'];
    } finally {
      Banco::usarPrefixo($prefixo);
      Config::limparCache();
    }
  }

  // ---------------- Administrador master ----------------

  public static function master(): ?array
  {
    Auth::iniciarSessao();
    $id = $_SESSION['master_id'] ?? null;
    if (!$id) return null;
    return Banco::um('SELECT id, nome, email, email_verificacao, ultimo_acesso FROM plataforma_admins WHERE id = ?', [$id]);
  }

  public static function exigirMaster(): array
  {
    Auth::iniciarSessao();
    if (!empty($_SESSION['master_id']) && time() - ($_SESSION['ultimo_uso'] ?? 0) > 8 * 3600) Auth::sair();
    $m = self::master();
    if (!$m) throw new ErroApi('Sua sessão expirou. Entre novamente.', 401);
    $_SESSION['ultimo_uso'] = time();
    return $m;
  }

  private static function limitar(string $chave, int $max, int $minutos, string $mensagem): void
  {
    Banco::executar('DELETE FROM plataforma_tentativas WHERE momento < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    $n = (int)Banco::valor(
      "SELECT COUNT(*) FROM plataforma_tentativas WHERE chave = ? AND momento > DATE_SUB(NOW(), INTERVAL {$minutos} MINUTE)",
      [$chave]
    );
    if ($n >= $max) throw new ErroApi($mensagem, 429);
  }

  private static function registrarTentativa(string $chave): void
  {
    Banco::executar('INSERT INTO plataforma_tentativas (chave) VALUES (?)', [$chave]);
  }

  /**
   * Login único ("Entrar"): confere o master e depois os administradores de cada loja ativa.
   * Devolve para onde ir: o painel master, o painel da loja ou a lista, se a pessoa administra várias.
   */
  public static function entrar(string $email, string $senha): array
  {
    $chave = 'login:' . Http::ip();
    self::limitar($chave, self::LOGIN_MAX, self::LOGIN_MINUTOS, 'Muitas tentativas. Aguarde ' . self::LOGIN_MINUTOS . ' minutos e tente de novo.');
    $email = mb_strtolower(trim($email));

    $m = Banco::um('SELECT * FROM plataforma_admins WHERE email = ?', [$email]);
    if ($m) {
      if ($m['senha_hash'] === null) {
        throw new ErroApi('Primeiro acesso: crie a sua senha. Clique em "Criar ou recuperar a senha".', 409, ['criar_senha' => true]);
      }
      if (!password_verify($senha, $m['senha_hash'])) {
        self::registrarTentativa($chave);
        throw new ErroApi('E-mail ou senha incorretos.', 401);
      }
      self::abrirSessaoMaster((int)$m['id']);
      return ['destino' => 'master', 'url' => 'master/'];
    }

    $destinos = [];
    $suspensas = [];
    $prefixo = Banco::prefixo();
    try {
      foreach (self::lojas() as $l) {
        LojaAtual::usar($l);
        $a = Banco::um('SELECT id, senha_hash FROM administradores WHERE email = ?', [$email]);
        if ($a && password_verify($senha, (string)$a['senha_hash'])) {
          if ($l['status'] !== 'ativa') {
            $suspensas[] = $l['nome'];
            continue;
          }
          Auth::entrarNaLoja((int)$l['id'], (int)$a['id']);
          Banco::executar('UPDATE administradores SET ultimo_acesso = NOW() WHERE id = ?', [$a['id']]);
          $destinos[] = ['nome' => $l['nome'], 'slug' => $l['slug'], 'url' => '../' . $l['slug'] . '/admin/'];
        }
      }
    } finally {
      Banco::usarPrefixo($prefixo);
      Config::limparCache();
    }
    if (!$destinos && $suspensas) {
      throw new ErroApi('A loja ' . implode(', ', $suspensas) . ' está suspensa no momento. Fale com a Odin Focus.', 403);
    }
    if (!$destinos) {
      self::registrarTentativa($chave);
      throw new ErroApi('E-mail ou senha incorretos.', 401);
    }
    return count($destinos) === 1
      ? ['destino' => 'loja', 'url' => $destinos[0]['url']]
      : ['destino' => 'escolher', 'lojas' => $destinos];
  }

  private static function abrirSessaoMaster(int $id): void
  {
    Auth::iniciarSessao();
    session_regenerate_id(true);
    $_SESSION['master_id'] = $id;
    $_SESSION['ultimo_uso'] = time();
    Banco::executar('UPDATE plataforma_admins SET ultimo_acesso = NOW() WHERE id = ?', [$id]);
  }

  /**
   * Envia um código de 6 dígitos para o e-mail de verificação do master.
   * - "senha": criar a primeira senha ou recuperar (sem estar logado; informa o e-mail de login).
   * - "alterar": trocar a senha estando logado.
   * Por segurança, a resposta é a mesma se o e-mail não for de um master.
   */
  public static function enviarCodigo(string $finalidade, string $email = ''): array
  {
    if (!in_array($finalidade, ['senha', 'alterar'], true)) throw new ErroApi('Pedido inválido.', 422);
    self::limitar('codigo:' . Http::ip(), 5, 60, 'Muitos pedidos de código. Aguarde uma hora e tente de novo.');
    self::registrarTentativa('codigo:' . Http::ip());
    $m = $finalidade === 'alterar'
      ? Banco::um('SELECT * FROM plataforma_admins WHERE id = ?', [self::exigirMaster()['id']])
      : Banco::um('SELECT * FROM plataforma_admins WHERE email = ?', [mb_strtolower(trim($email))]);
    if (!$m) {
      return ['ok' => true, 'mensagem' => 'Se este e-mail for de um administrador da plataforma, o código foi enviado ao e-mail de verificação.'];
    }
    $recentes = (int)Banco::valor('SELECT COUNT(*) FROM plataforma_codigos WHERE admin_id = ? AND criado_em > DATE_SUB(NOW(), INTERVAL 15 MINUTE)', [$m['id']]);
    if ($recentes >= 3) throw new ErroApi('Já enviamos 3 códigos nos últimos 15 minutos. Confira o e-mail (e o spam) ou aguarde.', 429);

    $codigo = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    Banco::executar('UPDATE plataforma_codigos SET usado_em = NOW() WHERE admin_id = ? AND usado_em IS NULL', [$m['id']]);
    Banco::executar(
      'INSERT INTO plataforma_codigos (admin_id, finalidade, codigo_hash, expira_em) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . self::CODIGO_MINUTOS . ' MINUTE))',
      [$m['id'], $finalidade, password_hash($codigo, PASSWORD_DEFAULT)]
    );
    $acao = $finalidade === 'alterar' ? 'alterar a senha' : ($m['senha_hash'] === null ? 'criar a senha' : 'recuperar a senha');
    $html = '<p>Olá!</p><p>Use o código abaixo para <strong>' . $acao . '</strong> do administrador master da plataforma Odin Focus ('
      . htmlspecialchars($m['email']) . '):</p>'
      . '<p style="font-size:30px;letter-spacing:8px;font-weight:700;color:#1B2D42;margin:18px 0">' . $codigo . '</p>'
      . '<p>O código vale ' . self::CODIGO_MINUTOS . ' minutos. Se não foi você que pediu, ignore este e-mail: a senha continua a mesma.</p>'
      . '<p style="color:#777;font-size:12px">Pedido feito do IP ' . htmlspecialchars(Http::ip()) . ' em ' . date('d/m/Y H:i') . '.</p>';
    if (!self::email($m['email_verificacao'], 'Odin Focus: código para ' . $acao, $html)) {
      throw new ErroApi('Não foi possível enviar o e-mail com o código agora. Tente de novo em alguns minutos.', 502);
    }
    return ['ok' => true, 'mensagem' => 'Enviamos um código de 6 dígitos para ' . self::mascararEmail($m['email_verificacao']) . '.'];
  }

  /** Confere o código e grava a nova senha (e entra no painel master). */
  public static function definirSenha(string $codigo, string $senha, string $email = ''): array
  {
    $logado = self::master();
    $m = $logado
      ? Banco::um('SELECT * FROM plataforma_admins WHERE id = ?', [$logado['id']])
      : Banco::um('SELECT * FROM plataforma_admins WHERE email = ?', [mb_strtolower(trim($email))]);
    $erro = new ErroApi('Código inválido ou vencido. Peça um novo código.', 422, ['campo' => 'codigo']);
    if (!$m) throw $erro;
    $c = Banco::um(
      'SELECT * FROM plataforma_codigos WHERE admin_id = ? AND usado_em IS NULL AND expira_em > NOW() ORDER BY id DESC LIMIT 1',
      [$m['id']]
    );
    if (!$c || (int)$c['tentativas'] >= self::CODIGO_TENTATIVAS) throw $erro;
    if (!password_verify(preg_replace('/\D/', '', $codigo), $c['codigo_hash'])) {
      Banco::executar('UPDATE plataforma_codigos SET tentativas = tentativas + 1 WHERE id = ?', [$c['id']]);
      throw $erro;
    }
    $nova = Auth::validarSenha($senha);
    Banco::executar('UPDATE plataforma_codigos SET usado_em = NOW() WHERE id = ?', [$c['id']]);
    Banco::executar('UPDATE plataforma_admins SET senha_hash = ? WHERE id = ?', [password_hash($nova, PASSWORD_DEFAULT), $m['id']]);
    self::abrirSessaoMaster((int)$m['id']);
    self::email(
      $m['email_verificacao'],
      'Odin Focus: a senha do administrador master foi alterada',
      '<p>A senha do administrador master (' . htmlspecialchars($m['email']) . ') foi alterada em ' . date('d/m/Y H:i')
      . ' (IP ' . htmlspecialchars(Http::ip()) . ').</p><p>Se não foi você, peça um código de recuperação imediatamente.</p>'
    );
    return ['ok' => true];
  }

  public static function mascararEmail(string $email): string
  {
    [$u, $d] = explode('@', $email, 2) + [1 => ''];
    return mb_substr($u, 0, 1) . '***' . (mb_strlen($u) > 2 ? mb_substr($u, -1) : '') . '@' . $d;
  }

  /** E-mail da plataforma (remetente nao-responda@ do domínio do site). */
  public static function email(string $para, string $assunto, string $corpo): bool
  {
    $html = '<!DOCTYPE html><html lang="pt-BR"><body style="margin:0;background:#F7F6F2;font-family:Helvetica,Arial,sans-serif;color:#333">'
      . '<div style="max-width:520px;margin:24px auto;background:#fff;border-radius:8px;overflow:hidden">'
      . '<div style="background:#1B2D42;color:#F7F6F2;padding:18px 24px;letter-spacing:3px">ODIN FOCUS</div>'
      . '<div style="padding:24px;font-size:15px;line-height:1.6">' . $corpo . '</div></div></body></html>';
    // Testes locais: grava o e-mail num arquivo em vez de enviar (config.php › "email_arquivo").
    $arquivo = (string)(Config::arquivo()['email_arquivo'] ?? '');
    if ($arquivo !== '') {
      return (bool)file_put_contents($arquivo, "Para: {$para}\nAssunto: {$assunto}\n{$html}\n\n", FILE_APPEND);
    }
    // Com SMTP configurado (config.php › "smtp"), envia autenticado pela caixa do domínio (chega na caixa de entrada).
    if (Smtp::configurado()) return Smtp::enviar($para, $assunto, $html, 'Odin Focus');
    $host = preg_replace('/^www\./', '', (string)parse_url(self::urlBase(), PHP_URL_HOST)) ?: 'odinfocus.com.br';
    $de = "nao-responda@{$host}";
    $cab = implode("\r\n", [
      'MIME-Version: 1.0',
      'Content-Type: text/html; charset=UTF-8',
      'From: =?UTF-8?B?' . base64_encode('Odin Focus') . "?= <{$de}>",
    ]);
    $ok = @mail($para, '=?UTF-8?B?' . base64_encode($assunto) . '?=', $html, $cab, '-f' . $de);
    if (!$ok) error_log("Falha ao enviar e-mail da plataforma para {$para}: {$assunto}");
    return $ok;
  }
}
