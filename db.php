<?php
/**
 * Portal Dashboard
 *
 * @author    FACRF
 * @copyright 2026 FACRF
 * @link      https://github.com/facrf/portal_dashboard
 */
// db.php
require_once __DIR__ . '/helpers.php';

$configuredDbPath = getenv('PORTAL_DB_PATH');
$localDbFile = __DIR__ . '/db_data/bd.db';
$legacyDbFile = dirname(__DIR__) . '/db_data/bd.db';
if ($configuredDbPath !== false && trim($configuredDbPath) !== '') {
    $dbFile = trim($configuredDbPath);
} elseif (!is_file($localDbFile) && is_file($legacyDbFile)) {
    // Compatibilidade com instalações anteriores, que gravavam no diretório pai.
    $dbFile = $legacyDbFile;
} else {
    $dbFile = $localDbFile;
}
$dbDir = dirname($dbFile);

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
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA busy_timeout = 5000');
$pdo->exec('PRAGMA journal_mode = WAL');

function addColumnIfNotExists($pdo, $table, $column, $definition) {
    $stmt = $pdo->query("PRAGMA table_info($table)");
    $exists = false;
    while ($row = $stmt->fetch()) { if ($row['name'] === $column) $exists = true; }
    if (!$exists) { $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition"); }
}

// Schema completo para instalações novas. Instalações antigas são atualizadas uma
// única vez por versão, evitando PRAGMAs e ALTER TABLE em toda requisição.
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    bg_color TEXT DEFAULT '#1e1e2e', bg_image TEXT DEFAULT '', text_color TEXT DEFAULT '#cdd6f4',
    portal_name TEXT DEFAULT 'Meu Portal', favicon TEXT DEFAULT '', footer_text TEXT DEFAULT '',
    language TEXT DEFAULT 'pt', session_days INTEGER DEFAULT 7,
    brute_max_attempts INTEGER DEFAULT 5, brute_lockout_time INTEGER DEFAULT 900,
    show_clock INTEGER DEFAULT 1, show_greeting INTEGER DEFAULT 1,
    greeting_name TEXT DEFAULT 'Administrador'
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    sort_order INTEGER DEFAULT 0
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS tools (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL, url TEXT NOT NULL, icon_url TEXT DEFAULT '', description TEXT DEFAULT '',
    category_id INTEGER NOT NULL,
    sort_order INTEGER DEFAULT 0, tag_name TEXT DEFAULT '', tag_color TEXT DEFAULT '#007bff',
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (ip TEXT PRIMARY KEY, attempts INTEGER NOT NULL DEFAULT 0, last_attempt INTEGER NOT NULL)");
$pdo->exec("CREATE TABLE IF NOT EXISTS health_cache (
    tool_id INTEGER PRIMARY KEY,
    status INTEGER NOT NULL,
    checked_at INTEGER NOT NULL,
    FOREIGN KEY (tool_id) REFERENCES tools(id) ON DELETE CASCADE
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (version INTEGER PRIMARY KEY, applied_at INTEGER NOT NULL)");

$schemaVersion = (int) $pdo->query("SELECT COALESCE(MAX(version), 0) FROM schema_migrations")->fetchColumn();
if ($schemaVersion < 1) {
    $pdo->beginTransaction();
    try {
        addColumnIfNotExists($pdo, 'settings', 'portal_name', "TEXT DEFAULT 'Meu Portal'");
        addColumnIfNotExists($pdo, 'settings', 'favicon', "TEXT DEFAULT ''");
        addColumnIfNotExists($pdo, 'settings', 'footer_text', "TEXT DEFAULT ''");
        addColumnIfNotExists($pdo, 'settings', 'language', "TEXT DEFAULT 'pt'");
        addColumnIfNotExists($pdo, 'settings', 'session_days', "INTEGER DEFAULT 7");
        addColumnIfNotExists($pdo, 'settings', 'brute_max_attempts', "INTEGER DEFAULT 5");
        addColumnIfNotExists($pdo, 'settings', 'brute_lockout_time', "INTEGER DEFAULT 900");
        addColumnIfNotExists($pdo, 'settings', 'show_clock', "INTEGER DEFAULT 1");
        addColumnIfNotExists($pdo, 'settings', 'show_greeting', "INTEGER DEFAULT 1");
        addColumnIfNotExists($pdo, 'settings', 'greeting_name', "TEXT DEFAULT 'Administrador'");
        addColumnIfNotExists($pdo, 'tools', 'category_id', "INTEGER DEFAULT 1");
        addColumnIfNotExists($pdo, 'tools', 'sort_order', "INTEGER DEFAULT 0");
        addColumnIfNotExists($pdo, 'tools', 'tag_name', "TEXT DEFAULT ''");
        addColumnIfNotExists($pdo, 'tools', 'tag_color', "TEXT DEFAULT '#007bff'");
        addColumnIfNotExists($pdo, 'categories', 'sort_order', "INTEGER DEFAULT 0");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tools_category_sort ON tools(category_id, sort_order, name)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_categories_sort ON categories(sort_order, name)");
        $pdo->prepare("INSERT INTO schema_migrations (version, applied_at) VALUES (1, ?)")->execute([time()]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

if ($pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn() == 0) {
    $pdo->exec("INSERT INTO settings (bg_color, bg_image, text_color, portal_name) VALUES ('#1e1e2e', '', '#cdd6f4', 'Meu Portal')");
}
if ($pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn() == 0) {
    $pdo->exec("INSERT INTO categories (name) VALUES ('Geral (Sem Categoria)')");
    $firstCatId = $pdo->lastInsertId();
    $pdo->exec("UPDATE tools SET category_id = $firstCatId WHERE category_id = 0 OR category_id IS NULL");
}

$schemaVersion = (int) $pdo->query("SELECT COALESCE(MAX(version), 0) FROM schema_migrations")->fetchColumn();
if ($schemaVersion < 2) {
    $foreignKeys = $pdo->query("PRAGMA foreign_key_list(tools)")->fetchAll();
    if ($foreignKeys === []) {
        $fallbackCategoryId = (int) $pdo->query("SELECT id FROM categories ORDER BY id LIMIT 1")->fetchColumn();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE tools SET category_id = ? WHERE category_id IS NULL OR category_id NOT IN (SELECT id FROM categories)")
                ->execute([$fallbackCategoryId]);
            $pdo->exec("CREATE TABLE tools_v2 (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL, url TEXT NOT NULL, icon_url TEXT DEFAULT '', description TEXT DEFAULT '',
                category_id INTEGER NOT NULL,
                sort_order INTEGER DEFAULT 0, tag_name TEXT DEFAULT '', tag_color TEXT DEFAULT '#007bff',
                FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
            )");
            $pdo->exec("INSERT INTO tools_v2 (id, name, url, icon_url, description, category_id, sort_order, tag_name, tag_color)
                SELECT id, COALESCE(name, ''), COALESCE(url, ''), COALESCE(icon_url, ''), COALESCE(description, ''),
                       category_id, COALESCE(sort_order, 0), COALESCE(tag_name, ''), COALESCE(tag_color, '#007bff')
                FROM tools");
            $pdo->exec("DROP TABLE tools");
            $pdo->exec("ALTER TABLE tools_v2 RENAME TO tools");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tools_category_sort ON tools(category_id, sort_order, name)");
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->exec('PRAGMA foreign_keys = ON');
            throw $e;
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    $pdo->prepare("INSERT INTO schema_migrations (version, applied_at) VALUES (2, ?)")->execute([time()]);
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
// AUTENTICAÇÃO: PORTAL PÚBLICO, ALTERAÇÕES E ADMINISTRAÇÃO PROTEGIDAS
// ==========================================
$currentFile = basename($_SERVER['PHP_SELF']);
$isAuthenticated = false;
if (!empty($_SESSION['logged_in']) && (!empty($_SESSION['user_id']) || !empty($_SESSION['username']))) {
    if (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id = ?");
        $stmt->execute([(int) $_SESSION['user_id']]);
    } else {
        // Compatibilidade com sessões criadas antes da adoção do identificador estável.
        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE username = ?");
        $stmt->execute([$_SESSION['username']]);
    }
    $sessionUser = $stmt->fetch();
    $isAuthenticated = $sessionUser !== false;
    if ($isAuthenticated) {
        $_SESSION['user_id'] = (int) $sessionUser['id'];
        $_SESSION['username'] = $sessionUser['username'];
    }
    if (!$isAuthenticated) {
        unset($_SESSION['logged_in'], $_SESSION['user_id'], $_SESSION['username']);
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
        echo json_encode(['status' => 'error', 'msg' => 'Autenticação necessária.']);
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
        return htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); 
    }
    return htmlspecialchars('icons/' . $icon, ENT_QUOTES, 'UTF-8');
}
?>
