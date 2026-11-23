<?php

declare(strict_types=1);

namespace Emissaoja;

/**
 * Faz as chamadas HTTP do cliente: cabeçalhos, chave de API, query string, JSON e erros.
 *
 * Uso interno: os recursos (`$ej->notas`, `$ej->empresa`...) usam esta classe; quem usa a biblioteca não precisa dela.
 *
 * @internal
 */
final class Conexao
{
    private ?string $chave;
    /** @var callable(float): void */
    private $dormir;
    /** @var callable(): (int|float) */
    private $relogio;

    /**
     * @param string|null   $chave   null = ler EMISSAOJA_KEY na primeira chamada que precisar dela
     * @param callable|null $dormir  fn(float $segundos): void
     * @param callable|null $relogio fn(): float, em segundos (monotônico)
     */
    public function __construct(
        #[\SensitiveParameter] ?string $chave,
        public readonly string $baseUrl,
        public readonly float $timeout,
        private readonly Transporte $transporte,
        ?callable $dormir = null,
        ?callable $relogio = null,
    ) {
        $this->chave = $chave;
        $this->dormir = $dormir ?? static function (float $segundos): void {
            if ($segundos <= 0) {
                return;
            }
            $inteiros = (int) floor($segundos);
            if ($inteiros > 0) {
                sleep($inteiros);
            }
            $micro = (int) round(($segundos - $inteiros) * 1_000_000);
            if ($micro > 0) {
                usleep($micro);
            }
        };
        $this->relogio = $relogio ?? static function (): float {
            $ns = hrtime(true);
            return \is_int($ns) || \is_float($ns) ? $ns / 1e9 : microtime(true);
        };
    }

    /**
     * Chamada que responde JSON. Resposta fora de 2xx vira {@see EmissaojaException}.
     *
     * @param array<array-key, mixed>|null $corpo      `null` = sem corpo (e sem Content-Type); `[]` vai como `{}`
     * @param array<array-key, mixed>      $query      valores `null` e `''` ficam de fora; listas viram `a,b`
     * @param array<string, string>        $cabecalhos cabeçalhos extras (por exemplo `Idempotency-Key`)
     *
     * @return array<array-key, mixed> o JSON da resposta (`[]` quando a resposta vem vazia)
     */
    public function json(string $metodo, string $caminho, ?array $corpo = null, array $query = [], array $cabecalhos = [], bool $autenticar = true): array
    {
        $r = $this->enviar($metodo, $caminho, $corpo, $query, $cabecalhos, $autenticar);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw self::erroDaResposta($r);
        }
        if (trim($r['corpo']) === '') {
            return [];
        }
        try {
            $json = json_decode($r['corpo'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $json = null;
        }
        if (!\is_array($json)) {
            throw new EmissaojaException(
                $r['status'],
                'resposta_invalida',
                sprintf('A API respondeu HTTP %d sem JSON. Confira a baseUrl (%s).', $r['status'], $this->baseUrl),
                [],
                self::requestId($r['cabecalhos']),
            );
        }
        return $json;
    }

    /** `GET` que devolve o corpo como veio (PDF, XML). Resposta fora de 2xx vira {@see EmissaojaException}. */
    public function bytes(string $caminho): string
    {
        $r = $this->enviar('GET', $caminho, null, [], [], true);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw self::erroDaResposta($r);
        }
        return $r['corpo'];
    }

    /** Espera entre as consultas de `aguardar` (a função `dormir` das opções). */
    public function dormir(float $segundos): void
    {
        ($this->dormir)(max(0.0, $segundos));
    }

    /** Instante atual em segundos (a função `relogio` das opções). */
    public function agora(): float
    {
        return (float) ($this->relogio)();
    }

    /** Caminho com os segmentos codificados: `caminho('/v1/tomadores/%s/%s', $tipo, $id)`. */
    public static function caminho(string $modelo, string ...$segmentos): string
    {
        return vsprintf($modelo, array_map('rawurlencode', $segmentos));
    }

    /**
     * Tira os campos `null` (do primeiro nível).
     *
     * @param array<array-key, mixed> $campos
     *
     * @return array<array-key, mixed>
     */
    public static function semNulos(array $campos): array
    {
        return array_filter($campos, static fn (mixed $valor): bool => $valor !== null);
    }

