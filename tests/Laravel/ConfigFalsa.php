<?php

declare(strict_types=1);

namespace Emissaoja\Tests\Laravel;

/** O pedaço do Illuminate\Config\Repository que o provider usa: get e set por chave de primeiro nível. */
final class ConfigFalsa
{
    /** @var array<string, mixed> */
    private array $itens = [];

    public function get(string $chave, mixed $padrao = null): mixed
    {
        return $this->itens[$chave] ?? $padrao;
    }

    public function set(string $chave, mixed $valor): void
    {
        $this->itens[$chave] = $valor;
    }
}
