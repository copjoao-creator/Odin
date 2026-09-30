<?php
/**
 * A loja desta requisição (plataforma com várias lojas).
 * Cada loja fica em odinfocus.com.br/{endereço}/ e tem as próprias tabelas (prefixo), a própria
 * pasta de imagens e o próprio painel. A loja 1, a original, fica em /loja/, sem prefixo e com as
 * imagens em loja/uploads/ — por isso nada dela mudou de lugar.
 */
final class LojaAtual
{
  private static ?array $loja = null;

  /** Ativa a loja: tabelas, pasta de imagens e endereço passam a ser os dela. */
  public static function usar(array $loja): void
  {
    self::$loja = $loja;
    Banco::usarPrefixo((string)$loja['prefixo']);
    Config::limparCache();
  }

  public static function ativa(): bool
  {
    return self::$loja !== null;
  }

  public static function dados(): array
  {
    if (!self::$loja) throw new ErroApi('Loja não encontrada.', 404);
    return self::$loja;
  }

  public static function id(): int
  {
    return (int)self::dados()['id'];
  }

  public static function slug(): string
  {
    return (string)self::dados()['slug'];
  }

  /** Pasta das imagens enviadas no painel. */
  public static function uploads(): string
  {
    $slug = self::$loja ? self::slug() : Plataforma::SLUG_ORIGINAL;
    return $slug === Plataforma::SLUG_ORIGINAL ? LOJA_RAIZ . '/uploads' : LOJA_RAIZ . '/uploads/lojas/' . $slug;
  }

  /** Endereço público da loja (https://www.odinfocus.com.br/{endereço}/). */
  public static function url(): string
  {
    $base = Plataforma::urlBase();
    return $base === '' ? '' : $base . '/' . (self::$loja ? self::slug() : Plataforma::SLUG_ORIGINAL) . '/';
  }
}
