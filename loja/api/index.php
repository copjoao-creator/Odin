<?php
/*
 * API da plataforma e das lojas. Todas as chamadas chegam aqui no formato api/?r=rota
 * (ex.: api/?r=produtos/vitrine).
 * - Rotas "plataforma/..." (login único, master, cadastro de lojas): nucleo/rotas_plataforma.php.
 * - As demais são da loja do endereço acessado (odinfocus.com.br/{loja}/api/...): nucleo/rotas/ e modulos/.
 */
require __DIR__ . '/nucleo/bootstrap.php';

try {
  if (!Config::instalado()) throw new ErroApi('A loja ainda não foi instalada. Abra o instalar.php para configurar.', 503);
  Plataforma::instalar();
  $rota = trim((string)($_GET['r'] ?? ''), '/');
  $roteador = new Roteador();

  if (strpos($rota, 'plataforma/') === 0) {
    (require __DIR__ . '/nucleo/rotas_plataforma.php')($roteador);
    $roteador->despachar(Http::metodo(), $rota);
    exit;
  }

  $loja = Plataforma::lojaDaRequisicao();
  LojaAtual::usar($loja);
  // Loja suspensa pelo master: fica fora do ar para clientes e lojistas; o master continua entrando
  // e os avisos de pagamento (webhooks) continuam sendo recebidos para nada se perder.
  if ($loja['status'] !== 'ativa' && strpos($rota, 'webhook/') !== 0 && !Plataforma::master()) {
    throw new ErroApi('Esta loja está temporariamente indisponível.', 503);
  }
  Migracoes::aplicar();
  foreach (glob(__DIR__ . '/nucleo/rotas/*.php') ?: [] as $arquivo) (require $arquivo)($roteador);
  foreach (Modulos::instalados() as $modulo) $modulo->rotas($roteador);
  $roteador->despachar(Http::metodo(), $rota);
} catch (ErroApi $e) {
  Http::erro($e->getMessage(), $e->status, $e->extra);
} catch (Throwable $e) {
  error_log('Erro na API da loja: ' . $e);
  // Só para o administrador master: o motivo técnico na própria tela (sem precisar abrir o error_log).
  // Clientes e lojistas veem apenas a mensagem genérica.
  $detalhe = null;
  try {
    if (Plataforma::master()) $detalhe = get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
  } catch (Throwable $ignorado) {
    // Sem banco ou sem sessão: fica a mensagem genérica.
  }
  Http::erro('Erro interno. Tente novamente em instantes.' . ($detalhe ? " [Detalhe para o master: {$detalhe}]" : ''), 500);
}
