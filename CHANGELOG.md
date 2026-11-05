# Changelog

Todas as mudanças relevantes deste pacote. Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/); versões seguem [SemVer](https://semver.org/lang/pt-BR/).

## [0.1.0] - 2026-11-05

Primeira versão pública.

### Adicionado

- `Emissaoja\Emissaoja`, cliente da API do emissaoja (NFS-e no Emissor Nacional, sem certificado digital), com os recursos `empresa`, `tomadores`, `servicos`, `notas`, `emissoes`, `operacoes` e `cnpj` e o método `status()`. Opções por argumentos nomeados ou por array: `chave` (padrão: `EMISSAOJA_KEY`, lida na primeira chamada que precisa dela), `baseUrl` (produção ou `Emissaoja::BASE_SANDBOX`), `timeout` (30 s) e `transporte`.
- `notas->emitir($referencia, $campos)` com `Idempotency-Key`; a referência é conferida antes de chamar a API (`chave_idempotencia_invalida`) e campos `null` não vão no corpo.
- `notas->aguardar()` (respeita `proxima_consulta_ms`, com intervalo mínimo), `empresa->aguardarCredencial()` e `operacoes->aguardar()`, com espera e relógio substituíveis (`dormir`, `relogio`) para testes.
- `notas->pdf()` (string binária) e `notas->xml()` (texto UTF-8).
- `Emissaoja\EmissaojaException` com `status` (HTTP, ou 0), `erro` (o `code` da API), `extras` (`bloqueios`, `nota_igual_recente`, `pedido_id`, `retry_after`...) e `requestId` (`X-Request-Id`).
- `Emissaoja\Transporte` e `Emissaoja\CurlTransporte` (extensão curl, sem dependências, sem seguir redirecionamentos; POST e PUT sem corpo saem sem `Content-Type`, com `Content-Length: 0`; `rede` e `tempo_esgotado` em falhas).
- Integração opcional com Laravel: `EmissaojaServiceProvider` (singleton configurado por `config/emissaoja.php`, que lê `EMISSAOJA_KEY`, `EMISSAOJA_BASE_URL` e `EMISSAOJA_TIMEOUT`), Facade `Emissaoja` e `php artisan vendor:publish --tag=emissaoja-config`.

[0.1.0]: https://github.com/Easymovel/emissaoja-php/releases/tag/v0.1.0
