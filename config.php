<?php
/**
 * Portal Dashboard
 *
 * @author    FACRF
 * @copyright 2026 FACRF
 * @link      https://github.com/facrf/portal_dashboard
 */
// config.php
require_once 'db.php';

// ==========================================
// PROCESSAMENTO DE FORMULÁRIOS (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCsrfToken($_POST);
    } catch (InvalidArgumentException $e) {
        die(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }
    try {

    // ==========================================
    // EXPORTAÇÃO SEGURA VIA POST
    // ==========================================
    if (isset($_POST['action']) && $_POST['action'] === 'export') {
        $data = [
            'format' => 'meu_portal_v1',
            'settings' => $pdo->query("SELECT * FROM settings LIMIT 1")->fetch(),
            'categories' => $pdo->query("SELECT * FROM categories")->fetchAll(),
            'tools' => $pdo->query("SELECT * FROM tools")->fetchAll()
        ];
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="portal_backup_' . date('Y-m-d_H-i') . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    // MÓDULO DE IMPORTAÇÃO (Nativo, Heimdall, Homepage)
    if (isset($_POST['action']) && $_POST['action'] === 'import') {
        if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
            $maxImportBytes = 5 * 1024 * 1024;
            if ((int) $_FILES['import_file']['size'] > $maxImportBytes) {
                http_response_code(413);
                die("Arquivo de importação muito grande. O limite é 5 MB.");
            }
            $fileContent = file_get_contents($_FILES['import_file']['tmp_name']);
            if ($fileContent === false || strlen($fileContent) > $maxImportBytes) {
                http_response_code(400);
                die("Não foi possível ler o arquivo de importação.");
            }
            $ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['json', 'yaml', 'yml'], true)) {
                throw new InvalidArgumentException('Formato não suportado. Envie JSON, YAML ou YML.');
            }

            if ($ext === 'json') {
                $json = json_decode($fileContent, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
                    http_response_code(400);
                    die("Arquivo JSON inválido.");
                }

                // 1. IMPORTAÇÃO NATIVA (Restore Completo com Transação)
                if (isset($json['format']) && $json['format'] === 'meu_portal_v1') {
                    if (isset($json['categories']) && is_array($json['categories']) && isset($json['tools']) && is_array($json['tools'])) {
                        if (count($json['categories']) > 500 || count($json['tools']) > 5000) {
                            http_response_code(413);
                            die("O backup excede o limite de 500 categorias ou 5.000 serviços.");
                        }
                        try {
                            // Valida e normaliza tudo antes de remover qualquer dado atual.
                            $normalizedCategories = [];
                            foreach ($json['categories'] as $index => $category) {
                                if (!is_array($category)) throw new InvalidArgumentException('Categoria inválida no backup.');
                                $normalizedCategories[] = [
                                    'old_id' => (string) ($category['id'] ?? "category-{$index}"),
                                    'name' => inputString($category, 'name', 120, true),
                                    'sort_order' => max(0, min(499, (int) ($category['sort_order'] ?? $index)))
                                ];
                            }
                            if ($normalizedCategories === []) {
                                $normalizedCategories[] = ['old_id' => '__fallback__', 'name' => 'Geral', 'sort_order' => 0];
                            }

                            $normalizedTools = [];
                            foreach ($json['tools'] as $index => $tool) {
                                if (!is_array($tool)) throw new InvalidArgumentException('Serviço inválido no backup.');
                                $normalizedTools[] = [
                                    'category_id' => (string) ($tool['category_id'] ?? '__fallback__'),
                                    'name' => inputString($tool, 'name', 120, true),
                                    'url' => validatedToolUrl(inputString($tool, 'url', 2048, true)),
                                    'icon_url' => validatedIconReference(inputString($tool, 'icon_url', 2048)),
                                    'description' => inputString($tool, 'description', 500),
                                    'sort_order' => max(0, min(4999, (int) ($tool['sort_order'] ?? $index))),
                                    'tag_name' => inputString($tool, 'tag_name', 30),
                                    'tag_color' => validatedColor((string) ($tool['tag_color'] ?? '#007bff'))
                                ];
                            }

                            $pdo->beginTransaction();

                            $pdo->exec("DELETE FROM health_cache");
                            $pdo->exec("DELETE FROM tools");
                            $pdo->exec("DELETE FROM categories");
                            
                            $catMap = [];
                            $insertCategory = $pdo->prepare("INSERT INTO categories (name, sort_order) VALUES (?, ?)");
                            foreach ($normalizedCategories as $category) {
                                $insertCategory->execute([$category['name'], $category['sort_order']]);
                                $catMap[$category['old_id']] = (int) $pdo->lastInsertId();
                            }
                            $fallbackCatId = reset($catMap);
                            
                            $insertTool = $pdo->prepare("INSERT INTO tools (name, url, icon_url, description, category_id, sort_order, tag_name, tag_color) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                            foreach ($normalizedTools as $tool) {
                                $newCatId = $catMap[$tool['category_id']] ?? $fallbackCatId;
                                $insertTool->execute([
                                    $tool['name'], $tool['url'], $tool['icon_url'], $tool['description'],
                                    $newCatId, $tool['sort_order'], $tool['tag_name'], $tool['tag_color']
                                ]);
                            }
                            
                            if (isset($json['settings']) && is_array($json['settings'])) {
                                $s = $json['settings'];
                                $footer = $s['footer_text'] ?? '';
                                
                                $allowedLangs = ['pt', 'es', 'en'];
                                $importLang = (isset($s['language']) && in_array($s['language'], $allowedLangs)) ? $s['language'] : 'pt';

                                $stmt = $pdo->prepare("UPDATE settings SET portal_name=?, favicon=?, bg_color=?, bg_image=?, text_color=?, language=?, footer_text=?, session_days=?, brute_max_attempts=?, brute_lockout_time=?, show_clock=?, show_greeting=?, greeting_name=? WHERE id=1");
                                $stmt->execute([
                                    inputString($s, 'portal_name', 120) ?: 'Meu Portal',
                                    validatedIconReference(inputString($s, 'favicon', 2048)),
                                    validatedColor((string) ($s['bg_color'] ?? '#000000'), '#000000'),
                                    validatedIconReference(inputString($s, 'bg_image', 2048)),
                                    validatedColor((string) ($s['text_color'] ?? '#ffffff'), '#ffffff'),
                                    $importLang, 
                                    is_string($footer) ? mb_substr($footer, 0, 5000, 'UTF-8') : '',
                                    max(1, min(365, (int)($s['session_days'] ?? 7))),
                                    max(1, min(50, (int)($s['brute_max_attempts'] ?? 5))),
                                    max(1, min(86400, (int)($s['brute_lockout_time'] ?? 900))),
                                    isset($s['show_clock']) ? (int)$s['show_clock'] : 1,
                                    isset($s['show_greeting']) ? (int)$s['show_greeting'] : 1,
                                    inputString($s, 'greeting_name', 80) ?: 'Administrador'
                                ]);
                            }

                            $pdo->commit();

                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            http_response_code(422);
                            die("Erro na importação. O banco de dados foi preservado. Detalhe: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
                        }
                    } else {
                        http_response_code(422);
                        die('Backup nativo incompleto.');
                    }
                } 
                // 2. IMPORTAÇÃO DO HEIMDALL (Detecta e Anexa dados)
                else {
                    $items = isset($json['apps']) ? $json['apps'] : (is_array($json) ? $json : []);
                    
                    if (!empty($items)) {
                        if (count($items) > 5000) {
                            http_response_code(413);
                            die("A importação excede o limite de 5.000 serviços.");
                        }
                        try {
                            $pdo->beginTransaction();
                            $pdo->exec("INSERT INTO categories (name) VALUES ('Importado: Heimdall')");
                            $catId = (int) $pdo->lastInsertId();
                            $stmt = $pdo->prepare("INSERT INTO tools (name, url, icon_url, description, category_id) VALUES (?, ?, ?, ?, ?)");
                            foreach ($items as $item) {
                                if (!is_array($item)) continue;
                                $nameValue = $item['title'] ?? $item['name'] ?? 'App';
                                $item['name'] = is_string($nameValue) ? $nameValue : 'App';
                                if (!is_string($item['url'] ?? null) || trim($item['url']) === '') continue;
                                $name = inputString($item, 'name', 120, true);
                                $url = validatedToolUrl(inputString($item, 'url', 2048, true));
                                $item['icon_url'] = is_string($item['icon'] ?? null) ? $item['icon'] : '';
                                $icon = validatedIconReference(inputString($item, 'icon_url', 2048));
                                $desc = inputString($item, 'description', 500);
                                $stmt->execute([$name, $url, $icon, $desc, $catId]);
                            }
                            $pdo->commit();
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            http_response_code(422);
                            die('Falha ao importar dados do Heimdall: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
                        }
                    }
                }
            } 
            // 3. IMPORTAÇÃO DO HOMEPAGE DASHBOARD (Parser YAML Customizado)
            elseif ($ext === 'yaml' || $ext === 'yml') {
                $lines = explode("\n", $fileContent);
                if (count($lines) > 20000) {
                    http_response_code(413);
                    die("O arquivo YAML excede o limite de 20.000 linhas.");
                }
                $pdo->beginTransaction();
                try {
                    $fallbackCategory = $pdo->query("SELECT id FROM categories ORDER BY id LIMIT 1")->fetchColumn();
                    if (!$fallbackCategory) {
                        $pdo->exec("INSERT INTO categories (name) VALUES ('Geral')");
                        $fallbackCategory = $pdo->lastInsertId();
                    }
                    $currentCatId = (int) $fallbackCategory;
                    $currentApp = null;
                    $currentAppProps = [];

                foreach ($lines as $line) {
                    if (trim($line) === '' || str_starts_with(trim($line), '#')) continue;
                    
                    preg_match('/^(\s*)/', $line, $matches);
                    $indent = strlen($matches[1]);
                    $content = trim($line);

                    if (preg_match('/^-\s+(.+?):$/', $content, $m)) {
                        if ($currentApp) {
                            $stmt = $pdo->prepare("INSERT INTO tools (name, url, icon_url, description, category_id) VALUES (?, ?, ?, ?, ?)");
                            if (!empty($currentAppProps['href'])) {
                                $stmt->execute([
                                    mb_substr($currentApp, 0, 120, 'UTF-8'),
                                    validatedToolUrl(mb_substr($currentAppProps['href'], 0, 2048, 'UTF-8')),
                                    validatedIconReference(mb_substr($currentAppProps['icon'] ?? '', 0, 2048, 'UTF-8')),
                                    mb_substr($currentAppProps['description'] ?? '', 0, 500, 'UTF-8'),
                                    $currentCatId
                                ]);
                            }
                            $currentApp = null;
                            $currentAppProps = [];
                        }

                        $catOrAppName = trim($m[1], " '\"");
                        
                        if ($indent === 0) {
                            $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
                            $stmt->execute([mb_substr($catOrAppName . ' (Homepage)', 0, 120, 'UTF-8')]);
                            $currentCatId = $pdo->lastInsertId();
                        } else {
                            $currentApp = $catOrAppName;
                        }
                    } 
                    elseif (preg_match('/^([a-zA-Z0-9_]+):\s*(.+)$/', $content, $m)) {
                        if ($currentApp) {
                            $currentAppProps[trim($m[1])] = trim($m[2], " '\"");
                        }
                    }
                }
                if ($currentApp) {
                    if (!empty($currentAppProps['href'])) {
                        $stmt = $pdo->prepare("INSERT INTO tools (name, url, icon_url, description, category_id) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([
                            mb_substr($currentApp, 0, 120, 'UTF-8'),
                            validatedToolUrl(mb_substr($currentAppProps['href'], 0, 2048, 'UTF-8')),
                            validatedIconReference(mb_substr($currentAppProps['icon'] ?? '', 0, 2048, 'UTF-8')),
                            mb_substr($currentAppProps['description'] ?? '', 0, 500, 'UTF-8'),
                            $currentCatId
                        ]);
                    }
                }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            }
            header("Location: config.php?import_success=1"); 
            exit;
        } else {
            throw new InvalidArgumentException('Nenhum arquivo válido foi enviado para importação.');
        }
    }

    // Salvar Configurações Visuais
    if (isset($_POST['action']) && $_POST['action'] === 'update_settings') {
        
        $allowedLangs = ['pt', 'es', 'en'];
        $langInput = inputString($_POST, 'language', 2);
        $lang = in_array($langInput, $allowedLangs, true) ? $langInput : 'pt';
        $showClock = isset($_POST['show_clock']) ? 1 : 0;
        $showGreeting = isset($_POST['show_greeting']) ? 1 : 0;
        $greetingName = inputString($_POST, 'greeting_name', 80) ?: 'Administrador';
        $portalName = inputString($_POST, 'portal_name', 120, true);
        $favicon = validatedIconReference(inputString($_POST, 'favicon', 2048));
        $bgColor = validatedColor(inputString($_POST, 'bg_color', 7), '#000000');
        $bgImage = validatedIconReference(inputString($_POST, 'bg_image', 2048));
        $textColor = validatedColor(inputString($_POST, 'text_color', 7), '#ffffff');

        $stmt = $pdo->prepare("UPDATE settings SET portal_name=?, favicon=?, bg_color=?, bg_image=?, text_color=?, language=?, show_clock=?, show_greeting=?, greeting_name=? WHERE id=1");
        $stmt->execute([$portalName, $favicon, $bgColor, $bgImage, $textColor, $lang, $showClock, $showGreeting, $greetingName]);
        
        header("Location: config.php?success=1"); exit;
    }
    
    // Salvar Configurações de Segurança e Acesso
    if (isset($_POST['action']) && $_POST['action'] === 'update_security') {
        $sessionDays = inputInt($_POST, 'session_days', 1, 365, 7);
        $maxAttempts = inputInt($_POST, 'brute_max_attempts', 1, 50, 5);
        $lockoutTime = inputInt($_POST, 'brute_lockout_time', 1, 86400, 900);

        $stmt = $pdo->prepare("UPDATE settings SET session_days=?, brute_max_attempts=?, brute_lockout_time=? WHERE id=1");
        $stmt->execute([$sessionDays, $maxAttempts, $lockoutTime]);
        
        header("Location: config.php?success=1"); exit;
    }
    
    // Ações de Categoria
    if (isset($_POST['action']) && $_POST['action'] === 'add_category') {
        $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)"); $stmt->execute([inputString($_POST, 'cat_name', 120, true)]); header("Location: config.php"); exit;
    }
    if (isset($_POST['action']) && $_POST['action'] === 'edit_category') {
        $stmt = $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?"); $stmt->execute([inputString($_POST, 'cat_name', 120, true), inputId($_POST, 'cat_id')]); header("Location: config.php"); exit;
    }
    
    // Excluir Categoria (COM CORREÇÃO SQL INJECTION)
    if (isset($_POST['action']) && $_POST['action'] === 'delete_category') {
        $catId = inputId($_POST, 'cat_id');
        
        $stmtFallback = $pdo->prepare("SELECT id FROM categories WHERE id != ? LIMIT 1");
        $stmtFallback->execute([$catId]);
        $fallbackCat = $stmtFallback->fetchColumn();
        
        if (!$fallbackCat) { 
            $pdo->exec("INSERT INTO categories (name) VALUES ('Geral')"); 
            $fallbackCat = $pdo->lastInsertId(); 
        }
        
        $pdo->prepare("UPDATE tools SET category_id = ? WHERE category_id = ?")->execute([$fallbackCat, $catId]);
        $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$catId]); 
        
        header("Location: config.php"); 
        exit;
    }
    
    // AJAX Reorder Categories
    if (isset($_POST['action']) && $_POST['action'] === 'reorder_categories' && isset($_POST['orders'])) {
        $orders = json_decode(inputString($_POST, 'orders', 20000, true), true);
        if (is_array($orders) && count($orders) <= 500) {
            $pdo->beginTransaction();
            foreach ($orders as $order) {
                if (!is_array($order)) continue;
                $id = filter_var($order['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $position = filter_var($order['order'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 499]]);
                if ($id === false || $position === false) continue;
                $stmt = $pdo->prepare("UPDATE categories SET sort_order = ? WHERE id = ?");
                $stmt->execute([(int) $position, (int) $id]);
            }
            $pdo->commit();
            jsonResponse(['status' => 'ok']);
        }
        jsonResponse(['status' => 'error', 'msg' => 'Ordenação inválida.'], 422);
    }
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(422);
        die(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }
}

$editCatMode = false; $editCat = null;
if (filter_var($_GET['edit_cat'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?"); $stmt->execute([(int) $_GET['edit_cat']]); $editCat = $stmt->fetch(); if ($editCat) $editCatMode = true;
}

$settings = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch();
$categories = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, name ASC")->fetchAll();

$bgColorValue = validatedColor((string) ($settings['bg_color'] ?? ''), '#000000');
$textColorValue = validatedColor((string) ($settings['text_color'] ?? ''), '#ffffff');
$currentLang = $settings['language'] ?? 'pt';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <!-- Developed with care by FACRF - https://github.com/facrf -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('appearance_tabs') ?></title>
    
    <!-- INÍCIO DO FAVICON -->
    <?php $favicon = resolveIconUrl($settings['favicon']); if(!empty($favicon)): ?>
        <link rel="icon" href="<?= $favicon ?>">
    <?php endif; ?>
    <!-- FIM DO FAVICON -->

    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <style>:root { --bg-color: <?= $bgColorValue ?>; --bg-image: url('<?= htmlspecialchars($settings['bg_image'], ENT_QUOTES, 'UTF-8') ?>'); --text-color: <?= $textColorValue ?>; }</style>
</head>
<body>
    <script>
        if(localStorage.getItem('theme') === 'light') document.body.classList.add('light-theme');
        function toggleTheme() {
            document.body.classList.toggle('light-theme');
            localStorage.setItem('theme', document.body.classList.contains('light-theme') ? 'light' : 'dark');
        }
    </script>
    <div class="container">
        <header>
            <h1><?= t('appearance_tabs') ?></h1>
            <div class="header-controls">
                <button type="button" class="theme-toggle-wrapper" onclick="toggleTheme()" title="Modo Claro/Escuro" aria-label="Modo Claro/Escuro">
                    <svg viewBox="0 0 24 24"><path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zm0 8c-1.65 0-3-1.35-3-3s1.35-3 3-3 3 1.35 3 3-1.35 3-3 3zm9-4h-2c-.55 0-1 .45-1 1s.45 1 1 1h2c.55 0 1-.45 1-1s-.45-1-1-1zM4 12c0 .55-.45 1-1 1H1c-.55 0-1-.45-1-1s.45-1 1-1h2c.55 0 1 .45 1 1zm7-9V1c0-.55-.45-1-1-1s-1 .45-1 1v2c0 .55.45 1 1 1s1-.45 1-1zm0 18v2c0 .55-.45 1 1 1s1-.45 1-1v-2c0-.55-.45-1-1-1s-1 .45-1 1zm7.66-13.88l1.41-1.41c.39-.39.39-1.03 0-1.41-.39-.39-1.03-.39-1.41 0l-1.41 1.41c-.39.39-.39 1.03 0 1.41.39.39 1.03.39 1.41 0zM4.93 19.07l1.41-1.41c.39-.39.39-1.03 0-1.41-.39-.39-1.03-.39-1.41 0l-1.41 1.41c-.39.39-.39 1.03 0 1.41.39.39 1.03.39 1.41 0zm14.14 0c.39.39 1.03.39 1.41 0 .39-.39.39-1.03 0-1.41l-1.41-1.41c-.39-.39-1.03-.39-1.41 0-.39.39-.39 1.03 0 1.41l1.41 1.41zM6.34 6.34c.39.39 1.03.39 1.41 0 .39-.39.39-1.03 0-1.41L6.34 3.51c-.39-.39-1.03-.39-1.41 0-.39.39-.39 1.03 0 1.41l1.41 1.42z"/></svg>
                    <div class="toggle-slot"><div class="toggle-button"></div></div>
                    <svg viewBox="0 0 24 24"><path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9c0-.46-.04-.92-.1-1.36-.98 1.37-2.58 2.26-4.4 2.26-3.03 0-5.5-2.47-5.5-5.5 0-1.82.89-3.42 2.26-4.4C12.92 3.04 12.46 3 12 3z"/></svg>
                </button>
                <div class="header-nav">
                    <a href="index.php" class="btn">← <?= t('dashboard') ?></a>
                    <a href="admin.php" class="btn"><?= t('manage_services') ?></a>
                    <form method="POST" action="login.php" style="display: inline; margin: 0;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="logout">
        <button type="submit" class="btn btn-danger" style="margin-left: 10px;"><?= t('logout') ?></button>
    </form>
                </div>
            </div>
        </header>

        <!-- ALERTA DE IMPORTAÇÃO COM SUCESSO -->
        <?php if (isset($_GET['import_success'])): ?>
            <div style="background: rgba(40, 167, 69, 0.2); color: #42e86b; padding: 15px; border-radius: 8px; margin-bottom: 2rem; border: 1px solid rgba(40, 167, 69, 0.4); text-align: center; font-weight: bold;">
                <?= t('Dados importados com sucesso!') ?>
            </div>
        <?php endif; ?>

        <div class="admin-panel">
            <h2><?= t('settings') ?></h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="update_settings">
                
                <div class="form-group">
                    <label><?= t('portal_name') ?>:</label>
                    <input type="text" name="portal_name" maxlength="120" value="<?= htmlspecialchars($settings['portal_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                
                <div class="form-group">
                    <label><?= t('language') ?>:</label>
                    <select name="language" required>
                        <option value="pt" <?= $currentLang == 'pt' ? 'selected' : '' ?>>Português</option>
                        <option value="es" <?= $currentLang == 'es' ? 'selected' : '' ?>>Español</option>
                        <option value="en" <?= $currentLang == 'en' ? 'selected' : '' ?>>English</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Favicon (<?= t('Ícone do Navegador - /icons ou URL') ?>):</label>
                    <input type="text" name="favicon" maxlength="2048" value="<?= htmlspecialchars($settings['favicon'], ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div style="display: flex; gap: 2rem; flex-wrap: wrap; margin-bottom: 1rem;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label><?= t('Cor de Fundo') ?>:</label>
                        <input type="color" name="bg_color" value="<?= $bgColorValue ?>" required>
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 0;">
                        <label><?= t('Cor do Texto Principal') ?>:</label>
                        <input type="color" name="text_color" value="<?= $textColorValue ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label><?= t('URL / Nome Imagem de Fundo') ?>:</label>
                    <input type="text" name="bg_image" maxlength="2048" value="<?= htmlspecialchars($settings['bg_image'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                
                <div class="form-group" style="display: flex; align-items: center; gap: 10px; margin-top: 15px;">
                    <input type="checkbox" name="show_clock" id="show_clock" value="1" <?= (!isset($settings['show_clock']) || $settings['show_clock'] == 1) ? 'checked' : '' ?> style="width: 20px; height: 20px; cursor: pointer;">
                    <label for="show_clock" style="margin: 0; cursor: pointer;"><?= t('Mostrar Relógio na Página Inicial') ?></label>
                </div>

                <div class="form-group" style="display: flex; align-items: center; gap: 10px; margin-top: 15px;">
                    <input type="checkbox" name="show_greeting" id="show_greeting" value="1" <?= (!isset($settings['show_greeting']) || $settings['show_greeting'] == 1) ? 'checked' : '' ?> style="width: 20px; height: 20px; cursor: pointer;">
                    <label for="show_greeting" style="margin: 0; cursor: pointer;"><?= t('Mostrar Saudação') ?></label>
                </div>

                <div class="form-group" style="margin-top: 15px;">
                    <label><?= t('Nome para a Saudação') ?>:</label>
                    <input type="text" name="greeting_name" maxlength="80" value="<?= htmlspecialchars($settings['greeting_name'] ?? 'Administrador', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                
                <button type="submit" class="btn"><?= t('save_changes') ?></button>
            </form>
        </div>

        <div class="admin-panel" id="security-panel">
            <h2><?= t('Segurança e Acesso') ?></h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="update_security">
                
                <div class="form-group">
                    <label><?= t('Dias de validade da sessão') ?>:</label>
                    <input type="number" name="session_days" value="<?= (int)($settings['session_days'] ?? 7) ?>" min="1" max="365" required>
                    <small style="opacity: 0.7; font-size: 0.85em; display: block; margin-top: 5px;"><?= t('Tempo que um usuário permanece logado sem precisar digitar a senha novamente.') ?></small>
                </div>
                
                <div class="form-group">
                    <label><?= t('Tentativas de login (Anti-Brute Force)') ?>:</label>
                    <input type="number" name="brute_max_attempts" value="<?= (int)($settings['brute_max_attempts'] ?? 5) ?>" min="1" max="50" required>
                    <small style="opacity: 0.7; font-size: 0.85em; display: block; margin-top: 5px;"><?= t('Número máximo de tentativas de login incorretas antes de bloquear o IP.') ?></small>
                </div>
                
                <div class="form-group">
                    <label><?= t('Tempo de bloqueio do IP (em segundos)') ?>:</label>
                    <input type="number" name="brute_lockout_time" value="<?= (int)($settings['brute_lockout_time'] ?? 900) ?>" min="1" max="86400" required>
                    <small style="opacity: 0.7; font-size: 0.85em; display: block; margin-top: 5px;"><?= t('Tempo pelo qual o IP do atacante ficará bloqueado (Ex: 900 = 15 minutos).') ?></small>
                </div>
                
                <button type="submit" class="btn"><?= t('save_changes') ?></button>
            </form>
        </div>

        <!-- NOVO PAINEL DE BACKUP E IMPORTAÇÃO -->
        <div class="admin-panel" id="backup-panel">
            <h2><?= t('Backup & Importação') ?></h2>
            <div style="display: flex; gap: 2rem; flex-wrap: wrap;">
                
<!-- Box Exportar -->
<div style="flex: 1; background: rgba(255,255,255,0.05); padding: 1.5rem; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);">
    <h3 style="margin-top:0;">📦 <?= t('Exportar Backup') ?></h3>
    <p style="opacity: 0.8; font-size: 0.9rem; margin-bottom: 1.5rem;">Baixe todas as suas configurações, abas e serviços cadastrados no formato nativo (JSON). É a melhor forma de salvar seu progresso para não perder os links e o layout configurado.</p>
    
    <form method="POST" action="config.php" style="margin: 0;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="export">
        <button type="submit" class="btn"><?= t('Download Backup (JSON)') ?></button>
    </form>
</div>

                <!-- Box Importar -->
                <div style="flex: 1; background: rgba(255,255,255,0.05); padding: 1.5rem; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);">
                    <h3 style="margin-top:0;">📥 <?= t('Importar Dados') ?></h3>
                    <p style="opacity: 0.8; font-size: 0.9rem; margin-bottom: 1rem;">Faça upload de um arquivo para importar. O sistema detecta automaticamente:</p>
                    <ul style="opacity: 0.8; font-size: 0.85rem; margin-bottom: 1.5rem; padding-left: 20px;">
                        <li><strong>Backup Nativo (.json):</strong> Substitui seu banco de dados inteiro pelo backup.</li>
                        <li><strong>Heimdall (.json):</strong> Adiciona os serviços em uma nova aba "Importado: Heimdall".</li>
                        <li><strong>Homepage (.yaml/.yml):</strong> Adiciona os serviços separando-os pelas abas originais do yaml.</li>
                    </ul>
                    
                    <form method="POST" enctype="multipart/form-data" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="import">
                        <input type="file" name="import_file" accept=".json,.yaml,.yml" required style="flex:1; background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.2); padding: 0.5rem; border-radius: 6px; color: var(--text-color);">
                        <button type="submit" class="btn btn-glow"><?= t('Importar') ?></button>
                    </form>
                </div>
                
            </div>
        </div>

        <div class="admin-panel" id="cat-panel">
            <h2><?= t('Gerenciar Categorias (Abas)') ?></h2>
            <form method="POST" style="display:flex; gap:10px; align-items:flex-end; margin-bottom: 2rem;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="<?= $editCatMode ? 'edit_category' : 'add_category' ?>">
                <?php if ($editCatMode): ?><input type="hidden" name="cat_id" value="<?= $editCat['id'] ?>"><?php endif; ?>
                
                <div class="form-group" style="flex:1; margin-bottom:0;">
                    <label><?= $editCatMode ? t('Editar Nome da Categoria') . ':' : t('Nova Categoria') . ':' ?></label>
                    <input type="text" name="cat_name" maxlength="120" value="<?= $editCatMode ? htmlspecialchars($editCat['name'], ENT_QUOTES, 'UTF-8') : '' ?>" required>
                </div>
                <button type="submit" class="btn"><?= $editCatMode ? t('save_changes') : t('Adicionar') ?></button>
                <?php if ($editCatMode): ?><a href="config.php" class="btn"><?= t('Cancelar') ?></a><?php endif; ?>
            </form>

            <div class="table-responsive">
                <table>
                    <thead><tr><th><?= t('Nome da Categoria') ?></th><th><?= t('Ações') ?></th></tr></thead>
                    <tbody id="categories-tbody">
                        <?php foreach ($categories as $cat): ?>
                            <tr draggable="true" data-id="<?= $cat['id'] ?>" class="draggable-row" style="<?= ($editCatMode && $editCat['id'] == $cat['id']) ? 'background: rgba(255,255,255,0.05);' : 'cursor: grab;' ?>">
                                <td style="font-weight: bold;"><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button type="button" class="btn move-category" data-direction="up" aria-label="Mover categoria para cima" title="Mover para cima" style="padding:0.3rem 0.6rem">↑</button>
                                        <button type="button" class="btn move-category" data-direction="down" aria-label="Mover categoria para baixo" title="Mover para baixo" style="padding:0.3rem 0.6rem">↓</button>
                                        <a href="config.php?edit_cat=<?= $cat['id'] ?>#cat-panel" class="btn" style="padding:0.3rem 0.6rem; font-size:0.8rem"><?= t('edit') ?></a>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="delete_category">
                                            <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
                                            <button type="submit" class="btn btn-danger" style="padding:0.3rem 0.6rem; font-size:0.8rem" onclick="return confirm('<?= t('Excluir aba? Serviços serão movidos para outra.') ?>');"><?= t('delete') ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <style>
        .draggable-row.drag-over { border-top: 2px solid #007bff; background: rgba(0, 123, 255, 0.1) !important; }
        .draggable-row.dragging { opacity: 0.5; }
    </style>

    <script>
        document.addEventListener("DOMContentLoaded", () => {
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('input', () => {
                    const btn = form.querySelector('button[type="submit"]');
                    if (btn && !btn.classList.contains('btn-danger') && !btn.classList.contains('btn-glow')) {
                        btn.classList.add('btn-glow');
                    }
                });
            });
            
            let draggedRow = null;
            const tbody = document.getElementById('categories-tbody');
            
            if (tbody) {
                tbody.querySelectorAll('.move-category').forEach(button => {
                    button.addEventListener('click', () => {
                        const row = button.closest('tr');
                        const target = button.dataset.direction === 'up' ? row.previousElementSibling : row.nextElementSibling;
                        if (!target) return;
                        if (button.dataset.direction === 'up') tbody.insertBefore(row, target);
                        else tbody.insertBefore(target, row);
                        saveCategoryOrder();
                    });
                });

                tbody.querySelectorAll('tr.draggable-row').forEach(row => {
                    row.addEventListener('dragstart', function(e) {
                        draggedRow = this;
                        setTimeout(() => this.classList.add('dragging'), 0);
                        e.dataTransfer.effectAllowed = 'move';
                    });
                    
                    row.addEventListener('dragend', function() {
                        this.classList.remove('dragging');
                        draggedRow = null;
                    });
                    
                    row.addEventListener('dragover', function(e) {
                        e.preventDefault();
                        const bounding = this.getBoundingClientRect();
                        const offset = bounding.y + (bounding.height / 2);
                        if (e.clientY - offset > 0) {
                            this.style.borderBottom = '2px solid #007bff';
                            this.style.borderTop = '';
                        } else {
                            this.style.borderTop = '2px solid #007bff';
                            this.style.borderBottom = '';
                        }
                    });
                    
                    row.addEventListener('dragleave', function() {
                        this.style.borderTop = '';
                        this.style.borderBottom = '';
                    });
                    
                    row.addEventListener('drop', function(e) {
                        e.preventDefault();
                        this.style.borderTop = '';
                        this.style.borderBottom = '';
                        if (draggedRow && draggedRow !== this) {
                            const bounding = this.getBoundingClientRect();
                            const offset = bounding.y + (bounding.height / 2);
                            if (e.clientY - offset > 0) {
                                tbody.insertBefore(draggedRow, this.nextSibling);
                            } else {
                                tbody.insertBefore(draggedRow, this);
                            }
                            saveCategoryOrder();
                        }
                    });
                });
            }

            function saveCategoryOrder() {
                const rows = tbody.querySelectorAll('tr.draggable-row');
                const orders = [];
                rows.forEach((row, index) => {
                    orders.push({ id: row.getAttribute('data-id'), order: index });
                });
                
                const formData = new FormData();
                formData.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>');
                formData.append('action', 'reorder_categories');
                formData.append('orders', JSON.stringify(orders));
                
                fetch('config.php', {
                    method: 'POST',
                    body: formData
                });
            }
        });
    </script>
</body>
</html>
