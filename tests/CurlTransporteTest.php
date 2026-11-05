<?php

declare(strict_types=1);

namespace Emissaoja\Tests;

use Emissaoja\Emissaoja;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * O CurlTransporte de verdade contra o servidor embutido do PHP (php -S) em 127.0.0.1: cabeçalhos, corpo, métodos,
 * bytes do PDF, erros, tempo esgotado, falha de conexão e redirecionamento.
 */
#[RequiresPhpExtension('curl')]
final class CurlTransporteTest extends TestCase
{
    use EsperaErro;

    /** @var resource|null */
    private static $processo = null;
    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        $porta = self::portaLivre();
        $nulo = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $processo = proc_open(
            [\PHP_BINARY, '-S', '127.0.0.1:' . $porta, __DIR__ . '/servidor/roteador.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $nulo, 'w'], 2 => ['file', $nulo, 'w']],
            $pipes,
        );
        if (!\is_resource($processo)) {
            self::markTestSkipped('Não foi possível subir o servidor embutido do PHP (php -S).');
        }
        self::$processo = $processo;
        self::$base = 'http://127.0.0.1:' . $porta;
        for ($i = 0; $i < 100; $i++) {
            $conexao = @fsockopen('127.0.0.1', $porta, $codigo, $mensagem, 0.1);
            if ($conexao !== false) {
                fclose($conexao);
                return;
            }
            usleep(50_000);
        }
        self::fail('O servidor embutido do PHP não subiu a tempo.');
    }

    public static function tearDownAfterClass(): void
    {
        if (\is_resource(self::$processo)) {
            proc_terminate(self::$processo);
            proc_close(self::$processo);
        }
        self::$processo = null;
    }

    private static function portaLivre(): int
    {
        $servidor = stream_socket_server('tcp://127.0.0.1:0', $codigo, $mensagem);
        if ($servidor === false) {
            self::markTestSkipped("Sem porta livre em 127.0.0.1: $mensagem");
        }
        $nome = (string) stream_socket_get_name($servidor, false);
        fclose($servidor);
        return (int) substr((string) strrchr($nome, ':'), 1);
    }

    /** @param array<string, mixed> $opcoes */
    private function cliente(array $opcoes = []): Emissaoja
    {
        return new Emissaoja(...array_merge(['chave' => 'ej_test_curl', 'baseUrl' => self::$base], $opcoes));
    }

    public function testGetComCabecalhosEStatusSemChave(): void
    {
        $ej = $this->cliente();

        $status = $ej->status()['eco'];
        self::assertSame('GET', $status['metodo']);
        self::assertArrayNotHasKey('authorization', $status['cabecalhos']);
        self::assertSame('application/json', $status['cabecalhos']['accept']);
        self::assertStringStartsWith('emissaoja-php/' . Emissaoja::VERSAO, $status['cabecalhos']['user-agent']);

        $empresa = $ej->empresa->obter()['eco'];
        self::assertSame('Bearer ej_test_curl', $empresa['cabecalhos']['authorization']);
        self::assertArrayNotHasKey('content-type', $empresa['cabecalhos']);
        self::assertSame('', $empresa['corpo']);
    }

    public function testPostComCorpoJsonEIdempotencyKey(): void
    {
        $eco = $this->cliente()->notas->emitir('pedido-curl-0001', [
            'tomador_tipo' => 'pj',
            'tomador_id' => 't1',
            'valor' => 150.5,
            'descricao' => 'Consultoria – março',
            'competencia' => null,
        ])['eco'];

        self::assertSame('POST', $eco['metodo']);
        self::assertSame('application/json', $eco['cabecalhos']['content-type']);
        self::assertSame('pedido-curl-0001', $eco['cabecalhos']['idempotency-key']);
        self::assertArrayNotHasKey('expect', $eco['cabecalhos']);
        self::assertSame('{"tomador_tipo":"pj","tomador_id":"t1","valor":150.5,"descricao":"Consultoria – março"}', $eco['corpo']);
    }

