<?php

// Imitação mínima das peças do Laravel que o EmissaojaServiceProvider, a Facade e o config/emissaoja.php usam, com
// as mesmas assinaturas do Laravel. O pacote não depende do Laravel; assim o CI executa esse código sem instalá-lo.
// Carregado só pelo LaravelTest, e só quando o Laravel não está instalado.

declare(strict_types=1);

namespace Illuminate\Support {
    if (!class_exists(ServiceProvider::class, false)) {
        abstract class ServiceProvider
        {
            /** @var array<string, array<string, string>> grupo => [origem => destino] */
            public static array $publicados = [];

            /** @var mixed */
            protected $app;

            public function __construct($app)
            {
                $this->app = $app;
            }

            public function register()
            {
            }

            protected function mergeConfigFrom($path, $key)
            {
                $config = $this->app->make('config');
                $config->set($key, array_merge(require $path, $config->get($key, [])));
            }

            protected function publishes(array $paths, $groups = null)
            {
                static::$publicados[(string) $groups] = $paths;
            }
        }
    }
}

namespace Illuminate\Support\Facades {
    if (!class_exists(Facade::class, false)) {
        abstract class Facade
        {
            /** @var mixed */
            protected static $app;

            public static function setFacadeApplication($app)
            {
                static::$app = $app;
            }

            public static function getFacadeRoot()
            {
                return static::$app[static::getFacadeAccessor()];
            }

            protected static function getFacadeAccessor()
            {
                throw new \RuntimeException('Facade does not implement getFacadeAccessor method.');
            }

            public static function __callStatic($method, $args)
            {
                return static::getFacadeRoot()->$method(...$args);
            }
        }
    }
}

namespace {
    if (!function_exists('env')) {
        function env($key, $default = null)
        {
            $valor = getenv($key);
            return $valor === false ? $default : $valor;
        }
    }
}
