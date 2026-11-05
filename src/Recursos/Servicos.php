<?php

declare(strict_types=1);

namespace Emissaoja\Recursos;

use Emissaoja\Conexao;

/**
 * Serviços da empresa (o que vai na nota) e o catálogo nacional de códigos de serviço.
 * `trib_issqn` aceita `tributavel` e `nao_incidencia`; isenção, imunidade e exportação ficam fora do emissaoja.
 */
class Servicos
{
    /** @internal use `$ej->servicos` */
    public function __construct(private readonly Conexao $conexao)
    {
    }

    /**
     * Lista os serviços (`GET /v1/servicos`).
     *
     * @param array<string, mixed> $filtros `ativos`: `'1'`, `'0'` ou `'todos'`
     *
     * @return array<array-key, mixed> `['servicos' => [...]]`
     */
    public function listar(array $filtros = []): array
    {
        return $this->conexao->json('GET', '/v1/servicos', query: $filtros);
    }

    /**
     * Cria um serviço (`POST /v1/servicos`): `descricao`, `c_trib_nac`, `trib_issqn` (`tributavel` ou
     * `nao_incidencia`) e `retem_iss` obrigatórios; `nbs`, `c_trib_mun`, `tp_oper`, `aliquota_iss` opcionais.
     *
     * @param array<string, mixed> $campos
     *
     * @return array<array-key, mixed> `['servico' => [...]]`
     */
    public function criar(array $campos): array
    {
        return $this->conexao->json('POST', '/v1/servicos', $campos);
    }

    /**
     * Edição parcial (`PUT /v1/servicos/{id}`). Aqui `null` vai no corpo: limpa o campo.
     *
     * @param array<string, mixed> $campos só os que mudam
     *
     * @return array<array-key, mixed> `['servico' => [...], 'vinculos_atualizados' => int]`
     */
    public function atualizar(string $id, array $campos): array
    {
        return $this->conexao->json('PUT', Conexao::caminho('/v1/servicos/%s', $id), $campos);
    }

    /**
     * Desativa o serviço (`DELETE /v1/servicos/{id}`); as notas emitidas continuam apontando para ele. O último
     * serviço ativo não pode ser desativado (409 `ultimo_servico_ativo`).
     *
     * @return array<array-key, mixed> `['servico' => [...]]`
     */
    public function desativar(string $id): array
    {
        return $this->conexao->json('DELETE', Conexao::caminho('/v1/servicos/%s', $id));
    }

    /**
     * Busca no catálogo nacional de códigos de serviço (`GET /v1/catalogo/servicos?q=&limite=`).
     *
     * @param string   $q      texto, por exemplo `desenvolvimento de sistemas`
     * @param int|null $limite até 50
     *
     * @return array<array-key, mixed> `['itens' => [['c_trib_nac' => ..., 'descricao' => ..., 'item_lc116' => ..., 'nbs_sugeridos' => [...]], ...]]`
     */
    public function catalogo(string $q, ?int $limite = null): array
    {
        return $this->conexao->json('GET', '/v1/catalogo/servicos', query: ['q' => $q, 'limite' => $limite ?: null]);
    }
}
