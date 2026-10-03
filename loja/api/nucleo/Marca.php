<?php
/**
 * Identidade da loja (white-label): logotipos, cores e dados da empresa definidos no painel.
 * Tudo o que é da marca do cliente sai daqui; o código não tem nome de loja fixo.
 */
final class Marca
{
  public const PASTA = 'marca';
  /** Campos de imagem do painel => chave de configuração. */
  public const IMAGENS = ['logo' => 'marca_logo', 'logo-escuro' => 'marca_logo_escuro', 'icone' => 'marca_icone'];

  /** Dados públicos da identidade, usados pela loja, pelo painel e pelos e-mails. */
  public static function publica(): array
  {
    $c = Config::todas();
    return [
      'marca' => [
        'logo' => Imagem::url(self::PASTA, $c['marca_logo'] ?: null),
        'logo_escuro' => Imagem::url(self::PASTA, $c['marca_logo_escuro'] ?: null),
        'icone' => Imagem::url(self::PASTA, $c['marca_icone'] ?: null),
        'mostrar_nome' => $c['marca_mostrar_nome'] !== '0',
      ],
      'cores' => Config::cores(),
      'areas' => (object)Config::coresAreas(), // cores próprias por área (escolhe o logotipo claro/escuro de cada fundo)
      'empresa' => self::empresa(),
    ];
  }

  /** Dados da empresa para exibição (documento e endereço já formatados). */
  public static function empresa(): array
  {
    $c = Config::todas();
    $doc = Validacao::digitos($c['empresa_documento']);
    $pj = $c['empresa_tipo'] !== 'pf';
    return [
      'tipo' => $pj ? 'pj' : 'pf',
      'documento' => $doc === '' ? '' : ($pj ? self::cnpj($doc) : self::cpf($doc)),
      'rotulo_documento' => $pj ? 'CNPJ' : 'CPF',
      'razao_social' => $c['empresa_razao_social'],
      'ie' => $c['empresa_ie'],
      'responsavel' => $c['empresa_responsavel'],
      'endereco' => self::endereco($c),
      'telefone' => $c['loja_telefone'],
    ];
  }

  /** "Rua X, 10 - Sala 2 - Centro, Cidade/UF - CEP 00000-000" (partes vazias somem). */
  private static function endereco(array $c): string
  {
    $cheio = fn(array $partes, string $sep) => implode($sep, array_filter($partes, fn($p) => $p !== ''));
    $linha = $cheio([$cheio([$c['empresa_rua'], $c['empresa_numero']], ', '), $c['empresa_complemento']], ' - ');
    $cidade = $c['empresa_cidade'] !== '' ? $cheio([$c['empresa_cidade'], $c['empresa_uf']], '/') : '';
    $local = $cheio([$c['empresa_bairro'], $cidade], ', ');
    $cep = strlen($c['empresa_cep']) === 8 ? 'CEP ' . substr($c['empresa_cep'], 0, 5) . '-' . substr($c['empresa_cep'], 5) : '';
    return $cheio([$linha, $local, $cep], ' - ');
  }

  /** Linha legal para rodapés: "Razão Social · CNPJ 00.000.000/0000-00 · Endereço". */
  public static function linhaLegal(): string
  {
    $e = self::empresa();
    $partes = [$e['razao_social'], $e['documento'] !== '' ? $e['rotulo_documento'] . ' ' . $e['documento'] : '', $e['endereco']];
    return implode(' · ', array_filter($partes, fn($p) => $p !== ''));
  }

  public static function cnpj(string $d): string
  {
    return strlen($d) === 14 ? preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $d) : $d;
  }

  public static function cpf(string $d): string
  {
    return strlen($d) === 11 ? preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $d) : $d;
  }

  /**
   * CSS do tema: as cores do painel substituem as variáveis-base de loja.css (--principal, --fundo, --secundaria,
   * --texto, --realce) e as derivadas que o CSS não sabe calcular sozinho (--titulo e --sobre-principal, que dependem
   * da claridade das cores, e o color-scheme dos campos nativos). As demais saem delas no próprio CSS.
   */
  public static function temaCss(): string
  {
    $c = Config::cores();
    // Cores por área: --a-{área}-fundo e --a-{área}-texto, só as definidas (as outras seguem as básicas no CSS).
    $areas = '';
    $proprias = Config::coresAreas();
    foreach ($proprias as $area => $cores) {
      foreach ($cores as $parte => $hex) $areas .= "--a-{$area}-{$parte}:{$hex};";
    }
    // Botão cheio do destaque: se a cor dos botões quase some no fundo do destaque, ele usa as cores do destaque invertidas.
    if (isset($proprias['destaque']['fundo'])) {
      $fundo = $proprias['destaque']['fundo'];
      if (Config::contraste($proprias['botao']['fundo'] ?? $c['principal'], $fundo) < 1.6) {
        $areas .= '--a-destaque-botao-fundo:' . ($proprias['destaque']['texto'] ?? $c['texto']) . ";--a-destaque-botao-texto:{$fundo};";
      }
    }
    // Textos pequenos do cabeçalho ("Loja oficial", "Atendimento"): a cor do texto da área, mais apagada.
    if (isset($proprias['cabecalho']['texto'])) {
      $areas .= "--a-cabecalho-suave:color-mix(in srgb,{$proprias['cabecalho']['texto']} 72%,var(--a-cabecalho-fundo,var(--fundo)));";
    }
    $esquema = $c['escuro'] ? 'dark' : 'light';
    return ":root{--principal:{$c['principal']};--fundo:{$c['fundo']};--secundaria:{$c['secundaria']};--texto:{$c['texto']};--realce:{$c['realce']};"
      . "--titulo:{$c['titulo']};--sobre-principal:{$c['sobre_principal']};color-scheme:{$esquema};{$areas}}\n";
  }

  /**
   * Endereço absoluto de um logotipo (para e-mails), ou null se não houver logo ou endereço da loja.
   * Pedindo o de fundo escuro sem ele ter sido enviado, não devolve nada (o logo claro some no fundo escuro).
   */
  public static function logoAbsoluto(string $chave = 'marca_logo'): ?string
  {
    $logo = Config::get($chave);
    $url = Http::urlLoja();
    return $logo !== '' && strpos($url, 'http') === 0 ? $url . Imagem::url(self::PASTA, $logo) : null;
  }
}
