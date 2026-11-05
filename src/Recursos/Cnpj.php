<?php

declare(strict_types=1);

namespace Emissaoja\Recursos;

use Emissaoja\Conexao;

/**
 * Consulta de CNPJ na base pública da Receita Federal. Não cria tomador: para isso use `$ej->tomadores->criar()`,
 * que já preenche nome e endereço pelo CNPJ.
 */
class Cnpj
{
    /** @internal use `$ej->cnpj` */
    public function __construct(private readonly Conexao $conexao)
    {
    }

    /**
     * Dados cadastrais do CNPJ (`GET /v1/cnpj/{cnpj}`): razão social, situação, CNAEs, endereço, Simples e MEI e
     * sócios (com CPF mascarado). Aceita o CNPJ com ou sem pontuação; só os números vão na URL.
     *
     * @return array<array-key, mixed>
     */
    public function consultar(string $cnpj): array
    {
        return $this->conexao->json('GET', Conexao::caminho('/v1/cnpj/%s', preg_replace('/\D+/', '', $cnpj) ?? ''));
    }
}
