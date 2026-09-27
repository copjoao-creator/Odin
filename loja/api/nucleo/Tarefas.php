<?php
/**
 * Rotina diária: cancela pedidos sem pagamento e gera as cobranças de renovação das assinaturas.
 * Roda pelo cron (api/cron.php) e, como reserva, quando o administrador abre o painel e a última
 * execução tem mais de 24 horas.
 */
final class Tarefas
{
  public static function executar(): array
  {
    $log = Pedidos::expirarAntigos();
    foreach (Modulos::instalados() as $m) {
      try {
        $log = array_merge($log, $m->tarefasDiarias());
      } catch (Throwable $e) {
        $log[] = $m->nome() . ': ' . $e->getMessage();
      }
    }
    Config::salvar(['ultima_tarefa_diaria' => date('Y-m-d H:i:s')]);
    return $log ?: ['Nada a fazer hoje.'];
  }

  public static function executarSeAtrasada(): void
  {
    $ultima = strtotime(Config::get('ultima_tarefa_diaria') ?: '2000-01-01');
    if (time() - $ultima < 24 * 3600) return;
    try {
      self::executar();
    } catch (Throwable $e) {
      error_log('Falha na rotina diária da loja: ' . $e->getMessage());
    }
  }
}
