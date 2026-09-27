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

  /** Primeiro e último nome (o Mercado Pago pede separados no boleto). */
  public static function nomes(string $nome): array
  {
    $partes = preg_split('/\s+/', trim($nome)) ?: [''];
    $primeiro = array_shift($partes);
    return [$primeiro, $partes ? implode(' ', $partes) : $primeiro];
  }
}
