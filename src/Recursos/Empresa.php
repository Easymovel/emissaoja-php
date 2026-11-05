<?php

declare(strict_types=1);

namespace Emissaoja\Recursos;

use Emissaoja\Conexao;
use Emissaoja\EmissaojaException;

/**
 * Empresa da conta: conexão com o Emissor Nacional (CNPJ e senha do portal), onboarding e perguntas fiscais.
 * Uma conta tem uma empresa.
 */
class Empresa
{
    /** @internal use `$ej->empresa` */
    public function __construct(private readonly Conexao $conexao)
    {
    }

    /**
     * `GET /v1/empresa`: a empresa (`null` antes de conectar), a credencial (`ausente`, `verificando`, `ativa`,
     * `congelada`), o onboarding (`configurando` → `perguntas` → `pronto`), a importação, os avisos e o aceite.
     *
     * @return array<array-key, mixed>
     */
    public function obter(): array
    {
        return $this->conexao->json('GET', '/v1/empresa');
    }

    /**
     * Conecta a empresa com o CNPJ e a senha do Emissor Nacional (`PUT /v1/empresa/credencial`). Responde na hora
     * (202) com a `tentativa`; a senha é conferida no portal em segundo plano: acompanhe com
     * {@see aguardarCredencial()}. Com a senha aceita, o emissaoja lê o cadastro fiscal e traz as notas dos últimos
     * 90 dias. A senha não aparece em resposta nem em log.
     *
     * O portal aceita 3 senhas erradas por CNPJ em 24 h. Quando a próxima tentativa for a última, a API responde
     * 409 `confirmar_ultima_tentativa`: repita com `$cienteUltimaTentativa = true`.
     *
     * @param string $cnpj  CNPJ da empresa, só números
     * @param string $senha senha do portal do Emissor Nacional
     *
     * @return array<array-key, mixed> `['tentativa' => ['id' => ..., 'estado' => ..., 'proximo_passo' => ..., 'code' => ...]]`
     */
    public function conectar(string $cnpj, #[\SensitiveParameter] string $senha, bool $cienteUltimaTentativa = false): array
    {
        return $this->conexao->json('PUT', '/v1/empresa/credencial', [
            'cnpj' => $cnpj,
            'senha' => $senha,
            'ciente_ultima_tentativa' => $cienteUltimaTentativa,
        ]);
    }

    /**
     * `GET /v1/empresa/credencial`: a credencial, o onboarding e a tentativa em andamento (ou a última), com
     * `estado` e `proximo_passo` (`aguardar`, `configurando`, `pronto`, `corrigir_senha`, `tentar_mais_tarde`,
     * `redefinir_no_portal`, `suporte`).
     *
     * @return array<array-key, mixed>
     */
    public function credencial(): array
    {
        return $this->conexao->json('GET', '/v1/empresa/credencial');
    }

    /**
     * Consulta {@see credencial()} até a tentativa sair de `proximo_passo` `aguardar` (ou não haver tentativa) e
     * devolve a última resposta. `configurando` ou `pronto`: senha aceita.
     *
     * @param float $intervalo segundos entre as consultas
     * @param float $timeout   segundos no total
     *
     * @return array<array-key, mixed>
     *
     * @throws EmissaojaException `tempo_esgotado` (status 0) se a verificação não terminar a tempo
     */
    public function aguardarCredencial(float $intervalo = 2.0, float $timeout = 120.0): array
    {
        $fim = $this->conexao->agora() + $timeout;
        while (true) {
            $resposta = $this->credencial();
            $tentativa = $resposta['tentativa'] ?? null;
            if (!\is_array($tentativa) || ($tentativa['proximo_passo'] ?? null) !== 'aguardar') {
                return $resposta;
            }
            if ($this->conexao->agora() >= $fim) {
                throw new EmissaojaException(0, 'tempo_esgotado', 'A verificação da senha não terminou a tempo.');
            }
            $this->conexao->dormir($intervalo);
        }
    }

    /**
     * `GET /v1/empresa/onboarding`: estágios, perguntas pendentes e bloqueio.
     *
     * @return array<array-key, mixed>
     */
    public function onboarding(): array
    {
        return $this->conexao->json('GET', '/v1/empresa/onboarding');
    }

    /**
     * Responde as perguntas que o cadastro fiscal não responde sozinho (`PUT /v1/empresa/fiscal`):
     * `aliquota_simples` (maior que 0 e até 33) e `reg_ap_trib_sn` (1, 2 ou 3). Campos `null` não são enviados.
     *
     * @param array<string, mixed> $campos
     *
     * @return array<array-key, mixed>
     */
    public function fiscal(array $campos): array
    {
        return $this->conexao->json('PUT', '/v1/empresa/fiscal', Conexao::semNulos($campos));
    }

    /**
     * Lê o cadastro fiscal de novo no portal (`POST /v1/empresa/reler-fisco`, 202).
     *
     * @return array<array-key, mixed>
     */
    public function relerFisco(): array
    {
        return $this->conexao->json('POST', '/v1/empresa/reler-fisco');
    }
}
