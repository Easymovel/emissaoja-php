<?php

declare(strict_types=1);

namespace Emissaoja\Recursos;

use Emissaoja\Conexao;
use Emissaoja\EmissaojaException;

/**
 * Notas: emitir (prévia + emissão num passo), acompanhar, listar, baixar PDF e XML e cancelar.
 *
 * Nota não se altera: para corrigir, cancele e emita outra.
 */
class Notas
{
    /** Estados finais do pedido de emissão. */
    public const TERMINAIS = ['autorizada', 'recusada', 'incerta'];

    private const REFERENCIA = '/\A[A-Za-z0-9_-]{8,64}\z/';

    /** @internal use `$ej->notas` */
    public function __construct(private readonly Conexao $conexao, private readonly Emissoes $emissoes)
    {
    }

    /**
     * Emite uma NFS-e: `POST /v1/notas` com o cabeçalho `Idempotency-Key: $referencia`.
     *
     * A referência é um id seu, único por nota (o número do pedido, da fatura...). Repetir a mesma referência devolve
     * o mesmo pedido (200, `repetido: true`) e nunca emite duas vezes: em falha de rede, tempo esgotado ou 503
     * `fila_indisponivel`, repita com a mesma referência. A biblioteca não repete sozinha.
     *
     * @param string               $referencia 8 a 64 caracteres: letras, números, `_` e `-` (conferida antes de chamar a API)
     * @param array<string, mixed> $campos     obrigatórios: `tomador_tipo` (`pj` ou `pf`), `tomador_id`, `valor` (reais,
     *                                         até 2 casas). Opcionais: `servico_id` (sem ele, o serviço principal),
     *                                         `descricao` (até 2000 caracteres), `competencia` (AAAA-MM-DD; sem ela,
     *                                         hoje), `retem_iss`, `aliquota_iss`, `municipio_prestacao` (código IBGE),
     *                                         `confirmo_repeticao`, `permitir_repetida_de`. Campos `null` não são enviados.
     *
     * @return array<array-key, mixed> `['pedido' => [...], 'previa' => ['resumo' => [...], 'avisos' => [...]]]` (202) ou
     *                                 `['pedido' => [...], 'repetido' => true]` (200, mesma referência)
     *
     * @throws EmissaojaException `chave_idempotencia_invalida` (status 0, sem chamar a API); 400; 402 `limite_notas`;
     *                            409 `possivel_duplicada`, `emissao_repetida_recente`, `incerta_pendente`; 422
     *                            `nota_bloqueada` (veja `extras['bloqueios']`); 429; 503
     */
    public function emitir(string $referencia, array $campos): array
    {
        if (preg_match(self::REFERENCIA, $referencia) !== 1) {
            throw new EmissaojaException(
                0,
                'chave_idempotencia_invalida',
                'Informe a referência da nota: de 8 a 64 caracteres (letras, números, _ e -), única por nota.',
            );
        }
        return $this->conexao->json('POST', '/v1/notas', Conexao::semNulos($campos), cabecalhos: ['Idempotency-Key' => $referencia]);
    }