    /**
     * Esconde a chave em var_dump() e print_r().
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
            'chave' => $this->chave !== null ? '***' : '(EMISSAOJA_KEY, lida na primeira chamada)',
            'transporte' => $this->transporte::class,
        ];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException(
            'O cliente do emissaoja não pode ser serializado: ele guarda a chave de API. '
            . 'Em jobs de fila, receba o cliente no handle() em vez de guardá-lo numa propriedade do job.',
        );
    }

    /** @param array<array-key, mixed> $dados */
    public function __unserialize(array $dados): void
    {
        throw new \LogicException('O cliente do emissaoja não pode ser desserializado.');
    }

    // ------------------------------------------------------------------ internos

    /**
     * @param array<array-key, mixed>|null $corpo
     * @param array<array-key, mixed>      $query
     * @param array<string, string>        $extras
     *
     * @return array{status: int, cabecalhos: array<string, string>, corpo: string}
     */
    private function enviar(string $metodo, string $caminho, ?array $corpo, array $query, array $extras, bool $autenticar): array
    {
        $cabecalhos = [
            'Accept' => 'application/json',
            'User-Agent' => 'emissaoja-php/' . Emissaoja::VERSAO . ' PHP/' . \PHP_VERSION,
        ];
        if ($autenticar) {
            $cabecalhos['Authorization'] = 'Bearer ' . $this->chave();
        }
        $json = null;
        if ($corpo !== null) {
            $json = self::paraJson($corpo);
            $cabecalhos['Content-Type'] = 'application/json';
        }
        foreach ($extras as $nome => $valor) {
            $cabecalhos[$nome] = $valor;
        }
        $url = $this->baseUrl . $caminho . self::query($query);

        try {
            $resposta = $this->transporte->enviar($metodo, $url, $cabecalhos, $json, $this->timeout);
        } catch (EmissaojaException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new EmissaojaException(0, 'rede', 'Falha ao chamar a API do emissaoja: ' . $e->getMessage(), [], null, $e);
        }
        return self::normalizar($resposta);
    }

    private function chave(): string
    {
        if ($this->chave === null) {
            $this->chave = self::env('EMISSAOJA_KEY') ?? throw new EmissaojaException(
                0,
                'chave_invalida',
                'Informe a chave de API: new Emissaoja\Emissaoja(chave: \'ej_live_...\') ou a variável de ambiente '
                . 'EMISSAOJA_KEY. A chave é criada no app, em Conta → API (https://emissaoja.com.br/app?origem=lib-php&utm_source=packagist&utm_medium=erro&utm_campaign=app).',
            );
        }
        if (preg_match('/[\x00-\x20\x7F]/', $this->chave) === 1) {
            throw new EmissaojaException(0, 'chave_invalida', 'A chave de API tem espaço ou quebra de linha no meio; copie de novo em Conta → API.');
        }
        return $this->chave;
    }

    private static function env(string $nome): ?string
    {
        $valor = getenv($nome);
        if (!\is_string($valor) || trim($valor) === '') {
            $valor = $_ENV[$nome] ?? $_SERVER[$nome] ?? null;
        }
        return \is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
    }

    /**
     * Corpo em JSON (snake_case, como a API). `[]` vira `{}`: a API espera um objeto.
     *
     * @param array<array-key, mixed> $corpo
     */
    private static function paraJson(array $corpo): string
    {
        if ($corpo === []) {
            return '{}';
        }
        // Com serialize_precision antigo (17, de php.ini de antes do PHP 7.1), json_encode(13.13) sai
        // 13.130000000000001 e a API recusa o valor. Durante a codificação, usa o padrão atual (-1): 13.13.
        $precisao = ini_get('serialize_precision');
        $trocar = \is_string($precisao) && $precisao !== '-1' && \function_exists('ini_set');
        if ($trocar) {
            ini_set('serialize_precision', '-1');
        }
        try {
            return json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new EmissaojaException(
                0,
                'validation_error',
                'Os campos não puderam ser convertidos em JSON (' . $e->getMessage() . '). Textos precisam estar em '
                . 'UTF-8: converta, por exemplo, com mb_convert_encoding($texto, \'UTF-8\', \'ISO-8859-1\').',
                [],
                null,
                $e,
            );
        } finally {
            if ($trocar) {
                ini_set('serialize_precision', $precisao);
            }
        }
    }

