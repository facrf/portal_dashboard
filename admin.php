<?php
/**
 * Portal Dashboard
 *
 * @author    FACRF
 * @copyright 2026 FACRF
 * @link      https://github.com/facrf/portal_dashboard
 */
// admin.php
require_once 'db.php';

// ==========================================
// PROCESSAMENTO DE AÇÕES (VIA POST + CSRF)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCsrfToken($_POST);
        $action = inputString($_POST, 'action', 40, true);

        if ($action === 'add_tool' || $action === 'edit_tool') {
            $name = inputString($_POST, 'name', 120, true);
            $url = validatedToolUrl(inputString($_POST, 'url', 2048, true));
            $icon = validatedIconReference(inputString($_POST, 'icon_url', 2048));
            $description = inputString($_POST, 'description', 500);
            $categoryId = inputId($_POST, 'category_id');
            $tagName = inputString($_POST, 'tag_name', 30);
            $tagColor = validatedColor(inputString($_POST, 'tag_color', 7));
            [$healthMethod, $healthUrl, $healthCodes] = validatedHealthSettings($_POST);
            require_once __DIR__ . '/health.php';
            healthTarget(['url' => $url, 'health_url' => $healthUrl, 'health_method' => $healthMethod]);

            $categoryExists = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE id = ?");
            $categoryExists->execute([$categoryId]);
            if (!$categoryExists->fetchColumn()) throw new InvalidArgumentException(t('category_missing'));

            if ($action === 'add_tool') {
                $stmt = $pdo->prepare("INSERT INTO tools (name, url, icon_url, description, category_id, tag_name, tag_color, health_method, health_url, health_codes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $url, $icon, $description, $categoryId, $tagName, $tagColor, $healthMethod, $healthUrl, $healthCodes]);
            } else {
                $toolId = inputId($_POST, 'tool_id');
                $stmt = $pdo->prepare("UPDATE tools SET name=?, url=?, icon_url=?, description=?, category_id=?, tag_name=?, tag_color=?, health_method=?, health_url=?, health_codes=? WHERE id=?");
                $stmt->execute([$name, $url, $icon, $description, $categoryId, $tagName, $tagColor, $healthMethod, $healthUrl, $healthCodes, $toolId]);
                $pdo->prepare("DELETE FROM health_cache WHERE tool_id = ?")->execute([$toolId]);
            }
            header("Location: admin.php"); exit;
        }

        if ($action === 'delete_tool') {
            $pdo->prepare("DELETE FROM tools WHERE id = ?")->execute([inputId($_POST, 'tool_id')]);
            header("Location: admin.php"); exit;
        }

        if ($action === 'reorder_tools') {
            saveSortOrder($pdo, 'tools', $_POST);
            jsonResponse(['status' => 'ok']);
        }

        if ($action === 'add_user' || $action === 'edit_user') {
            $username = validateUsername(inputString($_POST, 'username', 64, true));
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            validatePassword($password, $action === 'add_user');

            if ($action === 'add_user') {
                $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
                $stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT)]);
            } else {
                $userId = inputId($_POST, 'user_id');
                if ($password !== '') {
                    $stmt = $pdo->prepare("UPDATE users SET username=?, password=?, session_version=session_version+1 WHERE id=?");
                    $stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT), $userId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username=? WHERE id=?");
                    $stmt->execute([$username, $userId]);
                }
                if ((int) ($_SESSION['user_id'] ?? 0) === $userId) $_SESSION['username'] = $username;
            }
            header("Location: admin.php#user-panel"); exit;
        }

        if ($action === 'delete_user') {
            $userId = inputId($_POST, 'user_id');
            $pdo->prepare("DELETE FROM users WHERE id = ? AND (SELECT COUNT(*) FROM users) > 1")->execute([$userId]);
            header("Location: admin.php#user-panel"); exit;
        }

        if ($action === 'update_session') {
            $days = inputInt($_POST, 'session_days', 1, 365, 7);
            $pdo->prepare("UPDATE settings SET session_days=? WHERE id=1")->execute([$days]);
            header("Location: admin.php#user-panel"); exit;
        }
    } catch (InvalidArgumentException | JsonException $e) {
        if (http_response_code() !== 403) http_response_code(422);
        die(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(409);
        die(t('operation_failed'));
    }
}

