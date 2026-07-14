<?php
// ping.php
header('Content-Type: application/json');

if (!isset($_GET['url'])) {
    echo json_encode(['status' => 'error']);
    exit;
}

$url = filter_var($_GET['url'], FILTER_SANITIZE_URL);
if (!$url) {
    echo json_encode(['status' => 'error']);
    exit;
}

// Configuração otimizada para ser extremamente rápida
$context = stream_context_create([
    'http' => [
        'method' => 'HEAD', // Pega só o cabeçalho, não faz download da página
        'timeout' => 2,     // Desiste se demorar mais que 2 segundos
        'ignore_errors' => true
    ],
    'ssl' => [
        'verify_peer' => false, // Ignora erros de SSL de IPs locais (Homelab)
        'verify_peer_name' => false
    ]
]);

// Testa a URL ocultando warnings do PHP
$headers = @get_headers($url, 1, $context);

if ($headers !== false) {
    // Pega a resposta (Ex: "HTTP/1.1 200 OK" ou "HTTP/1.1 401 Unauthorized")
    $httpCodeStatus = $headers[0];
    preg_match('/HTTP\/\d(?:\.\d)?\s+(\d+)/', $httpCodeStatus, $matches);
    $code = isset($matches[1]) ? intval($matches[1]) : 0;

    // Se o serviço respondeu com qualquer código HTTP, ele está VIVO!
    if ($code > 0) {
        echo json_encode(['status' => 'ok']);
        exit;
    }
}

// Se não respondeu, deu timeout ou recusou conexão, está offline
echo json_encode(['status' => 'error']);
?>