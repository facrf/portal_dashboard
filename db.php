<?php
// db.php
$dbDir = __DIR__ . '/database';
$dbFile = $dbDir . '/bd.db';

if (!is_dir($dbDir)) { mkdir($dbDir, 0775, true); }

try {
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY AUTOINCREMENT, bg_color TEXT, bg_image TEXT, text_color TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tools (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, url TEXT, icon_url TEXT, description TEXT)");
    
    function addColumnIfNotExists($pdo, $table, $column, $definition) {
        $stmt = $pdo->query("PRAGMA table_info($table)");
        $exists = false;
        while ($row = $stmt->fetch()) { if ($row['name'] === $column) $exists = true; }
        if (!$exists) { $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition"); }
    }

    addColumnIfNotExists($pdo, 'settings', 'portal_name', "TEXT DEFAULT 'Meu Portal'");
    addColumnIfNotExists($pdo, 'settings', 'favicon', "TEXT DEFAULT ''");
    // NOVA COLUNA PARA O BLOCO DE NOTAS DO RODAPÉ
    addColumnIfNotExists($pdo, 'settings', 'footer_text', "TEXT DEFAULT ''"); 

    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");
    addColumnIfNotExists($pdo, 'tools', 'category_id', "INTEGER DEFAULT 1");

    if ($pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO settings (bg_color, bg_image, text_color, portal_name) VALUES ('#1e1e2e', '', '#cdd6f4', 'Meu Portal')");
    }

    if ($pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO categories (name) VALUES ('Geral (Sem Categoria)')");
        $firstCatId = $pdo->lastInsertId();
        $pdo->exec("UPDATE tools SET category_id = $firstCatId WHERE category_id = 0 OR category_id IS NULL");
    }

} catch (PDOException $e) {
    die("Erro na conexão com o banco de dados: " . $e->getMessage());
}

function resolveIconUrl($icon) {
    $icon = trim($icon);
    if (empty($icon)) return '';

    // 1. Se for uma URL externa (http/https) ou imagem em Base64, retorna direto
    if (preg_match('~^(https?://|data:)~i', $icon)) {
        return htmlspecialchars($icon);
    }

    // 2. Remove barras no início e limpa o prefixo antigo "projetos/portal/" caso ele exista no banco
    $iconClean = ltrim($icon, '/\\');
    if (strpos($iconClean, 'projetos/portal/') === 0) {
        $iconClean = substr($iconClean, strlen('projetos/portal/'));
    }

    // 3. Se for apenas o nome da imagem (ex: "gitea.png" sem barras), adiciona o "icons/" na frente
    if (strpos($iconClean, '/') === false) {
        $iconClean = 'icons/' . $iconClean;
    }

    // 4. Retorna o caminho estritamente relativo (ex: "icons/gitea.png")
    return htmlspecialchars($iconClean);
}
?>