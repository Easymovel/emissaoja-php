<?php

declare(strict_types=1);

namespace Emissaoja\Tests;

use Emissaoja\Emissaoja;
use PHPUnit\Framework\TestCase;

/**
 * notas->aguardar, empresa->aguardarCredencial e operacoes->aguardar com o relógio falso do TransporteFalso: as
 * esperas são registradas e o tempo só anda quando o cliente "dorme".
 */
final class AguardarTest extends TestCase
{
    use EsperaErro;

    private TransporteFalso $http;

    protected function setUp(): void
    {
        $this->http = new TransporteFalso();
    }

    /**
     * @param array<string, mixed> $extras
     *
     * @return array<string, mixed>
     */
    private static function pedido(string $estado, int|float|null $proximaConsultaMs = null, array $extras = []): array
    {
        return TransporteFalso::json(200, ['pedido' => ['id' => 'p1', 'estado' => $estado, 'proxima_consulta_ms' => $proximaConsultaMs] + $extras]);
    }

    public function testNotasAguardarRespeitaProximaConsultaMsComPisoEPadrao(): void
    {
        $this->http->responder(
            self::pedido('na_fila', 2500),
            self::pedido('emitindo', 400),       // menos que o intervalo mínimo (1 s): espera 1 s
            self::pedido('confirmando', null),   // sem dica: 3 s
            self::pedido('confirmando', 0),      // zero também vira 3 s
            self::pedido('autorizada', null, ['numero' => '42', 'chave_acesso' => '3106...', 'nota' => ['origem' => 'local', 'id' => 'n1']]),
        );

        $r = $this->http->cliente()->notas->aguardar('p1');

        self::assertSame('autorizada', $r['pedido']['estado']);
        self::assertSame(['origem' => 'local', 'id' => 'n1'], $r['pedido']['nota']);
        self::assertSame([2.5, 1.0, 3.0, 3.0], $this->http->esperas);
        self::assertSame(array_fill(0, 5, 'GET /v1/emissoes/p1'), $this->http->caminhos());
    }

    public function testIntervaloMinimoMenorDeixaSeguirADicaDaApi(): void
    {
        $this->http->responder(self::pedido('emitindo', 400), self::pedido('emitindo', 1500), self::pedido('recusada', null, ['motivo_code' => 'E0001', 'motivo' => 'Recusada pelo fisco']));

        $r = $this->http->cliente()->notas->aguardar('p1', intervaloMinimo: 0.1);

        self::assertSame('Recusada pelo fisco', $r['pedido']['motivo']);
        self::assertSame([0.4, 1.5], $this->http->esperas);
    }

    public function testEstadosFinaisDevolvemNaHora(): void
    {
        foreach (['autorizada', 'recusada', 'incerta'] as $estado) {
            $http = new TransporteFalso();
            $http->responder(self::pedido($estado, 5000));
            self::assertSame($estado, $http->cliente()->notas->aguardar('p1')['pedido']['estado']);
            self::assertCount(1, $http->requisicoes);
            self::assertSame([], $http->esperas);
        }
    }

    public function testNotasAguardarEstouraOTempoComOPedidoNosExtras(): void
    {
        $this->http->sempre(fn () => self::pedido('emitindo', 5000));

        $this->lanca(
            fn () => $this->http->cliente()->notas->aguardar('p1', timeout: 12),
            ['status' => 0, 'erro' => 'tempo_esgotado', 'extras' => ['pedido_id' => 'p1']],
        );

        // t=0, 5, 10 dentro do prazo; em t=15 passou dos 12 s
        self::assertSame([5.0, 5.0, 5.0], $this->http->esperas);
        self::assertCount(4, $this->http->requisicoes);
    }

