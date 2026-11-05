<?php

declare(strict_types=1);

namespace Emissaoja\Recursos;

use Emissaoja\Conexao;

/**
 * Pedidos de emissão: `na_fila` → `emitindo` → `confirmando` → `autorizada`, `recusada` ou `incerta`.
 * Para esperar o fim de um pedido, use `$ej->notas->aguardar($id)`.
 */
class Emissoes
{
    /** @internal use `$ej->emissoes` */
    public function __construct(private readonly Conexao $conexao)
    {
    }

    /**
     * O pedido, com `estado` e `proxima_consulta_ms` (quando consultar de novo) (`GET /v1/emissoes/{id}`).
     *
     * @return array<array-key, mixed> `['pedido' => [...]]`
     */
    public function obter(string $id): array
    {
        return $this->conexao->json('GET', Conexao::caminho('/v1/emissoes/%s', $id));
    }

    /**
     * Pedidos em andamento, ou nos estados pedidos (`GET /v1/emissoes?estados=`).
     *
     * @param array<string, mixed> $filtros `estados`: lista (`['na_fila', 'incerta']`) ou texto separado por vírgula
     *
     * @return array<array-key, mixed> `['pedidos' => [...]]`
     */
    public function listar(array $filtros = []): array
    {
        return $this->conexao->json('GET', '/v1/emissoes', query: $filtros);
    }

    /**
     * Confere no portal um pedido `incerta` (`POST /v1/emissoes/{id}/conferir`, 202; só leitura no portal).
     *
     * @return array<array-key, mixed> `['pedido' => [...]]`
     */
    public function conferir(string $id): array
    {
        return $this->conexao->json('POST', Conexao::caminho('/v1/emissoes/%s/conferir', $id));
    }
}
