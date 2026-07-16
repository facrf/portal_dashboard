<?php
// ping.php
header('Content-Type: application/json');

/**
 * Envia uma requisição UDP real para validar se o Servidor NTP está online.
 * Envia um pacote de 48 bytes (padrão do protocolo NTP) e aguarda resposta.
 */
function testNtpServer($host, $port = 123) {
    // Abre um socket UDP nativo do PHP
    $fp = @fsockopen("udp://$host", $port, $errno, $errstr, 1.5);
    if (!$fp) {
        return false;
    }

    // Define timeout de leitura/escrita rápido (1.5 segundos)
    stream_set_timeout($fp, 1, 500000);

    // Pacote de requisição NTP padrão de 48 bytes (LI = 0, VN = 3, Mode = 3)
    $packet = "\x1b" . str_repeat("\0", 47);

    // Envia o pacote UDP
    $write = @fwrite($fp, $packet);
    if ($write === false) {
        fclose($fp);
        return false;
    }

    // Aguarda e lê a resposta (servidores NTP ativos retornam 48 bytes)
    $response = @fread($fp, 48);
    fclose($fp);

    return (!empty($response) && strlen($response) >= 48);
}

// 1. Método de teste para portas (Bancos de dados, NTP, etc.)
if (isset($_GET['host']) && isset($_GET['port'])) {
    $host = filter_var($_GET['host'], FILTER_SANITIZE_URL);
    $port = intval($_GET['port']);

    // Limpa possíveis protocolos inseridos no host
    $host = preg_replace('~^https?://~i', '', $host);
    $host = preg_replace('~^udp://~i', '', $host);

    if (!empty($host) && $port > 0) {
        // Se a porta consultada for a 123, aplica o teste UDP para NTP
        if ($port === 123) {
            if (testNtpServer($host, $port)) {
                echo json_encode(['status' => 'ok']);
                exit;
            }
        } else {
            // Teste padrão TCP para portas de Bancos de Dados (Postgres, MariaDB, etc.)
            $connection = @fsockopen($host, $port, $errno, $errstr, 1.5);
            if (is_resource($connection)) {
                fclose($connection);
                echo json_encode(['status' => 'ok']);
                exit;
            }
        }
    }
    echo json_encode(['status' => 'error']);
    exit;
}

// 2. Método padrão: teste HTTP HEAD (Para sites e web apps normais)
if (isset($_GET['url'])) {
    $url = filter_var($_GET['url'], FILTER_SANITIZE_URL);
    if ($url) {
        $context = stream_context_create([
            'http' => [
                'method' => 'HEAD',
                'timeout' => 2,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);

        $headers = @get_headers($url, 1, $context);

        if ($headers !== false) {
            preg_match('/HTTP\/\d(?:\.\d)?\s+(\d+)/', $headers[0], $matches);
            $code = isset($matches[1]) ? intval($matches[1]) : 0;

            if ($code > 0) {
                echo json_encode(['status' => 'ok']);
                exit;
            }
        }
    }
}

echo json_encode(['status' => 'error']);
?>