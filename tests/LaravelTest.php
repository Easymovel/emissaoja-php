<?php

declare(strict_types=1);

namespace Emissaoja\Tests;

use Emissaoja\Emissaoja;
use Emissaoja\Laravel\EmissaojaServiceProvider;
use Emissaoja\Laravel\Facades\Emissaoja as EmissaojaFacade;
use Emissaoja\Recursos\Empresa;
use Emissaoja\Tests\Laravel\AppFalso;
use PHPUnit\Framework\TestCase;

/**
 * Service provider, Facade e config/emissaoja.php, sem instalar o Laravel: tests/Laravel/stubs.php imita as poucas
 * peças do Laravel que eles usam (ServiceProvider, Facade, env()).
 */
final class LaravelTest extends TestCase
{
    private const VARIAVEIS = ['EMISSAOJA_KEY', 'EMISSAOJA_BASE_URL', 'EMISSAOJA_TIMEOUT'];

    /** @var array<string, string|false> */
    private array $ambiente = [];

    protected function setUp(): void
    {
        if (class_exists(\Illuminate\Foundation\Application::class)) {
            self::markTestSkipped('Com o Laravel instalado, teste a integração num app de verdade.');
        }
        require_once __DIR__ . '/Laravel/stubs.php';
        \Illuminate\Support\ServiceProvider::$publicados = [];

        foreach (self::VARIAVEIS as $nome) {
            $this->ambiente[$nome] = getenv($nome);
            putenv($nome);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->ambiente as $nome => $valor) {
            putenv($valor === false ? $nome : "$nome=$valor");
        }
    }

    private static function arquivoDeConfig(): string
    {
        return \dirname(__DIR__) . '/config/emissaoja.php';
    }

    public function testConfigLeAsVariaveisDeAmbiente(): void
    {
        self::assertSame(
            ['key' => null, 'base_url' => 'https://emissaoja.com.br', 'timeout' => 30],
            require self::arquivoDeConfig(),
        );

        putenv('EMISSAOJA_KEY=ej_test_do_env');
        putenv('EMISSAOJA_BASE_URL=https://sandbox.emissaoja.com.br');
        putenv('EMISSAOJA_TIMEOUT=12');
        self::assertSame(
            ['key' => 'ej_test_do_env', 'base_url' => 'https://sandbox.emissaoja.com.br', 'timeout' => '12'],
            require self::arquivoDeConfig(),
        );
    }

    public function testProviderRegistraOSingletonComAConfigDoAppEPublicaOArquivo(): void
    {
        $app = new AppFalso('/projeto');
        $app->config->set('emissaoja', ['key' => 'ej_test_da_config', 'base_url' => 'https://sandbox.emissaoja.com.br/', 'timeout' => '12']);

        $provider = new EmissaojaServiceProvider($app);
        $provider->register();
        $provider->boot();

        $ej = $app->make(Emissaoja::class);
        self::assertInstanceOf(Emissaoja::class, $ej);
        self::assertSame($ej, $app->make(Emissaoja::class), 'singleton');
        self::assertSame($ej, $app->make('emissaoja'), 'apelido');
        self::assertSame('https://sandbox.emissaoja.com.br', $ej->baseUrl);
        self::assertSame(12.0, $ej->timeout);
        self::assertSame('***', $ej->__debugInfo()['chave']);

        $publicados = \Illuminate\Support\ServiceProvider::$publicados;
        self::assertSame(['emissaoja-config'], array_keys($publicados));
        self::assertCount(1, $publicados['emissaoja-config']);
        $origem = (string) array_key_first($publicados['emissaoja-config']);
        self::assertSame(realpath(self::arquivoDeConfig()), realpath($origem), 'publica o config/emissaoja.php do pacote');
        self::assertSame('/projeto/config/emissaoja.php', $publicados['emissaoja-config'][$origem]);
    }

    public function testProviderSemConfigDoAppUsaOsPadroes(): void
    {
        $app = new AppFalso('/projeto');
        (new EmissaojaServiceProvider($app))->register();

        $ej = $app->make(Emissaoja::class);
        self::assertSame(Emissaoja::BASE_PADRAO, $ej->baseUrl);
        self::assertSame(30.0, $ej->timeout);
        self::assertStringContainsString('EMISSAOJA_KEY', $ej->__debugInfo()['chave'], 'sem chave na config, lê o ambiente na primeira chamada');
    }

    public function testFacadeChegaAoClienteEAosRecursos(): void
    {
        $app = new AppFalso('/projeto');
        (new EmissaojaServiceProvider($app))->register();
        EmissaojaFacade::setFacadeApplication($app);

        $ej = $app->make(Emissaoja::class);
        self::assertSame($ej, EmissaojaFacade::getFacadeRoot());
        self::assertSame($ej->notas, EmissaojaFacade::notas());
        self::assertInstanceOf(Empresa::class, EmissaojaFacade::empresa());
    }
}
