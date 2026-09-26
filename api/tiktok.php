<?php
/*
 * Ponte (proxy) do ODIN para a API do TikTok, para hospedagem com PHP (ex.: HostGator).
 * Faz o mesmo que o servidor.ps1 faz no computador: /api/tiktok/... -> https://open.tiktokapis.com/...
 * A API do TikTok não aceita chamadas diretas do navegador, por isso o ODIN chama este arquivo.
 *
 * Segurança: só repassa para open.tiktokapis.com e só para os endereços que o ODIN usa.
 * Não guarda nada: a client secret e os tokens vêm do navegador a cada chamada.
 */
const TIKTOK_API = 'https://open.tiktokapis.com/';
const ALLOWED = ['v2/oauth/token/', 'v2/user/info/', 'v2/video/list/'];

header('Cache-Control: no-store');

function fail($status, $message) {
  http_response_code($status);
  header('Content-Type: application/json');
  echo json_encode(['error' => ['code' => 'proxy_error', 'message' => $message]]);
  exit;
}

// Tudo o que vem depois de /api/tiktok/ no endereço, com a query string (?fields=...).
$uri = $_SERVER['REQUEST_URI'] ?? '';
$pos = strpos($uri, '/api/tiktok/');
if ($pos === false) fail(404, 'Endereço inválido.');
$rest = substr($uri, $pos + strlen('/api/tiktok/'));
$path = explode('?', $rest, 2)[0];
if (!in_array($path, ALLOWED, true)) fail(403, 'Endereço da API do TikTok não permitido.');

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && $method !== 'POST') fail(405, 'Método não permitido.');

$body = file_get_contents('php://input', false, null, 0, 65536);

// Algumas hospedagens (CGI/FastCGI) escondem o cabeçalho Authorization; o .htaccess o repassa.
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!$auth && function_exists('getallheaders')) {
  foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $auth = $v;
}

$headers = [];
if ($auth) $headers[] = 'Authorization: ' . $auth;
if (!empty($_SERVER['CONTENT_TYPE'])) $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];

$ch = curl_init(TIKTOK_API . $rest);
curl_setopt_array($ch, [
  CURLOPT_CUSTOMREQUEST => $method,
  CURLOPT_HTTPHEADER => $headers,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 30,
  CURLOPT_FOLLOWLOCATION => false,
]);
if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

$response = curl_exec($ch);
if ($response === false) {
  $err = curl_error($ch);
  curl_close($ch);
  fail(502, $err);
}
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/json';
curl_close($ch);

http_response_code($status);
header('Content-Type: ' . $type);
echo $response;
