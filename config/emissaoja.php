<?php

// Configuração do emissaoja no Laravel. Para editar, publique este arquivo em config/emissaoja.php:
//
//   php artisan vendor:publish --tag=emissaoja-config
//
// e defina no .env (que nunca vai para o Git):
//
//   EMISSAOJA_KEY=ej_live_...
//   EMISSAOJA_BASE_URL=https://sandbox.emissaoja.com.br   (só para testar no sandbox, com uma chave ej_test_...)

return [

    // Chave de API, criada no app em Conta → API. É secreta: fica só no servidor.
    'key' => env('EMISSAOJA_KEY'),

    // Produção: https://emissaoja.com.br. Sandbox (grátis, nada vai ao fisco): https://sandbox.emissaoja.com.br
    'base_url' => env('EMISSAOJA_BASE_URL', 'https://emissaoja.com.br'),

    // Tempo máximo de cada chamada à API, em segundos.
    'timeout' => env('EMISSAOJA_TIMEOUT', 30),

];
