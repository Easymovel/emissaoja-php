# emissaoja: API de NFS-e nacional para PHP

[![Testes](https://github.com/Easymovel/emissaoja-php/actions/workflows/ci.yml/badge.svg)](https://github.com/Easymovel/emissaoja-php/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/emissaoja/emissaoja.svg)](https://packagist.org/packages/emissaoja/emissaoja)
[![PHP](https://img.shields.io/packagist/php-v/emissaoja/emissaoja.svg)](https://packagist.org/packages/emissaoja/emissaoja)
[![Licença MIT](https://img.shields.io/badge/licen%C3%A7a-MIT-blue.svg)](LICENSE)

**Emita NFS-e no Emissor Nacional a partir do seu sistema em PHP, sem certificado digital.** Esta é a biblioteca oficial da API do [emissaoja](https://emissaoja.com.br/?origem=lib-php&utm_source=packagist&utm_medium=readme&utm_campaign=home) para PHP: com o CNPJ e a senha do portal do Emissor Nacional (nfse.gov.br), ela conecta a empresa, cadastra tomadores e serviços, emite a nota fiscal de serviço eletrônica, acompanha até a autorização, cancela e baixa o PDF (DANFSe) e o XML. Feita para MEI e empresas do Simples Nacional.

- PHP 8.1 a 8.4, **sem dependências** (só as extensões `curl` e `json`). Funciona em Laravel, Symfony, WordPress ou PHP puro.
- **Nota em dobro não sai:** cada emissão leva uma `referencia` sua (cabeçalho `Idempotency-Key`), conferida antes de chamar a API.
- `aguardar()` acompanha o pedido até `autorizada`, `recusada` ou `incerta`, no ritmo que a API pede (`proxima_consulta_ms`).
- Erros viram `EmissaojaException` com o `code` da API, os `extras` (como os `bloqueios` da nota) e o `requestId` para o suporte.
- Integração com **Laravel** (service provider, Facade e `config/emissaoja.php`) sem exigir o Laravel.
- Transporte HTTP substituível: teste o seu código sem chamar a API.

## Instalação

```bash
composer require emissaoja/emissaoja
```

Requer PHP 8.1 ou mais novo com as extensões `curl` e `json` (presentes na maioria das instalações).

Para usar a API, [crie a sua conta no emissaoja](https://emissaoja.com.br/?origem=lib-php&utm_source=packagist&utm_medium=readme&utm_campaign=home): entre no app com a conta Google ([emissaoja.com.br/app](https://emissaoja.com.br/app?origem=lib-php&utm_source=packagist&utm_medium=readme&utm_campaign=app)), aceite os termos e crie a chave em **Conta → API**. Ela aparece uma vez e começa com `ej_live_`. Guarde-a no ambiente do servidor:

```bash
export EMISSAOJA_KEY=ej_live_...
```

## Teste no sandbox em 2 minutos

O sandbox (https://sandbox.emissaoja.com.br) é o mesmo emissaoja com um Emissor Nacional simulado: é grátis e nada vai ao fisco. Entre com a conta Google, crie uma chave em **Conta → API** (começa com `ej_test_` e só vale lá), coloque-a em `EMISSAOJA_KEY` e rode:

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Emissaoja\Emissaoja;

$ej = new Emissaoja(baseUrl: Emissaoja::BASE_SANDBOX); // a chave vem de EMISSAOJA_KEY

// 1. Conectar a empresa de exemplo, uma vez (no sandbox qualquer senha serve, menos "errada")
if ($ej->empresa->obter()['empresa'] === null) {
    $ej->empresa->conectar('11111111000191', 'senha-do-portal');
    $ej->empresa->aguardarCredencial();
}

// 2. Esperar o cadastro fiscal ser lido (configurando → pronto)
for ($i = 0; $i < 60 && $ej->empresa->obter()['onboarding']['estado'] !== 'pronto'; $i++) {
    sleep(2);
}

// 3. Tomador pelo CNPJ: nome e endereço vêm da Receita
$tomador = $ej->tomadores->criar('12345678000195')['tomador'];

// 4. Emitir. A referência é um id seu, único por nota: repetir a mesma nunca emite duas vezes
$pedido = $ej->notas->emitir('pedido-0001', [
    'tomador_tipo' => $tomador['tipo'],
    'tomador_id' => $tomador['id'],
    'valor' => 1500.00,
    'descricao' => 'Consultoria de outubro',
])['pedido'];

// 5. Acompanhar até autorizada, recusada ou incerta
$final = $ej->notas->aguardar($pedido['id'])['pedido'];

if ($final['estado'] === 'autorizada') {
    echo "NFS-e {$final['numero']} autorizada\n";
    file_put_contents('nota.pdf', $ej->notas->pdf($final['nota']['origem'], $final['nota']['id']));
} else {
    echo "Pedido {$final['estado']}: {$final['motivo']}\n";
}
```

No sandbox, alguns valores têm significado: `13.13` deixa a nota `incerta` (resolva com `$ej->emissoes->conferir($id)`, que leva a `autorizada`), `77.77` é uma incerta que não saiu, `66.66` é recusada pelo fisco e `99.99` é bloqueada já na prévia (`422 nota_bloqueada`). Outras empresas de exemplo: `22222222000191` (ME que pede a alíquota do Simples antes de emitir), `33333333000191` (MEI sem notas anteriores) e `44444444000191` (bloqueada). Tomador pessoa física de exemplo: CPF `52998224725`. A empresa conectada e as notas do sandbox continuam lá quando o emissaoja publica uma versão nova.

## Em produção

Troque a base e a chave: sem `baseUrl`, o cliente já usa a produção ([emissaoja.com.br](https://emissaoja.com.br/?origem=lib-php&utm_source=packagist&utm_medium=readme&utm_campaign=home)), com a chave `ej_live_` criada em [emissaoja.com.br/app](https://emissaoja.com.br/app?origem=lib-php&utm_source=packagist&utm_medium=readme&utm_campaign=app). A chave `ej_test_` só vale no sandbox, e a `ej_live_`, só em produção.

```php
$ej = new Emissaoja();                                  // EMISSAOJA_KEY=ej_live_... no ambiente
$ej = new Emissaoja(chave: $segredos['emissaoja']);     // ou a chave vinda do seu cofre de segredos
$ej = new Emissaoja(['chave' => $chave, 'timeout' => 15]); // as mesmas opções, num array
```

> **A chave de API é secreta.** Quem tem a chave emite notas em nome da empresa. Use-a só no servidor (back-end, filas, scripts), nunca em JavaScript do navegador nem em app distribuído, e não a escreva no código nem no Git: guarde-a numa variável de ambiente ou num cofre de segredos. Se vazar, revogue em **Conta → API**.

As respostas são o JSON da API em arrays associativos, com os nomes da API (`snake_case`). A lista completa de campos está na [documentação](https://emissaoja.com.br/docs/?origem=lib-php&utm_source=packagist&utm_medium=readme&utm_campaign=docs) e no [OpenAPI](https://emissaoja.com.br/v1/openapi.json).

## Empresa

Uma conta tem uma empresa. A conexão usa o CNPJ e a senha do portal do Emissor Nacional; a senha não aparece em resposta nem em log.

```php
$ej->empresa->obter();   // empresa (null antes de conectar), credencial, onboarding, avisos

$ej->empresa->conectar('11222333000181', $senhaDoPortal);  // responde na hora (202); a conferência segue em segundo plano
$r = $ej->empresa->aguardarCredencial();                   // consulta a cada 2 s, até 120 s

switch ($r['tentativa']['proximo_passo']) {
    case 'configurando':
    case 'pronto':
        break;                    // senha aceita: o emissaoja lê o cadastro fiscal e traz as notas dos últimos 90 dias
    case 'corrigir_senha':        // senha recusada pelo portal
    case 'redefinir_no_portal':   // tentativas esgotadas ou conta bloqueada no portal
    case 'tentar_mais_tarde':     // portal indisponível no momento
    case 'suporte':
        echo $r['tentativa']['code'];
}

$ej->empresa->onboarding();                           // estágios, perguntas pendentes e bloqueio
$ej->empresa->fiscal(['aliquota_simples' => 6.0]);    // responde as perguntas (também reg_ap_trib_sn: 1, 2 ou 3)
$ej->empresa->relerFisco();                           // lê o cadastro fiscal de novo no portal
```

O portal aceita 3 senhas erradas por CNPJ em 24 h. Quando a próxima for a última, a API responde `409 confirmar_ultima_tentativa`: repita com `$ej->empresa->conectar($cnpj, $senha, cienteUltimaTentativa: true)`. O onboarding segue `configurando → perguntas → pronto`; emitir antes de `pronto` devolve `422 nota_bloqueada` com o que falta.

## Tomadores

```php
$pj = $ej->tomadores->criar('12345678000195')['tomador'];             // CNPJ: nome e endereço vêm da Receita
$pf = $ej->tomadores->criar('52998224725', 'Maria Souza')['tomador'];  // CPF: o nome é obrigatório
// se já existia: ['tomador' => [...], 'ja_existia' => true]

$ej->tomadores->listar(['busca' => 'alfa', 'pagina' => 1, 'limite' => 20, 'ordem' => 'recentes']);
$ej->tomadores->buscar('alfa');                  // busca rápida por nome ou documento
$ej->tomadores->obter('pj', $pj['id']);          // tipo: pj ou pf
$ej->tomadores->atualizar('pj', $pj['id'], ['email' => 'financeiro@cliente.com.br', 'telefone' => '31999990000']);
```

Tomador não se apaga (as notas apontam para ele) e o documento não muda.

## Serviços

```php
$itens = $ej->servicos->catalogo('desenvolvimento de sistemas', 5)['itens'];   // catálogo nacional de códigos

$servico = $ej->servicos->criar([
    'descricao' => 'Desenvolvimento de sistemas',
    'c_trib_nac' => $itens[0]['c_trib_nac'],
    'trib_issqn' => 'tributavel',     // ou nao_incidencia
    'retem_iss' => false,
])['servico'];

$ej->servicos->listar(['ativos' => 'todos']);   // '1', '0' ou 'todos'
$ej->servicos->atualizar($servico['id'], ['descricao' => 'Desenvolvimento e manutenção de sistemas']);
$ej->servicos->desativar($servico['id']);       // o último serviço ativo não pode (409 ultimo_servico_ativo)
```

`trib_issqn` aceita `tributavel` e `nao_incidencia`; isenção, imunidade e exportação ficam fora do emissaoja.

## Notas

### Emitir

```php
$r = $ej->notas->emitir('fatura-2026-11-0042', [
    'tomador_tipo' => 'pj',
    'tomador_id' => $tomadorId,
    'valor' => 350.75,                   // reais, até 2 casas
    'servico_id' => $servicoId,          // opcional: sem ele, o serviço principal
    'descricao' => 'Manutenção mensal',  // opcional: sem ela, a descrição do serviço (até 2000 caracteres)
    'competencia' => '2026-11-01',       // opcional (AAAA-MM-DD): sem ela, hoje
]);
// 202: ['pedido' => [...], 'previa' => ['resumo' => [...], 'avisos' => [...]]]
// 200: ['pedido' => [...], 'repetido' => true]   mesma referência: nada é emitido de novo
```

A `referencia` (8 a 64 caracteres: letras, números, `_` e `-`) é conferida antes de chamar a API. Campos opcionais: `retem_iss`, `aliquota_iss` e `municipio_prestacao` (código IBGE), só quando um bloqueio pedir; `confirmo_repeticao` e `permitir_repetida_de` (veja [Idempotência e nota em dobro](#idempotência-e-nota-em-dobro)). Campos `null` não são enviados.

### Acompanhar

```php
$final = $ej->notas->aguardar($r['pedido']['id'])['pedido'];   // até 180 s, no ritmo de proxima_consulta_ms

// autorizada: numero, chave_acesso, consulta_publica_url e nota => ['origem' => 'local', 'id' => ...]
// recusada:   motivo_code e motivo (nota recusada não conta no plano)
// incerta:    o portal não confirmou; confira com $ej->emissoes->conferir($id) antes de emitir de novo
```

O pedido passa por `na_fila → emitindo → confirmando` até `autorizada`, `recusada` ou `incerta`. `aguardar` espera entre as consultas o que a API pede em `proxima_consulta_ms` (3 s quando não diz), nunca menos de 1 s (`intervaloMinimo:`). Se o prazo (`timeout:`) acabar, lança `tempo_esgotado` com o `pedido_id` nos `extras`: o pedido continua andando e pode ser consultado depois com `$ej->emissoes->obter($id)`.

### Listar, PDF e XML

```php
$cursor = null;
do {
    $pagina = $ej->notas->listar(['periodo' => 'mes_atual', 'situacao' => 'emitidas', 'limite' => 50, 'cursor' => $cursor]);
    foreach ($pagina['notas'] as $nota) {
        echo $nota['numero'], ' ', $nota['tomador']['nome'] ?? '', ' ', $nota['valor'], PHP_EOL;
    }
    $cursor = $pagina['proximo_cursor'];
} while ($cursor !== null);

$pdf = $ej->notas->pdf('local', $notaId);   // DANFSe: string binária
file_put_contents("nfse-$notaId.pdf", $pdf);
$xml = $ej->notas->xml('local', $notaId);   // XML da nota, em UTF-8
```

`periodo`: `mes_atual`, `mes_anterior`, `ano_atual`, `12_meses` (padrão) ou `personalizado`, com `de` e `ate` (AAAA-MM-DD). `situacao`: `todas`, `emitidas` ou `canceladas`. A lista traz as notas emitidas pelo emissaoja (`origem` `local`) e as importadas do portal (`importada`). Filtros `null` ou vazios não vão na URL.

### Cancelar

```php
$op = $ej->notas->cancelar('local', $notaId, '1', 'Valor digitado errado na emissão')['operacao'];
// motivo: '1' = erro na emissão, '2' = serviço não prestado; justificativa opcional, de 15 a 255 caracteres

$fim = $ej->operacoes->aguardar($op['id'])['operacao'];   // processando → concluida ou falhou
if ($fim['estado'] === 'falhou') {
    echo $fim['erro']['message'];
}
```

Nota não se altera: para corrigir, cancele e emita outra. Nota autorizada e depois cancelada conta no plano.

## Emissões, operações, CNPJ e status

```php
$ej->emissoes->listar(['estados' => ['na_fila', 'emitindo', 'confirmando', 'incerta']]);  // pedidos em andamento
$ej->emissoes->obter($pedidoId);        // ['pedido' => [...]], com proxima_consulta_ms
$ej->emissoes->conferir($pedidoId);     // pedido incerta: confere no portal (só leitura)
$ej->operacoes->obter($operacaoId);     // ['operacao' => ['estado' => 'processando'|'concluida'|'falhou', ...]]

$ej->cnpj->consultar('12.345.678/0001-95');   // dados da Receita: razão social, situação, CNAEs, endereço, Simples/MEI
$ej->status();                                // sem chave: ['ok' => true, 'versao' => ..., 'motor' => 'ok', 'ambiente' => 'producao', ...]
```

`cnpj->consultar` não cria tomador (para isso, `tomadores->criar`, que já preenche pelo CNPJ).

## Erros

Toda resposta fora de 2xx, falha de rede e tempo esgotado vira `Emissaoja\EmissaojaException`:

```php
use Emissaoja\EmissaojaException;

try {
    $r = $ej->notas->emitir($referencia, $campos);
} catch (EmissaojaException $e) {
    $e->status;        // HTTP (422, 409...), ou 0 para erro local, falha de rede e tempo esgotado
    $e->erro;          // o code da API: decida por ele, nunca pelo texto
    $e->getMessage();  // mensagem em português, pronta para mostrar
    $e->extras;        // outros campos do erro: bloqueios, nota_igual_recente, pedido_id, retry_after, details
    $e->requestId;     // cabeçalho X-Request-Id: mande ao suporte junto com a pergunta

    if ($e->erro === 'nota_bloqueada') {
        foreach ($e->extras['bloqueios'] as $bloqueio) {
            echo "{$bloqueio['mensagem']} ({$bloqueio['acao']})\n";
        }
    }
}
```

No PHP, `Exception::$code` é inteiro: por isso o `code` da API fica em `$e->erro`, e `$e->getCode()` devolve o status HTTP.

| `erro` | HTTP | O que fazer |
| --- | --- | --- |
| `chave_invalida` | 401 (0 sem chave) | Confira a chave e o ambiente: `ej_live_` só vale em produção, `ej_test_` só no sandbox. |
| `aceite_pendente` | 409 | Entre no app e aceite os termos. |
| `sem_empresa` | 409 | Conecte a empresa com `empresa->conectar()`. |
| `validation_error`, `valor_invalido`, `tomador_obrigatorio`, `cnpj_invalido` | 400 | Corrija o campo (detalhes em `extras['details']`). |
| `chave_idempotencia_invalida` | 0 | `referencia` com 8 a 64 caracteres: letras, números, `_` e `-`. |
| `limite_notas` | 402 | As notas do plano no mês acabaram; veja os planos em [emissaoja.com.br/#precos](https://emissaoja.com.br/?origem=lib-php&utm_source=packagist&utm_medium=readme&utm_campaign=precos#precos). |
| `tomador_nao_encontrado`, `servico_nao_encontrado`, `nota_nao_encontrada`, `pedido_nao_encontrado` | 404 | Confira o id. |
| `possivel_duplicada` | 409 | Saiu hoje uma nota igual; se for mesmo outra, repita com `permitir_repetida_de`. |
| `emissao_repetida_recente` | 409 | A mesma nota foi pedida há menos de 2 minutos; se for mesmo outra, repita com `confirmo_repeticao`. |
| `incerta_pendente` | 409 | Há um pedido igual ainda sem confirmação (`extras['pedido_id']`): não emita de novo; acompanhe esse pedido. |
| `ultimo_servico_ativo` | 409 | Crie ou ative outro serviço antes de desativar este. |
| `nota_bloqueada` | 422 | Resolva cada item de `extras['bloqueios']` (`code`, `mensagem`, `acao`). |
| `rate_limited` | 429 | Espere os segundos de `extras['retry_after']` (ou do cabeçalho `Retry-After`). |
| `fila_indisponivel` | 503 | Repita com a **mesma** referência. |
| `motor_indisponivel`, `receita_indisponivel` | 503 | Tente de novo em instantes. |
| `rede`, `tempo_esgotado` | 0 | Falha de conexão ou sem resposta; em `emitir`, repita com a mesma referência. |
| `resposta_invalida` | 2xx | A resposta não era JSON: confira a `baseUrl`. |
| `erro_desconhecido` | qualquer | Erro fora do formato da API (proxy, página HTML); veja `getMessage()`. |

## Idempotência e nota em dobro

A biblioteca **não repete pedidos sozinha**: emitir tem efeito fiscal, e quem decide repetir é você, sempre com a mesma referência.

- **Referência:** use em `emitir` um id seu por nota (o número do pedido ou da fatura). Repetir a mesma referência devolve o mesmo pedido (`200`, `repetido: true`) e nunca emite duas vezes. Se a chamada falhar por rede, tempo esgotado ou `503 fila_indisponivel`, repita com a **mesma** referência: se a nota já tinha sido pedida, você recebe o pedido que existe.
- **`409 possivel_duplicada`:** já saiu hoje uma nota igual (mesmo tomador, valor e competência), descrita em `extras['nota_igual_recente']`. Se for mesmo outra nota, confirme com quem emite e repita com `permitir_repetida_de`:

  ```php
  try {
      $r = $ej->notas->emitir($referencia, $campos);
  } catch (EmissaojaException $e) {
      if ($e->erro !== 'possivel_duplicada') {
          throw $e;
      }
      // depois de confirmar que é outra nota:
      $r = $ej->notas->emitir($referencia, $campos + ['permitir_repetida_de' => $e->extras['nota_igual_recente']['pedido_id']]);
  }
  ```

- **`409 emissao_repetida_recente`:** a mesma nota foi pedida com outra referência há menos de 2 minutos. Se for mesmo outra, repita com `'confirmo_repeticao' => true`.
- **`409 incerta_pendente`:** existe um pedido igual ainda sem confirmação do portal, em `extras['pedido_id']`. Não emita de novo: acompanhe esse pedido com `$ej->notas->aguardar($id)` e, se ele ficar `incerta`, confira com `$ej->emissoes->conferir($id)`.

## Laravel

O pacote se registra sozinho (package discovery): o cliente vira um singleton configurado por `config/emissaoja.php`, também disponível como `app('emissaoja')`, e a Facade `Emissaoja` fica registrada. Para editar a configuração, publique o arquivo:

```bash
php artisan vendor:publish --tag=emissaoja-config
```

No `.env`, que nunca vai para o Git:

```dotenv
EMISSAOJA_KEY=ej_live_...
# Para testar no sandbox:
# EMISSAOJA_KEY=ej_test_...
# EMISSAOJA_BASE_URL=https://sandbox.emissaoja.com.br
# EMISSAOJA_TIMEOUT=30
```

Peça o cliente por injeção de dependência. Emitir combina com um job de fila: a chamada responde rápido, mas a autorização leva alguns segundos.

```php
use App\Models\Pedido;
use Emissaoja\Emissaoja;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EmitirNotaDoPedido implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $pedidoId)
    {
    }

    public function handle(Emissaoja $ej): void
    {
        $pedido = Pedido::findOrFail($this->pedidoId);

        // mesma referência em toda nova tentativa do job: a nota não sai duas vezes
        $r = $ej->notas->emitir('pedido-' . $pedido->id, [
            'tomador_tipo' => $pedido->cliente->emissaoja_tipo,
            'tomador_id' => $pedido->cliente->emissaoja_id,
            'valor' => (float) $pedido->total,
            'descricao' => $pedido->descricao_servico,
        ]);
        $final = $ej->notas->aguardar($r['pedido']['id'])['pedido'];

        $pedido->update(['nfse_estado' => $final['estado'], 'nfse_numero' => $final['numero']]);
    }
}
```

Receba o cliente no `handle()`, não no construtor do job: o cliente guarda a chave e não pode ser serializado na fila.

Pela Facade, os recursos são métodos:

```php
use Emissaoja\Laravel\Facades\Emissaoja;

$notas = Emissaoja::notas()->listar(['periodo' => 'mes_atual']);

return response(Emissaoja::notas()->pdf('local', $id), 200, ['Content-Type' => 'application/pdf']);
```

## Testar o seu código sem chamar a API

Passe um `transporte` próprio: qualquer classe que implemente `Emissaoja\Transporte` (um método, `enviar`). O mesmo vale para usar outro cliente HTTP, como Guzzle ou Symfony HttpClient.

```php
use Emissaoja\Emissaoja;
use Emissaoja\Transporte;

$falso = new class implements Transporte {
    public array $pedidos = [];

    public function enviar(string $metodo, string $url, array $cabecalhos, ?string $corpo, float $timeout): array
    {
        $this->pedidos[] = [$metodo, $url, $corpo];
        return ['status' => 202, 'cabecalhos' => [], 'corpo' => '{"pedido":{"id":"p1","estado":"na_fila"}}'];
    }
};

$ej = new Emissaoja(chave: 'ej_test_qualquer', transporte: $falso);
$ej->notas->emitir('pedido-0001', ['tomador_tipo' => 'pj', 'tomador_id' => 't1', 'valor' => 10]);
// $falso->pedidos[0] === ['POST', 'https://emissaoja.com.br/v1/notas', '{"tomador_tipo":"pj","tomador_id":"t1","valor":10}']
```

No Laravel: `$this->app->instance(Emissaoja::class, new Emissaoja(chave: 'ej_test_qualquer', transporte: $falso));`. Para não esperar de verdade em `aguardar`, passe `dormir: fn (float $segundos) => null`.

## Opções do cliente

| Opção | Padrão | |
| --- | --- | --- |
| `chave` | `EMISSAOJA_KEY` | `ej_live_...` (produção) ou `ej_test_...` (sandbox); lida do ambiente só na primeira chamada que precisa dela |
| `baseUrl` | `https://emissaoja.com.br` | `Emissaoja::BASE_SANDBOX` para o sandbox |
| `timeout` | `30` | segundos por chamada (`0` desliga) |
| `transporte` | `CurlTransporte` | qualquer `Emissaoja\Transporte` |
| `dormir` | pausa de verdade | `fn(float $segundos): void`, usada entre as consultas de `aguardar` |
| `relogio` | relógio do sistema | `fn(): float`, em segundos, usado nos prazos de `aguardar` |

O `CurlTransporte` reaproveita a conexão entre as chamadas e não segue redirecionamentos, para a chave nunca ir a outro endereço.

## Limites

- 60 chamadas por minuto por chave; emissões, 10 por minuto; buscas e catálogo, 120; consulta de CNPJ, 30.
- Passou do limite: `429 rate_limited`, com o cabeçalho `Retry-After` e `extras['retry_after']`.
- A nota conta no plano quando o Emissor Nacional aceita o pedido; recusada não conta. O plano Grátis tem 1 nota por mês.

## Perguntas frequentes

**Precisa de certificado digital?** Não. O emissaoja entra no Emissor Nacional com o CNPJ e a senha do próprio portal.

**Funciona para MEI?** Sim, para MEI e para ME e EPP optantes pelo Simples Nacional. O CNPJ da empresa é o numérico (o CNPJ alfanumérico ainda não é aceito para a empresa).

**Tem ambiente de testes?** Sim: o sandbox, em https://sandbox.emissaoja.com.br, com o Emissor Nacional simulado, empresas e tomadores de exemplo e chaves `ej_test_`. É grátis e nada vai ao fisco.

**Quanto custa?** Há o plano Grátis, com 1 nota por mês, e planos pagos: veja em [emissaoja.com.br/#precos](https://emissaoja.com.br/?origem=lib-php&utm_source=packagist&utm_medium=readme&utm_campaign=precos#precos). A biblioteca é gratuita (MIT).

**Tem webhook?** Ainda não. Acompanhe o pedido com `$ej->notas->aguardar($id)` ou `$ej->emissoes->obter($id)`, que trazem `proxima_consulta_ms`.

## Links

- Documentação da API: [emissaoja.com.br/docs](https://emissaoja.com.br/docs/?origem=lib-php&utm_source=packagist&utm_medium=readme&utm_campaign=docs) (também em Markdown: https://emissaoja.com.br/docs.md)
- OpenAPI 3.1: https://emissaoja.com.br/v1/openapi.json
- Para IAs: https://emissaoja.com.br/llms.txt
- MCP (emitir pelo seu assistente de IA): https://emissaoja.com.br/mcp
- Status: https://emissaoja.com.br/v1/status
- Suporte: suporte@emissaoja.com.br · [issues](https://github.com/Easymovel/emissaoja-php/issues)
- Outras bibliotecas oficiais: [JavaScript (npm `emissaoja`)](https://www.npmjs.com/package/emissaoja), [Python (PyPI `emissaoja`)](https://pypi.org/project/emissaoja/), [.NET (NuGet `Emissaoja`)](https://www.nuget.org/packages/Emissaoja), [Go (`emissaoja.com.br/go`)](https://pkg.go.dev/emissaoja.com.br/go), [Ruby (RubyGems `emissaoja`)](https://rubygems.org/gems/emissaoja), [n8n (`n8n-nodes-emissaoja`)](https://www.npmjs.com/package/n8n-nodes-emissaoja)

Licença [MIT](LICENSE). Copyright (c) 2026 BINOBO LTDA (CNPJ 68.649.497/0001-07), Belo Horizonte (MG).

## In English

**emissaoja** is the official PHP client for the [emissaoja](https://emissaoja.com.br/?origem=lib-php&utm_source=packagist&utm_medium=readme&utm_campaign=home) API: issue Brazilian **NFS-e** (electronic service invoices) through the national issuer, the Emissor Nacional, with the company's CNPJ and portal password, **no digital certificate required**, for MEI and Simples Nacional companies. Connect the company, register customers and services, issue, track, cancel and download the PDF (DANFSe) and XML.

PHP 8.1+, no dependencies (ext-curl, ext-json), idempotent issuing, Laravel integration included: `composer require emissaoja/emissaoja`. Try it for free in the sandbox (https://sandbox.emissaoja.com.br) and [create your account](https://emissaoja.com.br/?origem=lib-php&utm_source=packagist&utm_medium=readme&utm_campaign=home).

---

O emissaoja é um serviço privado e não tem vínculo com a Receita Federal nem com o Emissor Nacional.
