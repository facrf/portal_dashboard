<?php
/** Processo isolado usado para verificar a instalação concorrente do SQLite. */
putenv('PORTAL_DB_PATH=' . $argv[1]);
require dirname(__DIR__) . '/database.php';
echo $pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
