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
$sessionDays = $pdo->query("SELECT session_days FROM settings LIMIT 1")->fetchColumn();
if (!$sessionDays) $sessionDays = 7;
$lifetime = $sessionDays * 86400; 

// Detecção avançada de HTTPS (Cobre Proxy Reverso e Cloudflare Tunnels)
$isSecure = false;
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    $isSecure = true;
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $isSecure = true; // Detecta proxy padrão
} elseif (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false) {
    $isSecure = true; // Detecta túnel Cloudflare especificamente
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
$adminPages = ['admin.php', 'config.php'];

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