<?php
/** Login do painel administrativo: sessão PHP, proteção CSRF e limite de tentativas por IP. */
final class Auth
{
  private const MAX_TENTATIVAS = 5;
  private const JANELA_MINUTOS = 15;

  public static function iniciarSessao(): void
  {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('ODINLOJA');
    session_set_cookie_params([
      'lifetime' => 0,
      'path' => '/',
      'secure' => Http::https(),
      'httponly' => true,
      'samesite' => 'Strict',
    ]);
    session_start();
  }

  public static function admin(): ?array
  {
    self::iniciarSessao();
    // Plataforma: a mesma sessão guarda um acesso por loja ($_SESSION['lojas'][id da loja]) e,
    // se houver, o do administrador master, que entra no painel de qualquer loja como "tecnico".
    $id = $_SESSION['lojas'][LojaAtual::id()] ?? null;
    $master = $_SESSION['master_id'] ?? null;
    if (!$id && !$master) return null;
    // Sessões expiram após 8 horas sem uso.
    if (time() - ($_SESSION['ultimo_uso'] ?? 0) > 8 * 3600) {
      self::sair();
      return null;
    }
    $_SESSION['ultimo_uso'] = time();
    if ($id) {
      $a = Banco::um('SELECT id, nome, email, perfil FROM administradores WHERE id = ?', [$id]);
      if ($a) return $a;
    }
    $m = $master ? Plataforma::master() : null;
    return $m ? ['id' => 0, 'nome' => $m['nome'], 'email' => $m['email'], 'perfil' => 'tecnico', 'master' => true] : null;
  }

  public static function exigirAdmin(): array
  {
    $a = self::admin();
    if (!$a) throw new ErroApi('Sua sessão expirou. Entre novamente.', 401);
    return $a;
  }

  /** Perfil "tecnico": quem instala e mantém a loja (vê e altera as chaves de pagamento). */
  public static function ehTecnico(?array $a = null): bool
  {
    $a = $a ?? self::admin();
    return $a !== null && ($a['perfil'] ?? '') === 'tecnico';
  }

  public static function exigirTecnico(): array
  {
    $a = self::exigirAdmin();
    if (!self::ehTecnico($a)) throw new ErroApi('Só o acesso técnico pode fazer isso. Fale com o suporte técnico da loja.', 403);
    return $a;
  }

  public static function csrf(): string
  {
    self::iniciarSessao();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
  }

  public static function verificarCsrf(): void
  {
    $enviado = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($enviado === '' || !hash_equals(self::csrf(), $enviado)) {
      throw new ErroApi('Sessão inválida. Recarregue a página e tente novamente.', 403);
    }
  }

  public static function entrar(string $email, string $senha): array
  {
    $ip = Http::ip();
    Banco::executar('DELETE FROM login_tentativas WHERE momento < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    $falhas = (int)Banco::valor(
      'SELECT COUNT(*) FROM login_tentativas WHERE ip = ? AND momento > DATE_SUB(NOW(), INTERVAL ' . self::JANELA_MINUTOS . ' MINUTE)',
      [$ip]
    );
    if ($falhas >= self::MAX_TENTATIVAS) {
      throw new ErroApi('Muitas tentativas. Aguarde ' . self::JANELA_MINUTOS . ' minutos e tente de novo.', 429);
    }

    $a = Banco::um('SELECT * FROM administradores WHERE email = ?', [mb_strtolower(trim($email))]);
    if (!$a || !password_verify($senha, $a['senha_hash'])) {
      Banco::executar('INSERT INTO login_tentativas (ip) VALUES (?)', [$ip]);
      throw new ErroApi('E-mail ou senha incorretos.', 401);
    }
    if (password_needs_rehash($a['senha_hash'], PASSWORD_DEFAULT)) {
      Banco::executar('UPDATE administradores SET senha_hash = ? WHERE id = ?', [password_hash($senha, PASSWORD_DEFAULT), $a['id']]);
    }
    Banco::executar('DELETE FROM login_tentativas WHERE ip = ?', [$ip]);
    Banco::executar('UPDATE administradores SET ultimo_acesso = NOW() WHERE id = ?', [$a['id']]);

    self::entrarNaLoja(LojaAtual::id(), (int)$a['id']);
    return ['id' => (int)$a['id'], 'nome' => $a['nome'], 'email' => $a['email'], 'perfil' => $a['perfil'] ?? 'administrador', 'csrf' => self::csrf()];
  }

  /** Abre a sessão do painel de uma loja (sem derrubar os acessos às outras lojas nem o do master). */
  public static function entrarNaLoja(int $lojaId, int $adminId): void
  {
    self::iniciarSessao();
    session_regenerate_id(true);
    $_SESSION['lojas'] = ($_SESSION['lojas'] ?? []);
    $_SESSION['lojas'][$lojaId] = $adminId;
    $_SESSION['ultimo_uso'] = time();
    unset($_SESSION['admin_id']); // sessão antiga (antes da plataforma)
  }

  public static function sair(): void
  {
    self::iniciarSessao();
    $_SESSION = [];
    session_destroy();
  }

  public static function validarSenha(string $senha): string
  {
    if (mb_strlen($senha) < 8) throw new ErroApi('A senha precisa ter pelo menos 8 caracteres.', 422, ['campo' => 'senha']);
    if (mb_strlen($senha) > 200) throw new ErroApi('Senha longa demais.', 422, ['campo' => 'senha']);
    return $senha;
  }
}
