<?php
/** Cadastro de clientes. CPF (chave primária) e e-mail (único) identificam cada cliente. */
final class Clientes
{
  /** Valida e normaliza os dados do formulário do cliente. */
  public static function validar(array $d): array
  {
    return [
      'nome' => Validacao::texto($d, 'nome', 'Nome', 150),
      'cpf' => Validacao::cpf($d['cpf'] ?? ''),
      'email' => Validacao::email($d['email'] ?? ''),
      'celular' => Validacao::celular($d['celular'] ?? ''),
      'cep' => Validacao::cep($d['cep'] ?? ''),
      'rua' => Validacao::texto($d, 'rua', 'Rua', 150),
      'numero' => Validacao::texto($d, 'numero', 'Número', 20),
      'complemento' => Validacao::texto($d, 'complemento', 'Complemento', 80, false),
      'bairro' => Validacao::texto($d, 'bairro', 'Bairro', 100),
      'cidade' => Validacao::texto($d, 'cidade', 'Cidade', 100),
      'estado' => Validacao::uf($d['estado'] ?? ''),
    ];
  }

  public static function buscar(string $cpf): ?array
  {
    return Banco::um('SELECT * FROM clientes WHERE cpf = ?', [$cpf]);
  }

  /**
   * Usado no checkout da loja: cadastra o cliente novo ou atualiza o endereço de quem já comprou.
   * Recusa CPF e e-mail que pertencem a cadastros diferentes.
   */
  public static function salvarDoCheckout(array $c): array
  {
    $porCpf = self::buscar($c['cpf']);
    $porEmail = Banco::um('SELECT cpf FROM clientes WHERE email = ?', [$c['email']]);
    if ($porCpf && $porCpf['email'] !== $c['email']) {
      throw new ErroApi('Este CPF já está cadastrado com outro e-mail. Use o e-mail do seu cadastro ou fale conosco.', 409, ['campo' => 'email']);
    }
    if (!$porCpf && $porEmail) {
      throw new ErroApi('Este e-mail já está cadastrado com outro CPF. Confira o CPF ou fale conosco.', 409, ['campo' => 'cpf']);
    }
    if ($porCpf) {
      self::atualizar($c['cpf'], $c);
    } else {
      self::inserir($c);
    }
    return self::buscar($c['cpf']);
  }

  public static function inserir(array $c): void
  {
    try {
      Banco::executar(
        'INSERT INTO clientes (cpf, email, nome, cep, rua, numero, complemento, bairro, cidade, estado, celular)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$c['cpf'], $c['email'], $c['nome'], $c['cep'], $c['rua'], $c['numero'], $c['complemento'] ?: null, $c['bairro'], $c['cidade'], $c['estado'], $c['celular']]
      );
    } catch (PDOException $e) {
      if (Banco::duplicado($e)) throw new ErroApi('Já existe um cliente com este CPF ou e-mail.', 409);
      throw $e;
    }
  }

  /** Atualiza o cadastro; o CPF também pode ser corrigido (os pedidos acompanham). */
  public static function atualizar(string $cpfAtual, array $c): void
  {
    try {
      Banco::executar(
        'UPDATE clientes SET cpf = ?, email = ?, nome = ?, cep = ?, rua = ?, numero = ?, complemento = ?, bairro = ?, cidade = ?, estado = ?, celular = ?
         WHERE cpf = ?',
        [$c['cpf'], $c['email'], $c['nome'], $c['cep'], $c['rua'], $c['numero'], $c['complemento'] ?: null, $c['bairro'], $c['cidade'], $c['estado'], $c['celular'], $cpfAtual]
      );
    } catch (PDOException $e) {
      if (Banco::duplicado($e)) throw new ErroApi('Já existe outro cliente com este CPF ou e-mail.', 409);
      throw $e;
    }
  }

  // ---------------- Cliente que já tem cadastro (checkout pelo CPF) ----------------
  // Quem digita um CPF na loja não prova que é o dono dele: por isso a loja só mostra um resumo
  // mascarado do cadastro e usa os dados completos no pedido sem devolvê-los ao navegador.

