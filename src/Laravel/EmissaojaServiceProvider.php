<?php

declare(strict_types=1);

namespace Emissaoja\Laravel;

use Emissaoja\Emissaoja;
use Illuminate\Support\ServiceProvider;

/**
 * Registra o cliente do emissaoja no Laravel (descoberta automática pelo composer.json):
 *
 * - `Emissaoja\Emissaoja` vira um singleton configurado por `config/emissaoja.php` (`EMISSAOJA_KEY`,
 *   `EMISSAOJA_BASE_URL`, `EMISSAOJA_TIMEOUT`), também disponível como `app('emissaoja')`;
 * - `php artisan vendor:publish --tag=emissaoja-config` copia a configuração para o projeto.
 *
 * O Laravel não é dependência do pacote: esta classe só é carregada dentro de um app Laravel.
 */
class EmissaojaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(self::configuracao(), 'emissaoja');

        $this->app->singleton(Emissaoja::class, static function ($app): Emissaoja {
            $config = $app->make('config')->get('emissaoja', []);
            $config = \is_array($config) ? $config : [];
            $timeout = $config['timeout'] ?? null;

            return new Emissaoja(
                chave: self::texto($config['key'] ?? null),
                baseUrl: self::texto($config['base_url'] ?? null),
                timeout: is_numeric($timeout) ? (float) $timeout : null,
            );
        });
        $this->app->alias(Emissaoja::class, 'emissaoja');
    }

    public function boot(): void
    {
        $this->publishes([
            self::configuracao() => $this->app->configPath('emissaoja.php'),
        ], 'emissaoja-config');
    }

    private static function configuracao(): string
    {
        return \dirname(__DIR__, 2) . '/config/emissaoja.php';
    }

    private static function texto(mixed $valor): ?string
    {
        return \is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
    }
}
