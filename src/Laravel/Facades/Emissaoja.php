<?php

declare(strict_types=1);

namespace Emissaoja\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade do cliente do emissaoja no Laravel. Pela Facade, os recursos são métodos:
 *
 * ```php
 * use Emissaoja\Laravel\Facades\Emissaoja;
 *
 * $pedido = Emissaoja::notas()->emitir('pedido-0001', [...])['pedido'];
 * $status = Emissaoja::status();
 * ```
 *
 * @method static array<array-key, mixed>          status()
 * @method static \Emissaoja\Recursos\Empresa      empresa()
 * @method static \Emissaoja\Recursos\Tomadores    tomadores()
 * @method static \Emissaoja\Recursos\Servicos     servicos()
 * @method static \Emissaoja\Recursos\Notas        notas()
 * @method static \Emissaoja\Recursos\Emissoes     emissoes()
 * @method static \Emissaoja\Recursos\Operacoes    operacoes()
 * @method static \Emissaoja\Recursos\Cnpj         cnpj()
 *
 * @see \Emissaoja\Emissaoja
 */
class Emissaoja extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Emissaoja\Emissaoja::class;
    }
}