  private const CONSULTAS_MAX = 10;
  private const CONSULTAS_MINUTOS = 15;

  /** Limite de consultas de CPF por IP (evita varrer CPFs para descobrir quem é cliente). */
  public static function limitarConsultas(): void
  {
    $chave = 'cpf:' . Http::ip();
    $n = (int)Banco::valor(
      'SELECT COUNT(*) FROM login_tentativas WHERE ip = ? AND momento > DATE_SUB(NOW(), INTERVAL ' . self::CONSULTAS_MINUTOS . ' MINUTE)',
      [$chave]
    );
    if ($n >= self::CONSULTAS_MAX) {
      throw new ErroApi('Muitas consultas de CPF em pouco tempo. Aguarde alguns minutos e tente de novo.', 429);
    }
    Banco::executar('INSERT INTO login_tentativas (ip) VALUES (?)', [$chave]);
  }

  /** Resumo que o cliente reconhece sem expor os dados: "Maria S.", "m***a@gmail.com", "(11) *****-4321". */
  public static function resumoMascarado(array $c): array
  {
    return [
      'nome' => self::nomeMascarado($c['nome']),
      'email' => self::emailMascarado($c['email']),
      'celular' => self::celularMascarado($c['celular']),
      'endereco' => self::ruaMascarada($c['rua']) . ', ' . self::numeroMascarado($c['numero']) . ' — ' . $c['cidade'] . '/' . $c['estado'],
    ];
  }

  /** Mesmos campos do cliente em Pedidos::publico, mas mascarados (cidade e estado ficam visíveis). */
  public static function mascarado(array $c, string $cpf): array
  {
    return [
      'nome' => self::nomeMascarado($c['nome'] ?? ''),
      'email' => self::emailMascarado($c['email'] ?? ''),
      'cpf' => '***.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-**',
      'celular' => self::celularMascarado($c['celular'] ?? ''),
      'cep' => substr((string)($c['cep'] ?? ''), 0, 2) . '***-***',
      'rua' => self::ruaMascarada($c['rua'] ?? ''),
      'numero' => self::numeroMascarado($c['numero'] ?? ''),
      'complemento' => null,
      'bairro' => '',
      'cidade' => $c['cidade'] ?? '',
      'estado' => $c['estado'] ?? '',
      'protegido' => true,
    ];
  }

  private static function nomeMascarado(string $nome): string
  {
    [$primeiro, $ultimo] = self::nomes($nome);
    return $ultimo !== $primeiro ? $primeiro . ' ' . mb_substr($ultimo, 0, 1) . '.' : $primeiro;
  }

  private static function emailMascarado(string $email): string
  {
    [$usuario, $dominio] = explode('@', $email, 2) + [1 => ''];
    $n = mb_strlen($usuario);
    $visivel = $n <= 2 ? mb_substr($usuario, 0, 1) . '***' : mb_substr($usuario, 0, 1) . '***' . mb_substr($usuario, -1);
    return $visivel . '@' . $dominio;
  }

  private static function celularMascarado(string $celular): string
  {
    $d = Validacao::digitos($celular);
    return strlen($d) >= 6 ? '(' . substr($d, 0, 2) . ') *****-' . substr($d, -4) : '';
  }

  private static function ruaMascarada(string $rua): string
  {
    $rua = trim($rua);
    return mb_strlen($rua) <= 6 ? mb_substr($rua, 0, 1) . '***' : mb_substr($rua, 0, 6) . '***';
  }

  private static function numeroMascarado(string $numero): string
  {
    $numero = trim($numero);
    return $numero === '' ? '' : mb_substr($numero, 0, 1) . str_repeat('*', max(1, mb_strlen($numero) - 1));
  }

  /** Primeiro e último nome (o Mercado Pago pede separados no boleto). */
  public static function nomes(string $nome): array
  {
    $partes = preg_split('/\s+/', trim($nome)) ?: [''];
    $primeiro = array_shift($partes);
    return [$primeiro, $partes ? implode(' ', $partes) : $primeiro];
  }
}
