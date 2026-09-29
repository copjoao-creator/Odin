<?php
/**
 * Fotos de produtos e serviços: aceita só PNG ou JPG e garante o tamanho máximo
 * (800x800 nos produtos, 250x250 nos serviços). Imagens maiores são reduzidas
 * automaticamente, mantendo a proporção. A imagem é regravada, o que também
 * descarta qualquer conteúdo escondido no arquivo enviado.
 */
final class Imagem
{
  private const TAMANHO_MAXIMO_ARQUIVO = 8 * 1024 * 1024;

  /** Salva o arquivo enviado em uploads/{pasta} e devolve [arquivo, largura, altura]. */
  public static function salvar(?array $envio, string $pasta, int $max): array
  {
    if (!$envio || !isset($envio['error']) || is_array($envio['error'])) throw new ErroApi('Nenhuma imagem enviada.', 422);
    if ($envio['error'] === UPLOAD_ERR_INI_SIZE || $envio['error'] === UPLOAD_ERR_FORM_SIZE) throw new ErroApi('Imagem grande demais (máximo 8 MB).', 422);
    if ($envio['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($envio['tmp_name'])) throw new ErroApi('Falha ao receber a imagem. Tente de novo.', 422);
    if ($envio['size'] > self::TAMANHO_MAXIMO_ARQUIVO) throw new ErroApi('Imagem grande demais (máximo 8 MB).', 422);

    $info = @getimagesize($envio['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
      throw new ErroApi('Formato não aceito: envie uma imagem PNG ou JPG.', 422);
    }
    [$larg, $alt, $tipo] = $info;
    $ext = $tipo === IMAGETYPE_PNG ? 'png' : 'jpg';
    $destinoPasta = LOJA_RAIZ . '/uploads/' . $pasta;
    if (!is_dir($destinoPasta) && !mkdir($destinoPasta, 0755, true)) throw new ErroApi('Não foi possível criar a pasta de imagens.', 500);
    $nome = bin2hex(random_bytes(12)) . '.' . $ext;
    $destino = $destinoPasta . '/' . $nome;

    if (!function_exists('imagecreatetruecolor')) {
      // Sem a extensão GD não dá para reduzir: aceita só imagens que já estão no limite.
      if ($larg > $max || $alt > $max) throw new ErroApi("A imagem deve ter no máximo {$max}x{$max} pixels.", 422);
      if (!move_uploaded_file($envio['tmp_name'], $destino)) throw new ErroApi('Não foi possível salvar a imagem.', 500);
      return ['arquivo' => $nome, 'largura' => $larg, 'altura' => $alt];
    }

    $origem = $tipo === IMAGETYPE_PNG ? @imagecreatefrompng($envio['tmp_name']) : @imagecreatefromjpeg($envio['tmp_name']);
    if (!$origem) throw new ErroApi('Imagem corrompida ou inválida.', 422);

    $escala = min(1, $max / $larg, $max / $alt);
    $novaL = max(1, (int)round($larg * $escala));
    $novaA = max(1, (int)round($alt * $escala));
    $final = imagecreatetruecolor($novaL, $novaA);
    if ($tipo === IMAGETYPE_PNG) {
      imagealphablending($final, false);
      imagesavealpha($final, true);
      imagefill($final, 0, 0, imagecolorallocatealpha($final, 0, 0, 0, 127));
    }
    imagecopyresampled($final, $origem, 0, 0, 0, 0, $novaL, $novaA, $larg, $alt);
    $ok = $tipo === IMAGETYPE_PNG ? imagepng($final, $destino, 6) : imagejpeg($final, $destino, 86);
    imagedestroy($origem);
    imagedestroy($final);
    if (!$ok) throw new ErroApi('Não foi possível salvar a imagem.', 500);
    return ['arquivo' => $nome, 'largura' => $novaL, 'altura' => $novaA];
  }

  /**
   * Ícone quadrado (aba do navegador, atalho no celular) a partir de uma imagem já salva em uploads/{pasta}:
   * a imagem inteira, centralizada num quadrado transparente de {lado}px, sem cortar. Sem a extensão GD, devolve null.
   */
  public static function icone(string $pasta, string $arquivo, int $lado = 180): ?string
  {
    if (!function_exists('imagecreatetruecolor')) return null;
    $caminho = LOJA_RAIZ . '/uploads/' . $pasta . '/' . $arquivo;
    $info = @getimagesize($caminho);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) return null;
    $origem = $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($caminho) : @imagecreatefromjpeg($caminho);
    if (!$origem) return null;
    [$larg, $alt] = $info;
    $escala = min($lado / $larg, $lado / $alt);
    $novaL = max(1, (int)round($larg * $escala));
    $novaA = max(1, (int)round($alt * $escala));
    $final = imagecreatetruecolor($lado, $lado);
    imagealphablending($final, false);
    imagesavealpha($final, true);
    imagefill($final, 0, 0, imagecolorallocatealpha($final, 0, 0, 0, 127));
    imagealphablending($final, true);
    imagecopyresampled($final, $origem, intdiv($lado - $novaL, 2), intdiv($lado - $novaA, 2), 0, 0, $novaL, $novaA, $larg, $alt);
    $nome = bin2hex(random_bytes(12)) . '.png';
    $ok = imagepng($final, LOJA_RAIZ . '/uploads/' . $pasta . '/' . $nome, 6);
    imagedestroy($origem);
    imagedestroy($final);
    return $ok ? $nome : null;
  }

  public static function apagar(?string $pasta, ?string $arquivo): void
  {
    if (!$pasta || !$arquivo || !preg_match('/^[a-f0-9]{24}\.(png|jpg)$/', $arquivo)) return;
    $caminho = LOJA_RAIZ . '/uploads/' . $pasta . '/' . $arquivo;
    if (is_file($caminho)) @unlink($caminho);
  }

  /** Endereço público da imagem, relativo à pasta da loja. */
  public static function url(string $pasta, ?string $arquivo): ?string
  {
    return $arquivo ? 'uploads/' . $pasta . '/' . $arquivo : null;
  }
}
