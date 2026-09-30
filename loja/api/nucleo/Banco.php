<?php
/** Conexão com o MySQL (PDO) e atalhos para consultas com parâmetros. */
final class Banco
{
  private static ?PDO $pdo = null;

  public static function conectar(array $db): PDO
  {
    $dsn = 'mysql:host=' . ($db['host'] ?? 'localhost')
      . (!empty($db['porta']) ? ';port=' . (int)$db['porta'] : '')
      . ';dbname=' . $db['nome'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $db['usuario'], $db['senha'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    // Horário de Brasília (sem horário de verão desde 2019), igual ao do PHP.
    $pdo->exec("SET time_zone = '-03:00'");
    return $pdo;
  }

  public static function pdo(): PDO
  {
    if (self::$pdo === null) self::$pdo = self::conectar(Config::arquivo()['db']);
    return self::$pdo;
  }

  public static function usar(PDO $pdo): void
  {
    self::$pdo = $pdo;
  }

  /**
   * Plataforma com várias lojas no mesmo banco: cada loja tem as próprias tabelas com um prefixo
   * (ex.: "l2_pedidos"). A loja 1 (a original) não tem prefixo. O SQL do sistema continua escrito com
   * os nomes simples ("FROM pedidos") e é ajustado aqui, antes de ir para o banco.
   */
  public const TABELAS_DA_LOJA = [
    'configuracoes', 'administradores', 'login_tentativas', 'clientes', 'pedidos', 'pedido_itens',
    'pagamentos', 'produtos', 'produto_fotos', 'servicos', 'assinaturas',
  ];

  private static string $prefixo = '';

  public static function usarPrefixo(string $prefixo): void
  {
    if ($prefixo !== '' && !preg_match('/^l[0-9]{1,6}_$/', $prefixo)) throw new RuntimeException('Prefixo de loja inválido.');
    self::$prefixo = $prefixo;
  }

  public static function prefixo(): string
  {
    return self::$prefixo;
  }

  /** Nome real da tabela da loja atual (para consultas ao information_schema). */
  public static function t(string $tabela): string
  {
    return in_array($tabela, self::TABELAS_DA_LOJA, true) ? self::$prefixo . $tabela : $tabela;
  }

  /**
   * Põe o prefixo da loja nos nomes de tabela depois de FROM, JOIN, INTO, UPDATE, TABLE, EXISTS e
   * REFERENCES, e nos nomes das chaves estrangeiras (únicos no banco inteiro). Nomes que já têm
   * prefixo não mudam, então aplicar duas vezes não estraga nada.
   */
  public static function sql(string $sql): string
  {
    if (self::$prefixo === '') return $sql;
    $p = self::$prefixo;
    $sql = preg_replace_callback(
      '/\b(FROM|JOIN|INTO|UPDATE|TABLE|EXISTS|REFERENCES)(\s+)(`?)(' . implode('|', self::TABELAS_DA_LOJA) . ')\b/i',
      fn($m) => $m[1] . $m[2] . $m[3] . $p . $m[4],
      $sql
    );
    return preg_replace('/\bCONSTRAINT(\s+)(fk_)/i', 'CONSTRAINT$1' . $p . '$2', $sql);
  }

  /** prepare() já com o prefixo da loja (use no lugar de Banco::pdo()->prepare). */
  public static function preparar(string $sql): PDOStatement
  {
    return self::pdo()->prepare(self::sql($sql));
  }

  public static function um(string $sql, array $params = []): ?array
  {
    $st = self::preparar($sql);
    $st->execute($params);
    $linha = $st->fetch();
    return $linha === false ? null : $linha;
  }

  public static function todos(string $sql, array $params = []): array
  {
    $st = self::preparar($sql);
    $st->execute($params);
    return $st->fetchAll();
  }

  public static function valor(string $sql, array $params = [])
  {
    $st = self::preparar($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
  }

  /** Executa e devolve quantas linhas foram afetadas. */
  public static function executar(string $sql, array $params = []): int
  {
    $st = self::preparar($sql);
    $st->execute($params);
    return $st->rowCount();
  }

  public static function ultimoId(): int
  {
    return (int)self::pdo()->lastInsertId();
  }

  /** Roda $fn numa transação; transações aninhadas reaproveitam a de fora. */
  public static function transacao(callable $fn)
  {
    $pdo = self::pdo();
    if ($pdo->inTransaction()) return $fn();
    $pdo->beginTransaction();
    try {
      $r = $fn();
      $pdo->commit();
      return $r;
    } catch (Throwable $e) {
      $pdo->rollBack();
      throw $e;
    }
  }

  /** Erro de chave duplicada (CPF, e-mail ou código já cadastrados). */
  public static function duplicado(Throwable $e): bool
  {
    return $e instanceof PDOException && ($e->errorInfo[1] ?? 0) === 1062;
  }

  /** Erro de registro em uso (ex.: cliente com pedidos). */
  public static function emUso(Throwable $e): bool
  {
    return $e instanceof PDOException && in_array($e->errorInfo[1] ?? 0, [1451, 1217], true);
  }

  /** Executa um arquivo .sql com vários comandos separados por ";" no fim da linha. */
  public static function executarArquivo(PDO $pdo, string $arquivo): void
  {
    $sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($arquivo));
    foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $comando) {
      if (trim($comando) !== '') $pdo->exec(self::sql($comando));
    }
  }
}
