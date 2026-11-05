<?php

declare(strict_types=1);

namespace Emissaoja\Tests;

use Emissaoja\EmissaojaException;
use Emissaoja\Emissaoja;
use Emissaoja\Recursos\Notas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmissaojaTest extends TestCase
{
    use EsperaErro;

    private const TOMADOR = '0f8fad5b-d9cb-469f-a165-70867728950e';

    private TransporteFalso $http;

    protected function setUp(): void
    {
        $this->http = new TransporteFalso();
    }

    /** @return array<string, mixed> */
    private static function nota(array $extras = []): array
    {
        return ['tomador_tipo' => 'pj', 'tomador_id' => self::TOMADOR, 'valor' => 1500] + $extras;
    }

    // ------------------------------------------------------------------ cabeçalhos e chave

    public function testStatusVaiSemChaveEAsOutrasRotasComChaveAcceptEUserAgent(): void
    {
        $this->http->responder(
            TransporteFalso::json(200, ['ok' => true, 'versao' => '0.1.0', 'motor' => 'simulado', 'ambiente' => 'sandbox']),
            TransporteFalso::json(200, ['empresa' => null]),
        );
        $ej = $this->http->cliente();

        self::assertSame('sandbox', $ej->status()['ambiente']);
        self::assertSame(['empresa' => null], $ej->empresa->obter());

        [$status, $empresa] = $this->http->requisicoes;
        self::assertSame(['GET /v1/status', 'GET /v1/empresa'], $this->http->caminhos());
        self::assertArrayNotHasKey('Authorization', $status['cabecalhos']);
        self::assertSame('Bearer ' . TransporteFalso::CHAVE, $empresa['cabecalhos']['Authorization']);
        foreach ([$status, $empresa] as $r) {
            self::assertSame('application/json', $r['cabecalhos']['Accept']);
            self::assertStringStartsWith('emissaoja-php/' . Emissaoja::VERSAO . ' PHP/', $r['cabecalhos']['User-Agent']);
            self::assertArrayNotHasKey('Content-Type', $r['cabecalhos'], 'GET não tem corpo');
            self::assertNull($r['corpo']);
            self::assertSame(30.0, $r['timeout']);
        }
    }

    public function testChaveDoAmbienteLidaSoNaPrimeiraChamadaQuePrecisaDela(): void
    {
        $antes = getenv('EMISSAOJA_KEY');
        $antesEnv = $_ENV['EMISSAOJA_KEY'] ?? null;
        $antesServer = $_SERVER['EMISSAOJA_KEY'] ?? null;
        try {
            putenv('EMISSAOJA_KEY');
            unset($_ENV['EMISSAOJA_KEY'], $_SERVER['EMISSAOJA_KEY']);

            $ej = $this->http->cliente(['chave' => null]); // o construtor não lê o ambiente

            $this->http->responder(TransporteFalso::json(200, ['ok' => true]));
            $ej->status(); // não precisa de chave
            $e = $this->lanca(fn () => $ej->empresa->obter(), ['status' => 0, 'erro' => 'chave_invalida']);
            self::assertStringContainsString('EMISSAOJA_KEY', $e->getMessage());
            self::assertCount(1, $this->http->requisicoes, 'sem chave, não chama a API');

            putenv('EMISSAOJA_KEY=ej_test_do_ambiente');
            $this->http->responder(TransporteFalso::json(200, []), TransporteFalso::json(200, []));
            $ej->empresa->obter();
            putenv('EMISSAOJA_KEY=ej_test_trocada_depois');
            $ej->empresa->credencial();

            self::assertSame('Bearer ej_test_do_ambiente', $this->http->requisicoes[1]['cabecalhos']['Authorization']);
            self::assertSame('Bearer ej_test_do_ambiente', $this->http->requisicoes[2]['cabecalhos']['Authorization'], 'lida uma vez só');

            // dotenv de frameworks costuma preencher só $_ENV/$_SERVER
            putenv('EMISSAOJA_KEY');
            $_SERVER['EMISSAOJA_KEY'] = ' ej_test_do_server ';
            $this->http->responder(TransporteFalso::json(200, []));
            $this->http->cliente(['chave' => null])->empresa->obter();
            self::assertSame('Bearer ej_test_do_server', $this->http->ultima()['cabecalhos']['Authorization']);
        } finally {
            putenv($antes === false ? 'EMISSAOJA_KEY' : 'EMISSAOJA_KEY=' . $antes);
            unset($_ENV['EMISSAOJA_KEY'], $_SERVER['EMISSAOJA_KEY']);
            if ($antesEnv !== null) {
                $_ENV['EMISSAOJA_KEY'] = $antesEnv;
            }
            if ($antesServer !== null) {
                $_SERVER['EMISSAOJA_KEY'] = $antesServer;
            }
        }
    }

    public function testChaveComEspacoNoMeioNaoSai(): void
    {
        $this->lanca(fn () => $this->http->cliente(['chave' => "ej_live_abc\ndef"])->empresa->obter(), ['status' => 0, 'erro' => 'chave_invalida']);
        self::assertSame([], $this->http->requisicoes);

        $this->http->responder(TransporteFalso::json(200, []));
        $this->http->cliente(['chave' => "  ej_live_abcdef\n"])->empresa->obter(); // espaço nas pontas é aparado
        self::assertSame('Bearer ej_live_abcdef', $this->http->ultima()['cabecalhos']['Authorization']);
    }

    // ------------------------------------------------------------------ emitir e idempotência

    /** @return iterable<string, array{string}> */
    public static function referenciasInvalidas(): iterable
    {
        yield 'vazia' => [''];
        yield 'curta (7)' => ['pedido1'];
        yield 'longa (65)' => [str_repeat('a', 65)];
        yield 'com espaço' => ['pedido 0001'];
        yield 'com barra' => ['pedido/0001'];
        yield 'com acento' => ['emissão-0001'];
        yield 'quebra de linha no fim' => ["pedido-0001\n"];
    }

    #[DataProvider('referenciasInvalidas')]
    public function testEmitirSemReferenciaValidaNaoChamaARede(string $referencia): void
    {
        $e = $this->lanca(
            fn () => $this->http->cliente()->notas->emitir($referencia, self::nota()),
            ['status' => 0, 'erro' => 'chave_idempotencia_invalida'],
        );
        self::assertStringContainsString('8 a 64', $e->getMessage());
        self::assertSame([], $this->http->requisicoes);
    }

    public function testEmitirMandaIdempotencyKeyEOCorpoSemOsNulos(): void
    {
        $this->http->responder(
            TransporteFalso::json(202, ['pedido' => ['id' => 'p1', 'estado' => 'na_fila', 'proxima_consulta_ms' => 1500], 'previa' => ['resumo' => [], 'avisos' => []]]),
            TransporteFalso::json(200, ['pedido' => ['id' => 'p1', 'estado' => 'emitindo'], 'repetido' => true]),
        );
        $ej = $this->http->cliente();

        $r = $ej->notas->emitir('pedido-0001', self::nota([
            'descricao' => 'Consultoria – revisão de código/infra',
            'competencia' => null,
            'servico_id' => null,
            'retem_iss' => false,
        ]));
        self::assertSame('p1', $r['pedido']['id']);

        $req = $this->http->ultima();
        self::assertSame('POST', $req['metodo']);
        self::assertSame(TransporteFalso::BASE . '/v1/notas', $req['url']);
        self::assertSame('pedido-0001', $req['cabecalhos']['Idempotency-Key']);
        self::assertSame('application/json', $req['cabecalhos']['Content-Type']);
        self::assertSame(
            '{"tomador_tipo":"pj","tomador_id":"' . self::TOMADOR . '","valor":1500,"descricao":"Consultoria – revisão de código/infra","retem_iss":false}',
            $req['corpo'],
        );

        // limites do formato: 8 e 64 caracteres passam
        $repetido = $ej->notas->emitir(str_repeat('A', 64), self::nota());
        self::assertTrue($repetido['repetido']);
        self::assertSame(str_repeat('A', 64), $this->http->ultima()['cabecalhos']['Idempotency-Key']);
    }

    public function testValorDecimalSaiSemRuidoMesmoComSerializePrecisionAntigo(): void
    {
        $antes = ini_get('serialize_precision');
        ini_set('serialize_precision', '17');
        try {
            $this->http->responder(TransporteFalso::json(202, ['pedido' => ['id' => 'p1']]));
            $this->http->cliente()->notas->emitir('pedido-0003', ['tomador_tipo' => 'pj', 'tomador_id' => 't1', 'valor' => 13.13]);

            self::assertSame('{"tomador_tipo":"pj","tomador_id":"t1","valor":13.13}', $this->http->ultima()['corpo']);
            self::assertSame('17', ini_get('serialize_precision'), 'a configuração do PHP volta como estava');
        } finally {
            ini_set('serialize_precision', (string) $antes);
        }
    }

    public function testTextoQueNaoEUtf8ViraErroLocalSemChamarAApi(): void
    {
        $e = $this->lanca(
            fn () => $this->http->cliente()->notas->emitir('pedido-0004', self::nota(['descricao' => "Servi\xE7o"])),
            ['status' => 0, 'erro' => 'validation_error'],
        );
        self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        self::assertStringContainsString('UTF-8', $e->getMessage());
        self::assertSame([], $this->http->requisicoes);
    }

    // ------------------------------------------------------------------ erros

    public function testErro422ComBloqueiosNosExtrasERequestId(): void
    {
        $bloqueios = [['code' => 'tomador_incompleto', 'mensagem' => 'Falta o endereço do tomador.', 'acao' => 'completar_tomador']];
        $this->http->responder(TransporteFalso::erro(422, 'nota_bloqueada', 'Antes de emitir, resolva:', ['bloqueios' => $bloqueios], ['X-Request-Id' => 'r_8f2a']));

        $e = $this->lanca(
            fn () => $this->http->cliente()->notas->emitir('pedido-0002', self::nota()),
            ['status' => 422, 'erro' => 'nota_bloqueada', 'extras' => ['bloqueios' => $bloqueios], 'requestId' => 'r_8f2a'],
        );
        self::assertSame('Antes de emitir, resolva:', $e->getMessage());
        self::assertSame(422, $e->getCode());
        self::assertSame(422, $e->getStatus());
        self::assertSame('nota_bloqueada', $e->getErro());
        self::assertSame('r_8f2a', $e->getRequestId());
        self::assertSame('completar_tomador', $e->getExtras()['bloqueios'][0]['acao']);
    }

    public function testErrosDeDuplicidadeTrazemOQueOCodigoPrecisa(): void
    {
        $igual = ['pedido_id' => '9b2e2c6e-1f43-4f4b-9a4e-6f1d2d6a7c11', 'numero_nfse' => '120', 'emitida_em' => '2026-11-05T13:00:00Z', 'status' => 'autorizada'];
        $this->http->responder(
            TransporteFalso::erro(409, 'possivel_duplicada', 'Já foi emitida hoje uma nota igual a esta. Emitir outra?', ['nota_igual_recente' => $igual]),
            TransporteFalso::json(202, ['pedido' => ['id' => 'p2', 'estado' => 'na_fila']]),
        );
        $ej = $this->http->cliente();

        $e = $this->lanca(fn () => $ej->notas->emitir('pedido-0005', self::nota()), ['status' => 409, 'erro' => 'possivel_duplicada']);
        $ej->notas->emitir('pedido-0005', self::nota(['permitir_repetida_de' => $e->extras['nota_igual_recente']['pedido_id']]));

        $req = $this->http->ultima();
        self::assertSame('pedido-0005', $req['cabecalhos']['Idempotency-Key']);
        self::assertStringEndsWith(',"permitir_repetida_de":"' . $igual['pedido_id'] . '"}', (string) $req['corpo']);
    }

    public function testLimiteEFilaIndisponivelNaoSaoRepetidosPelaBiblioteca(): void
    {
        $this->http->responder(
            TransporteFalso::erro(429, 'rate_limited', 'Muitas ações seguidas. Espere 12 s.', ['retry_after' => 12], ['Retry-After' => '12']),
            TransporteFalso::erro(503, 'fila_indisponivel', 'Não conseguimos enviar agora. Tentando de novo…'),
        );
        $ej = $this->http->cliente();

        $e = $this->lanca(fn () => $ej->notas->emitir('pedido-0006', self::nota()), ['status' => 429, 'erro' => 'rate_limited']);
        self::assertSame(['retry_after' => 12], $e->extras);
        $this->lanca(fn () => $ej->notas->emitir('pedido-0006', self::nota()), ['status' => 503, 'erro' => 'fila_indisponivel']);

        self::assertCount(2, $this->http->requisicoes, 'uma chamada por emitir, sem repetição automática');
        self::assertSame([], $this->http->esperas);
    }

    public function testCorpoDeErroQueNaoEJson(): void
    {
        $this->http->responder(
            ['status' => 502, 'corpo' => "\n<html><body>Bad Gateway</body></html>\n", 'cabecalhos' => ['Content-Type' => 'text/html']],
            ['status' => 500, 'corpo' => str_repeat('é', 250)],
            ['status' => 503, 'corpo' => ''],
            TransporteFalso::json(409, ['status' => 'ERROR', 'message' => 'Sem code no corpo']),
            ['status' => 500, 'corpo' => '{"code": "quebrado'],
        );
        $ej = $this->http->cliente();

        $e = $this->lanca(fn () => $ej->empresa->obter(), ['status' => 502, 'erro' => 'erro_desconhecido', 'extras' => [], 'requestId' => null]);
        self::assertSame('<html><body>Bad Gateway</body></html>', $e->getMessage());

        $e = $this->lanca(fn () => $ej->empresa->obter(), ['status' => 500, 'erro' => 'erro_desconhecido']);
        self::assertSame(str_repeat('é', 200), $e->getMessage(), 'até 200 caracteres (não bytes)');

        $e = $this->lanca(fn () => $ej->empresa->obter(), ['status' => 503, 'erro' => 'erro_desconhecido']);
        self::assertSame('HTTP 503', $e->getMessage());

        $e = $this->lanca(fn () => $ej->empresa->obter(), ['status' => 409, 'erro' => 'erro_desconhecido', 'extras' => []]);
        self::assertSame('Sem code no corpo', $e->getMessage());

        $e = $this->lanca(fn () => $ej->empresa->obter(), ['status' => 500, 'erro' => 'erro_desconhecido']);
        self::assertSame('{"code": "quebrado', $e->getMessage());
    }

    public function testRedirecionamentoNaoESeguidoEExplicaABaseUrl(): void
    {
        $this->http->responder(['status' => 301, 'corpo' => '', 'cabecalhos' => ['Location' => 'https://emissaoja.com.br/v1/empresa']]);
        $e = $this->lanca(fn () => $this->http->cliente()->empresa->obter(), ['status' => 301, 'erro' => 'erro_desconhecido']);
        self::assertStringContainsString('https://emissaoja.com.br/v1/empresa', $e->getMessage());
        self::assertStringContainsString('baseUrl', $e->getMessage());
    }

    public function testRespostaDeSucessoSemJsonERespostaVazia(): void
    {
        $this->http->responder(
            ['status' => 200, 'corpo' => '<!doctype html><title>app</title>', 'cabecalhos' => ['X-Request-Id' => 'r_html']],
            ['status' => 202, 'corpo' => ''],
        );
        $ej = $this->http->cliente();

        $e = $this->lanca(fn () => $ej->status(), ['status' => 200, 'erro' => 'resposta_invalida', 'requestId' => 'r_html']);
        self::assertStringContainsString(TransporteFalso::BASE, $e->getMessage());
        self::assertSame([], $ej->empresa->relerFisco());
    }

    public function testFalhaDoTransporteViraRedeEErroDoTransportePassaDireto(): void
    {
        $tempo = new EmissaojaException(0, 'tempo_esgotado', 'A API do emissaoja não respondeu em 30 s.');
        $this->http->responder(new \RuntimeException('conexão recusada pelo proxy'), $tempo);
        $ej = $this->http->cliente();

        $e = $this->lanca(fn () => $ej->empresa->obter(), ['status' => 0, 'erro' => 'rede']);
        self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        self::assertStringContainsString('conexão recusada pelo proxy', $e->getMessage());

        self::assertSame($tempo, $this->lanca(fn () => $ej->empresa->obter(), ['erro' => 'tempo_esgotado']));
    }

    public function testTransporteQueDevolveFormatoErradoEhAvisado(): void
    {
        $this->http->responder(['status' => '200', 'corpo' => '{}']);
        $this->expectException(\UnexpectedValueException::class);
        $this->http->cliente()->empresa->obter();
    }

    // ------------------------------------------------------------------ corpo, query e caminho

    public function testRequisicoesSemCorpoNaoMandamCorpoNemContentType(): void
    {
        $this->http->sempre(fn () => TransporteFalso::json(202, []));
        $ej = $this->http->cliente();
        $ej->empresa->relerFisco();
        $ej->emissoes->conferir('p1');
        $ej->servicos->desativar('s1');

        self::assertSame(['POST /v1/empresa/reler-fisco', 'POST /v1/emissoes/p1/conferir', 'DELETE /v1/servicos/s1'], $this->http->caminhos());
        foreach ($this->http->requisicoes as $req) {
            self::assertNull($req['corpo']);
            self::assertArrayNotHasKey('Content-Type', $req['cabecalhos']);
        }
    }

    public function testCorpoVazioViraObjetoJsonENulosSaemOndeNaoTemSentido(): void
    {
        $this->http->sempre(fn () => TransporteFalso::json(200, []));
        $ej = $this->http->cliente();
        $ej->empresa->fiscal([]);
        $ej->empresa->fiscal(['aliquota_simples' => 6.5, 'reg_ap_trib_sn' => null]);
        $ej->tomadores->atualizar('pj', 't1', []);
        $ej->servicos->atualizar('s1', ['c_trib_mun' => null]); // na edição, null limpa o campo e vai no corpo

        self::assertSame(
            ['{}', '{"aliquota_simples":6.5}', '{}', '{"c_trib_mun":null}'],
            array_column($this->http->requisicoes, 'corpo'),
        );
        foreach ($this->http->requisicoes as $req) {
            self::assertSame('application/json', $req['cabecalhos']['Content-Type']);
        }
    }

    public function testCorposMontadosPelosMetodos(): void
    {
        $this->http->sempre(fn () => TransporteFalso::json(200, []));
        $ej = $this->http->cliente();
        $ej->empresa->conectar('11111111000191', 'senha do portal');
        $ej->empresa->conectar('11111111000191', 'senha do portal', cienteUltimaTentativa: true);
        $ej->tomadores->criar('12345678000195');
        $ej->tomadores->criar('52998224725', 'Maria Souza');
        $ej->notas->cancelar('local', 'n1', 1);
        $ej->notas->cancelar('local', 'n1', '2', 'Serviço não foi prestado ao cliente');
        $ej->servicos->criar(['descricao' => 'Desenvolvimento de sistemas', 'c_trib_nac' => '010101', 'trib_issqn' => 'tributavel', 'retem_iss' => false]);

        self::assertSame([
            '{"cnpj":"11111111000191","senha":"senha do portal","ciente_ultima_tentativa":false}',
            '{"cnpj":"11111111000191","senha":"senha do portal","ciente_ultima_tentativa":true}',
            '{"documento":"12345678000195"}',
            '{"documento":"52998224725","nome":"Maria Souza"}',
            '{"motivo":"1"}',
            '{"motivo":"2","justificativa":"Serviço não foi prestado ao cliente"}',
            '{"descricao":"Desenvolvimento de sistemas","c_trib_nac":"010101","trib_issqn":"tributavel","retem_iss":false}',
        ], array_column($this->http->requisicoes, 'corpo'));
    }

    public function testQueryOmiteNulosEVaziosEJuntaListas(): void
    {
        $this->http->sempre(fn () => TransporteFalso::json(200, []));
        $ej = $this->http->cliente();
        $ej->notas->listar([
            'periodo' => 'personalizado',
            'de' => '2026-10-01',
            'ate' => '2026-10-31',
            'situacao' => '',
            'busca' => 'joão & filhos',
            'cursor' => null,
            'limite' => 20,
        ]);
        $ej->tomadores->listar();
        $ej->tomadores->listar(['busca' => null, 'pagina' => 2, 'ordem' => 'recentes']);
        $ej->emissoes->listar(['estados' => ['na_fila', 'emitindo', null]]);
        $ej->servicos->listar(['ativos' => 'todos']);
        $ej->servicos->listar(['ativos' => true]);
        $ej->servicos->catalogo('consultoria em ti', 0);
        $ej->servicos->catalogo('ti', 5);
        $ej->tomadores->buscar('alfa beta');

        self::assertSame([
            'GET /v1/notas?periodo=personalizado&de=2026-10-01&ate=2026-10-31&busca=jo%C3%A3o%20%26%20filhos&limite=20',
            'GET /v1/tomadores',
            'GET /v1/tomadores?pagina=2&ordem=recentes',
            'GET /v1/emissoes?estados=na_fila%2Cemitindo',
            'GET /v1/servicos?ativos=todos',
            'GET /v1/servicos?ativos=1',
            'GET /v1/catalogo/servicos?q=consultoria%20em%20ti',
            'GET /v1/catalogo/servicos?q=ti&limite=5',
            'GET /v1/tomadores/busca?q=alfa%20beta',
        ], $this->http->caminhos());
    }

    public function testFiltroDeTipoInvalidoEhRecusadoAntesDeChamar(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('busca');
        $this->http->cliente()->notas->listar(['busca' => new \stdClass()]);
    }

    public function testSegmentosDoCaminhoSaoCodificados(): void
    {
        $this->http->sempre(fn () => TransporteFalso::json(200, []));
        $ej = $this->http->cliente();
        $ej->tomadores->obter('pj', 'a/b c?d#e%f');
        $ej->operacoes->obter('op 1');
        $ej->emissoes->obter('../segredo');
        $ej->servicos->atualizar('s/1', ['ativo' => true]);

        self::assertSame([
            'GET /v1/tomadores/pj/a%2Fb%20c%3Fd%23e%25f',
            'GET /v1/operacoes/op%201',
            'GET /v1/emissoes/..%2Fsegredo',
            'PUT /v1/servicos/s%2F1',
        ], $this->http->caminhos());
    }

    public function testCnpjConsultarTiraAPontuacao(): void
    {
        $this->http->responder(TransporteFalso::json(200, ['cnpj' => '12345678000195', 'razao_social' => 'EMPRESA EXEMPLO LTDA']));
        $dados = $this->http->cliente()->cnpj->consultar('12.345.678/0001-95');
        self::assertSame('EMPRESA EXEMPLO LTDA', $dados['razao_social']);
        self::assertSame(['GET /v1/cnpj/12345678000195'], $this->http->caminhos());
    }

    public function testPdfDevolveOsBytesEXmlOTexto(): void
    {
        $pdf = "%PDF-1.4\n" . implode('', array_map('chr', range(0, 255))) . "\n%%EOF\n";
        $xml = '<?xml version="1.0" encoding="UTF-8"?><NFSe><infNFSe><xDescServ>Consultoria – revisão</xDescServ></infNFSe></NFSe>';
        $this->http->responder(
            ['status' => 200, 'corpo' => $pdf, 'cabecalhos' => ['Content-Type' => 'application/pdf']],
            ['status' => 200, 'corpo' => $xml, 'cabecalhos' => ['Content-Type' => 'application/xml; charset=utf-8']],
            TransporteFalso::erro(404, 'nota_nao_encontrada', 'Nota não encontrada.', [], ['X-Request-Id' => 'r_404']),
        );
        $ej = $this->http->cliente();

        self::assertSame($pdf, $ej->notas->pdf('local', 'n-1'));
        self::assertSame($xml, $ej->notas->xml('importada', 'n-2'));
        $this->lanca(fn () => $ej->notas->pdf('local', 'sumida'), ['status' => 404, 'erro' => 'nota_nao_encontrada', 'requestId' => 'r_404']);

        self::assertSame(['GET /v1/notas/local/n-1/pdf', 'GET /v1/notas/importada/n-2/xml', 'GET /v1/notas/local/sumida/pdf'], $this->http->caminhos());
        self::assertSame('Bearer ' . TransporteFalso::CHAVE, $this->http->requisicoes[0]['cabecalhos']['Authorization']);
    }

    public function testCadaMetodoChamaARotaCerta(): void
    {
        $this->http->sempre(fn () => TransporteFalso::json(200, ['ok' => true]));
        $ej = $this->http->cliente();

        $ej->status();
        $ej->empresa->obter();
        $ej->empresa->conectar('11111111000191', 'senha');
        $ej->empresa->credencial();
        $ej->empresa->onboarding();
        $ej->empresa->fiscal(['aliquota_simples' => 6]);
        $ej->empresa->relerFisco();
        $ej->tomadores->listar(['busca' => 'alfa']);
        $ej->tomadores->buscar('alfa');
        $ej->tomadores->criar('12345678000195');
        $ej->tomadores->obter('pj', 't1');
        $ej->tomadores->atualizar('pj', 't1', ['email' => 'financeiro@cliente.com.br']);
        $ej->servicos->listar();
        $ej->servicos->criar(['descricao' => 'Consultoria']);
        $ej->servicos->atualizar('s1', ['descricao' => 'Consultoria em TI']);
        $ej->servicos->desativar('s1');
        $ej->servicos->catalogo('ti', 5);
        $ej->notas->emitir('pedido-0001', self::nota());
        $ej->notas->listar();
        $ej->notas->cancelar('local', 'n1', '1');
        $ej->emissoes->obter('p1');
        $ej->emissoes->listar();
        $ej->emissoes->conferir('p1');
        $ej->operacoes->obter('o1');
        $ej->cnpj->consultar('12345678000195');

        self::assertSame([
            'GET /v1/status',
            'GET /v1/empresa',
            'PUT /v1/empresa/credencial',
            'GET /v1/empresa/credencial',
            'GET /v1/empresa/onboarding',
            'PUT /v1/empresa/fiscal',
            'POST /v1/empresa/reler-fisco',
            'GET /v1/tomadores?busca=alfa',
            'GET /v1/tomadores/busca?q=alfa',
            'POST /v1/tomadores',
            'GET /v1/tomadores/pj/t1',
            'PUT /v1/tomadores/pj/t1',
            'GET /v1/servicos',
            'POST /v1/servicos',
            'PUT /v1/servicos/s1',
            'DELETE /v1/servicos/s1',
            'GET /v1/catalogo/servicos?q=ti&limite=5',
            'POST /v1/notas',
            'GET /v1/notas',
            'POST /v1/notas/local/n1/cancelar',
            'GET /v1/emissoes/p1',
            'GET /v1/emissoes',
            'POST /v1/emissoes/p1/conferir',
            'GET /v1/operacoes/o1',
            'GET /v1/cnpj/12345678000195',
        ], $this->http->caminhos());
    }

    // ------------------------------------------------------------------ construtor

    public function testOpcoesPorArrayOuPorArgumentosNomeados(): void
    {
        $t = $this->http;
        $porArray = new Emissaoja(['chave' => 'ej_test_x', 'baseUrl' => 'https://sandbox.emissaoja.com.br/v1/', 'timeout' => 5, 'transporte' => $t]);
        self::assertSame('https://sandbox.emissaoja.com.br', $porArray->baseUrl);
        self::assertSame(5.0, $porArray->timeout);

        $nomeados = new Emissaoja(chave: 'ej_test_x', baseUrl: Emissaoja::BASE_SANDBOX, timeout: 5, transporte: $t);
        self::assertSame($porArray->baseUrl, $nomeados->baseUrl);

        $misturado = new Emissaoja(['timeout' => 5, 'transporte' => $t], timeout: 9);
        self::assertSame(9.0, $misturado->timeout, 'o argumento nomeado vale mais');
        self::assertSame(Emissaoja::BASE_PADRAO, $misturado->baseUrl);

        $t->responder(TransporteFalso::json(200, []), TransporteFalso::json(200, []));
        $porArray->empresa->obter();
        (new Emissaoja('ej_test_posicional', transporte: $t))->empresa->obter();
        self::assertSame('Bearer ej_test_x', $t->requisicoes[0]['cabecalhos']['Authorization']);
        self::assertSame('Bearer ej_test_posicional', $t->requisicoes[1]['cabecalhos']['Authorization']);
        self::assertSame(5.0, $t->requisicoes[0]['timeout']);
        self::assertStringStartsWith('https://emissaoja.com.br/v1/', $t->requisicoes[1]['url']);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function opcoesInvalidas(): iterable
    {
        yield 'nome desconhecido' => [['base_url' => 'https://x.teste'], 'base_url'];
        yield 'timeout em texto' => [['timeout' => '30'], 'timeout'];
        yield 'transporte errado' => [['transporte' => 'curl'], 'transporte'];
        yield 'chave que não é texto' => [['chave' => 123], 'chave'];
        yield 'dormir que não é função' => [['dormir' => 'nao_existe_essa_funcao'], 'dormir'];
        yield 'baseUrl sem esquema' => [['baseUrl' => 'emissaoja.com.br'], 'https://'];
        yield 'baseUrl com query' => [['baseUrl' => 'https://emissaoja.com.br/?origem=x'], 'https://'];
    }

    /** @param array<string, mixed> $opcoes */
    #[DataProvider('opcoesInvalidas')]
    public function testOpcoesInvalidasSaoRecusadasNoConstrutor(array $opcoes, string $trecho): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($trecho);
        new Emissaoja($opcoes);
    }

    public function testRecursosTambemComoMetodosParaAFacade(): void
    {
        $ej = $this->http->cliente();
        self::assertInstanceOf(Notas::class, $ej->notas());
        self::assertSame($ej->notas, $ej->notas());
        self::assertSame($ej->empresa, $ej->empresa());
        self::assertSame($ej->tomadores, $ej->tomadores());
        self::assertSame($ej->servicos, $ej->servicos());
        self::assertSame($ej->emissoes, $ej->emissoes());
        self::assertSame($ej->operacoes, $ej->operacoes());
        self::assertSame($ej->cnpj, $ej->cnpj());
    }

    public function testChaveNaoApareceAoInspecionarNemSerializar(): void
    {
        $ej = $this->http->cliente(['chave' => 'ej_live_segredo123']);
        foreach ([$ej, $ej->notas, $ej->empresa] as $objeto) {
            self::assertStringNotContainsString('segredo123', print_r($objeto, true));
            ob_start();
            var_dump($objeto);
            self::assertStringNotContainsString('segredo123', (string) ob_get_clean());
        }
        self::assertSame('***', $ej->__debugInfo()['chave']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('chave de API');
        serialize($ej);
    }
}
