<?php
/** Erro com mensagem para o usuário: vira uma resposta JSON { erro: "..." } com o status HTTP indicado. */
final class ErroApi extends Exception
{
  public int $status;
  public array $extra;

  public function __construct(string $mensagem, int $status = 400, array $extra = [])
  {
    parent::__construct($mensagem);
    $this->status = $status;
    $this->extra = $extra;
  }
}

/** Entrada e saída HTTP da API. */
final class Http
{
  public static function json($dados, int $status = 200): void
  {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  public static function erro(string $mensagem, int $status = 400, array $extra = []): void
  {
    self::json(['erro' => $mensagem] + $extra, $status);
  }

  public static function metodo(): string
  {
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
  }

  /** Corpo da requisição: JSON ou formulário (multipart, usado no envio de fotos). */
  public static function entrada(): array
  {
    static $dados = null;
    if ($dados !== null) return $dados;
    $tipo = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($tipo, 'application/json') !== false) {
      $bruto = file_get_contents('php://input', false, null, 0, 1048576);
      $dados = $bruto === '' || $bruto === false ? [] : json_decode($bruto, true);
      if (!is_array($dados)) throw new ErroApi('Dados enviados em formato inválido.');
    } else {
      $dados = $_POST;
    }
    return $dados;
  }

  public static function ip(): string
  {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
  }

  public static function https(): bool
  {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
      || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
  }

  /** Endereço público da loja (ex.: https://www.odinfocus.com.br/loja/), usado em links e no webhook. */
  public static function urlLoja(): string
  {
    $url = trim(Config::get('loja_url'));
    if ($url !== '') return rtrim($url, '/') . '/';
    if (empty($_SERVER['HTTP_HOST'])) return '';
    // /loja/api/index.php ou /loja/instalar.php -> /loja
    $pasta = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $pasta = rtrim((string)preg_replace('#/api$#', '', $pasta), '/');
    return (self::https() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $pasta . '/';
  }
}
