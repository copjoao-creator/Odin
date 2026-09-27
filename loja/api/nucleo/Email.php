<?php
/** E-mails para o cliente (confirmação de pedido, pagamento aprovado, cobrança de renovação), via mail() da hospedagem. */
final class Email
{
  public static function enviar(string $para, string $assunto, string $html): bool
  {
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) return false;
    $loja = Config::get('loja_nome');
    $remetente = Config::get('loja_email');
    if ($remetente === '') {
      $host = preg_replace('/^www\./', '', (string)parse_url(Http::urlLoja(), PHP_URL_HOST));
      if ($host === '') return false;
      $remetente = 'nao-responda@' . $host;
    }
    $nome = '=?UTF-8?B?' . base64_encode($loja) . '?=';
    $cabecalhos = implode("\r\n", [
      'MIME-Version: 1.0',
      'Content-Type: text/html; charset=UTF-8',
      "From: {$nome} <{$remetente}>",
      "Reply-To: {$remetente}",
    ]);
    $ok = @mail($para, '=?UTF-8?B?' . base64_encode($assunto) . '?=', self::modelo($assunto, $html), $cabecalhos);
    if (!$ok) error_log("Falha ao enviar e-mail para {$para}: {$assunto}");
    return $ok;
  }

  /** Moldura do e-mail nas cores da loja. */
  private static function modelo(string $titulo, string $corpo): string
  {
    $loja = htmlspecialchars(Config::get('loja_nome'));
    $titulo = htmlspecialchars($titulo);
    $url = Http::urlLoja();
    $contato = $url === '' ? '' : ' Dúvidas? <a href="' . htmlspecialchars($url . 'contato.html') . '" style="color:#1B2D42">Fale conosco</a>.';
    return <<<HTML
<!DOCTYPE html><html lang="pt-BR"><body style="margin:0;background:#F7F6F2;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;color:#333333">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;overflow:hidden">
<tr><td style="background:#1B2D42;color:#F7F6F2;padding:20px 28px;font-size:18px;letter-spacing:3px;text-transform:uppercase">{$loja}</td></tr>
<tr><td style="padding:28px;font-size:15px;line-height:1.6">
<h1 style="margin:0 0 16px;font-size:20px;font-weight:400;color:#1B2D42">{$titulo}</h1>
{$corpo}
</td></tr>
<tr><td style="background:#E5DECF;padding:14px 28px;font-size:12px;color:#555">Este é um e-mail automático de {$loja}.{$contato}</td></tr>
</table></td></tr></table></body></html>
HTML;
  }

  public static function botao(string $texto, string $url): string
  {
    $t = htmlspecialchars($texto);
    $u = htmlspecialchars($url);
    return "<p style=\"margin:24px 0\"><a href=\"{$u}\" style=\"background:#1B2D42;color:#F7F6F2;padding:14px 26px;text-decoration:none;text-transform:uppercase;letter-spacing:1px;font-size:14px\">{$t}</a></p>";
  }
}
