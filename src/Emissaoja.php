<?php

declare(strict_types=1);

namespace Emissaoja;

use Emissaoja\Recursos\Cnpj;
use Emissaoja\Recursos\Emissoes;
use Emissaoja\Recursos\Empresa;
use Emissaoja\Recursos\Notas;
use Emissaoja\Recursos\Operacoes;
use Emissaoja\Recursos\Servicos;
use Emissaoja\Recursos\Tomadores;

/**
 * Cliente oficial da API do emissaoja (https://emissaoja.com.br/docs/): emite NFS-e no Emissor Nacional com o CNPJ e
 * a senha do portal, sem certificado digital, para MEI e empresas do Simples Nacional.
 *
 * ```php
 * $ej = new Emissaoja\Emissaoja();   // lê EMISSAOJA_KEY na primeira chamada que precisar dela
 * $tomador = $ej->tomadores->criar('12345678000195')['tomador'];
 * $pedido = $ej->notas->emitir('pedido-0001', [
 *     'tomador_tipo' => $tomador['tipo'], 'tomador_id' => $tomador['id'], 'valor' => 1500, 'descricao' => 'Consultoria',
 * ])['pedido'];
 * $final = $ej->notas->aguardar($pedido['id'])['pedido'];   // autorizada, recusada ou incerta
 * ```
 *
 * A chave de API é secreta: use este cliente só no servidor. As respostas são o JSON da API em arrays associativos;
 * erros viram {@see EmissaojaException}. A biblioteca não repete pedidos sozinha.
 */
class Emissaoja
{
    public const VERSAO = '0.1.0';
    /** Produção. */
    public const BASE_PADRAO = 'https://emissaoja.com.br';
    /** Sandbox: Emissor Nacional simulado, grátis, nada vai ao fisco (chaves `ej_test_`). */
    public const BASE_SANDBOX = 'https://sandbox.emissaoja.com.br';
    /** Segundos por chamada. */
    public const TIMEOUT_PADRAO = 30.0;

    private const OPCOES = ['chave', 'baseUrl', 'timeout', 'transporte', 'dormir', 'relogio'];

    /** URL base da API, sem barra no fim e sem `/v1`. */
    public readonly string $baseUrl;
    /** Tempo máximo de cada chamada, em segundos (`0` = sem limite). */
    public readonly float $timeout;

    /** Empresa, conexão com o Emissor Nacional, onboarding e perguntas fiscais. */
    public readonly Empresa $empresa;
    /** Tomadores (os clientes da nota). */
    public readonly Tomadores $tomadores;
    /** Serviços da empresa e catálogo nacional de códigos de serviço. */
    public readonly Servicos $servicos;
    /** Emitir, acompanhar, listar, baixar (PDF e XML) e cancelar notas. */
    public readonly Notas $notas;
    /** Pedidos de emissão. */
    public readonly Emissoes $emissoes;
    /** Operações em andamento (cancelamento). */
    public readonly Operacoes $operacoes;
    /** Consulta de CNPJ na base da Receita Federal. */
    public readonly Cnpj $cnpj;

    private readonly Conexao $conexao;

    /**
     * Aceita argumentos nomeados ou um array de opções com as mesmas chaves:
     *
     * ```php
     * new Emissaoja(chave: 'ej_live_...', timeout: 15);
     * new Emissaoja(['chave' => 'ej_test_...', 'baseUrl' => Emissaoja::BASE_SANDBOX]);
     * ```
     *
     * Com os dois, o argumento nomeado vale mais que a mesma chave no array.
     *
     * @param array<string, mixed>|string|null $chave      chave de API (`ej_live_...` em produção, `ej_test_...` no
     *                                                     sandbox) ou o array de opções. Omitida: a variável de
     *                                                     ambiente `EMISSAOJA_KEY`, lida na primeira chamada que
     *                                                     precisar dela.
     * @param string|null                      $baseUrl    padrão {@see self::BASE_PADRAO}; sandbox:
     *                                                     {@see self::BASE_SANDBOX}
     * @param int|float|null                   $timeout    segundos por chamada (padrão 30; `0` = sem limite)
     * @param Transporte|null                  $transporte padrão {@see CurlTransporte}
     * @param callable|null                    $dormir     `fn(float $segundos): void`, usado entre as consultas de
     *                                                     `aguardar` (útil em testes)
     * @param callable|null                    $relogio    `fn(): float`, instante em segundos (útil em testes)
     *
     * @throws \InvalidArgumentException opção desconhecida ou de tipo errado, ou baseUrl sem http(s)://
     */
    public function __construct(
        #[\SensitiveParameter] array|string|null $chave = null,
        ?string $baseUrl = null,
        int|float|null $timeout = null,
        ?Transporte $transporte = null,
        ?callable $dormir = null,
        ?callable $relogio = null,
    ) {
        if (\is_array($chave)) {
            $opcoes = self::lerOpcoes($chave);
            $chave = $opcoes['chave'];
            $baseUrl ??= $opcoes['baseUrl'];
            $timeout ??= $opcoes['timeout'];
            $transporte ??= $opcoes['transporte'];
            $dormir ??= $opcoes['dormir'];
            $relogio ??= $opcoes['relogio'];
        }
        $chave = $chave !== null ? trim($chave) : null;

        $this->baseUrl = self::normalizarBase($baseUrl);
        $this->timeout = max(0.0, (float) ($timeout ?? self::TIMEOUT_PADRAO));
        $this->conexao = new Conexao(
            $chave !== '' ? $chave : null,
            $this->baseUrl,
            $this->timeout,
            $transporte ?? new CurlTransporte(),
            $dormir,
            $relogio,
        );

        $this->empresa = new Empresa($this->conexao);
        $this->tomadores = new Tomadores($this->conexao);
        $this->servicos = new Servicos($this->conexao);
        $this->emissoes = new Emissoes($this->conexao);
        $this->notas = new Notas($this->conexao, $this->emissoes);
        $this->operacoes = new Operacoes($this->conexao);
        $this->cnpj = new Cnpj($this->conexao);
    }

