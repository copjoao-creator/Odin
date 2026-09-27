<?php
/*
 * Instalador da loja Odin Focus.
 * Abra no navegador (ex.: https://www.odinfocus.com.br/loja/instalar.php), informe os dados do
 * banco MySQL criado no cPanel e o seu acesso ao painel. O instalador cria todas as tabelas,
 * o primeiro administrador e o arquivo config.php. Depois de instalado, ele se bloqueia sozinho.
 */
require __DIR__ . '/api/nucleo/bootstrap.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$erros = [];
$feito = null;
$v = [
  'db_host' => $_POST['db_host'] ?? 'localhost',
  'db_nome' => $_POST['db_nome'] ?? '',
  'db_usuario' => $_POST['db_usuario'] ?? '',
  'loja_nome' => $_POST['loja_nome'] ?? 'Odin Focus',
  'admin_nome' => $_POST['admin_nome'] ?? '',
  'admin_email' => $_POST['admin_email'] ?? '',
];

// Verificações do servidor.
$checagens = [
  ['PHP 8.0 ou mais novo', version_compare(PHP_VERSION, '8.0.0', '>='), 'Versão atual: ' . PHP_VERSION . '. No cPanel, use “Selecionar versão do PHP” (ou MultiPHP Manager) e escolha 8.1 ou mais novo.'],
  ['Extensão PDO MySQL', extension_loaded('pdo_mysql'), 'Ative “pdo_mysql” em “Selecionar versão do PHP › Extensões”.'],
  ['Extensão cURL (Mercado Pago)', extension_loaded('curl'), 'Ative “curl” em “Selecionar versão do PHP › Extensões”.'],
  ['Extensão GD (redimensionar fotos)', extension_loaded('gd'), 'Ative “gd” em “Selecionar versão do PHP › Extensões”. Sem ela, só são aceitas fotos já no tamanho máximo.'],
  ['Pasta uploads com permissão de escrita', is_writable(__DIR__ . '/uploads'), 'No Gerenciador de Arquivos, clique com o botão direito na pasta “uploads” › Alterar permissões › 755.'],
  ['Pasta da loja com permissão de escrita', is_writable(__DIR__), 'Necessário para criar o config.php. Ajuste as permissões da pasta “loja” para 755.'],
];
$bloqueantes = array_filter($checagens, fn($c) => !$c[1] && $c[0] !== 'Extensão GD (redimensionar fotos)');

