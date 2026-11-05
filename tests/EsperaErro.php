<?php

declare(strict_types=1);

namespace Emissaoja\Tests;

use Emissaoja\EmissaojaException;

trait EsperaErro
{
    /**
     * Roda $f, exige uma EmissaojaException e confere as propriedades pedidas.
     *
     * @param array<string, mixed> $esperado propriedade => valor (status, erro, extras, requestId)
     */
    private function lanca(callable $f, array $esperado = []): EmissaojaException
    {
        try {
            $f();
        } catch (EmissaojaException $e) {
            foreach ($esperado as $propriedade => $valor) {
                self::assertSame($valor, $e->{$propriedade}, "EmissaojaException::\$$propriedade");
            }
            return $e;
        }
        self::fail('Esperava EmissaojaException');
    }
}