    /**
     * Consulta o pedido (`GET /v1/emissoes/{id}`) até `autorizada`, `recusada` ou `incerta` e devolve a última
     * resposta. Entre as consultas espera o que a API pede em `proxima_consulta_ms` (3 s quando não diz), nunca menos
     * que `$intervaloMinimo`.
     *
     * - `autorizada`: `numero`, `chave_acesso`, `consulta_publica_url` e `nota` (`['origem' => ..., 'id' => ...]`, o
     *   par de {@see pdf()} e {@see xml()}).
     * - `recusada`: `motivo_code` e `motivo` (a nota recusada não conta no plano).
     * - `incerta`: o portal não confirmou; confira com `$ej->emissoes->conferir($id)` antes de emitir de novo.
     *
     * @param float $timeout         segundos no total
     * @param float $intervaloMinimo menor espera entre as consultas, em segundos
     *
     * @return array<array-key, mixed> `['pedido' => [...]]`
     *
     * @throws EmissaojaException `tempo_esgotado` (status 0, `extras['pedido_id']`) se o pedido não terminar a tempo
     */
    public function aguardar(string $pedidoId, float $timeout = 180.0, float $intervaloMinimo = 1.0): array
    {
        $fim = $this->conexao->agora() + $timeout;
        while (true) {
            $resposta = $this->emissoes->obter($pedidoId);
            $pedido = \is_array($resposta['pedido'] ?? null) ? $resposta['pedido'] : [];
            if (\in_array($pedido['estado'] ?? null, self::TERMINAIS, true)) {
                return $resposta;
            }
            if ($this->conexao->agora() >= $fim) {
                throw new EmissaojaException(
                    0,
                    'tempo_esgotado',
                    'O pedido não terminou a tempo; consulte de novo mais tarde.',
                    ['pedido_id' => $pedidoId],
                );
            }
            $proxima = $pedido['proxima_consulta_ms'] ?? null;
            $milissegundos = (\is_int($proxima) || \is_float($proxima)) && $proxima > 0 ? (float) $proxima : 3000.0;
            $this->conexao->dormir(max($intervaloMinimo, $milissegundos / 1000));
        }
    }

    /**
     * Lista as notas emitidas pelo emissaoja (`origem` `local`) e as importadas do portal (`importada`),
     * paginadas por cursor (`GET /v1/notas`).
     *
     * @param array<string, mixed> $filtros `periodo` (`mes_atual`, `mes_anterior`, `ano_atual`, `12_meses`, padrão,
     *                                      ou `personalizado` com `de` e `ate` em AAAA-MM-DD), `situacao` (`todas`,
     *                                      `emitidas`, `canceladas`), `busca`, `cursor`, `limite` (até 50); `null` e
     *                                      `''` ficam de fora
     *
     * @return array<array-key, mixed> `['notas' => [...], 'total' => ..., 'janela_cheia' => bool, 'proximo_cursor' => ?string]`
     */
    public function listar(array $filtros = []): array
    {
        return $this->conexao->json('GET', '/v1/notas', query: $filtros);
    }

    /**
     * PDF da nota, o DANFSe (`GET /v1/notas/{origem}/{id}/pdf`), como string binária: grave com
     * `file_put_contents()` ou devolva numa resposta HTTP com `Content-Type: application/pdf`.
     *
     * @param string $origem `local` ou `importada`
     */
    public function pdf(string $origem, string $id): string
    {
        return $this->conexao->bytes(Conexao::caminho('/v1/notas/%s/%s/pdf', $origem, $id));
    }

    /**
     * XML da nota (`GET /v1/notas/{origem}/{id}/xml`), em UTF-8.
     *
     * @param string $origem `local` ou `importada`
     */
    public function xml(string $origem, string $id): string
    {
        return $this->conexao->bytes(Conexao::caminho('/v1/notas/%s/%s/xml', $origem, $id));
    }

    /**
     * Cancela a nota dentro do prazo do portal (`POST /v1/notas/{origem}/{id}/cancelar`, 202). O cancelamento segue
     * em segundo plano: acompanhe com `$ej->operacoes->aguardar($resposta['operacao']['id'])`.
     *
     * @param string      $origem        `local` ou `importada`
     * @param string|int  $motivo        `1` = erro na emissão, `2` = serviço não prestado
     * @param string|null $justificativa de 15 a 255 caracteres (opcional)
     *
     * @return array<array-key, mixed> `['operacao' => ['id' => ..., 'tipo' => 'cancelamento', 'estado' => 'processando']]`
     */
    public function cancelar(string $origem, string $id, string|int $motivo, ?string $justificativa = null): array
    {
        $corpo = ['motivo' => (string) $motivo];
        if ($justificativa !== null && $justificativa !== '') {
            $corpo['justificativa'] = $justificativa;
        }
        return $this->conexao->json('POST', Conexao::caminho('/v1/notas/%s/%s/cancelar', $origem, $id), $corpo);
    }
}
