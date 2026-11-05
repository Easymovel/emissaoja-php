<?php

// Servidor falso para o teste do CurlTransporte: php -S 127.0.0.1:PORTA tests/servidor/roteador.php
// As rotas de eco devolvem, em JSON, o que chegou (método, URI, cabeçalhos e corpo); as outras simulam PDF, XML,
// erros, demora e redirecionamento.

declare(strict_types=1);

$metodo = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$caminho = (string) parse_url($uri, PHP_URL_PATH);

$cabecalhos = [];
foreach (getallheaders() as $nome => $valor) {
    $cabecalhos[strtolower((string) $nome)] = (string) $valor;
}
$corpo = (string) file_get_contents('php://input');

$json = static function (int $status, array $dados, array $extras = []): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    foreach ($extras as $nome => $valor) {
        header($nome . ': ' . $valor);
    }
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
$eco = ['eco' => ['metodo' => $metodo, 'uri' => $uri, 'cabecalhos' => $cabecalhos, 'corpo' => $corpo]];

if (str_starts_with($caminho, '/redireciona/')) {
    http_response_code(302);
    header('Location: https://emissaoja.com.br' . substr($caminho, \strlen('/redireciona')));
    return;
}

switch ($caminho) {
    case '/v1/status':
    case '/v1/empresa':
    case '/v1/empresa/fiscal':
    case '/v1/empresa/reler-fisco':
    case '/v1/emissoes/p1/conferir':
    case '/v1/notas':
        $json($metodo === 'GET' ? 200 : 202, $eco, ['X-Request-Id' => 'r_eco']);
        return;

    case '/v1/notas/local/n1/pdf':
        header('Content-Type: application/pdf');
        echo "%PDF-1.4\n" . implode('', array_map('chr', range(0, 255))) . "\n%%EOF\n";
        return;

    case '/v1/notas/local/n1/xml':
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<NFSe><infNFSe><xDescServ>Consultoria – revisão de código</xDescServ></infNFSe></NFSe>';
        return;

    case '/v1/notas/local/sumida/pdf':
        $json(404, ['status' => 'ERROR', 'code' => 'nota_nao_encontrada', 'message' => 'Nota não encontrada.'], ['X-Request-Id' => 'r_curl404']);
        return;

    case '/v1/empresa/onboarding':
        http_response_code(502);
        header('Content-Type: text/html');
        echo '<html><body><h1>502 Bad Gateway</h1></body></html>';
        return;

    case '/v1/emissoes/lento':
        usleep(1_500_000);
        $json(200, ['pedido' => ['id' => 'lento', 'estado' => 'emitindo']]);
        return;
}

// Tomadores e serviços: eco com a URI crua, para conferir os segmentos e a query codificados
if (str_starts_with($caminho, '/v1/tomadores') || str_starts_with($caminho, '/v1/servicos/')) {
    $json(200, $eco);
    return;
}

$json(404, ['status' => 'ERROR', 'code' => 'rota_desconhecida', 'message' => "Rota desconhecida no servidor de teste: $metodo $caminho"]);
