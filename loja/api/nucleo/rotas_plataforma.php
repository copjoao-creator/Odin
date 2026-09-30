<?php
/*
 * Rotas da plataforma (api/?r=plataforma/...): login único ("Entrar"), senha do master e cadastro das lojas.
 * Não dependem de loja: funcionam em qualquer endereço. As do master exigem a sessão dele e o token CSRF.
 */
return function (Roteador $r) {
  $master = function (): array {
    $m = Plataforma::exigirMaster();
    if (Http::metodo() !== 'GET') Auth::verificarCsrf();
    return $m;
  };

  // Tela "Entrar" e painel master ao abrir: quem está logado e o token CSRF.
  $r->publica('GET', 'plataforma/sessao', function () {
    $m = Plataforma::master();
    return ['master' => $m ? ['nome' => $m['nome'], 'email' => $m['email'], 'verificacao' => Plataforma::mascararEmail($m['email_verificacao'])] : null, 'csrf' => Auth::csrf()];
  });

  $r->publica('POST', 'plataforma/entrar', function () {
    $d = Http::entrada();
    return Plataforma::entrar((string)($d['email'] ?? ''), (string)($d['senha'] ?? '')) + ['csrf' => Auth::csrf()];
  });

  $r->publica('POST', 'plataforma/sair', function () {
    Auth::sair();
    return ['ok' => true];
  });

  // Código por e-mail: "senha" (criar/recuperar, sem login) ou "alterar" (logado).
  $r->publica('POST', 'plataforma/codigo', function () {
    $d = Http::entrada();
    $finalidade = (string)($d['finalidade'] ?? 'senha');
    if ($finalidade === 'alterar') Auth::verificarCsrf();
    return Plataforma::enviarCodigo($finalidade, (string)($d['email'] ?? ''));
  });

  $r->publica('POST', 'plataforma/senha', function () {
    $d = Http::entrada();
    if (Plataforma::master()) Auth::verificarCsrf();
    return Plataforma::definirSenha((string)($d['codigo'] ?? ''), (string)($d['senha'] ?? ''), (string)($d['email'] ?? '')) + ['csrf' => Auth::csrf()];
  });

  // ---------------- Lojas (só o master) ----------------

  $r->publica('GET', 'plataforma/lojas', function () use ($master) {
    $master();
    return ['lojas' => array_map([Plataforma::class, 'resumoLoja'], Plataforma::lojas()), 'url_base' => Plataforma::urlBase()];
  });

  $r->publica('POST', 'plataforma/lojas', function () use ($master) {
    $master();
    $l = Plataforma::criarLoja(Http::entrada());
    return ['loja' => Plataforma::resumoLoja($l)];
  });

  $r->publica('PUT', 'plataforma/lojas/{id}', function ($id) use ($master) {
    $master();
    $l = Plataforma::loja((int)$id);
    $d = Http::entrada();
    if (array_key_exists('nome', $d)) {
      Banco::executar('UPDATE plataforma_lojas SET nome = ? WHERE id = ?', [Validacao::texto($d, 'nome', 'Nome da loja', 120), $l['id']]);
    }
    if (array_key_exists('status', $d)) {
      $status = $d['status'] === 'suspensa' ? 'suspensa' : 'ativa';
      Banco::executar('UPDATE plataforma_lojas SET status = ? WHERE id = ?', [$status, $l['id']]);
    }
    return ['loja' => Plataforma::resumoLoja(Plataforma::loja((int)$l['id']))];
  });

  // Cria ou redefine um acesso ao painel da loja (inclusive "esqueci a senha" do lojista).
  $r->publica('POST', 'plataforma/lojas/{id}/acesso', function ($id) use ($master) {
    $master();
    return Plataforma::definirAcessoDaLoja(Plataforma::loja((int)$id), Http::entrada());
  });
};
