<?php

declare(strict_types=1);

namespace Emissaoja\Recursos;

use Emissaoja\Conexao;

/**
 * Tomadores: os clientes que recebem a nota. Tomador não se apaga (as notas emitidas apontam para ele) e o documento
 * não muda.
 */
class Tomadores
{
    /** @internal use `$ej->tomadores` */
    public function __construct(private readonly Conexao $conexao)
    {
    }

    /**
     * Lista paginada (`GET /v1/tomadores`).
     *
     * @param array<string, mixed> $filtros `busca`, `pagina`, `limite` (até 50), `ordem` (`nome` ou `recentes`);
     *                                      `null` e `''` ficam de fora
     *
     * @return array<array-key, mixed> `['tomadores' => [...], 'total' => ..., 'pagina' => ..., 'limite' => ...]`
     */
    public function listar(array $filtros = []): array
    {
        return $this->conexao->json('GET', '/v1/tomadores', query: $filtros);
    }

    /**
     * Busca rápida por nome ou documento (`GET /v1/tomadores/busca?q=`).
     *
     * @return array<array-key, mixed> `['tomadores' => [...]]`
     */
    public function buscar(string $q): array
    {
        return $this->conexao->json('GET', '/v1/tomadores/busca', query: ['q' => $q]);
    }

    /**
     * Cria o tomador (`POST /v1/tomadores`). CNPJ: nome e endereço vêm da Receita. CPF: informe o nome.
     * Se já existia, devolve o mesmo tomador com `ja_existia: true`.
     *
     * @param string      $documento CNPJ ou CPF
     * @param string|null $nome      obrigatório para CPF
     *
     * @return array<array-key, mixed> `['tomador' => ['tipo' => 'pj'|'pf', 'id' => ..., ...], 'ja_existia' => bool]`
     */
    public function criar(string $documento, ?string $nome = null): array
    {
        $corpo = ['documento' => $documento];
        if ($nome !== null && $nome !== '') {
            $corpo['nome'] = $nome;
        }
        return $this->conexao->json('POST', '/v1/tomadores', $corpo);
    }

    /**
     * Ficha do tomador (`GET /v1/tomadores/{tipo}/{id}`).
     *
     * @param string $tipo `pj` ou `pf`
     *
     * @return array<array-key, mixed> `['tomador' => [...]]`
     */
    public function obter(string $tipo, string $id): array
    {
        return $this->conexao->json('GET', Conexao::caminho('/v1/tomadores/%s/%s', $tipo, $id));
    }

    /**
     * Edição parcial de endereço e contato (`PUT /v1/tomadores/{tipo}/{id}`): `nome`, `cep`, `endereco`, `numero`,
     * `complemento`, `bairro`, `cidade`, `estado`, `codigo_municipio_ibge`, `telefone`, `email`,
     * `inscricao_municipal`, `atualizar_pela_receita`. O documento não muda.
     *
     * @param string               $tipo   `pj` ou `pf`
     * @param array<string, mixed> $campos só os que mudam
     *
     * @return array<array-key, mixed> `['tomador' => [...]]`
     */
    public function atualizar(string $tipo, string $id, array $campos): array
    {
        return $this->conexao->json('PUT', Conexao::caminho('/v1/tomadores/%s/%s', $tipo, $id), $campos);
    }
}
