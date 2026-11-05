<?php

declare(strict_types=1);

namespace Emissaoja\Tests;

use Emissaoja\Emissaoja;
use Emissaoja\Transporte;

/**
 * Transporte em memória para os testes: responde a partir de uma fila (ou de uma função), registra as requisições e
 * as esperas, e tem um relógio falso que só anda quando o cliente "dorme". Nenhum teste com ele usa a rede.
 */
final class TransporteFalso implements Transporte
{
    public const BASE = 'https://emissaoja.teste';
    public const CHAVE = 'ej_test_chave_dos_testes_0123456789';

    /** @var list<array{metodo: string, url: string, cabecalhos: array<string, string>, corpo: ?string, timeout: float}> */
    public array $requisicoes = [];
    /** @var list<float> */
    public array $esperas = [];
    public float $agora = 1000.0;

    /** @var list<array<string, mixed>|\Throwable> */
    private array $fila = [];
    /** @var (callable(string, string): (array<string, mixed>|\Throwable))|null */
    private $tratador = null;

    /**
     * Enfileira respostas: `['status' => int, 'corpo' => string|array, 'cabecalhos' => array]` ou uma exceção, que o
     * transporte lança no lugar de responder.
     *
     * @param array<string, mixed>|\Throwable ...$respostas
     */
    public function responder(array|\Throwable ...$respostas): self
    {
        foreach ($respostas as $resposta) {
            $this->fila[] = $resposta;
        }
        return $this;
    }

    /** Responde (depois da fila) com fn(string $metodo, string $url): array|\Throwable. */
    public function sempre(callable $tratador): self
    {
        $this->tratador = $tratador;
        return $this;
    }

    public function enviar(string $metodo, string $url, array $cabecalhos, ?string $corpo, float $timeout): array
    {
        $this->requisicoes[] = ['metodo' => $metodo, 'url' => $url, 'cabecalhos' => $cabecalhos, 'corpo' => $corpo, 'timeout' => $timeout];
        if ($this->fila !== []) {
            $resposta = array_shift($this->fila);
        } elseif ($this->tratador !== null) {
            $resposta = ($this->tratador)($metodo, $url);
        } else {
            // \Error de propósito: o cliente transforma \Exception do transporte em `rede`, e isto é erro do teste
            throw new \AssertionError("Requisição inesperada: $metodo $url");
        }
        if ($resposta instanceof \Throwable) {
            throw $resposta;
        }
        $conteudo = $resposta['corpo'] ?? '';
        return [
            'status' => $resposta['status'],
            'cabecalhos' => $resposta['cabecalhos'] ?? [],
            'corpo' => \is_string($conteudo) ? $conteudo : json_encode($conteudo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * Cliente ligado a este transporte, com chave de teste e o relógio falso.
     *
     * @param array<string, mixed> $opcoes argumentos nomeados do construtor que substituem os padrões do teste
     */
    public function cliente(array $opcoes = []): Emissaoja
    {
        return new Emissaoja(...array_merge([
            'chave' => self::CHAVE,
            'baseUrl' => self::BASE,
            'transporte' => $this,
            'dormir' => function (float $segundos): void {
                $this->esperas[] = $segundos;
                $this->agora += $segundos;
            },
            'relogio' => fn (): float => $this->agora,
        ], $opcoes));
    }

    /** @return list<string> "MÉTODO /caminho?query" de cada requisição, sem a base */
    public function caminhos(): array
    {
        return array_map(
            static fn (array $r): string => $r['metodo'] . ' ' . substr($r['url'], \strlen(self::BASE)),
            $this->requisicoes,
        );
    }

    /** @return array{metodo: string, url: string, cabecalhos: array<string, string>, corpo: ?string, timeout: float} */
    public function ultima(): array
    {
        $ultima = end($this->requisicoes);
        if ($ultima === false) {
            throw new \AssertionError('Nenhuma requisição feita');
        }
        return $ultima;
    }

    /**
     * @param array<array-key, mixed> $corpo
     * @param array<string, string>   $cabecalhos
     *
     * @return array<string, mixed>
     */
    public static function json(int $status, array $corpo, array $cabecalhos = []): array
    {
        return ['status' => $status, 'corpo' => $corpo, 'cabecalhos' => $cabecalhos + ['Content-Type' => 'application/json; charset=utf-8']];
    }

    /**
     * Erro no envelope da API: `{status: "ERROR", code, message, ...extras}`.
     *
     * @param array<string, mixed>  $extras
     * @param array<string, string> $cabecalhos
     *
     * @return array<string, mixed>
     */
    public static function erro(int $status, string $code, string $message, array $extras = [], array $cabecalhos = []): array
    {
        return self::json($status, ['status' => 'ERROR', 'code' => $code, 'message' => $message] + $extras, $cabecalhos);
    }
}
