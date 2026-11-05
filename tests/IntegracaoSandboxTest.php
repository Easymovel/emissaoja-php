<?php

declare(strict_types=1);

namespace Emissaoja\Tests;

use Emissaoja\Emissaoja;
use PHPUnit\Framework\TestCase;

/**
 * Fluxo completo contra o sandbox (https://sandbox.emissaoja.com.br: Emissor Nacional simulado, nada vai ao fisco).
 *
 * Opcional: só roda com EMISSAOJA_TEST_KEY (uma chave ej_test_ criada no sandbox, em Conta → API); sem ela, os testes
 * são pulados (é o caso do CI). EMISSAOJA_TEST_BASE_URL troca a base, por exemplo para um servidor local. Por
 * segurança, o teste falha se o servidor não disser que é o sandbox (ou que o motor é simulado).
 *
 *     EMISSAOJA_TEST_KEY=ej_test_... vendor/bin/phpunit --filter IntegracaoSandboxTest
 */
final class IntegracaoSandboxTest extends TestCase
{
    use EsperaErro;

    private Emissaoja $ej;

    protected function setUp(): void
    {
        $chave = getenv('EMISSAOJA_TEST_KEY');
        if (!\is_string($chave) || trim($chave) === '') {
            self::markTestSkipped('Defina EMISSAOJA_TEST_KEY (chave ej_test_ do sandbox) para testar contra o sandbox.');
        }
        $base = getenv('EMISSAOJA_TEST_BASE_URL');
        $this->ej = new Emissaoja(
            chave: trim($chave),
            baseUrl: \is_string($base) && trim($base) !== '' ? trim($base) : Emissaoja::BASE_SANDBOX,
            timeout: 60,
        );
        $status = $this->ej->status();
        if (($status['ambiente'] ?? null) !== 'sandbox' && ($status['motor'] ?? null) !== 'simulado') {
            self::fail(sprintf(
                'Recusado: %s respondeu ambiente "%s" e motor "%s"; este teste emite notas e só roda no sandbox.',
                $this->ej->baseUrl,
                (string) ($status['ambiente'] ?? '?'),
                (string) ($status['motor'] ?? '?'),
            ));
        }
    }

    public function testStatusDoSandbox(): void
    {
        $status = $this->ej->status();
        self::assertTrue($status['ok']);
        self::assertNotEmpty($status['versao']);
    }

    public function testConectarCriarTomadorEmitirAguardarEBaixar(): void
    {
        $ej = $this->ej;

        if ($ej->empresa->obter()['empresa'] === null) {
            $tentativa = $ej->empresa->conectar('11111111000191', 'senha-de-teste')['tentativa'];
            self::assertArrayHasKey('proximo_passo', $tentativa);
            $credencial = $ej->empresa->aguardarCredencial();
            self::assertContains($credencial['tentativa']['proximo_passo'] ?? 'pronto', ['configurando', 'pronto']);
        }
        $onboarding = null;
        for ($i = 0; $i < 60; $i++) {
            $onboarding = $ej->empresa->obter()['onboarding']['estado'] ?? null;
            if ($onboarding === 'pronto') {
                break;
            }
            sleep(2);
        }
        if ($onboarding !== 'pronto') {
            self::markTestSkipped("A empresa da conta do sandbox não ficou pronta para emitir (onboarding: $onboarding). Use a 11111111000191.");
        }

        $tomador = $ej->tomadores->criar('12345678000195')['tomador'];
        self::assertSame('pj', $tomador['tipo']);

        // valor sorteado entre 100,01 e 199,99: os valores com significado no sandbox (11,11 a 99,99, 21,01 a 21,11,
        // 87,87) ficam abaixo de 100; referência nova a cada execução
        $referencia = 'php-' . bin2hex(random_bytes(8));
        $campos = [
            'tomador_tipo' => $tomador['tipo'],
            'tomador_id' => $tomador['id'],
            'valor' => random_int(10001, 19999) / 100,
            'descricao' => 'Teste de integração da biblioteca emissaoja-php',
        ];
        $pedido = $ej->notas->emitir($referencia, $campos)['pedido'];
        self::assertNotEmpty($pedido['id']);

        $dobro = $ej->notas->emitir($referencia, $campos);
        self::assertTrue($dobro['repetido'] ?? false, 'mesma referência devolve o mesmo pedido');
        self::assertSame($pedido['id'], $dobro['pedido']['id']);

        $final = $ej->notas->aguardar($pedido['id'], timeout: 120)['pedido'];
        self::assertSame('autorizada', $final['estado'], (string) json_encode($final, JSON_UNESCAPED_UNICODE));
        self::assertNotEmpty($final['numero']);

        $pdf = $ej->notas->pdf($final['nota']['origem'], $final['nota']['id']);
        self::assertStringStartsWith('%PDF', $pdf);
        self::assertStringContainsString('<', $ej->notas->xml($final['nota']['origem'], $final['nota']['id']));

        $e = $this->lanca(fn () => $ej->tomadores->obter('pj', 'nao-existe'), ['status' => 404, 'erro' => 'tomador_nao_encontrado']);
        self::assertNotNull($e->requestId);
    }
}
