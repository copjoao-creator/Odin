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

  public static function um(string $sql, array $params = []): ?array
  {
    $st = self::pdo()->prepare($sql);
    $st->execute($params);
    $linha = $st->fetch();
    return $linha === false ? null : $linha;
  }

  public static function todos(string $sql, array $params = []): array
  {
    $st = self::pdo()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
  }

  public static function valor(string $sql, array $params = [])
  {
    $st = self::pdo()->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
  }

  /** Executa e devolve quantas linhas foram afetadas. */
  public static function executar(string $sql, array $params = []): int
  {
    $st = self::pdo()->prepare($sql);
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
      if (trim($comando) !== '') $pdo->exec($comando);
    }
  }
}
