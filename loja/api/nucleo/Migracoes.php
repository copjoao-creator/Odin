<?php
/**
 * Atualizações do banco para lojas já instaladas (o instalar.php só roda uma vez).
 * Cada passo confere antes se a coluna já existe, então pode rodar quantas vezes for preciso.
 * A versão aplicada fica na configuração "versao_banco".
 */
final class Migracoes
{
  private const VERSAO = 7;

  /** Trechos dos textos padrão antigos que citavam o Mercado Pago (v6: todos os pagamentos passam a ser pelo Asaas). */
  private const SEM_MERCADO_PAGO = [' via Mercado Pago' => '', ' pelo Mercado Pago' => ''];

  /** Textos padrão da loja até a v4 (a loja passou a vir com textos neutros para qualquer cliente). */
  private const TEXTOS_ANTIGOS = [
    'loja_nome' => 'Odin Focus',
    'loja_titulo' => 'Qualidade que você sente em cada detalhe',
    'loja_subtitulo' => 'Produtos e serviços selecionados, pagamento seguro pelo Mercado Pago e atendimento de verdade, do pedido à entrega.',
    'loja_sobre' => 'A Odin Focus nasceu para oferecer produtos e serviços de alto padrão com um atendimento próximo e transparente. Cuidamos de cada pedido como se fosse nosso.',
    'aviso_topo' => 'Pagamento seguro via Mercado Pago · Pix, boleto e cartões',
  ];

  public static function aplicar(): void
  {
    $versao = (int)Config::get('versao_banco');
    if ($versao >= self::VERSAO) return;

    // v2: gateway de cada pagamento (coluna app) e assinaturas cobradas no cartão.
    self::coluna('pagamentos', 'app', "VARCHAR(12) NOT NULL DEFAULT 'loja' AFTER mp_id");
    self::coluna('pedidos', 'mp_assinatura', 'VARCHAR(40) NULL AFTER forma_pagamento');
    if (self::tabela('assinaturas')) {
      self::coluna('assinaturas', 'mp_assinatura', 'VARCHAR(40) NULL AFTER pedido_renovacao');
      if (!self::indice('assinaturas', 'ix_assinaturas_mp')) Banco::executar('ALTER TABLE assinaturas ADD KEY ix_assinaturas_mp (mp_assinatura)');
    }

    // v3: data final das assinaturas (definida no painel) e a situação "encerrada".
    self::coluna('pedidos', 'assinatura_data_final', 'DATE NULL AFTER mp_assinatura');
    if (self::tabela('assinaturas')) {
      self::coluna('assinaturas', 'data_final', 'DATE NULL AFTER proxima_cobranca');
      $tipo = (string)Banco::valor(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'status'", [Banco::t('assinaturas')]
      );
      if (strpos($tipo, 'encerrada') === false) {
        Banco::executar("ALTER TABLE assinaturas MODIFY COLUMN status ENUM('ativa','atrasada','cancelada','encerrada') NOT NULL DEFAULT 'ativa'");
      }
    }

    // v4: pedido feito "com os dados do cadastro" (pelo CPF) mostra os dados do cliente mascarados.
    self::coluna('pedidos', 'dados_protegidos', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER assinatura_data_final');

    // v5: loja personalizável (white-label) e perfis de acesso.
    // - Quem já administrava a loja vira "tecnico" (vê as chaves de pagamento); novos acessos nascem "administrador".
    // - Lojas já instaladas guardam os textos que estavam usando, antes de os padrões ficarem neutros.
    if (!self::colunaExiste('administradores', 'perfil')) {
      self::coluna('administradores', 'perfil', "VARCHAR(20) NOT NULL DEFAULT 'administrador' AFTER senha_hash");
      Banco::executar("UPDATE administradores SET perfil = 'tecnico'");
    }
    if ($versao >= 1 && $versao < 5) {
      $salvos = array_column(Banco::todos('SELECT chave FROM configuracoes'), 'chave');
      $manter = array_diff_key(self::TEXTOS_ANTIGOS, array_flip($salvos));
      if ($manter) Config::salvar($manter);
    }

    // v6: todos os pagamentos pelo Asaas (Pix, boleto, cartão e assinaturas).
    // - O id do parcelamento do Asaas (UUID) tem 36 caracteres.
    // - As chaves do Mercado Pago saem do banco; os pagamentos antigos ficam só no histórico.
    // - Os textos padrão antigos deixam de citar o Mercado Pago.
    if ($versao < 6) {
      Banco::executar("ALTER TABLE pagamentos MODIFY COLUMN mp_id VARCHAR(60) NOT NULL, MODIFY COLUMN app VARCHAR(12) NOT NULL DEFAULT 'asaas'");
      Banco::executar('DELETE FROM configuracoes WHERE chave IN (' . implode(', ', array_fill(0, count(Config::ANTIGAS), '?')) . ')', Config::ANTIGAS);
      $textos = [];
      foreach (['aviso_topo', 'loja_subtitulo'] as $k) {
        $atual = (string)Banco::valor('SELECT valor FROM configuracoes WHERE chave = ?', [$k]);
        $novo = strtr($atual, self::SEM_MERCADO_PAGO);
        if ($novo !== $atual) $textos[$k] = $novo;
      }
      if ($textos) Config::salvar($textos);
    }

    // v7: recebimentos informados pela loja (dinheiro, maquininha de outra empresa, Pix em outra conta...).
    $formas = "ENUM('pix','boleto','credito','debito','dinheiro','transferencia','outro')";
    Banco::executar("ALTER TABLE pagamentos MODIFY COLUMN metodo {$formas} NOT NULL");
    Banco::executar("ALTER TABLE pedidos MODIFY COLUMN forma_pagamento {$formas} NULL");
    self::coluna('pagamentos', 'observacao', 'VARCHAR(255) NULL AFTER aprovado_em');
    self::coluna('pagamentos', 'registrado_por', 'VARCHAR(100) NULL AFTER observacao');

    Config::salvar(['versao_banco' => (string)self::VERSAO]);
  }

  private static function tabela(string $tabela): bool
  {
    return (bool)Banco::valor(
      'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
      [Banco::t($tabela)]
    );
  }

  private static function colunaExiste(string $tabela, string $coluna): bool
  {
    return (bool)Banco::valor(
      'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
      [Banco::t($tabela), $coluna]
    );
  }

  private static function coluna(string $tabela, string $coluna, string $definicao): void
  {
    if (!self::colunaExiste($tabela, $coluna)) Banco::executar("ALTER TABLE {$tabela} ADD COLUMN {$coluna} {$definicao}");
  }

  private static function indice(string $tabela, string $indice): bool
  {
    return (bool)Banco::valor(
      'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
      [Banco::t($tabela), $indice]
    );
  }
}
