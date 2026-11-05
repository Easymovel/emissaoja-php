<?php

declare(strict_types=1);

namespace Emissaoja\Tests\Laravel;

/**
 * O pedaço do container do Laravel (Illuminate\Foundation\Application) que o provider e a Facade usam: singleton,
 * alias, make, configPath e acesso como array.
 *
 * @implements \ArrayAccess<string, mixed>
 */
final class AppFalso implements \ArrayAccess
{
    public ConfigFalsa $config;

    /** @var array<string, \Closure> */
    private array $fabricas = [];
    /** @var array<string, mixed> */
    private array $instancias = [];
    /** @var array<string, string> */
    private array $apelidos = [];

    public function __construct(private readonly string $raiz)
    {
        $this->config = new ConfigFalsa();
    }

    public function singleton(string $abstrato, \Closure $fabrica): void
    {
        $this->fabricas[$abstrato] = $fabrica;
    }

    public function alias(string $abstrato, string $apelido): void
    {
        $this->apelidos[$apelido] = $abstrato;
    }

    public function make(string $abstrato): mixed
    {
        if ($abstrato === 'config') {
            return $this->config;
        }
        $abstrato = $this->apelidos[$abstrato] ?? $abstrato;
        if (!\array_key_exists($abstrato, $this->instancias)) {
            if (!isset($this->fabricas[$abstrato])) {
                throw new \LogicException("Nada registrado para $abstrato");
            }
            $this->instancias[$abstrato] = ($this->fabricas[$abstrato])($this);
        }
        return $this->instancias[$abstrato];
    }

    public function configPath(string $caminho = ''): string
    {
        return $this->raiz . '/config' . ($caminho !== '' ? '/' . $caminho : '');
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->fabricas[$offset]) || isset($this->apelidos[$offset]) || $offset === 'config';
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->make((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('AppFalso não aceita atribuição');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('AppFalso não aceita remoção');
    }
}
