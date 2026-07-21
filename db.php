<?php
// db.php

$dbDir = dirname(__DIR__) . '/db_data';
$dbFile = $dbDir . '/bd.db';

set_exception_handler(function($e) {
    if ($e instanceof PDOException) {
        die("<div style='background: rgba(220,53,69,0.15); border: 1px solid rgba(220,53,69,0.3); color: #ff4d4d; padding: 20px; text-align: center; font-family: system-ui; border-radius: 8px; max-width: 400px; margin: 40px auto;'>⚠️ Problema com acesso ao banco</div>");
    }
    throw $e;
});

if (!is_dir($dbDir)) { mkdir($dbDir, 0775, true); }

$pdo = new PDO("sqlite:" . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function addColumnIfNotExists($pdo, $table, $column, $definition) {
    $stmt = $pdo->query("PRAGMA table_info($table)");
    $exists = false;
    while ($row = $stmt->fetch()) { if ($row['name'] === $column) $exists = true; }
    if (!$exists) { $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition"); }
}

// Tabelas base
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY AUTOINCREMENT, bg_color TEXT, bg_image TEXT, text_color TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS tools (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, url TEXT, icon_url TEXT, description TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");

// Tabela de Autenticação
$pdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password TEXT)");

// TABELA ANTI BRUTE-FORCE
$pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (ip TEXT PRIMARY KEY, attempts INTEGER, last_attempt INTEGER)");

// Migrações e Defaults
addColumnIfNotExists($pdo, 'settings', 'portal_name', "TEXT DEFAULT 'Meu Portal'");
addColumnIfNotExists($pdo, 'settings', 'favicon', "TEXT DEFAULT ''");
addColumnIfNotExists($pdo, 'settings', 'footer_text', "TEXT DEFAULT ''"); 
addColumnIfNotExists($pdo, 'settings', 'language', "TEXT DEFAULT 'pt'");
addColumnIfNotExists($pdo, 'settings', 'session_days', "INTEGER DEFAULT 7"); // Padrão: 7 dias
addColumnIfNotExists($pdo, 'tools', 'category_id', "INTEGER DEFAULT 1");

if ($pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn() == 0) {
    $pdo->exec("INSERT INTO settings (bg_color, bg_image, text_color, portal_name) VALUES ('#1e1e2e', '', '#cdd6f4', 'Meu Portal')");
}
if ($pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn() == 0) {
    $pdo->exec("INSERT INTO categories (name) VALUES ('Geral (Sem Categoria)')");
    $firstCatId = $pdo->lastInsertId();
    $pdo->exec("UPDATE tools SET category_id = $firstCatId WHERE category_id = 0 OR category_id IS NULL");
}

// ==========================================
// SEGURANÇA: SESSÃO DINÂMICA E COOKIES HTTPS
// ==========================================
$sessionDays = (int) $pdo->query("SELECT session_days FROM settings LIMIT 1")->fetchColumn();
$sessionDays = min(365, max(1, $sessionDays ?: 7));
$lifetime = $sessionDays * 86400;

function isLocalOrPrivateIp($ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $value = ip2long($ip);
        return (($value & 0xff000000) === 0x0a000000)       // 10.0.0.0/8
            || (($value & 0xfff00000) === 0xac100000)      // 172.16.0.0/12
            || (($value & 0xffff0000) === 0xc0a80000)      // 192.168.0.0/16
            || (($value & 0xff000000) === 0x7f000000)      // loopback
            || (($value & 0xffff0000) === 0xa9fe0000);     // link-local
    }

    $packed = inet_pton($ip);
    if ($packed === false) return false;
    if ($packed === inet_pton('::1')) return true;

    $first = ord($packed[0]);
    $second = ord($packed[1]);
    return (($first & 0xfe) === 0xfc)                      // fc00::/7
        || ($first === 0xfe && ($second & 0xc0) === 0x80); // fe80::/10
}

// Compara um IP com um endereço ou bloco CIDR (IPv4 e IPv6).
function ipMatchesRange($ip, $range) {
    $ip = trim($ip);
    $range = trim($range);
    if (!filter_var($ip, FILTER_VALIDATE_IP) || $range === '') return false;

    if (strpos($range, '/') === false) {
        $ipPacked = inet_pton($ip);
        $rangePacked = inet_pton($range);
        return $ipPacked !== false && $rangePacked !== false && hash_equals($rangePacked, $ipPacked);
    }

    [$network, $prefix] = array_pad(explode('/', $range, 2), 2, null);
    if (!filter_var($network, FILTER_VALIDATE_IP) || !ctype_digit((string) $prefix)) return false;

    $ipPacked = inet_pton($ip);
    $networkPacked = inet_pton($network);
    if ($ipPacked === false || $networkPacked === false || strlen($ipPacked) !== strlen($networkPacked)) return false;

    $prefix = (int) $prefix;
    $maxBits = strlen($ipPacked) * 8;
    if ($prefix < 0 || $prefix > $maxBits) return false;

    $wholeBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;
    if ($wholeBytes > 0 && substr($ipPacked, 0, $wholeBytes) !== substr($networkPacked, 0, $wholeBytes)) return false;
    if ($remainingBits === 0) return true;

    $mask = (0xff << (8 - $remainingBits)) & 0xff;
    return (ord($ipPacked[$wholeBytes]) & $mask) === (ord($networkPacked[$wholeBytes]) & $mask);
}

// Somente peers configurados explicitamente podem fornecer cabeçalhos encaminhados.
// Exemplo: PORTAL_TRUSTED_PROXIES=172.20.0.10,10.10.0.0/24
function isTrustedProxyIp($ip) {
    static $trustedRanges = null;
    if ($trustedRanges === null) {
        $configured = getenv('PORTAL_TRUSTED_PROXIES');
        $trustedRanges = $configured === false || trim($configured) === ''
            ? []
            : preg_split('/[\\s,]+/', trim($configured), -1, PREG_SPLIT_NO_EMPTY);
    }

    foreach ($trustedRanges as $range) {
        if (ipMatchesRange($ip, $range)) return true;
    }
    return false;
}

function hasUntrustedProxyHeaders() {
    $peerIp = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
    if (!$peerIp || isTrustedProxyIp($peerIp)) return false;

    return !empty($_SERVER['HTTP_X_FORWARDED_FOR'])
        || !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
        || !empty($_SERVER['HTTP_CF_CONNECTING_IP'])
        || !empty($_SERVER['HTTP_CF_VISITOR']);
}

function getClientIp() {
    $peerIp = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
    if (!$peerIp) return '0.0.0.0';

    if (!isTrustedProxyIp($peerIp) || empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return $peerIp;

    $forwardedIps = [];
    foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $candidate) {
        $candidate = trim($candidate);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) $forwardedIps[] = $candidate;
    }

    // O proxy confiável deve sobrescrever ou anexar o IP real. Lendo da direita
    // para a esquerda, valores forjados à esquerda não substituem o cliente real.
    for ($i = count($forwardedIps) - 1; $i >= 0; $i--) {
        if (!isTrustedProxyIp($forwardedIps[$i])) return $forwardedIps[$i];
    }

    return $forwardedIps[0] ?? $peerIp;
}

