<?php

declare(strict_types=1);

namespace Emissaoja;

/**
 * Transporte HTTP do cliente. O padrão é {@see CurlTransporte}. Implemente esta interface para usar outro cliente
 * HTTP (Guzzle, Symfony HttpClient, um proxy com log) ou para testar o seu código sem chamar a API.
 */
interface Transporte
{
    /**
     * Faz a requisição e devolve a resposta como veio, qualquer que seja o status HTTP (2xx, 3xx, 4xx ou 5xx).
     * Não siga redirecionamentos: a chave de API não deve ir para outro endereço.
     *
     * @param string                $metodo     `GET`, `POST`, `PUT` ou `DELETE`
     * @param string                $url        URL completa, já com a query string
     * @param array<string, string> $cabecalhos nome => valor (`Authorization`, `Accept`, `User-Agent`,
     *                                          `Content-Type`, `Idempotency-Key`)
     * @param string|null           $corpo      JSON já codificado, ou `null` quando a requisição não tem corpo (nesse
     *                                          caso não mande `Content-Type`)
     * @param float                 $timeout    tempo máximo da chamada, em segundos (`0` = sem limite)
     *
     * @return array{status: int, cabecalhos: array<string, string>, corpo: string} nomes de cabeçalho em minúsculas
     *
     * @throws EmissaojaException status `0` com `erro` `rede` (conexão, DNS, TLS) ou `tempo_esgotado`. Qualquer outra
     *                            `\Exception` lançada aqui chega a quem chamou como `rede`.
     */
    public function enviar(string $metodo, string $url, #[\SensitiveParameter] array $cabecalhos, ?string $corpo, float $timeout): array;
}