    public function testDormirPadraoComIntervalosMinusculos(): void
    {
        $t = new TransporteFalso();
        $t->responder(self::pedido('emitindo', 1), self::pedido('confirmando', 2), self::pedido('autorizada'));
        $ej = new Emissaoja(chave: TransporteFalso::CHAVE, baseUrl: TransporteFalso::BASE, transporte: $t); // dorme de verdade

        $inicio = microtime(true);
        $r = $ej->notas->aguardar('p1', timeout: 5, intervaloMinimo: 0.001);

        self::assertSame('autorizada', $r['pedido']['estado']);
        self::assertCount(3, $t->requisicoes);
        self::assertLessThan(2.0, microtime(true) - $inicio);
    }

    public function testAguardarCredencialAteSairDeAguardar(): void
    {
        $verificando = TransporteFalso::json(200, [
            'credencial' => ['estado' => 'verificando'],
            'tentativa' => ['id' => 'tc1', 'estado' => 'verificando', 'proximo_passo' => 'aguardar', 'code' => null],
        ]);
        $this->http->responder($verificando, $verificando, TransporteFalso::json(200, [
            'credencial' => ['estado' => 'ativa'],
            'onboarding' => ['estado' => 'configurando'],
            'tentativa' => ['id' => 'tc1', 'estado' => 'aceita', 'proximo_passo' => 'configurando', 'code' => null],
        ]));

        $r = $this->http->cliente()->empresa->aguardarCredencial();

        self::assertSame('configurando', $r['tentativa']['proximo_passo']);
        self::assertSame([2.0, 2.0], $this->http->esperas);
        self::assertSame(array_fill(0, 3, 'GET /v1/empresa/credencial'), $this->http->caminhos());
    }

    public function testAguardarCredencialSemTentativaDevolveNaHoraEEstouraOTempo(): void
    {
        $this->http->responder(TransporteFalso::json(200, ['credencial' => ['estado' => 'ausente'], 'tentativa' => null]));
        self::assertNull($this->http->cliente()->empresa->aguardarCredencial()['tentativa']);
        self::assertSame([], $this->http->esperas);

        $http = new TransporteFalso();
        $http->sempre(fn () => TransporteFalso::json(200, ['tentativa' => ['proximo_passo' => 'aguardar']]));
        $this->lanca(fn () => $http->cliente()->empresa->aguardarCredencial(intervalo: 1, timeout: 2.5), ['status' => 0, 'erro' => 'tempo_esgotado']);
        self::assertSame([1.0, 1.0, 1.0], $http->esperas);
        self::assertCount(4, $http->requisicoes);
    }

    public function testOperacoesAguardarAteSairDeProcessando(): void
    {
        $operacao = static fn (string $estado, array $extras = []): array => TransporteFalso::json(200, [
            'operacao' => array_replace(['id' => 'o1', 'tipo' => 'cancelamento', 'estado' => $estado, 'resultado' => null, 'erro' => null], $extras),
        ]);
        $this->http->responder($operacao('processando'), $operacao('processando'), $operacao('concluida', ['resultado' => ['situacao' => 'cancelada']]));

        $r = $this->http->cliente()->operacoes->aguardar('o1', intervalo: 0.5);

        self::assertSame('concluida', $r['operacao']['estado']);
        self::assertSame([0.5, 0.5], $this->http->esperas);
        self::assertSame(array_fill(0, 3, 'GET /v1/operacoes/o1'), $this->http->caminhos());

        $http = new TransporteFalso();
        $http->responder($operacao('falhou', ['erro' => ['code' => 'cancelamento_fora_do_prazo', 'message' => 'Fora do prazo.']]));
        $falhou = $http->cliente()->operacoes->aguardar('o1')['operacao'];
        self::assertSame('falhou', $falhou['estado']);
        self::assertSame('cancelamento_fora_do_prazo', $falhou['erro']['code']);

        $http = new TransporteFalso();
        $http->sempre(fn () => $operacao('processando'));
        $this->lanca(fn () => $http->cliente()->operacoes->aguardar('o1', timeout: 3), ['status' => 0, 'erro' => 'tempo_esgotado']);
        self::assertSame([2.0, 2.0], $http->esperas);
    }
}
