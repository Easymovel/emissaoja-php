<?php

declare(strict_types=1);

namespace Emissaoja;

/**
 * Erro do cliente do emissaoja: toda resposta fora de 2xx, falha de rede, tempo esgotado e os erros locais.
 *
 * - `$status`: status HTTP (400, 401, 402, 404, 409, 422, 429, 503...) ou `0` para erro local, falha de rede e
 *   tempo esgotado. `getCode()` devolve o mesmo número: o `Exception::$code` do PHP é inteiro, por isso o código da
 *   API (texto) fica em `$erro`.
 * - `$erro`: o `code` da API, da lista fechada (`nota_bloqueada`, `limite_notas`, `possivel_duplicada`,
 *   `rate_limited`...). Decida por ele, nunca pelo texto. Erros locais: `chave_invalida` (sem chave),
 *   `chave_idempotencia_invalida` (referência inválida), `rede`, `tempo_esgotado`, `resposta_invalida`; resposta de
 *   erro sem `code` vira `erro_desconhecido`.
 * - `$extras`: os outros campos do corpo do erro (`bloqueios`, `nota_igual_recente`, `pedido_id`, `retry_after`,
 *   `details`...).
 * - `$requestId`: cabeçalho `X-Request-Id` da resposta; mande ao suporte junto com a pergunta.
 */
class EmissaojaException extends \RuntimeException
{
    /**
     * @param int                      $status    HTTP, ou 0 para erro local, rede e tempo esgotado
     * @param string                   $erro      `code` da API (ou um dos códigos locais)
     * @param string                   $mensagem  mensagem em português (`message` da API)
     * @param array<array-key, mixed>  $extras    demais campos do corpo do erro
     * @param string|null              $requestId cabeçalho `X-Request-Id`
     */
    public function __construct(
        public readonly int $status,
        public readonly string $erro,
        string $mensagem,
        public readonly array $extras = [],
        public readonly ?string $requestId = null,
        ?\Throwable $anterior = null,
    ) {
        parent::__construct($mensagem, $status, $anterior);
    }

    /** Status HTTP, ou 0 para erro local, falha de rede e tempo esgotado. */
    public function getStatus(): int
    {
        return $this->status;
    }

    /** O `code` da API, por exemplo `nota_bloqueada`. */
    public function getErro(): string
    {
        return $this->erro;
    }

    /**
     * Demais campos do corpo do erro (`bloqueios`, `nota_igual_recente`, `pedido_id`, `retry_after`, `details`...).
     *
     * @return array<array-key, mixed>
     */
    public function getExtras(): array
    {
        return $this->extras;
    }

    /** Cabeçalho `X-Request-Id` da resposta, ou null. */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }
}