if (Config::instalado()) {
  $jaInstalado = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$bloqueantes) {
  $jaInstalado = false;
  try {
    $db = [
      'host' => trim($v['db_host']) ?: 'localhost',
      'nome' => trim($v['db_nome']),
      'usuario' => trim($v['db_usuario']),
      'senha' => (string)($_POST['db_senha'] ?? ''),
    ];
    if ($db['nome'] === '' || $db['usuario'] === '') throw new ErroApi('Preencha o nome do banco e o usuário do banco.');
    $lojaNome = Validacao::texto($_POST, 'loja_nome', 'Nome da loja', 80);
    $adminNome = Validacao::texto($_POST, 'admin_nome', 'Seu nome', 100);
    $adminEmail = Validacao::email($_POST['admin_email'] ?? '');
    $senha = Auth::validarSenha((string)($_POST['admin_senha'] ?? ''));
    if ($senha !== (string)($_POST['admin_senha2'] ?? '')) throw new ErroApi('As duas senhas do painel não são iguais.');

    try {
      $pdo = Banco::conectar($db);
    } catch (PDOException $e) {
      $cod = $e->errorInfo[1] ?? $e->getCode();
      if ((int)$cod === 1045) throw new ErroApi('O MySQL recusou o usuário ou a senha do banco. Confira no cPanel se o usuário foi criado com essa senha e se ele foi adicionado ao banco com “Todos os privilégios”.');
      if ((int)$cod === 1049) throw new ErroApi('Banco de dados não encontrado. Use o nome completo, com o prefixo (ex.: seuusuario_loja).');
      if ((int)$cod === 1044) throw new ErroApi('O usuário não tem permissão neste banco. No cPanel, em “Bancos de dados MySQL”, adicione o usuário ao banco com “Todos os privilégios”.');
      throw new ErroApi('Não foi possível conectar ao MySQL: ' . $e->getMessage());
    }
    Banco::usar($pdo);

    Banco::executarArquivo($pdo, __DIR__ . '/api/nucleo/tabelas.sql');
    foreach (Modulos::instalados() as $m) Banco::executarArquivo($pdo, $m->arquivoSql());

    $pdo->prepare('INSERT INTO administradores (nome, email, senha_hash) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE nome = VALUES(nome), senha_hash = VALUES(senha_hash)')
      ->execute([$adminNome, $adminEmail, password_hash($senha, PASSWORD_DEFAULT)]);

    $url = Http::urlLoja();
    Config::salvar(['loja_nome' => $lojaNome, 'loja_url' => Http::https() ? $url : '']);

    $conteudo = "<?php\n// Criado pelo instalar.php em " . date('d/m/Y H:i') . ".\n"
      . "// Contém a senha do banco de dados: não compartilhe este arquivo.\n"
      . 'return ' . var_export(['db' => $db], true) . ";\n";
    if (file_put_contents(ARQUIVO_CONFIG, $conteudo, LOCK_EX) === false) {
      throw new ErroApi('As tabelas foram criadas, mas não foi possível gravar o config.php. Ajuste a permissão da pasta da loja para 755 e clique em Instalar de novo.');
    }
    @chmod(ARQUIVO_CONFIG, 0640);
    $feito = ['url' => $url, 'cron' => '/usr/local/bin/php ' . __DIR__ . '/api/cron.php'];
  } catch (ErroApi $e) {
    $erros[] = $e->getMessage();
  } catch (Throwable $e) {
    $erros[] = 'Erro inesperado: ' . $e->getMessage();
  }
} else {
  $jaInstalado = false;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Instalação · Loja Odin Focus</title>
  <style>
    :root { --creme: #F7F6F2; --bege: #E5DECF; --azul: #1B2D42; --texto: #333333; --ok: #2E6B4F; --erro: #A33A3A; }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--creme); color: var(--texto); font: 16px/1.55 'Helvetica Neue', Helvetica, Arial, sans-serif; }
    header { background: var(--azul); color: var(--creme); padding: 22px 16px; text-align: center; letter-spacing: 3px; text-transform: uppercase; }
    main { max-width: 720px; margin: 0 auto; padding: 28px 16px 60px; }
    h1 { font-size: 1.3rem; font-weight: 400; margin: 0; }
    h2 { color: var(--azul); font-weight: 400; letter-spacing: 1px; text-transform: uppercase; font-size: 1rem; margin: 28px 0 10px; }
    .card { background: #fff; border-radius: 8px; padding: 22px; box-shadow: 0 4px 12px rgba(0,0,0,.05); margin-bottom: 18px; }
    label { display: block; font-size: .9rem; margin: 12px 0 4px; }
    input { width: 100%; padding: 11px 12px; border: 1px solid var(--bege); border-radius: 4px; font: inherit; background: var(--creme); }
    input:focus { outline: 2px solid var(--azul); outline-offset: 1px; }
    .dica { font-size: .85rem; color: #666; margin: 4px 0 0; }
    .linha { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media (max-width: 560px) { .linha { grid-template-columns: 1fr; } }
    .btn { display: inline-block; background: var(--azul); color: var(--creme); border: 0; padding: 15px 30px; font-size: 1rem; text-transform: uppercase; letter-spacing: 1px; cursor: pointer; text-decoration: none; margin-top: 18px; }
    .btn:hover { opacity: .9; }
    .erro { background: #f6e3e3; border-left: 4px solid var(--erro); padding: 12px 14px; margin-bottom: 16px; }
    .sucesso { background: #e3f0e8; border-left: 4px solid var(--ok); padding: 12px 14px; margin-bottom: 16px; }
    ul.checks { list-style: none; padding: 0; margin: 0; }
    ul.checks li { padding: 6px 0; border-bottom: 1px solid var(--creme); }
    .s-ok { color: var(--ok); font-weight: 700; } .s-no { color: var(--erro); font-weight: 700; }
    code { background: var(--bege); padding: 2px 6px; border-radius: 3px; word-break: break-all; }
  </style>
</head>
<body>
<header><h1>Instalação da loja</h1></header>
<main>
<?php if ($jaInstalado): ?>
  <div class="card">
    <div class="sucesso">A loja já está instalada.</div>
    <p>Por segurança, apague o arquivo <code>instalar.php</code> pelo Gerenciador de Arquivos do cPanel.</p>
    <p>Para reinstalar do zero, apague antes o arquivo <code>config.php</code> (as tabelas e os dados continuam no banco).</p>
    <a class="btn" href="admin/">Abrir o painel</a>
  </div>
<?php elseif ($feito): ?>
  <div class="card">
    <div class="sucesso"><strong>Pronto! A loja foi instalada.</strong></div>
    <h2>Próximos passos</h2>
    <ol>
      <li>Apague o arquivo <code>instalar.php</code> pelo Gerenciador de Arquivos do cPanel.</li>
      <li>Entre no painel com o e-mail e a senha que você acabou de criar.</li>
      <li>No painel, em <strong>Configurações › Mercado Pago</strong>, cole a Public Key e o Access Token.</li>
      <li>No cPanel, em <strong>Trabalhos Cron</strong>, crie uma tarefa diária com o comando:<br><code><?= $h($feito['cron']) ?></code></li>
    </ol>
    <a class="btn" href="admin/">Abrir o painel</a>
  </div>
<?php else: ?>
  <?php foreach ($erros as $e): ?><div class="erro"><?= $h($e) ?></div><?php endforeach; ?>

  <div class="card">
    <h2 style="margin-top:0">1. Verificação do servidor</h2>
    <ul class="checks">
      <?php foreach ($checagens as [$nome, $ok, $ajuda]): ?>
        <li><span class="<?= $ok ? 's-ok' : 's-no' ?>"><?= $ok ? '✓' : '✗' ?></span> <?= $h($nome) ?><?php if (!$ok): ?><p class="dica"><?= $h($ajuda) ?></p><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($bloqueantes): ?><p class="erro" style="margin-top:14px">Corrija os itens marcados com ✗ e recarregue esta página.</p><?php endif; ?>
  </div>

  <form method="post" class="card" autocomplete="off">
    <h2 style="margin-top:0">2. Banco de dados MySQL</h2>
    <p class="dica">São os dados que você criou no cPanel em “Bancos de dados MySQL”. O passo a passo está no arquivo INSTALACAO.md.</p>
    <label>Servidor do banco <input name="db_host" value="<?= $h($v['db_host']) ?>" required></label>
    <p class="dica">Na HostGator é sempre <code>localhost</code>.</p>
    <label>Nome do banco (com o prefixo) <input name="db_nome" value="<?= $h($v['db_nome']) ?>" placeholder="seuusuario_loja" required></label>
    <div class="linha">
      <label>Usuário do banco <input name="db_usuario" value="<?= $h($v['db_usuario']) ?>" placeholder="seuusuario_lojaadm" required></label>
      <label>Senha do usuário do banco <input name="db_senha" type="password" required></label>
    </div>

    <h2>3. Sua loja e seu acesso ao painel</h2>
    <label>Nome da loja <input name="loja_nome" value="<?= $h($v['loja_nome']) ?>" required></label>
    <label>Seu nome <input name="admin_nome" value="<?= $h($v['admin_nome']) ?>" required></label>
    <label>Seu e-mail (para entrar no painel) <input name="admin_email" type="email" value="<?= $h($v['admin_email']) ?>" required></label>
    <div class="linha">
      <label>Crie uma senha (mínimo 8 caracteres) <input name="admin_senha" type="password" minlength="8" required></label>
      <label>Repita a senha <input name="admin_senha2" type="password" minlength="8" required></label>
    </div>
    <button class="btn" type="submit" <?= $bloqueantes ? 'disabled' : '' ?>>Instalar</button>
  </form>
<?php endif; ?>
</main>
</body>
</html>