// O protocolo encaminhado segue a mesma lista explícita de confiança.
$isSecure = isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) === 'on';
if (!$isSecure && isTrustedProxyIp($_SERVER['REMOTE_ADDR'] ?? '')) {
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0])) === 'https') {
        $isSecure = true;
    }
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', $lifetime);
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => $isSecure,   // Agora funciona perfeitamente atrás do túnel
        'httponly' => true,      
        'samesite' => 'Strict'   
    ]);
    session_start();
}

// Gera um token CSRF por sessão antes de qualquer formulário ou validação.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ==========================================
// GATE DE AUTENTICAÇÃO (PROTEGE TODAS AS PÁGINAS)
// ==========================================
$currentFile = basename($_SERVER['PHP_SELF']);
$isPing = (isset($_GET['action']) && $_GET['action'] === 'ping');

if ($currentFile !== 'login.php') {
    // 1. Quantos usuários temos no banco?
    $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    
    // Se não há usuários, BLOQUEIA TUDO e obriga a ir para o setup
    if ($userCount == 0) {
        header("Location: login.php");
        exit;
    }

    // 2. Se chegou aqui, existe usuário. Exige validação rigorosa.
    $unauthorized = true; // Assumimos bloqueio por padrão
    
    if (!empty($_SESSION['logged_in']) && !empty($_SESSION['username'])) {
        // A sessão existe, mas o usuário AINDA ESTÁ no banco?
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->execute([$_SESSION['username']]);
        
        if ($stmt->fetchColumn() > 0) {
            $unauthorized = false; // Tudo certo, passe livre!
        } else {
            // Fantasma detectado!
            session_destroy();
            setcookie(session_name(), '', time() - 3600, '/');
        }
    }

    // 3. Executa o bloqueio se falhou na verificação
    if ($unauthorized) {
        if ($isPing) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
            exit;
        }
        header("Location: login.php");
        exit;
    }
}

// ==========================================
// HEADERS DE SEGURANÇA (HARDENING ADMINISTRATIVO)
// ==========================================
$adminPages = ['admin.php', 'config.php', 'login.php'];

if (in_array($currentFile, $adminPages)) {
    // 1. CSP & X-Frame-Options: Bloqueia Clickjacking (impede que o painel seja embutido em iframes ocultos)
    header("Content-Security-Policy: frame-ancestors 'none';");
    header("X-Frame-Options: DENY"); // Fallback para navegadores legados
    
    // 2. X-Content-Type-Options: Impede MIME-Sniffing (força o navegador a respeitar os Content-Types declarados)
    header("X-Content-Type-Options: nosniff");
    
    // 3. Cache-Control: Impede que as páginas administrativas fiquem no histórico offline do navegador
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: 0");
}

// ==========================================
// MOTOR MULTI-IDIOMAS (i18n)
// ==========================================
$langCode = 'pt';
$allowedLangs = ['pt', 'es', 'en'];
try {
    $settingsLang = $pdo->query("SELECT language FROM settings LIMIT 1")->fetchColumn();
    if ($settingsLang && in_array($settingsLang, $allowedLangs)) { $langCode = $settingsLang; }
} catch (PDOException $e) {}

$langCode = basename($langCode);
$langFile = __DIR__ . "/lang/{$langCode}.php";
$langData = file_exists($langFile) ? include($langFile) : include(__DIR__ . "/lang/pt.php");

function t($key) {
    global $langData;
    return isset($langData[$key]) ? $langData[$key] : $key; 
}

// ==========================================
// RESOLUÇÃO DE ÍCONES
// ==========================================
function resolveIconUrl($icon) {
    $icon = trim($icon);
    if (empty($icon)) return '';
    if (preg_match('~^(https?://|data:|/|\\.\\./|\\./)~i', $icon) || strpos($icon, '/') !== false) { 
        return htmlspecialchars($icon); 
    }
    return htmlspecialchars('icons/' . $icon);
}
?>