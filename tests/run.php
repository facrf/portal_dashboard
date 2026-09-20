<?php
/**
 * Testes rápidos sem dependências externas.
 */

$configuredDbPath = getenv('PORTAL_DB_PATH');
$testDbDir = $configuredDbPath !== false && $configuredDbPath !== ''
    ? dirname($configuredDbPath)
    : dirname(__DIR__) . '/db_data';
if (!is_dir($testDbDir)) mkdir($testDbDir, 0775, true);
$testDb = $testDbDir . '/test-suite-' . getmypid() . '.db';
putenv('PORTAL_DB_PATH=' . $testDb);
register_shutdown_function(static function () use ($testDb): void {
    foreach ([$testDb, $testDb . '-wal', $testDb . '-shm'] as $file) {
        if (is_file($file)) unlink($file);
    }
});

$_SERVER['PHP_SELF'] = '/login.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// Simula o schema de uma versão antiga para exercitar as migrações reais.
$legacy = new PDO('sqlite:' . $testDb);
$legacy->exec("CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, bg_color TEXT, bg_image TEXT, text_color TEXT)");
$legacy->exec("CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");
$legacy->exec("CREATE TABLE tools (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, url TEXT, icon_url TEXT, description TEXT)");
$legacy->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password TEXT)");
$legacy->exec("CREATE TABLE login_attempts (ip TEXT PRIMARY KEY, attempts INTEGER, last_attempt INTEGER)");
$legacy->exec("INSERT INTO settings (bg_color, bg_image, text_color) VALUES ('#000000', '', '#ffffff')");
$legacy->exec("INSERT INTO categories (name) VALUES ('Legado')");
$legacy->exec("INSERT INTO tools (name, url, icon_url, description) VALUES ('Legado', 'https://legacy.example', '', '')");
$legacy = null;

require dirname(__DIR__) . '/db.php';

$failures = [];
function expect(bool $condition, string $message): void {
    global $failures;
    if (!$condition) $failures[] = $message;
}

expect((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1, 'Foreign keys devem estar habilitadas.');
expect((int) $pdo->query('PRAGMA busy_timeout')->fetchColumn() === 5000, 'Busy timeout deve ser 5 segundos.');
expect((int) $pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn() >= 1, 'Migração inicial não registrada.');

$pdo->exec("INSERT INTO categories (name, sort_order) VALUES ('Teste', 0)");
$categoryId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO tools (name, url, category_id, tag_name, tag_color) VALUES (?, ?, ?, ?, ?)')
    ->execute(['Serviço', 'https://example.test', $categoryId, 'TEST', '#112233']);
$tool = $pdo->query("SELECT name, url, tag_name, tag_color FROM tools WHERE name = 'Serviço'")->fetch();
expect($tool['tag_name'] === 'TEST' && $tool['tag_color'] === '#112233', 'Campos de tag não foram persistidos.');
expect((int) $pdo->query("SELECT COUNT(*) FROM tools WHERE name = 'Legado'")->fetchColumn() === 1, 'A migração descartou um serviço legado.');

$foreignKeyRejected = false;
try {
    $pdo->prepare('INSERT INTO tools (name, url, category_id) VALUES (?, ?, ?)')
        ->execute(['Órfão', 'https://example.test', 999999]);
} catch (PDOException $e) {
    $foreignKeyRejected = true;
}
expect($foreignKeyRejected, 'O banco aceitou um serviço sem categoria.');

foreach ([['', true], ['curta', true], [str_repeat('x', 73), true]] as [$password, $required]) {
    $rejected = false;
    try {
        validatePassword($password, $required);
    } catch (InvalidArgumentException $e) {
        $rejected = true;
    }
    expect($rejected, 'Senha insegura não foi rejeitada.');
}
expect(validatePassword('senha-segura-123', true) === 'senha-segura-123', 'Senha válida foi rejeitada.');

$unsafeUrlRejected = false;
try {
    validatedToolUrl('javascript:alert(1)');
} catch (InvalidArgumentException $e) {
    $unsafeUrlRejected = true;
}
expect($unsafeUrlRejected, 'URL com protocolo perigoso não foi rejeitada.');

$referenceKeys = array_keys(include dirname(__DIR__) . '/lang/pt.php');
foreach (['en', 'es'] as $language) {
    $keys = array_keys(include dirname(__DIR__) . "/lang/{$language}.php");
    sort($keys);
    $expected = $referenceKeys;
    sort($expected);
    expect($keys === $expected, "As chaves de tradução de {$language} estão incompletas.");
}

foreach (['index.php', 'login.php', 'admin.php', 'config.php'] as $template) {
    $content = file_get_contents(dirname(__DIR__) . '/' . $template);
    expect(str_contains($content, '<!-- Developed with care by FACRF - https://github.com/facrf -->'), "Assinatura ausente em {$template}.");
}

foreach (glob(dirname(__DIR__) . '/*.php') as $phpFile) {
    $output = [];
    $exitCode = 0;
    exec(PHP_BINARY . ' -l ' . escapeshellarg($phpFile), $output, $exitCode);
    expect($exitCode === 0, 'Erro de sintaxe em ' . basename($phpFile));
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Todos os testes passaram." . PHP_EOL;
