<?php
// db.php
$dbDir = __DIR__ . '/database';
$dbFile = $dbDir . '/bd.db';

// ==========================================
// INTERCEPTADOR GLOBAL DE ERROS DO BANCO
// ==========================================
// Captura qualquer exceção do PDO em qualquer arquivo e exibe a mensagem amigável
set_exception_handler(function($e) {
    if ($e instanceof PDOException) {
        // Você pode descomentar a linha abaixo se quiser registrar o erro real nos logs do servidor (Apache/Nginx)
        // error_log("Erro BD Portal: " . $e->getMessage()); 
        
        die("
            <div style='background: rgba(220, 53, 69, 0.15); border: 1px solid rgba(220, 53, 69, 0.3); color: #ff4d4d; padding: 20px; text-align: center; font-family: system-ui, sans-serif; border-radius: 8px; max-width: 400px; margin: 40px auto; font-weight: 500;'>
                ⚠️ Problema com acesso ao banco
            </div>
        ");
    }
    throw $e; // Se for outro tipo de erro de PHP, segue normalmente
});

// Cria o diretório do banco caso não exista
if (!is_dir($dbDir)) { 
    mkdir($dbDir, 0775, true); 
}

// Conexão e Inicialização (Se falhar aqui, o Interceptador acima assume)
$pdo = new PDO("sqlite:" . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// 1. Cria as tabelas principais se for uma instalação limpa
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY AUTOINCREMENT, bg_color TEXT, bg_image TEXT, text_color TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS tools (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, url TEXT, icon_url TEXT, description TEXT)");

// Função helper para fazer a migração e adicionar novas colunas sem destruir dados
function addColumnIfNotExists($pdo, $table, $column, $definition) {
    $stmt = $pdo->query("PRAGMA table_info($table)");
    $exists = false;
    while ($row = $stmt->fetch()) { if ($row['name'] === $column) $exists = true; }
    if (!$exists) { $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition"); }
}

// 2. Aplica as migrações (adiciona colunas novas dinamicamente)
addColumnIfNotExists($pdo, 'settings', 'portal_name', "TEXT DEFAULT 'Meu Portal'");
addColumnIfNotExists($pdo, 'settings', 'favicon', "TEXT DEFAULT ''");
addColumnIfNotExists($pdo, 'settings', 'footer_text', "TEXT DEFAULT ''"); 
addColumnIfNotExists($pdo, 'settings', 'language', "TEXT DEFAULT 'pt'");

$pdo->exec("CREATE TABLE IF NOT EXISTS categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");
addColumnIfNotExists($pdo, 'tools', 'category_id', "INTEGER DEFAULT 1");

// 3. Insere configurações base na primeira vez que o painel rodar
if ($pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn() == 0) {
    $pdo->exec("INSERT INTO settings (bg_color, bg_image, text_color, portal_name) VALUES ('#1e1e2e', '', '#cdd6f4', 'Meu Portal')");
}

// 4. Cria a aba 'Geral' padrão e move os serviços sem aba para ela
if ($pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn() == 0) {
    $pdo->exec("INSERT INTO categories (name) VALUES ('Geral (Sem Categoria)')");
    $firstCatId = $pdo->lastInsertId();
    $pdo->exec("UPDATE tools SET category_id = $firstCatId WHERE category_id = 0 OR category_id IS NULL");
}

// ==========================================
// MOTOR MULTI-IDIOMAS (i18n)
// ==========================================
$langCode = 'pt'; // Idioma fallback (padrão)
try {
    $settingsLang = $pdo->query("SELECT language FROM settings LIMIT 1")->fetchColumn();
    if ($settingsLang) {
        $langCode = $settingsLang;
    }
} catch (PDOException $e) {
    // Evita travar a página se a coluna de idioma ainda não existir
}

$langFile = __DIR__ . "/lang/{$langCode}.php";
$langData = file_exists($langFile) ? include($langFile) : include(__DIR__ . "/lang/pt.php");

function t($key) {
    global $langData;
    return isset($langData[$key]) ? $langData[$key] : $key; 
}

// ==========================================
// RESOLUÇÃO DE ÍCONES (Local / Remoto)
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