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
        http_response_code(500);
        error_log("Portal database error: " . $e->getMessage());
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

function addColumnIfNotExists($pdo, $table, $column, $definition) {
    $stmt = $pdo->query("PRAGMA table_info($table)");
    $exists = false;
    while ($row = $stmt->fetch()) { if ($row['name'] === $column) $exists = true; }
    if (!$exists) { $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition"); }
}

// Instalação/migração é serializada entre processos que compartilham o banco.
$hasMigrations = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='schema_migrations'")->fetchColumn();
$needsMigration = $pdo->query('PRAGMA journal_mode')->fetchColumn() !== 'wal' || !$hasMigrations || (int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn() < 3;
if ($needsMigration) {
    $migrationLock = fopen($dbFile . '.migration.lock', 'c');
    if ($migrationLock === false || !flock($migrationLock, LOCK_EX)) throw new RuntimeException('Não foi possível reservar a migração.');
    try {
        $pdo->exec('PRAGMA journal_mode = WAL');
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


        // Migração de sessões e monitoramento configurável.
        if ((int) $pdo->query("SELECT COALESCE(MAX(version), 0) FROM schema_migrations")->fetchColumn() < 3) {
            $pdo->beginTransaction();
            try {
                addColumnIfNotExists($pdo, 'users', 'session_version', 'INTEGER NOT NULL DEFAULT 1');
                addColumnIfNotExists($pdo, 'tools', 'health_method', "TEXT NOT NULL DEFAULT 'auto'");
                addColumnIfNotExists($pdo, 'tools', 'health_url', "TEXT NOT NULL DEFAULT ''");
                addColumnIfNotExists($pdo, 'tools', 'health_codes', "TEXT NOT NULL DEFAULT '200-399'");
                addColumnIfNotExists($pdo, 'health_cache', 'lease_until', 'INTEGER NOT NULL DEFAULT 0');
                $pdo->prepare("INSERT INTO schema_migrations (version, applied_at) VALUES (3, ?)")->execute([time()]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
    } finally {
        flock($migrationLock, LOCK_UN);
        fclose($migrationLock);
    }
}
