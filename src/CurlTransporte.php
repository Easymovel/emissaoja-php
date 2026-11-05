<?php

declare(strict_types=1);

namespace Emissaoja;

/**
 * Transporte HTTP padrão, com a extensão curl do PHP (sem dependências).
 *
 * - Reaproveita a conexão entre as chamadas (útil em `aguardar`, que consulta várias vezes).
 * - Não segue redirecionamentos: a chave de API nunca vai para outro endereço.
 * - POST e PUT sem corpo saem com `Content-Length: 0` e sem `Content-Type`.
 * - Falha de rede vira {@see EmissaojaException} `rede`; estouro do tempo, `tempo_esgotado` (status 0 nos dois).
 */
final class CurlTransporte implements Transporte
{
    private ?\CurlHandle $handle = null;

    public function enviar(string $metodo, string $url, #[\SensitiveParameter] array $cabecalhos, ?string $corpo, float $timeout): array
    {
        $ch = $this->handle();

        $linhas = [];
        foreach ($cabecalhos as $nome => $valor) {
            $linhas[] = $nome . ': ' . $valor;
        }
        $recebidos = [];
        $ms = $timeout > 0 ? (int) ceil($timeout * 1000) : 0;
        $opcoes = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => $ms,
            CURLOPT_CONNECTTIMEOUT_MS => $ms > 0 ? min($ms, 10000) : 10000,
            CURLOPT_ENCODING => '',
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $linha) use (&$recebidos): int {
                $partes = explode(':', $linha, 2);
                if (\count($partes) === 2) {
                    $recebidos[strtolower(trim($partes[0]))] = trim($partes[1]);
                } elseif (str_starts_with($linha, 'HTTP/')) {
                    $recebidos = []; // nova resposta (100 Continue, proxy): descarta os cabeçalhos anteriores
                }
                return \strlen($linha);
            },
        ];
        if ($metodo === 'GET') {
            $opcoes[CURLOPT_HTTPGET] = true;
        } else {
            // CUSTOMREQUEST só troca o nome do método; o corpo, quando há, vai por POSTFIELDS.
            $opcoes[CURLOPT_CUSTOMREQUEST] = $metodo;
            if ($corpo !== null) {
                $opcoes[CURLOPT_POSTFIELDS] = $corpo;
                $linhas[] = 'Expect:'; // sem "Expect: 100-continue": o corpo vai junto, sem esperar o servidor
            } elseif ($metodo === 'POST' || $metodo === 'PUT' || $metodo === 'PATCH') {
                // Sem corpo, nada de CURLOPT_POST ou CURLOPT_POSTFIELDS: o curl poria Content-Type
                // application/x-www-form-urlencoded, que a API recusa (415). Vai só o método, com Content-Length: 0.
                $linhas[] = 'Content-Length: 0';
            }
        }
        $opcoes[CURLOPT_HTTPHEADER] = $linhas;
        if (!curl_setopt_array($ch, $opcoes)) {
            throw new EmissaojaException(0, 'rede', 'Não foi possível configurar a requisição no curl.');
        }

        $resposta = curl_exec($ch);
        if (!\is_string($resposta)) {
            $numero = curl_errno($ch);
            $detalhe = curl_error($ch);
            if ($numero === CURLE_OPERATION_TIMEDOUT) {
                throw new EmissaojaException(0, 'tempo_esgotado', $timeout > 0
                    ? sprintf('A API do emissaoja não respondeu em %s s.', self::segundos($timeout))
                    : 'A API do emissaoja não respondeu a tempo.');
            }
            // 60 e 77: certificado do servidor não verificado ou pacote de certificados (CA) ausente
            $dica = \in_array($numero, [60, 77], true)
                ? ' Confira o pacote de certificados do PHP (curl.cainfo ou openssl.cafile no php.ini).'
                : '';
            throw new EmissaojaException(0, 'rede', sprintf(
                'Falha de rede ao chamar a API do emissaoja: %s.%s',
                $detalhe !== '' ? rtrim($detalhe, '. ') : 'curl ' . $numero,
                $dica,
            ));
        }

        return [
            'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'cabecalhos' => $recebidos,
            'corpo' => $resposta,
        ];
    }

    private function handle(): \CurlHandle
    {
        if (!\function_exists('curl_init')) {
            throw new EmissaojaException(0, 'rede', 'A extensão curl do PHP não está habilitada (ext-curl).');
        }
        if ($this->handle === null) {
            $handle = curl_init();
            if ($handle === false) {
                throw new EmissaojaException(0, 'rede', 'Não foi possível iniciar o curl.');
            }
            $this->handle = $handle;
        } else {
            curl_reset($this->handle);
        }
        return $this->handle;
    }

    /** 30.0 → "30"; 0.25 → "0,25". */
    private static function segundos(float $segundos): string
    {
        return rtrim(rtrim(number_format($segundos, 3, ',', ''), '0'), ',');
    }
}
