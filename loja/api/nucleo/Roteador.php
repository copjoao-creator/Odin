<?php
/**
 * Rotas da API no formato "produtos/{codigo}/fotos/{posicao}".
 * A rota chega em ?r=... (funciona em qualquer hospedagem, sem depender de reescrita de URL).
 * Rotas de administrador exigem login e, se alteram dados, o token CSRF da sessão.
 */
final class Roteador
{
  private array $rotas = [];

  public function publica(string $metodo, string $padrao, callable $acao): void
  {
    $this->rotas[] = [$metodo, $padrao, $acao, false];
  }

  public function admin(string $metodo, string $padrao, callable $acao): void
  {
    $this->rotas[] = [$metodo, $padrao, $acao, true];
  }

  public function despachar(string $metodo, string $rota): void
  {
    $caminhoExiste = false;
    foreach ($this->rotas as [$m, $padrao, $acao, $admin]) {
      $regex = '#^' . preg_replace('#\\\\\{[a-z_]+\\\\\}#', '([^/]+)', preg_quote($padrao, '#')) . '$#';
      if (!preg_match($regex, $rota, $partes)) continue;
      $caminhoExiste = true;
      if ($m !== $metodo) continue;

      if ($admin) {
        Auth::exigirAdmin();
        if ($metodo !== 'GET') Auth::verificarCsrf();
      }
      $params = array_map('rawurldecode', array_slice($partes, 1));
      $resultado = $acao(...$params);
      if ($resultado !== null) Http::json($resultado);
      return;
    }
    throw new ErroApi($caminhoExiste ? 'Método não permitido.' : 'Endereço não encontrado.', $caminhoExiste ? 405 : 404);
  }
}