    /** @param array<array-key, mixed> $parametros */
    private static function query(array $parametros): string
    {
        $partes = [];
        foreach ($parametros as $nome => $valor) {
            $texto = self::textoDaQuery((string) $nome, $valor);
            if ($texto !== '') {
                $partes[] = rawurlencode((string) $nome) . '=' . rawurlencode($texto);
            }
        }
        return $partes === [] ? '' : '?' . implode('&', $partes);
    }

    private static function textoDaQuery(string $nome, mixed $valor): string
    {
        if ($valor === null) {
            return '';
        }
        if (\is_bool($valor)) {
            return $valor ? '1' : '0';
        }
        if (\is_string($valor) || \is_int($valor) || \is_float($valor) || $valor instanceof \Stringable) {
            return (string) $valor;
        }
        if (\is_array($valor)) {
            $itens = [];
            foreach ($valor as $item) {
                $texto = self::textoDaQuery($nome, $item);
                if ($texto !== '') {
                    $itens[] = $texto;
                }
            }
            return implode(',', $itens);
        }
        throw new \InvalidArgumentException(sprintf(
            'O filtro "%s" deve ser texto, número, booleano ou lista (recebeu %s).',
            $nome,
            get_debug_type($valor),
        ));
    }

    /**
     * @param array<array-key, mixed> $resposta
     *
     * @return array{status: int, cabecalhos: array<string, string>, corpo: string}
     */
    private static function normalizar(array $resposta): array
    {
        $status = $resposta['status'] ?? null;
        $corpo = $resposta['corpo'] ?? null;
        $recebidos = $resposta['cabecalhos'] ?? [];
        if (!\is_int($status) || !\is_string($corpo) || !\is_array($recebidos)) {
            throw new \UnexpectedValueException(
                'O transporte deve devolver array{status: int, cabecalhos: array<string, string>, corpo: string}.',
            );
        }
        $cabecalhos = [];
        foreach ($recebidos as $nome => $valor) {
            $cabecalhos[strtolower((string) $nome)] = \is_array($valor) ? implode(', ', $valor) : (string) $valor;
        }
        return ['status' => $status, 'cabecalhos' => $cabecalhos, 'corpo' => $corpo];
    }

    /** @param array{status: int, cabecalhos: array<string, string>, corpo: string} $resposta */
    private static function erroDaResposta(array $resposta): EmissaojaException
    {
        $status = $resposta['status'];
        $texto = trim($resposta['corpo']);
        $json = null;
        if (str_starts_with($texto, '{')) {
            try {
                $json = json_decode($texto, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $json = null;
            }
        }
        if (\is_array($json)) {
            $erro = \is_string($json['code'] ?? null) && $json['code'] !== '' ? $json['code'] : 'erro_desconhecido';
            $mensagem = \is_string($json['message'] ?? null) && $json['message'] !== '' ? $json['message'] : 'HTTP ' . $status;
            $extras = $json;
            unset($extras['status'], $extras['message'], $extras['code']);
        } else {
            $erro = 'erro_desconhecido';
            $mensagem = $texto !== '' ? self::inicio($texto, 200) : 'HTTP ' . $status;
            $extras = [];
        }
        $destino = $resposta['cabecalhos']['location'] ?? '';
        if ($status >= 300 && $status < 400 && $destino !== '') {
            $mensagem = sprintf(
                'HTTP %d: a API redirecionou para %s e o cliente não segue redirecionamentos. Confira a baseUrl.',
                $status,
                $destino,
            );
        }
        return new EmissaojaException($status, $erro, $mensagem, $extras, self::requestId($resposta['cabecalhos']));
    }

    /** @param array<string, string> $cabecalhos */
    private static function requestId(array $cabecalhos): ?string
    {
        $id = trim($cabecalhos['x-request-id'] ?? '');
        return $id !== '' ? $id : null;
    }

    /** Até $limite caracteres (UTF-8) do começo do texto; texto que não é UTF-8 válido é cortado por bytes. */
    private static function inicio(string $texto, int $limite): string
    {
        if (preg_match('/\A.{0,' . $limite . '}/su', $texto, $m) === 1) {
            return $m[0];
        }
        return substr($texto, 0, $limite);
    }
}
