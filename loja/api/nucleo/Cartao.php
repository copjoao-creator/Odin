<?php
/**
 * Cartão de crédito digitado na página de pagamento (compras e assinaturas).
 * Os dados só passam por aqui a caminho do Asaas: nunca são gravados nem registrados em log.
 */
final class Cartao
{
  /** Tentativas de cartão por hora: por pedido e por IP (evita usar a página para testar cartões roubados). */
  private const TENTATIVAS_PEDIDO = 5;
  private const TENTATIVAS_IP = 10;

  /** Confere os dados do cartão e do titular antes de enviar ao Asaas. */
  public static function validar(array $f): array
  {
    $numero = Validacao::digitos($f['numero'] ?? '');
    if (strlen($numero) < 13 || strlen($numero) > 19 || !self::luhn($numero)) {
      throw new ErroApi('Número do cartão inválido. Confira os números digitados.', 422, ['campo' => 'numero']);
    }
    $mes = (int)Validacao::digitos($f['mes'] ?? '');
    $ano = (int)Validacao::digitos($f['ano'] ?? '');
    if ($ano < 100) $ano += 2000;
    $agora = [(int)date('Y'), (int)date('n')];
    if ($mes < 1 || $mes > 12 || $ano > $agora[0] + 20 || $ano < $agora[0] || ($ano === $agora[0] && $mes < $agora[1])) {
      throw new ErroApi('Validade do cartão inválida ou vencida.', 422, ['campo' => 'validade']);
    }
    $cvv = Validacao::digitos($f['cvv'] ?? '');
    if (strlen($cvv) < 3 || strlen($cvv) > 4) throw new ErroApi('Código de segurança (CVV) inválido.', 422, ['campo' => 'cvv']);
    $nome = trim((string)preg_replace('/\s+/u', ' ', (string)($f['nome'] ?? '')));
    if (mb_strlen($nome) < 3 || mb_strlen($nome) > 100 || !preg_match("/^[\\p{L} .'-]+$/u", $nome)) {
      throw new ErroApi('Informe o nome do titular como está impresso no cartão.', 422, ['campo' => 'nome']);
    }
    $cpf = Validacao::digitos($f['cpf'] ?? '');
    if (!Validacao::cpfValido($cpf)) throw new ErroApi('CPF do titular do cartão inválido.', 422, ['campo' => 'cpf']);
    // Endereço e celular do titular: só quando o cartão é de outra pessoa (senão, vale o cadastro do cliente).
    $cep = Validacao::digitos($f['cep'] ?? '');
    if ($cep !== '' && strlen($cep) !== 8) throw new ErroApi('CEP do titular inválido.', 422, ['campo' => 'cep']);
    $celular = Validacao::digitos($f['celular'] ?? '');
    if ($celular !== '' && (strlen($celular) < 10 || strlen($celular) > 11)) throw new ErroApi('Celular do titular inválido.', 422, ['campo' => 'celular']);
    return [
      'numero' => $numero,
      'mes' => sprintf('%02d', $mes),
      'ano' => (string)$ano,
      'cvv' => $cvv,
      'titular' => [
        'nome' => $nome,
        'cpf' => $cpf,
        'cep' => $cep,
        'numero' => mb_substr(trim((string)($f['numero_endereco'] ?? '')), 0, 20),
        'celular' => $celular,
      ],
    ];
  }

  /** Campos "creditCard" e "creditCardHolderInfo" do Asaas (o titular do cartão, comparado com o banco emissor). */
  public static function paraAsaas(array $cartao, array $cliente): array
  {
    $t = $cartao['titular'];
    return [
      'creditCard' => [
        'holderName' => $t['nome'],
        'number' => $cartao['numero'],
        'expiryMonth' => $cartao['mes'],
        'expiryYear' => $cartao['ano'],
        'ccv' => $cartao['cvv'],
      ],
      'creditCardHolderInfo' => [
        'name' => $t['nome'],
        'email' => $cliente['email'],
        'cpfCnpj' => $t['cpf'],
        'postalCode' => $t['cep'] ?: $cliente['cep'],
        'addressNumber' => $t['numero'] ?: $cliente['numero'],
        'phone' => $t['celular'] ?: $cliente['celular'],
        'mobilePhone' => $t['celular'] ?: $cliente['celular'],
      ],
      'remoteIp' => Http::ip(),
    ];
  }

  /** Mensagem para o cliente quando o Asaas recusa o cartão (HTTP 400: nada é cobrado nem criado). */
  public static function recusa(ErroApi $e): ErroApi
  {
    if ($e->status !== 422) return $e;
    return new ErroApi(($e->extra['codigo_asaas'] ?? '') === 'invalid_creditCard'
      ? 'O banco não autorizou este cartão. Confira os dados do cartão e do titular (nome e CPF) ou use outro cartão de crédito.'
      : $e->getMessage(), 422);
  }

  /** Dígito verificador do número do cartão (algoritmo de Luhn). */
  public static function luhn(string $n): bool
  {
    $soma = 0;
    $dobra = false;
    for ($i = strlen($n) - 1; $i >= 0; $i--) {
      $d = (int)$n[$i];
      if ($dobra && ($d *= 2) > 9) $d -= 9;
      $soma += $d;
      $dobra = !$dobra;
    }
    return $soma % 10 === 0;
  }

  public static function limitarTentativas(int $pedidoId): void
  {
    $chaves = ['cc:p' . $pedidoId => self::TENTATIVAS_PEDIDO, 'cc:' . substr(sha1(Http::ip()), 0, 40) => self::TENTATIVAS_IP];
    foreach ($chaves as $chave => $max) {
      $n = (int)Banco::valor('SELECT COUNT(*) FROM login_tentativas WHERE ip = ? AND momento > DATE_SUB(NOW(), INTERVAL 1 HOUR)', [$chave]);
      if ($n >= $max) throw new ErroApi('Muitas tentativas com cartão em pouco tempo. Aguarde 1 hora e tente de novo, ou fale com a loja.', 429);
    }
    foreach (array_keys($chaves) as $chave) Banco::executar('INSERT INTO login_tentativas (ip) VALUES (?)', [$chave]);
  }
}
