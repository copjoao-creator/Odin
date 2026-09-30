<?php
/*
 * Rotina diária de todas as lojas: cancela pedidos sem pagamento e gera as cobranças de renovação
 * das assinaturas (serviços recorrentes), enviando o link de pagamento por e-mail.
 *
 * Configure no cPanel › Trabalhos Cron, uma vez por dia (o painel mostra o comando exato):
 *   /usr/local/bin/php /home/SEU_USUARIO/public_html/loja/api/cron.php
 */
if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('Este arquivo só roda pelo cron.');
}
require __DIR__ . '/nucleo/bootstrap.php';

try {
  // Plataforma: a rotina roda para cada loja ativa, cada uma com as próprias tabelas.
  Plataforma::instalar();
  $falhas = 0;
  foreach (Plataforma::lojas('ativa') as $loja) {
    try {
      LojaAtual::usar($loja);
      Migracoes::aplicar();
      foreach (Tarefas::executar() as $linha) echo date('d/m/Y H:i') . " [{$loja['slug']}] " . $linha . PHP_EOL;
    } catch (Throwable $e) {
      $falhas++;
      fwrite(STDERR, "Falha na rotina diária da loja {$loja['slug']}: " . $e->getMessage() . PHP_EOL);
    }
  }
  if ($falhas) exit(1);
} catch (Throwable $e) {
  fwrite(STDERR, 'Falha na rotina diária: ' . $e->getMessage() . PHP_EOL);
  exit(1);
}
