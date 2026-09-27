<?php
/**
 * Atualizações do banco para lojas já instaladas (o instalar.php só roda uma vez).
 * Cada passo confere antes se a coluna já existe, então pode rodar quantas vezes for preciso.
 * A versão aplicada fica na configuração "versao_banco".
 */
final class Migracoes
{
  private const VERSAO = 2;

  public static function aplicar(): void
  {
    if ((int)Config::get('versao_banco') >= self::VERSAO) return;

    // v2: duas aplicações do Mercado Pago e assinaturas cobradas no cartão.
    self::coluna('pagamentos', 'app', "VARCHAR(12) NOT NULL DEFAULT 'loja' AFTER mp_id");
    self::coluna('pedidos', 'mp_assinatura', 'VARCHAR(40) NULL AFTER forma_pagamento');
    if (self::tabela('assinaturas')) {
      self::coluna('assinaturas', 'mp_assinatura', 'VARCHAR(40) NULL AFTER pedido_renovacao');
      if (!self::indice('assinaturas', 'ix_assinaturas_mp')) Banco::executar('ALTER TABLE assinaturas ADD KEY ix_assinaturas_mp (mp_assinatura)');
    }

    Config::salvar(['versao_banco' => (string)self::VERSAO]);
  }

  private static function tabela(string $tabela): bool
  {
    return (bool)Banco::valor(
      'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
      [$tabela]
    );
  }

  private static function coluna(string $tabela, string $coluna, string $definicao): void
  {
    $existe = Banco::valor(
      'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
      [$tabela, $coluna]
    );
    if (!$existe) Banco::executar("ALTER TABLE {$tabela} ADD COLUMN {$coluna} {$definicao}");
  }

  private static function indice(string $tabela, string $indice): bool
  {
    return (bool)Banco::valor(
      'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
      [$tabela, $indice]
    );
  }
}
