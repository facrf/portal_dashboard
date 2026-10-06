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
    foreach ([$testDb, $testDb . '-wal', $testDb . '-shm', $testDb . '.migration.lock'] as $file) {
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
require dirname(__DIR__) . '/imports.php';

$failures = [];
function expect(bool $condition, string $message): void {
    global $failures;
    if (!$condition) $failures[] = $message;
}

expect((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1, 'Foreign keys devem estar habilitadas.');
expect((int) $pdo->query('PRAGMA busy_timeout')->fetchColumn() === 5000, 'Busy timeout deve ser 5 segundos.');
expect((int) $pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn() === 3, 'Migração de sessão e monitoramento não registrada.');

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

foreach (array_merge(glob(dirname(__DIR__) . '/*.php'), glob(__DIR__ . '/*.php'), glob(dirname(__DIR__) . '/templates/*.php'), glob(dirname(__DIR__) . '/lang/*.php')) as $phpFile) {
    $output = [];
    $exitCode = 0;
    exec(PHP_BINARY . ' -l ' . escapeshellarg($phpFile), $output, $exitCode);
    expect($exitCode === 0, 'Erro de sintaxe em ' . basename($phpFile));
}

// Seis processos concorrentes devem instalar um único schema completo.
$concurrentDb = $testDb . '.concurrent.db';
register_shutdown_function(static function () use ($concurrentDb): void {
    foreach ([$concurrentDb, $concurrentDb . '-wal', $concurrentDb . '-shm', $concurrentDb . '.migration.lock'] as $file) {
        if (is_file($file)) unlink($file);
    }
});
$workers = [];
for ($i = 0; $i < 6; $i++) {
    $worker = proc_open([PHP_BINARY, __DIR__ . '/migration-worker.php', $concurrentDb], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $workerPipes);
    $workers[] = [$worker, $workerPipes];
}
foreach ($workers as [$worker, $workerPipes]) {
    fclose($workerPipes[0]);
    $output = stream_get_contents($workerPipes[1]);
    $errorOutput = stream_get_contents($workerPipes[2]);
    fclose($workerPipes[1]); fclose($workerPipes[2]);
    expect(proc_close($worker) === 0 && trim($output) === '3' && $errorOutput === '', 'Instalação concorrente falhou: ' . $errorOutput);
}

// Expiração explícita e revogação após mudança da senha.
$user = ['session_version' => 2];
$validSession = ['authenticated_at' => 1000, 'session_version' => 2];
expect(sessionIsValid($validSession, $user, 100, 1099), 'Sessão válida foi rejeitada.');
expect(!sessionIsValid($validSession, $user, 100, 1100), 'Sessão expirada foi aceita.');
expect(!sessionIsValid($validSession, ['session_version' => 3], 100, 1050), 'Sessão com versão revogada foi aceita.');
expect(!sessionIsValid(['session_version' => 2], $user, 100, 1050), 'Sessão antiga sem data de login foi aceita.');

// Protocolos HTTP em portas não padrão continuam sendo HTTP.
$healthTool = ['url' => 'https://example.test:8443', 'health_url' => '', 'health_method' => 'auto', 'health_codes' => '200-399'];
expect(healthTarget($healthTool)['method'] === 'http', 'HTTPS em porta 8443 virou TCP.');
$healthTool['url'] = 'tcp://127.0.0.1:123';
expect(healthTarget($healthTool)['method'] === 'tcp', 'TCP em porta 123 virou NTP.');
$healthTool['url'] = 'udp://127.0.0.1:123';
expect(healthTarget($healthTool)['method'] === 'ntp', 'NTP não reconhecido.');
expect(acceptsHttpCode(302, '200-399') && !acceptsHttpCode(500, '200-399'), 'Códigos HTTP padrão incorretos.');
expect(acceptsHttpCode(401, '200-299,401,403'), 'Código HTTP explícito não aceito.');
expect(healthTarget(['url' => 'https://example.test', 'health_url' => 'http://127.0.0.1:8080/health', 'health_method' => 'http'])['port'] === 8080, 'Destino separado de monitoramento ignorado.');

// YAML real: comentários, dois pontos e texto multilinha.
$yaml = <<<'YAML'
- "Grupo: casa":
    - "Servidor: mídia":
        href: "https://example.test:8443/" # comentário
        description: >-
          Primeira linha
          segunda linha
YAML;
$plan = buildImportPlan($yaml, 'yaml');
expect(count($plan['tools']) === 1 && $plan['tools'][0]['description'] === 'Primeira linha segunda linha', 'YAML multilinha interpretado incorretamente.');
expect($plan['tools'][0]['url'] === 'https://example.test:8443/', 'Comentário YAML foi incorporado à URL.');
foreach (["- a: &a [*a]", "---\n- a: []\n---\n- b: []", "- a: !php/object x", "- a: []"] as $badYaml) {
    $rejected = false;
    try { buildImportPlan($badYaml, 'yaml'); } catch (InvalidArgumentException $e) { $rejected = true; }
    expect($rejected, 'YAML inseguro ou sem serviços foi aceito.');
}
$heimdall = buildImportPlan(json_encode(['apps' => [
    ['title' => 'Válido', 'url' => 'https://example.test'],
    ['title' => 'Sem destino'],
    ['title' => 'Inválido', 'url' => 'javascript:alert(1)']
]]), 'json');
expect(count($heimdall['tools']) === 1 && $heimdall['ignored'] === 1 && count($heimdall['errors']) === 1, 'Prévia externa não separou itens válidos, ignorados e inválidos.');
$nativeData = ['format' => 'meu_portal_v1', 'categories' => [['id' => 10, 'name' => 'Restaurada']], 'tools' => [
    ['name' => 'Novo', 'url' => 'https://example.test', 'category_id' => 10, 'tag_name' => 'PROD', 'tag_color' => '#112233', 'health_method' => 'http', 'health_url' => 'https://example.test/health', 'health_codes' => '200,401']
]];
$native = buildImportPlan(json_encode($nativeData), 'json');
$before = importFingerprint($pdo);
$native['fingerprint'] = $before;
$pdo->exec("UPDATE categories SET name = 'Alterada' WHERE id = 1");
$changed = false;
try { applyImportPlan($pdo, $native); } catch (InvalidArgumentException $e) { $changed = true; }
expect($changed && (int) $pdo->query("SELECT COUNT(*) FROM tools WHERE name='Legado'")->fetchColumn() === 1, 'Uma prévia desatualizada substituiu o banco.');
$native['fingerprint'] = importFingerprint($pdo);
applyImportPlan($pdo, $native);
$restored = $pdo->query('SELECT * FROM tools')->fetch();
expect($restored['health_url'] === 'https://example.test/health' && $restored['health_codes'] === '200,401' && $restored['tag_name'] === 'PROD', 'Restauração perdeu configurações de monitoramento ou tags.');
$nativeData['tools'][0]['category_id'] = 99;
$rejected = false;
try { buildImportPlan(json_encode($nativeData), 'json'); } catch (InvalidArgumentException $e) { $rejected = true; }
expect($rejected, 'Backup com serviço órfão foi aceito.');

// Ordenação inválida é rejeitada integralmente antes de atualizar o banco.
$beforeOrder = $pdo->query('SELECT sort_order FROM tools LIMIT 1')->fetchColumn();
$rejected = false;
try { saveSortOrder($pdo, 'tools', ['orders' => json_encode([['id' => $restored['id'], 'order' => 10], ['id' => 0, 'order' => 1]])]); }
catch (InvalidArgumentException $e) { $rejected = true; }
expect($rejected && $pdo->query('SELECT sort_order FROM tools LIMIT 1')->fetchColumn() === $beforeOrder, 'Ordenação inválida foi aplicada parcialmente.');

// Uma reserva vigente impede outra checagem, inclusive sem cache prévio.
$pdo->prepare('INSERT INTO health_cache (tool_id, status, checked_at, lease_until) VALUES (?, 0, 0, ?)')->execute([$restored['id'], time() + 8]);
expect(cachedHealth($pdo, $restored)['status'] === 'unknown', 'Reserva de monitoramento ignorada.');
$pdo->prepare('UPDATE health_cache SET checked_at=?, status=1 WHERE tool_id=?')->execute([time(), $restored['id']]);
expect(cachedHealth($pdo, $restored)['status'] === 'ok', 'Cache válido ignorado.');

// Verificação HTTP real, isolada e sem depender de serviços externos.
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$address = stream_socket_get_name($socket, false);
fclose($socket);
$fixture = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/http-fixture.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__));
if (!is_resource($fixture)) throw new RuntimeException('Não foi possível iniciar o servidor de teste.');
try {
    for ($i = 0; $i < 50; $i++) {
        $ready = @stream_socket_client('tcp://' . $address, $errno, $errstr, .1);
        if ($ready) { fclose($ready); break; }
        usleep(20000);
    }
    $local = ['url' => 'http://' . $address . '/?code=200', 'health_url' => '', 'health_method' => 'auto', 'health_codes' => '200-399'];
    expect(checkHealth($local), 'HTTP 200 em porta não padrão foi rejeitado.');
    $local['url'] = 'http://' . $address . '/?code=500';
    expect(!checkHealth($local), 'HTTP 500 foi marcado online.');
    $local['url'] = 'http://' . $address . '/?code=401';
    expect(!checkHealth($local), 'HTTP 401 deveria exigir configuração explícita.');
    $local['health_codes'] = '200-399,401';
    expect(checkHealth($local), 'HTTP 401 explicitamente permitido foi rejeitado.');
    $pdo->prepare('DELETE FROM health_cache WHERE tool_id=?')->execute([$restored['id']]);
    $staleTool = $restored;
    $staleTool['health_url'] = 'http://' . $address . '/?code=200';
    expect(cachedHealth($pdo, $staleTool)['status'] === 'unknown', 'Checagem de uma configuração antiga sobrescreveu o cache atual.');
} finally {
    proc_terminate($fixture);
    foreach ($pipes as $pipe) fclose($pipe);
    proc_close($fixture);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Todos os testes passaram." . PHP_EOL;
