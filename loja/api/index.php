<?php
/*
 * API da loja Odin Focus. Todas as chamadas chegam aqui no formato api/?r=rota
 * (ex.: api/?r=produtos/vitrine). Rotas do núcleo em nucleo/rotas/ e de cada módulo em modulos/.
 */
require __DIR__ . '/nucleo/bootstrap.php';

try {
  if (!Config::instalado()) throw new ErroApi('A loja ainda não foi instalada. Abra o instalar.php para configurar.', 503);
  $roteador = new Roteador();
  foreach (glob(__DIR__ . '/nucleo/rotas/*.php') ?: [] as $arquivo) (require $arquivo)($roteador);
  foreach (Modulos::instalados() as $modulo) $modulo->rotas($roteador);
  $roteador->despachar(Http::metodo(), trim((string)($_GET['r'] ?? ''), '/'));
} catch (ErroApi $e) {
  Http::erro($e->getMessage(), $e->status, $e->extra);
} catch (Throwable $e) {
  error_log('Erro na API da loja: ' . $e);
  Http::erro('Erro interno. Tente novamente em instantes.', 500);
}
