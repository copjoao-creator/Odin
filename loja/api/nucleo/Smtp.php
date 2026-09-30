<?php
/**
 * Envio de e-mail autenticado (SMTP), sem bibliotecas externas.
 * Com o e-mail do domínio no Titan (HostGator), só os servidores do Titan podem enviar em nome de
 * @odinfocus.com.br (registro SPF). Enviar pela caixa do Titan com usuário e senha faz os e-mails
 * chegarem na caixa de entrada em vez do spam.
 *
 * Configuração em config.php (só no servidor):
 *   'smtp' => ['host' => 'smtp.titan.email', 'porta' => 465, 'usuario' => 'contato@odinfocus.com.br', 'senha' => '...'],
 * Porta 465 = SSL; 587 = STARTTLS. "seguranca" => "nenhuma" só para testes locais.
 */
final class Smtp
{
  public static function configurado(): bool
  {
    $c = self::config();
    return $c['host'] !== '' && $c['usuario'] !== '' && $c['senha'] !== '';
  }

  /** Conta que envia (o remetente precisa ser ela; o e-mail da loja vai em "Responder para"). */
  public static function remetente(): string
  {
    return self::config()['usuario'];
  }

  private static function config(): array
  {
    $s = Config::arquivo()['smtp'] ?? [];
    $porta = (int)($s['porta'] ?? 465);
    return [
      'host' => trim((string)($s['host'] ?? '')),
      'porta' => $porta,
      'usuario' => trim((string)($s['usuario'] ?? '')),
      'senha' => (string)($s['senha'] ?? ''),
      'seguranca' => (string)($s['seguranca'] ?? ($porta === 465 ? 'ssl' : 'tls')),
    ];
  }

  /**
   * Envia um e-mail HTML. $nomeDe é o nome que aparece para quem recebe (ex.: o nome da loja).
   * Devolve false (e registra no error_log) se o servidor recusar; nunca mostra a senha.
   */
  public static function enviar(string $para, string $assunto, string $html, string $nomeDe, ?string $responderPara = null): bool
  {
    $c = self::config();
    $cabecalho = fn(string $t) => '=?UTF-8?B?' . base64_encode($t) . '?=';
    $dominio = substr(strrchr($c['usuario'], '@') ?: '@localhost', 1);
    $mensagem = implode("\r\n", array_filter([
      'Date: ' . date('r'),
      'From: ' . $cabecalho($nomeDe) . " <{$c['usuario']}>",
      "To: <{$para}>",
      $responderPara ? "Reply-To: <{$responderPara}>" : null,
      'Subject: ' . $cabecalho($assunto),
      'Message-ID: <' . bin2hex(random_bytes(12)) . "@{$dominio}>",
      'MIME-Version: 1.0',
      'Content-Type: text/html; charset=UTF-8',
      'Content-Transfer-Encoding: base64',
    ])) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($html), 76, "\r\n"));

    $alvo = ($c['seguranca'] === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['porta'];
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $s = @stream_socket_client($alvo, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) {
      error_log("SMTP: não conectou em {$c['host']}:{$c['porta']} ({$errstr})");
      return false;
    }
    stream_set_timeout($s, 20);
    try {
      self::esperar($s, 220);
      $ehlo = 'EHLO ' . (parse_url(Plataforma::urlBase(), PHP_URL_HOST) ?: 'localhost');
      self::comando($s, $ehlo, 250);
      if ($c['seguranca'] === 'tls') {
        self::comando($s, 'STARTTLS', 220);
        if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('STARTTLS falhou');
        self::comando($s, $ehlo, 250);
      }
      self::comando($s, 'AUTH LOGIN', 334);
      self::comando($s, base64_encode($c['usuario']), 334);
      self::comando($s, base64_encode($c['senha']), 235, true);
      self::comando($s, "MAIL FROM:<{$c['usuario']}>", 250);
      self::comando($s, "RCPT TO:<{$para}>", [250, 251]);
      self::comando($s, 'DATA', 354);
      // Linhas que começam com "." ganham outro "." (regra do SMTP).
      self::comando($s, preg_replace('/^\./m', '..', $mensagem) . "\r\n.", 250);
      @fwrite($s, "QUIT\r\n");
      return true;
    } catch (Throwable $e) {
      error_log('SMTP: ' . $e->getMessage() . " (para {$para}: {$assunto})");
      return false;
    } finally {
      fclose($s);
    }
  }

  /** Envia um comando e confere o código da resposta. $segredo: não registra o comando em erro. */
  private static function comando($s, string $linha, $esperado, bool $segredo = false): void
  {
    fwrite($s, $linha . "\r\n");
    try {
      self::esperar($s, $esperado);
    } catch (RuntimeException $e) {
      throw new RuntimeException(($segredo ? '[autenticação]' : strtok($linha, "\r\n")) . ' -> ' . $e->getMessage());
    }
  }

  private static function esperar($s, $esperado): void
  {
    $resposta = '';
    while (($linha = fgets($s, 1024)) !== false) {
      $resposta .= $linha;
      if (strlen($linha) < 4 || $linha[3] === ' ') break; // "250 ok" encerra; "250-..." continua
    }
    $codigo = (int)substr($resposta, 0, 3);
    if (!in_array($codigo, (array)$esperado, true)) throw new RuntimeException(trim($resposta) ?: 'sem resposta do servidor');
  }
}