    /**
     * Status do serviço, sem chave (`GET /v1/status`):
     * `['ok' => true, 'versao' => '0.1.0', 'motor' => 'ok'|'degradado'|'simulado', 'ambiente' => 'producao'|'sandbox', 'tempo' => '...']`.
     *
     * @return array<array-key, mixed>
     */
    public function status(): array
    {
        return $this->conexao->json('GET', '/v1/status', autenticar: false);
    }

    // Os recursos também como métodos: a Facade do Laravel só repassa métodos (Emissaoja::notas()->emitir(...)).

    /** O mesmo que `$ej->empresa`. */
    public function empresa(): Empresa
    {
        return $this->empresa;
    }

    /** O mesmo que `$ej->tomadores`. */
    public function tomadores(): Tomadores
    {
        return $this->tomadores;
    }

    /** O mesmo que `$ej->servicos`. */
    public function servicos(): Servicos
    {
        return $this->servicos;
    }

    /** O mesmo que `$ej->notas`. */
    public function notas(): Notas
    {
        return $this->notas;
    }

    /** O mesmo que `$ej->emissoes`. */
    public function emissoes(): Emissoes
    {
        return $this->emissoes;
    }

    /** O mesmo que `$ej->operacoes`. */
    public function operacoes(): Operacoes
    {
        return $this->operacoes;
    }

    /** O mesmo que `$ej->cnpj`. */
    public function cnpj(): Cnpj
    {
        return $this->cnpj;
    }

    /**
     * Esconde a chave em var_dump() e print_r().
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->conexao->__debugInfo();
    }

    /**
     * @param array<array-key, mixed> $opcoes
     *
     * @return array{chave: ?string, baseUrl: ?string, timeout: int|float|null, transporte: ?Transporte, dormir: ?callable, relogio: ?callable}
     */
    private static function lerOpcoes(array $opcoes): array
    {
        foreach (array_keys($opcoes) as $nome) {
            if (!\in_array($nome, self::OPCOES, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Opção desconhecida "%s". As opções são: %s.',
                    $nome,
                    implode(', ', self::OPCOES),
                ));
            }
        }
        $chave = $opcoes['chave'] ?? null;
        $baseUrl = $opcoes['baseUrl'] ?? null;
        $timeout = $opcoes['timeout'] ?? null;
        $transporte = $opcoes['transporte'] ?? null;
        $dormir = $opcoes['dormir'] ?? null;
        $relogio = $opcoes['relogio'] ?? null;
        self::exigir('chave', $chave === null || \is_string($chave), 'texto');
        self::exigir('baseUrl', $baseUrl === null || \is_string($baseUrl), 'texto');
        self::exigir('timeout', $timeout === null || \is_int($timeout) || \is_float($timeout), 'um número de segundos');
        self::exigir('transporte', $transporte === null || $transporte instanceof Transporte, 'um ' . Transporte::class);
        self::exigir('dormir', $dormir === null || \is_callable($dormir), 'uma função fn(float $segundos): void');
        self::exigir('relogio', $relogio === null || \is_callable($relogio), 'uma função fn(): float');

        return [
            'chave' => $chave,
            'baseUrl' => $baseUrl,
            'timeout' => $timeout,
            'transporte' => $transporte,
            'dormir' => $dormir,
            'relogio' => $relogio,
        ];
    }

    private static function exigir(string $opcao, bool $valida, string $esperado): void
    {
        if (!$valida) {
            throw new \InvalidArgumentException(sprintf('A opção "%s" deve ser %s.', $opcao, $esperado));
        }
    }

    private static function normalizarBase(?string $baseUrl): string
    {
        $base = rtrim(trim($baseUrl ?? ''), '/');
        if ($base === '') {
            return self::BASE_PADRAO;
        }
        if (str_ends_with(strtolower($base), '/v1')) {
            $base = rtrim(substr($base, 0, -3), '/');
        }
        if (preg_match('#\Ahttps?://[^\s/?\#]+(?:/[^\s?\#]*)?\z#i', $base) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'A baseUrl deve começar com https:// e não ter ? nem # (por exemplo %s); recebi "%s".',
                self::BASE_SANDBOX,
                $base,
            ));
        }
        return $base;
    }
}