// ==========================================
// BUSCAR DADOS PARA EXIBIR NA TELA
// ==========================================
$editMode = false; $editTool = null;
if (filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
    $stmt = $pdo->prepare("SELECT * FROM tools WHERE id = ?");
    $stmt->execute([(int) $_GET['edit']]);
    $editTool = $stmt->fetch();
    if ($editTool) $editMode = true;
}

$editUserMode = false; $editUser = null;
if (filter_var($_GET['edit_user'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id = ?");
    $stmt->execute([(int) $_GET['edit_user']]);
    $editUser = $stmt->fetch();
    if ($editUser) $editUserMode = true;
}

$settings = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch();
$categories = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, name ASC")->fetchAll();
$tools = $pdo->query("SELECT t.*, c.name as cat_name FROM tools t LEFT JOIN categories c ON t.category_id = c.id ORDER BY t.category_id ASC, t.sort_order ASC, t.name ASC")->fetchAll();
$usersList = $pdo->query("SELECT id, username FROM users ORDER BY username ASC")->fetchAll();

$currentLang = $settings['language'] ?? 'pt';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <!-- Developed with care by FACRF - https://github.com/facrf -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('manage_services') ?></title>
    <?php require __DIR__ . '/templates/head-assets.php'; ?>
</head>
<body>
    <div class="container">
        <header>
            <h1><?= t('manage_services') ?></h1>
            <div class="header-controls">
                
                <button type="button" class="theme-toggle-wrapper" onclick="toggleTheme()" title="<?= t('toggle_theme') ?>" aria-label="<?= t('toggle_theme') ?>">
                    <svg viewBox="0 0 24 24"><path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zm0 8c-1.65 0-3-1.35-3-3s1.35-3 3-3 3 1.35 3 3-1.35 3-3 3zm9-4h-2c-.55 0-1 .45-1 1s.45 1 1 1h2c.55 0 1-.45 1-1s-.45-1-1-1zM4 12c0 .55-.45 1-1 1H1c-.55 0-1-.45-1-1s.45-1 1-1h2c.55 0 1 .45 1 1zm7-9V1c0-.55-.45-1-1-1s-1 .45-1 1v2c0 .55.45 1 1 1s1-.45 1-1zm0 18v2c0 .55-.45 1 1 1s1-.45 1-1v-2c0-.55-.45-1-1-1s-1 .45-1 1zm7.66-13.88l1.41-1.41c.39-.39.39-1.03 0-1.41-.39-.39-1.03-.39-1.41 0l-1.41 1.41c-.39.39-.39 1.03 0 1.41.39.39 1.03.39 1.41 0zM4.93 19.07l1.41-1.41c.39-.39.39-1.03 0-1.41-.39-.39-1.03-.39-1.41 0l-1.41 1.41c-.39.39-.39 1.03 0 1.41.39.39 1.03.39 1.41 0zm14.14 0c.39.39 1.03.39 1.41 0 .39-.39.39-1.03 0-1.41l-1.41-1.41c-.39-.39-1.03-.39-1.41 0-.39.39-.39 1.03 0 1.41l1.41 1.41zM6.34 6.34c.39.39 1.03.39 1.41 0 .39-.39.39-1.03 0-1.41L6.34 3.51c-.39-.39-1.03-.39-1.41 0-.39.39-.39 1.03 0 1.41l1.41 1.42z"/></svg>
                    <div class="toggle-slot"><div class="toggle-button"></div></div>
                    <svg viewBox="0 0 24 24"><path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9c0-.46-.04-.92-.1-1.36-.98 1.37-2.58 2.26-4.4 2.26-3.03 0-5.5-2.47-5.5-5.5 0-1.82.89-3.42 2.26-4.4C12.92 3.04 12.46 3 12 3z"/></svg>
                </button>
                
                <div class="header-nav">
                    <a href="index.php" class="btn">← <?= t('dashboard') ?></a>
                    <a href="config.php" class="btn"><?= t('appearance_tabs') ?></a>

                    <form method="POST" action="login.php" style="display: inline; margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="btn btn-danger" style="margin-left: 10px;"><?= t('logout') ?></button>
                    </form>
                </div>
            </div>
        </header>

        <div class="admin-panel" id="form-panel">
            <h2><?= $editMode ? t('edit') . ' — ' . t('Nome do Serviço') : t('add_service') ?></h2>
            <form method="POST" action="admin.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="<?= $editMode ? 'edit_tool' : 'add_tool' ?>">
                <?php if ($editMode): ?><input type="hidden" name="tool_id" value="<?= $editTool['id'] ?>"><?php endif; ?>

                <div class="form-group">
                    <label for="admin-name-1"><?= t('Nome do Serviço') ?>:</label>
                    <input id="admin-name-1" type="text" name="name" maxlength="120" value="<?= $editMode ? htmlspecialchars($editTool['name'], ENT_QUOTES, 'UTF-8') : '' ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="admin-category_id-2"><?= t('Categoria / Aba') ?>:</label>
                    <select id="admin-category_id-2" name="category_id" required>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= ($editMode && $editTool['category_id'] == $cat['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="admin-url-3"><?= t('URL de Destino') ?>:</label>
                    <input id="admin-url-3" type="text" name="url" maxlength="2048" value="<?= $editMode ? htmlspecialchars($editTool['url'], ENT_QUOTES, 'UTF-8') : '' ?>" required>
                </div>
                <div class="form-group">
                    <label for="admin-icon_url-4"><?= t('Ícone') ?> (URL / /icons):</label>
                    <input id="admin-icon_url-4" type="text" name="icon_url" maxlength="2048" value="<?= $editMode ? htmlspecialchars($editTool['icon_url'], ENT_QUOTES, 'UTF-8') : '' ?>">
                </div>
                <div class="form-group">
                    <label for="admin-description-5"><?= t('Descrição Curta') ?>:</label>
                    <textarea id="admin-description-5" name="description" rows="2" maxlength="500"><?= $editMode ? htmlspecialchars($editTool['description'], ENT_QUOTES, 'UTF-8') : '' ?></textarea>
                </div>
                
                <fieldset class="health-settings">
                    <legend><?= t('health_settings') ?></legend>
                    <div class="form-group">
                        <label for="health_method"><?= t('health_method') ?></label>
                        <select id="health_method" name="health_method">
                            <?php foreach (['auto', 'http', 'tcp', 'ntp'] as $method): ?>
                            <option value="<?= $method ?>" <?= ($editTool['health_method'] ?? 'auto') === $method ? 'selected' : '' ?>><?= t('health_' . $method) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="health_url"><?= t('health_url') ?></label>
                        <input id="health_url" name="health_url" type="text" maxlength="2048" value="<?= htmlspecialchars($editTool['health_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <small><?= t('health_url_help') ?></small>
                    </div>
                    <div class="form-group">
                        <label for="health_codes"><?= t('health_codes') ?></label>
                        <input id="health_codes" name="health_codes" type="text" maxlength="100" value="<?= htmlspecialchars($editTool['health_codes'] ?? '200-399', ENT_QUOTES, 'UTF-8') ?>">
                        <small><?= t('health_codes_help') ?></small>
                    </div>
                </fieldset>

                <div>
                    <div style="display: flex; gap: 10px; margin-bottom: 1rem;">
                    <div class="form-group" style="flex: 1; margin-bottom: 0;">
                        <label for="admin-tag_name-6">Tag (<?= t('Opcional') ?>):</label>
                        <input id="admin-tag_name-6" type="text" name="tag_name" maxlength="30" value="<?= $editMode ? htmlspecialchars($editTool['tag_name'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?>" placeholder="EX: PROD">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="admin-tag_color-7"><?= t('Cor da Tag') ?>:</label>
                        <input id="admin-tag_color-7" type="color" name="tag_color" value="<?= $editMode ? htmlspecialchars($editTool['tag_color'] ?? '#007bff', ENT_QUOTES, 'UTF-8') : '#007bff' ?>">
                    </div>
                </div>
                
                <button type="submit" class="btn"><?= $editMode ? t('save_changes') : t('Adicionar') ?></button>
                    <?php if ($editMode): ?><a href="admin.php" class="btn"><?= t('Cancelar') ?></a><?php endif; ?>
                </div>
            </form>
        </div>

        <!-- PAINEL DE USUÁRIOS E SEGURANÇA -->
        <div class="admin-panel" id="user-panel">
            <h2><?= t('access_management') ?></h2>
            
            <form method="POST" style="margin-bottom: 2rem; padding: 1.5rem; background: rgba(0,0,0,0.2); border-radius: 8px; border: 1px solid rgba(255,255,255,0.05);">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="update_session">
                <div style="display:flex; gap:15px; align-items:flex-end; flex-wrap: wrap;">
                    <div class="form-group" style="flex:1; min-width: 200px; margin-bottom:0;">
                        <label for="admin-session_days-8"><?= t('Dias de validade da sessão') ?>:</label>
                        <input id="admin-session_days-8" type="number" name="session_days" value="<?= $settings['session_days'] ?? 7 ?>" min="1" max="365" required>
                    </div>
                    <button type="submit" class="btn"><?= t('save_changes') ?></button>
                </div>
            </form>

            <form method="POST" style="display:flex; gap:15px; align-items:flex-end; margin-bottom: 2rem; flex-wrap: wrap;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="<?= $editUserMode ? 'edit_user' : 'add_user' ?>">
                <?php if ($editUserMode): ?><input type="hidden" name="user_id" value="<?= $editUser['id'] ?>"><?php endif; ?>
                
                <div class="form-group" style="flex:1; min-width: 150px; margin-bottom:0;">
                    <label for="admin-username-9"><?= $editUserMode ? t('edit_user') : t('new_user') ?>:</label>
                    <input id="admin-username-9" type="text" name="username" maxlength="64" value="<?= $editUserMode ? htmlspecialchars($editUser['username'], ENT_QUOTES, 'UTF-8') : '' ?>" required>
                </div>
                <div class="form-group" style="flex:1; min-width: 150px; margin-bottom:0;">
                    <label for="admin-password-10"><?= $editUserMode ? t('new_password') : t('password') ?>:</label>
                    <input id="admin-password-10" type="password" name="password" minlength="10" maxlength="72" <?= $editUserMode ? '' : 'required' ?>>
                </div>
                <button type="submit" class="btn"><?= $editUserMode ? t('save_user') : t('create_user') ?></button>
                <?php if ($editUserMode): ?><a href="admin.php#user-panel" class="btn"><?= t('Cancelar') ?></a><?php endif; ?>
            </form>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th><?= t('username') ?></th>
                            <th style="width: 150px; text-align: right;"><?= t('Ações') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usersList as $usr): ?>
                            <tr style="<?= ($editUserMode && $editUser['id'] == $usr['id']) ? 'background: rgba(255,255,255,0.05);' : '' ?>">
                                <td style="font-weight:bold"><?= htmlspecialchars($usr['username'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <div class="action-buttons" style="justify-content: flex-end;">
                                        <a href="admin.php?edit_user=<?= $usr['id'] ?>#user-panel" class="btn" style="padding:0.3rem 0.6rem; font-size:0.8rem"><?= t('edit') ?></a>
                                        <?php if (count($usersList) > 1): ?>
                                            <form method="POST" style="margin:0;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?= $usr['id'] ?>">
                                                <button type="submit" class="btn btn-danger" style="padding:0.3rem 0.6rem; font-size:0.8rem" onclick="return confirm(<?= htmlspecialchars(json_encode(t('delete_user_confirm', ['name' => $usr['username']])), ENT_QUOTES, 'UTF-8') ?>);"><?= t('delete') ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- PAINEL DE SERVIÇOS -->
        <div class="admin-panel">
            <h2><?= t('Serviços Cadastrados') ?></h2>
            <div style="margin-bottom: 10px; font-size: 0.85rem; opacity: 0.8;"><?= t('Arraste as linhas para reordenar os serviços dentro das categorias.') ?></div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th><?= t('Ícone') ?></th>
                            <th><?= t('Nome do Serviço') ?></th>
                            <th><?= t('Categoria') ?></th>
                            <th><?= t('Ações') ?></th>
                        </tr>
                    </thead>
                    <tbody id="tools-tbody">
                        <?php foreach ($tools as $tool): ?>
                            <tr draggable="true" data-id="<?= $tool['id'] ?>" data-category="<?= (int) $tool['category_id'] ?>" class="draggable-row" style="<?= ($editMode && $editTool['id'] == $tool['id']) ? 'background: rgba(255,255,255,0.05);' : 'cursor: grab;' ?>">
                                <td>
                                    <?php $resIco = resolveIconUrl($tool['icon_url']); if(!empty($resIco)): ?>
                                        <img src="<?= $resIco ?>" style="width:32px; height:32px; object-fit:contain" alt="">
                                    <?php endif; ?>
                                </td>
                                <td style="font-weight:bold"><?= htmlspecialchars($tool['name'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($tool['tag_name'])): ?>
                                        <span style="background-color: <?= validatedColor((string) $tool['tag_color']) ?>; color: #fff; padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; margin-left: 8px;">
                                            <?= htmlspecialchars($tool['tag_name'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 0.85rem; opacity: 0.8;"><?= htmlspecialchars($tool['cat_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button type="button" class="btn move-row" data-direction="up" aria-label="<?= t('move_service_up') ?>" title="<?= t('move_service_up') ?>" style="padding:0.3rem 0.6rem">↑</button>
                                        <button type="button" class="btn move-row" data-direction="down" aria-label="<?= t('move_service_down') ?>" title="<?= t('move_service_down') ?>" style="padding:0.3rem 0.6rem">↓</button>
                                        <a href="admin.php?edit=<?= $tool['id'] ?>#form-panel" class="btn" style="padding:0.3rem 0.6rem; font-size:0.8rem"><?= t('edit') ?></a>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="delete_tool">
                                            <input type="hidden" name="tool_id" value="<?= $tool['id'] ?>">
                                            <button type="submit" class="btn btn-danger" style="padding:0.3rem 0.6rem; font-size:0.8rem" onclick="return confirm(<?= htmlspecialchars(json_encode(t('delete') . ' \'' . $tool['name'] . '\'?'), ENT_QUOTES, 'UTF-8') ?>);"><?= t('delete') ?></button>
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
            const tbody = document.getElementById('tools-tbody');
            const orderController = Portal.createOrderController(tbody ? [tbody] : [], 'admin.php', 'reorder_tools', 'tr.draggable-row');
            
            if (tbody) {
                tbody.querySelectorAll('.move-row').forEach(button => {
                    button.addEventListener('click', () => {
                        const row = button.closest('tr');
                        const category = row.dataset.category;
                        const siblings = Array.from(tbody.querySelectorAll(`tr[data-category="${category}"]`));
                        const index = siblings.indexOf(row);
                        const target = button.dataset.direction === 'up' ? siblings[index - 1] : siblings[index + 1];
                        if (!target) return;
                        if (button.dataset.direction === 'up') tbody.insertBefore(row, target);
                        else tbody.insertBefore(target, row);
                        saveOrder();
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
                            if (draggedRow.dataset.category !== this.dataset.category) return;
                            const bounding = this.getBoundingClientRect();
                            const offset = bounding.y + (bounding.height / 2);
                            if (e.clientY - offset > 0) {
                                tbody.insertBefore(draggedRow, this.nextSibling);
                            } else {
                                tbody.insertBefore(draggedRow, this);
                            }
                            saveOrder();
                        }
                    });
                });
            }

            function saveOrder() {
                orderController.save();
            }
        });
    </script>
</body>
</html>