    /**
     * A API (Fastify) recusa POST sem corpo que chega com Content-Type: 415 com application/x-www-form-urlencoded
     * (o padrão do curl com POSTFIELDS) e 400 com application/json vazio. Tem de chegar sem Content-Type.
     */
    public function testPostSemCorpoChegaSemContentTypeEComContentLengthZero(): void
    {
        $ej = $this->cliente();

        foreach ([$ej->empresa->relerFisco()['eco'], $ej->emissoes->conferir('p1')['eco']] as $eco) {
            self::assertSame('POST', $eco['metodo']);
            self::assertSame('', $eco['corpo']);
            self::assertArrayNotHasKey('content-type', $eco['cabecalhos'], 'sem corpo, sem Content-Type');
            self::assertSame('0', $eco['cabecalhos']['content-length'] ?? null);
            self::assertArrayNotHasKey('transfer-encoding', $eco['cabecalhos']);
        }
    }

    public function testPutComObjetoVazioVaiComoJson(): void
    {
        $fiscal = $this->cliente()->empresa->fiscal([])['eco'];
        self::assertSame('PUT', $fiscal['metodo']);
        self::assertSame('{}', $fiscal['corpo']);
        self::assertSame('application/json', $fiscal['cabecalhos']['content-type']);
        self::assertSame('2', $fiscal['cabecalhos']['content-length'] ?? null);
    }

    public function testDeleteESegmentosEQueryCodificados(): void
    {
        $ej = $this->cliente();

        $delete = $ej->servicos->desativar('s 1')['eco'];
        self::assertSame('DELETE', $delete['metodo']);
        self::assertSame('/v1/servicos/s%201', $delete['uri']);
        self::assertSame('', $delete['corpo']);

        self::assertSame('/v1/tomadores/pj/a%2Fb%20c', $ej->tomadores->obter('pj', 'a/b c')['eco']['uri']);
        self::assertSame(
            '/v1/tomadores?busca=jo%C3%A3o%20%26%20cia&pagina=2',
            $ej->tomadores->listar(['busca' => 'joão & cia', 'pagina' => 2, 'ordem' => null])['eco']['uri'],
        );
    }

    public function testPdfBinarioEXml(): void
    {
        $ej = $this->cliente();
        self::assertSame("%PDF-1.4\n" . implode('', array_map('chr', range(0, 255))) . "\n%%EOF\n", $ej->notas->pdf('local', 'n1'));

        $xml = $ej->notas->xml('local', 'n1');
        self::assertStringStartsWith('<?xml', $xml);
        self::assertStringContainsString('Consultoria – revisão de código', $xml);
    }

    public function testErroJsonComRequestIdEErroHtml(): void
    {
        $ej = $this->cliente();

        $e = $this->lanca(fn () => $ej->notas->pdf('local', 'sumida'), ['status' => 404, 'erro' => 'nota_nao_encontrada', 'requestId' => 'r_curl404']);
        self::assertSame('Nota não encontrada.', $e->getMessage());

        $e = $this->lanca(fn () => $ej->empresa->onboarding(), ['status' => 502, 'erro' => 'erro_desconhecido']);
        self::assertSame('<html><body><h1>502 Bad Gateway</h1></body></html>', $e->getMessage());
    }

    public function testNaoSegueRedirecionamento(): void
    {
        $e = $this->lanca(fn () => $this->cliente(['baseUrl' => self::$base . '/redireciona'])->empresa->obter(), ['status' => 302]);
        self::assertStringContainsString('https://emissaoja.com.br/v1/empresa', $e->getMessage());
    }

    public function testFalhaDeConexaoViraRede(): void
    {
        $e = $this->lanca(fn () => $this->cliente(['baseUrl' => 'http://127.0.0.1:' . self::portaLivre()])->status(), ['status' => 0, 'erro' => 'rede']);
        self::assertStringContainsString('Falha de rede', $e->getMessage());
    }

    public function testTempoEsgotado(): void
    {
        $inicio = microtime(true);
        $e = $this->lanca(fn () => $this->cliente(['timeout' => 0.3])->emissoes->obter('lento'), ['status' => 0, 'erro' => 'tempo_esgotado']);
        self::assertStringContainsString('0,3 s', $e->getMessage());
        self::assertLessThan(1.4, microtime(true) - $inicio, 'parou no timeout, sem esperar a resposta');
    }
}
