<?php
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
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

// Gera um token CSRF por sessão antes de qualquer formulário ou validação.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ==========================================
// AUTENTICAÇÃO: PORTAL PÚBLICO, ALTERAÇÕES E ADMINISTRAÇÃO PROTEGIDAS
// ==========================================
$currentFile = basename($_SERVER['PHP_SELF']);
$isAuthenticated = false;
if (!empty($_SESSION['logged_in']) && (!empty($_SESSION['user_id']) || !empty($_SESSION['username']))) {
    if (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT id, username, session_version FROM users WHERE id = ?");
        $stmt->execute([(int) $_SESSION['user_id']]);
    } else {
        // Compatibilidade com sessões criadas antes da adoção do identificador estável.
        $stmt = $pdo->prepare("SELECT id, username, session_version FROM users WHERE username = ?");
        $stmt->execute([$_SESSION['username']]);
    }
    $sessionUser = $stmt->fetch();
    $isAuthenticated = $sessionUser !== false && sessionIsValid($_SESSION, $sessionUser, $lifetime, time());
    if ($isAuthenticated) {
        $_SESSION['user_id'] = (int) $sessionUser['id'];
        $_SESSION['username'] = $sessionUser['username'];
    }
    if (!$isAuthenticated) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

$isPublicPortal = $currentFile === 'index.php'
    && in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true);
if ($currentFile !== 'login.php' && !$isPublicPortal && !$isAuthenticated) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'msg' => t('auth_required')]);
        exit;
    }
    $destination = in_array($currentFile, ['admin.php', 'config.php'], true) ? $currentFile : 'config.php';
    header('Location: login.php?next=' . urlencode($destination));
    exit;
}

// ==========================================
// HEADERS DE SEGURANÇA
// ==========================================
$adminPages = ['admin.php', 'config.php', 'login.php'];

header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: http: https:; font-src 'self' data:; connect-src 'self'");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: no-referrer");
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
if ($isSecure) {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
}

if (in_array($currentFile, $adminPages, true)) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: 0");
}


function startAuthenticatedSession(array $user): void {
    session_regenerate_id(true);
    $_SESSION = [
        'logged_in' => true,
        'user_id' => (int) $user['id'],
        'username' => $user['username'],
        'session_version' => (int) $user['session_version'],
        'authenticated_at' => time(),
        'csrf_token' => bin2hex(random_bytes(32))
    ];
}
