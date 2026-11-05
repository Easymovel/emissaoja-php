<?php

declare(strict_types=1);

namespace Emissaoja\Recursos;

use Emissaoja\Conexao;
use Emissaoja\EmissaojaException;

/**
 * Operações em segundo plano, como o cancelamento: `processando` → `concluida` ou `falhou`.
 */
class Operacoes
{
    /** @internal use `$ej->operacoes` */
    public function __construct(private readonly Conexao $conexao)
    {
    }

    /**
     * A operação (`GET /v1/operacoes/{id}`): `estado`, `resultado` e, quando falha, `erro` (`code`, `message`).
     *
     * @return array<array-key, mixed> `['operacao' => [...]]`
     */
    public function obter(string $id): array
    {
        return $this->conexao->json('GET', Conexao::caminho('/v1/operacoes/%s', $id));
    }

    /**
     * Consulta a operação até sair de `processando` e devolve a última resposta.
     *
     * @param float $intervalo segundos entre as consultas
     * @param float $timeout   segundos no total
     *
     * @return array<array-key, mixed> `['operacao' => [...]]`
     *
     * @throws EmissaojaException `tempo_esgotado` (status 0) se a operação não terminar a tempo
     */
    public function aguardar(string $id, float $intervalo = 2.0, float $timeout = 180.0): array
    {
        $fim = $this->conexao->agora() + $timeout;
        while (true) {
            $resposta = $this->obter($id);
            $operacao = $resposta['operacao'] ?? null;
            if (!\is_array($operacao) || ($operacao['estado'] ?? null) !== 'processando') {
                return $resposta;
            }
            if ($this->conexao->agora() >= $fim) {
                throw new EmissaojaException(0, 'tempo_esgotado', 'A operação não terminou a tempo.');
            }
            $this->conexao->dormir($intervalo);
        }
    }
}
