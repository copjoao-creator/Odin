<?php
/** E-mails para o cliente (confirmação de pedido, pagamento aprovado, cobrança de renovação), por SMTP (Smtp.php) ou mail() da hospedagem. */
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
    // Testes locais: grava o e-mail num arquivo em vez de enviar (config.php › "email_arquivo").
    $arquivo = (string)(Config::arquivo()['email_arquivo'] ?? '');
    if ($arquivo !== '') return (bool)file_put_contents($arquivo, "Para: {$para}\nAssunto: {$assunto}\n" . self::modelo($assunto, $html) . "\n\n", FILE_APPEND);
    // Com SMTP configurado (config.php › "smtp"), sai autenticado pela caixa da plataforma com o nome
    // da loja; o e-mail da loja vai em "Responder para", então a resposta do cliente chega à loja.
    // Se o SMTP falhar, tenta pelo mail() do servidor (o motivo fica no error_log: "SMTP: ...").
    if (Smtp::configurado() && Smtp::enviar($para, $assunto, self::modelo($assunto, $html), $loja, $remetente)) return true;
    $ok = @mail($para, '=?UTF-8?B?' . base64_encode($assunto) . '?=', self::modelo($assunto, $html), $cabecalhos, '-f' . $remetente);
    if (!$ok) error_log("Falha ao enviar e-mail para {$para}: {$assunto}");
    return $ok;
  }

  /**
   * Moldura do e-mail no padrão visual da loja (o do Tênis de Mesa para Todos, se a loja não mudou as cores):
   * quadro com borda na cor secundária sobre o fundo, títulos claros, links na cor de realce e botão arredondado.
   */
  private static function modelo(string $titulo, string $corpo): string
  {
    $c = Config::cores();
    $loja = htmlspecialchars(Config::get('loja_nome'));
    $titulo = htmlspecialchars($titulo);
    $url = Http::urlLoja();
    $contato = $url === '' ? '' : ' Dúvidas? <a href="' . htmlspecialchars($url . 'contato.html') . '" style="color:' . $c['realce'] . '">Fale conosco</a>.';
    // Topo: no fundo escuro, o logotipo para fundo escuro (o comum vai sobre uma etiqueta branca); sem logotipo, o nome da loja.
    $logo = $c['escuro'] ? Marca::logoAbsoluto('marca_logo_escuro') : null;
    $etiqueta = '';
    if (!$logo) {
      $logo = Marca::logoAbsoluto();
      if ($logo && $c['escuro']) $etiqueta = 'background:#ffffff;padding:4px 8px;border-radius:8px;';
    }
    $topo = $logo
      ? '<img src="' . htmlspecialchars($logo) . '" alt="' . $loja . '" style="max-height:48px;max-width:260px;display:block;' . $etiqueta . '">'
      : $loja;
    $legal = htmlspecialchars(Marca::linhaLegal());
    $legal = $legal !== '' ? "<br>{$legal}" : '';
    $fonte = "'Source Sans 3','Segoe UI',Arial,sans-serif";
    return <<<HTML
<!DOCTYPE html><html lang="pt-BR"><body style="margin:0;background:{$c['fundo']};font-family:{$fonte};color:{$c['texto']};letter-spacing:.05em">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{$c['fundo']}"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:{$c['fundo']};border:1px solid {$c['secundaria']};border-radius:16px">
<tr><td style="padding:20px 28px;font-size:18px;color:{$c['titulo']};border-bottom:1px solid {$c['linha']}">{$topo}</td></tr>
<tr><td style="padding:28px;font-size:15px;line-height:1.6;color:{$c['texto']}">
<h1 style="margin:0 0 16px;font-size:20px;font-weight:400;color:{$c['titulo']}">{$titulo}</h1>
{$corpo}
</td></tr>
<tr><td style="border-top:1px solid {$c['linha']};padding:14px 28px;font-size:12px;color:{$c['suave']}">Este é um e-mail automático de {$loja}.{$contato}{$legal}</td></tr>
</table></td></tr></table></body></html>
HTML;
  }

  public static function botao(string $texto, string $url): string
  {
    $c = Config::cores();
    $t = htmlspecialchars($texto);
    $u = htmlspecialchars($url);
    return "<p style=\"margin:24px 0\"><a href=\"{$u}\" style=\"background:{$c['principal']};color:{$c['sobre_principal']};padding:12px 22px;border-radius:12px;text-decoration:none;font-size:15px;display:inline-block\">{$t}</a></p>";
  }
}
