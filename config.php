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
require_once __DIR__ . '/imports.php';
$importError = '';
if (isset($_SESSION['import_preview']) && time() - $_SESSION['import_preview']['created_at'] > 600) unset($_SESSION['import_preview']);

// ==========================================
// PROCESSAMENTO DE FORMULÁRIOS (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCsrfToken($_POST);
    } catch (InvalidArgumentException | JsonException $e) {
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
    
    $action = inputString($_POST, 'action', 40, true);
    if ($action === 'cancel_import') {
        unset($_SESSION['import_preview']);
        header('Location: config.php#backup-panel'); exit;
    }
    if ($action === 'confirm_import') {
        $preview = $_SESSION['import_preview'] ?? null;
        $nonce = inputString($_POST, 'import_nonce', 64, true);
        if (!$preview || time() - $preview['created_at'] > 600 || !hash_equals($preview['nonce'], $nonce)) {
            throw new InvalidArgumentException(t('import_expired'));
        }
        applyImportPlan($pdo, $preview['plan']);
        unset($_SESSION['import_preview']);
        header('Location: config.php?import_success=1#backup-panel'); exit;
    }
    if ($action === 'import') {
        unset($_SESSION['import_preview']);
        $file = $_FILES['import_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new InvalidArgumentException(t('import_file_error'));
        }
        if ($file['size'] > 5 * 1024 * 1024) throw new InvalidArgumentException(t('import_size_error'));
        $content = file_get_contents($file['tmp_name'], false, null, 0, 5 * 1024 * 1024 + 1);
        if ($content === false) throw new InvalidArgumentException(t('import_file_error'));
        $plan = buildImportPlan($content, strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)));
        $plan['fingerprint'] = importFingerprint($pdo);
        $_SESSION['import_preview'] = ['plan' => $plan, 'created_at' => time(), 'nonce' => bin2hex(random_bytes(32))];
        header('Location: config.php#backup-panel'); exit;
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
        
        $pdo->beginTransaction();
        $stmtFallback = $pdo->prepare("SELECT id FROM categories WHERE id != ? LIMIT 1");
        $stmtFallback->execute([$catId]);
        $fallbackCat = $stmtFallback->fetchColumn();
        
        if (!$fallbackCat) { 
            $pdo->exec("INSERT INTO categories (name) VALUES ('Geral')"); 
            $fallbackCat = $pdo->lastInsertId(); 
        }
        
        $pdo->prepare("UPDATE tools SET category_id = ? WHERE category_id = ?")->execute([$fallbackCat, $catId]);
        $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$catId]);
        $pdo->commit();
        
        header("Location: config.php"); 
        exit;
    }
    
    // AJAX Reorder Categories
    if (isset($_POST['action']) && $_POST['action'] === 'reorder_categories' && isset($_POST['orders'])) {
        saveSortOrder($pdo, 'categories', $_POST);
        jsonResponse(['status' => 'ok']);
    }
    } catch (InvalidArgumentException | JsonException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(422);
        if (in_array($_POST['action'] ?? '', ['import', 'confirm_import'], true)) {
            $importError = $e->getMessage();
        } else {
            die(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Portal configuration error: ' . $e->getMessage());
        http_response_code(409);
        $importError = t('operation_failed');
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
    <?php require __DIR__ . '/templates/head-assets.php'; ?>
</head>
<body>
    <div class="container">
        <header>
            <h1><?= t('appearance_tabs') ?></h1>
            <div class="header-controls">
                <button type="button" class="theme-toggle-wrapper" onclick="toggleTheme()" title="<?= t('toggle_theme') ?>" aria-label="<?= t('toggle_theme') ?>">
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
                    <label for="config-portal_name-1"><?= t('portal_name') ?>:</label>
                    <input id="config-portal_name-1" type="text" name="portal_name" maxlength="120" value="<?= htmlspecialchars($settings['portal_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="config-language-2"><?= t('language') ?>:</label>
                    <select id="config-language-2" name="language" required>
                        <option value="pt" <?= $currentLang == 'pt' ? 'selected' : '' ?>>Português</option>
                        <option value="es" <?= $currentLang == 'es' ? 'selected' : '' ?>>Español</option>
                        <option value="en" <?= $currentLang == 'en' ? 'selected' : '' ?>>English</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="config-favicon-3">Favicon (<?= t('Ícone do Navegador - /icons ou URL') ?>):</label>
                    <input id="config-favicon-3" type="text" name="favicon" maxlength="2048" value="<?= htmlspecialchars($settings['favicon'], ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div style="display: flex; gap: 2rem; flex-wrap: wrap; margin-bottom: 1rem;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="config-bg_color-4"><?= t('Cor de Fundo') ?>:</label>
                        <input id="config-bg_color-4" type="color" name="bg_color" value="<?= $bgColorValue ?>" required>
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="config-text_color-5"><?= t('Cor do Texto Principal') ?>:</label>
                        <input id="config-text_color-5" type="color" name="text_color" value="<?= $textColorValue ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="config-bg_image-6"><?= t('URL / Nome Imagem de Fundo') ?>:</label>
                    <input id="config-bg_image-6" type="text" name="bg_image" maxlength="2048" value="<?= htmlspecialchars($settings['bg_image'], ENT_QUOTES, 'UTF-8') ?>">
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
                    <label for="greeting_name"><?= t('Nome para a Saudação') ?>:</label>
                    <input type="text" id="greeting_name" name="greeting_name" maxlength="80" value="<?= htmlspecialchars($settings['greeting_name'] ?? 'Administrador', ENT_QUOTES, 'UTF-8') ?>">
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
                    <label for="config-session_days-7"><?= t('Dias de validade da sessão') ?>:</label>
                    <input id="config-session_days-7" type="number" name="session_days" value="<?= (int)($settings['session_days'] ?? 7) ?>" min="1" max="365" required>
                    <small style="opacity: 0.7; font-size: 0.85em; display: block; margin-top: 5px;"><?= t('Tempo que um usuário permanece logado sem precisar digitar a senha novamente.') ?></small>
                </div>
                
                <div class="form-group">
                    <label for="config-brute_max_attempts-8"><?= t('Tentativas de login (Anti-Brute Force)') ?>:</label>
                    <input id="config-brute_max_attempts-8" type="number" name="brute_max_attempts" value="<?= (int)($settings['brute_max_attempts'] ?? 5) ?>" min="1" max="50" required>
                    <small style="opacity: 0.7; font-size: 0.85em; display: block; margin-top: 5px;"><?= t('Número máximo de tentativas de login incorretas antes de bloquear o IP.') ?></small>
                </div>
                
                <div class="form-group">
                    <label for="config-brute_lockout_time-9"><?= t('Tempo de bloqueio do IP (em segundos)') ?>:</label>
                    <input id="config-brute_lockout_time-9" type="number" name="brute_lockout_time" value="<?= (int)($settings['brute_lockout_time'] ?? 900) ?>" min="1" max="86400" required>
                    <small style="opacity: 0.7; font-size: 0.85em; display: block; margin-top: 5px;"><?= t('Tempo pelo qual o IP do atacante ficará bloqueado (Ex: 900 = 15 minutos).') ?></small>
                </div>
                
                <button type="submit" class="btn"><?= t('save_changes') ?></button>
            </form>
        </div>

        <!-- NOVO PAINEL DE BACKUP E IMPORTAÇÃO -->
        <div class="admin-panel" id="backup-panel">
            <h2><?= t('Backup & Importação') ?></h2>
            <?php require __DIR__ . '/templates/import-preview.php'; ?>
            <div style="display: flex; gap: 2rem; flex-wrap: wrap;">
                
<!-- Box Exportar -->
<div style="flex: 1; background: rgba(255,255,255,0.05); padding: 1.5rem; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);">
    <h3 style="margin-top:0;">📦 <?= t('Exportar Backup') ?></h3>
    <p style="opacity: 0.8; font-size: 0.9rem; margin-bottom: 1.5rem;"><?= t('backup_help') ?></p>
    
    <form method="POST" action="config.php" style="margin: 0;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="export">
        <button type="submit" class="btn"><?= t('Download Backup (JSON)') ?></button>
    </form>
</div>

                <!-- Box Importar -->
                <div style="flex: 1; background: rgba(255,255,255,0.05); padding: 1.5rem; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);">
                    <h3 style="margin-top:0;">📥 <?= t('Importar Dados') ?></h3>
                    <p style="opacity: 0.8; font-size: 0.9rem; margin-bottom: 1rem;"><?= t('import_help') ?></p>
                    <ul style="opacity: 0.8; font-size: 0.85rem; margin-bottom: 1.5rem; padding-left: 20px;">
                        <li><?= t('native_help') ?></li>
                        <li><?= t('heimdall_help') ?></li>
                        <li><?= t('homepage_help') ?></li>
                    </ul>
                    
                    <form method="POST" enctype="multipart/form-data" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="import">
                        <input type="file" aria-label="<?= t('import_file') ?>" name="import_file" accept=".json,.yaml,.yml" required style="flex:1; background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.2); padding: 0.5rem; border-radius: 6px; color: var(--text-color);">
                        <button type="submit" class="btn btn-glow"><?= t('preview_import') ?></button>
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
                    <label for="config-cat_name-10"><?= $editCatMode ? t('Editar Nome da Categoria') . ':' : t('Nova Categoria') . ':' ?></label>
                    <input id="config-cat_name-10" type="text" name="cat_name" maxlength="120" value="<?= $editCatMode ? htmlspecialchars($editCat['name'], ENT_QUOTES, 'UTF-8') : '' ?>" required>
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
                                        <button type="button" class="btn move-category" data-direction="up" aria-label="<?= t('move_category_up') ?>" title="<?= t('move_category_up') ?>" style="padding:0.3rem 0.6rem">↑</button>
                                        <button type="button" class="btn move-category" data-direction="down" aria-label="<?= t('move_category_down') ?>" title="<?= t('move_category_down') ?>" style="padding:0.3rem 0.6rem">↓</button>
                                        <a href="config.php?edit_cat=<?= $cat['id'] ?>#cat-panel" class="btn" style="padding:0.3rem 0.6rem; font-size:0.8rem"><?= t('edit') ?></a>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="delete_category">
                                            <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
                                            <button type="submit" class="btn btn-danger" style="padding:0.3rem 0.6rem; font-size:0.8rem" onclick="return confirm(<?= htmlspecialchars(json_encode(t('Excluir aba? Serviços serão movidos para outra.')), ENT_QUOTES, 'UTF-8') ?>);"><?= t('delete') ?></button>
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
            const orderController = Portal.createOrderController(tbody ? [tbody] : [], 'config.php', 'reorder_categories', 'tr.draggable-row');
            
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
                orderController.save();
            }
        });
    </script>
</body>
</html>
