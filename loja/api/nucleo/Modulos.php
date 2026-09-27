<?php
/**
 * Contrato dos módulos de catálogo (produtos, serviços...).
 * O núcleo (clientes, pedidos, pagamentos) não conhece estoque nem assinaturas:
 * cada módulo cuida das próprias tabelas, rotas e do que acontece quando uma venda
 * é paga, estornada ou cancelada. Assim os módulos são independentes entre si e
 * podem ser desligados no painel (ou removidos apagando a pasta do módulo).
 */
interface ModuloCatalogo
{
  /** Identificador usado nos itens do pedido ("produto", "servico"). */
  public function tipo(): string;

  public function nome(): string;

  /** Arquivo .sql com as tabelas do módulo (executado pelo instalador). */
  public function arquivoSql(): string;

  public function rotas(Roteador $r): void;

  /**
   * Confere se o item pode ser vendido e devolve os dados congelados no pedido:
   * [descricao, preco (string), custo (string), entrega (bool), renovacao (?string)].
   */
  public function itemParaVenda(string $codigo, int $quantidade): array;

  /** Pedido pago: baixa de estoque, criação ou renovação de assinatura etc. */
  public function aoAprovar(array $item, array $pedido): void;

  /** Pagamento estornado depois de aprovado: desfaz o que aoAprovar fez. */
  public function aoReverter(array $item, array $pedido): void;

  /** Pedido cancelado ou expirado sem pagamento. */
  public function aoCancelar(array $item, array $pedido): void;

  /** Rotina diária (api/cron.php). Devolve mensagens para o registro. */
  public function tarefasDiarias(): array;

  /** Indicadores extras para o painel financeiro no período. */
  public function resumo(string $de, string $ate): array;
}

final class Modulos
{
  private static ?array $instalados = null;

  /** Todos os módulos presentes em api/modulos/, ligados ou não. */
  public static function instalados(): array
  {
    if (self::$instalados === null) {
      self::$instalados = [];
      foreach (glob(API_RAIZ . '/modulos/*/modulo.php') ?: [] as $arquivo) {
        $m = require $arquivo;
        if ($m instanceof ModuloCatalogo) self::$instalados[$m->tipo()] = $m;
      }
    }
    return self::$instalados;
  }

  public static function ligado(string $tipo): bool
  {
    return isset(self::instalados()[$tipo]) && Config::get('modulo_' . $tipo) !== '0';
  }

  /** Módulos ligados: aparecem na loja e aceitam novas vendas. */
  public static function ativos(): array
  {
    return array_filter(self::instalados(), fn($m) => self::ligado($m->tipo()));
  }

  /** Módulo de um item já vendido (funciona mesmo desligado, para concluir vendas antigas). */
  public static function doItem(string $tipo): ?ModuloCatalogo
  {
    return self::instalados()[$tipo] ?? null;
  }

  public static function paraVenda(string $tipo): ModuloCatalogo
  {
    if (!self::ligado($tipo)) throw new ErroApi('Este tipo de item não está disponível para venda.', 422);
    return self::instalados()[$tipo];
  }

  /** A primeira linha da descrição é o título do item na loja; o restante são os detalhes. */
  public static function tituloEDetalhes(string $descricao): array
  {
    $linhas = preg_split('/\n/', trim($descricao), 2);
    $titulo = trim($linhas[0] ?? '');
    if (mb_strlen($titulo) > 140) $titulo = rtrim(mb_substr($titulo, 0, 139)) . '…';
    return [$titulo, trim($linhas[1] ?? '')];
  }
}
