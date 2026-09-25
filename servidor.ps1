# Servidor local do ODIN (sem instalar nada).
# Uso:  powershell -ExecutionPolicy Bypass -File servidor.ps1   e abra http://localhost:8787
#
# Além de servir os arquivos, faz a ponte (proxy) para a API do TikTok,
# que não aceita chamadas diretas do navegador: /api/tiktok/... -> https://open.tiktokapis.com/...
# Só atende em localhost e só repassa chamadas para open.tiktokapis.com.
param([int]$Port = 8787)

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$root = $PSScriptRoot
$listener = New-Object System.Net.HttpListener
$listener.Prefixes.Add("http://localhost:$Port/")
$listener.Start()
Write-Host "ODIN rodando em http://localhost:$Port  (Ctrl+C para parar)"

$types = @{
  '.html' = 'text/html; charset=utf-8'; '.css' = 'text/css; charset=utf-8'; '.js' = 'text/javascript; charset=utf-8'
  '.json' = 'application/json'; '.svg' = 'image/svg+xml'; '.png' = 'image/png'; '.ico' = 'image/x-icon'; '.md' = 'text/plain; charset=utf-8'
}

function Send-Bytes($ctx, [int]$status, [string]$contentType, [byte[]]$bytes) {
  $ctx.Response.StatusCode = $status
  $ctx.Response.ContentType = $contentType
  $ctx.Response.Headers.Add('Cache-Control', 'no-cache')
  $ctx.Response.ContentLength64 = $bytes.Length
  if ($ctx.Request.HttpMethod -ne 'HEAD') { $ctx.Response.OutputStream.Write($bytes, 0, $bytes.Length) }
}

function Send-Proxy($ctx, [string]$target) {
  $in = $ctx.Request
  try {
    $req = [Net.HttpWebRequest]::Create($target)
    $req.Method = $in.HttpMethod
    $req.Timeout = 30000
    $req.Proxy = [Net.WebRequest]::GetSystemWebProxy()
    $req.Proxy.Credentials = [Net.CredentialCache]::DefaultNetworkCredentials
    if ($in.Headers['Authorization']) { $req.Headers.Add('Authorization', $in.Headers['Authorization']) }
    if ($in.HasEntityBody) {
      $req.ContentType = $in.ContentType
      $buf = New-Object IO.MemoryStream
      $in.InputStream.CopyTo($buf)
      $body = $buf.ToArray()
      $req.ContentLength = $body.Length
      $s = $req.GetRequestStream(); $s.Write($body, 0, $body.Length); $s.Close()
    }
    try {
      $resp = $req.GetResponse()
    } catch {
      # Respostas 4xx/5xx do TikTok chegam como exceção; repassa o corpo mesmo assim.
      $ex = $_.Exception
      while ($ex -and -not ($ex -is [Net.WebException])) { $ex = $ex.InnerException }
      if ($ex -and $ex.Response) { $resp = $ex.Response } else { throw }
    }
    $out = New-Object IO.MemoryStream
    $resp.GetResponseStream().CopyTo($out)
    $status = [int]$resp.StatusCode
    $type = $resp.ContentType
    $resp.Close()
    Send-Bytes $ctx $status $type $out.ToArray()
  } catch {
    $msg = ($_.Exception.Message -replace '"', "'")
    Write-Host "Falha no proxy do TikTok: $msg"
    Send-Bytes $ctx 502 'application/json' ([Text.Encoding]::UTF8.GetBytes("{`"error`":{`"code`":`"proxy_error`",`"message`":`"$msg`"}}"))
  }
}

try {
  while ($listener.IsListening) {
    $ctx = $listener.GetContext()
    try {
      $path = [Uri]::UnescapeDataString($ctx.Request.Url.AbsolutePath).TrimStart('/')

      if ($path.StartsWith('api/tiktok/')) {
        $target = 'https://open.tiktokapis.com/' + $ctx.Request.Url.AbsolutePath.Substring('/api/tiktok/'.Length) + $ctx.Request.Url.Query
        Send-Proxy $ctx $target
        continue
      }

      if ($path -eq '') { $path = 'index.html' }
      $file = [IO.Path]::GetFullPath((Join-Path $root $path))
      if ($file.StartsWith($root) -and (Test-Path $file -PathType Leaf)) {
        $ext = [IO.Path]::GetExtension($file).ToLower()
        $type = if ($types[$ext]) { $types[$ext] } else { 'application/octet-stream' }
        Send-Bytes $ctx 200 $type ([IO.File]::ReadAllBytes($file))
      } else {
        $ctx.Response.StatusCode = 404
      }
    } catch {
      Write-Host "Erro ao servir $($ctx.Request.Url): $_"
    } finally {
      try { $ctx.Response.Close() } catch { }
    }
  }
} finally {
  $listener.Stop()
}
