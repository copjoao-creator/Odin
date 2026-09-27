<?php
/** Validação e normalização dos dados recebidos. Cada método devolve o valor limpo ou lança ErroApi. */
final class Validacao
{
  public const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

  public static function digitos($v): string
  {
    return preg_replace('/\D+/', '', (string)$v);
  }

  /** CPF com os dois dígitos verificadores conferidos. */
  public static function cpfValido(string $cpf): bool
  {
    if (!preg_match('/^\d{11}$/', $cpf) || preg_match('/^(\d)\1{10}$/', $cpf)) return false;
    for ($t = 9; $t < 11; $t++) {
      $soma = 0;
      for ($i = 0; $i < $t; $i++) $soma += (int)$cpf[$i] * ($t + 1 - $i);
      $dv = ((10 * $soma) % 11) % 10;
      if ((int)$cpf[$t] !== $dv) return false;
    }
    return true;
  }

  public static function cpf($v): string
  {
    $cpf = self::digitos($v);
    if (!self::cpfValido($cpf)) throw new ErroApi('CPF inválido. Confira os números digitados.', 422, ['campo' => 'cpf']);
    return $cpf;
  }

  public static function email($v, string $campo = 'email'): string
  {
    $email = mb_strtolower(trim((string)$v));
    if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new ErroApi('E-mail inválido.', 422, ['campo' => $campo]);
    }
    return $email;
  }

  public static function cep($v): string
  {
    $cep = self::digitos($v);
    if (strlen($cep) !== 8) throw new ErroApi('CEP inválido: use os 8 números.', 422, ['campo' => 'cep']);
    return $cep;
  }

  public static function uf($v): string
  {
    $uf = strtoupper(trim((string)$v));
    if (!in_array($uf, self::UFS, true)) throw new ErroApi('Estado inválido: use a sigla (ex.: SP).', 422, ['campo' => 'estado']);
    return $uf;
  }

  /** Celular com DDD: 11 números, o terceiro é 9. */
  public static function celular($v): string
  {
    $cel = self::digitos($v);
    if (strlen($cel) === 13 && strpos($cel, '55') === 0) $cel = substr($cel, 2);
    if (!preg_match('/^[1-9]{2}9\d{8}$/', $cel)) throw new ErroApi('Celular inválido: informe DDD + número (ex.: 11 91234-5678).', 422, ['campo' => 'celular']);
    return $cel;
  }

  public static function texto(array $d, string $campo, string $rotulo, int $max, bool $obrigatorio = true): string
  {
    $v = trim(preg_replace('/\s+/u', ' ', (string)($d[$campo] ?? '')));
    if ($obrigatorio && $v === '') throw new ErroApi("Preencha o campo {$rotulo}.", 422, ['campo' => $campo]);
    if (mb_strlen($v) > $max) throw new ErroApi("{$rotulo}: no máximo {$max} caracteres.", 422, ['campo' => $campo]);
    return $v;
  }

  /** Texto com várias linhas (descrições). */
  public static function textoLongo(array $d, string $campo, string $rotulo, int $max, bool $obrigatorio = true): string
  {
    $v = trim(str_replace("\r\n", "\n", (string)($d[$campo] ?? '')));
    if ($obrigatorio && $v === '') throw new ErroApi("Preencha o campo {$rotulo}.", 422, ['campo' => $campo]);
    if (mb_strlen($v) > $max) throw new ErroApi("{$rotulo}: no máximo {$max} caracteres.", 422, ['campo' => $campo]);
    return $v;
  }

  /** Código de produto ou serviço: letras, números, ponto, hífen e sublinhado. */
  public static function codigo($v, string $campo, string $rotulo): string
  {
    $c = strtoupper(trim((string)$v));
    if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,39}$/', $c)) {
      throw new ErroApi("{$rotulo}: use até 40 letras, números, ponto, hífen ou sublinhado (sem espaços).", 422, ['campo' => $campo]);
    }
    return $c;
  }

  /** Valor em reais: aceita 1234.56, "1234,56" ou "1.234,56". Devolve "1234.56". */
  public static function dinheiro($v, string $campo, string $rotulo, bool $obrigatorio = true): string
  {
    if (is_int($v) || is_float($v)) {
      $n = (float)$v;
    } else {
      $s = trim(str_replace(['R$', ' '], '', (string)$v));
      if ($s === '') {
        if ($obrigatorio) throw new ErroApi("Preencha o campo {$rotulo}.", 422, ['campo' => $campo]);
        return '0.00';
      }
      if (strpos($s, ',') !== false) $s = str_replace(['.', ','], ['', '.'], $s);
      if (!is_numeric($s)) throw new ErroApi("{$rotulo}: valor inválido.", 422, ['campo' => $campo]);
      $n = (float)$s;
    }
    if ($n < 0 || $n > 99999999.99) throw new ErroApi("{$rotulo}: valor fora do permitido.", 422, ['campo' => $campo]);
    return number_format($n, 2, '.', '');
  }

  public static function inteiro($v, string $campo, string $rotulo, int $min, int $max): int
  {
    if (is_string($v)) $v = trim($v);
    if (!is_int($v) && !(is_string($v) && preg_match('/^-?\d+$/', $v))) {
      throw new ErroApi("{$rotulo}: informe um número inteiro.", 422, ['campo' => $campo]);
    }
    $n = (int)$v;
    if ($n < $min || $n > $max) throw new ErroApi("{$rotulo}: use um valor entre {$min} e {$max}.", 422, ['campo' => $campo]);
    return $n;
  }

  public static function booleano($v): bool
  {
    return in_array($v, [true, 1, '1', 'true', 'sim', 'on'], true);
  }

  public static function data($v, string $campo, string $rotulo): string
  {
    $s = trim((string)$v);
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    if (!$d || $d->format('Y-m-d') !== $s) throw new ErroApi("{$rotulo}: data inválida.", 422, ['campo' => $campo]);
    return $s;
  }
}
