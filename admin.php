<?php
// admin.php
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add_tool') {
        $stmt = $pdo->prepare("INSERT INTO tools (name, url, icon_url, description, category_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$_POST['name'], $_POST['url'], $_POST['icon_url'], $_POST['description'], $_POST['category_id']]);
        header("Location: admin.php"); exit;
    }
    if (isset($_POST['action']) && $_POST['action'] === 'edit_tool') {
        $stmt = $pdo->prepare("UPDATE tools SET name=?, url=?, icon_url=?, description=?, category_id=? WHERE id=?");
        $stmt->execute([$_POST['name'], $_POST['url'], $_POST['icon_url'], $_POST['description'], $_POST['category_id'], $_POST['tool_id']]);
        header("Location: admin.php"); exit;
    }
    if (isset($_POST['action']) && $_POST['action'] === 'delete_tool') {
        $pdo->prepare("DELETE FROM tools WHERE id=?")->execute([$_POST['tool_id']]);
        header("Location: admin.php"); exit;
    }
}

$editMode = false; $editTool = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM tools WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editTool = $stmt->fetch();
    if ($editTool) $editMode = true;
}

$settings = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch();
$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
$tools = $pdo->query("SELECT t.*, c.name as cat_name FROM tools t LEFT JOIN categories c ON t.category_id = c.id ORDER BY t.name ASC")->fetchAll();

$currentLang = $settings['language'] ?? 'pt';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang) ?>">
<head>
    <meta charset="UTF-8">
    <title><?= t('manage_services') ?></title>
    
    <!-- INÍCIO DO FAVICON -->
    <?php $favicon = resolveIconUrl($settings['favicon']); if(!empty($favicon)): ?>
        <link rel="icon" href="<?= $favicon ?>">
    <?php endif; ?>
    <!-- FIM DO FAVICON -->

    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <style>:root { --bg-color: <?= htmlspecialchars($settings['bg_color']) ?>; --bg-image: url('<?= htmlspecialchars($settings['bg_image']) ?>'); --text-color: <?= htmlspecialchars($settings['text_color']) ?>; }</style>
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
            <h1><?= t('manage_services') ?></h1>
            <div class="header-controls">
                
                <div class="theme-toggle-wrapper" onclick="toggleTheme()" title="Modo Claro/Escuro">
                    <svg viewBox="0 0 24 24"><path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zm0 8c-1.65 0-3-1.35-3-3s1.35-3 3-3 3 1.35 3 3-1.35 3-3 3zm9-4h-2c-.55 0-1 .45-1 1s.45 1 1 1h2c.55 0 1-.45 1-1s-.45-1-1-1zM4 12c0 .55-.45 1-1 1H1c-.55 0-1-.45-1-1s.45-1 1-1h2c.55 0 1 .45 1 1zm7-9V1c0-.55-.45-1-1-1s-1 .45-1 1v2c0 .55.45 1 1 1s1-.45 1-1zm0 18v2c0 .55-.45 1 1 1s1-.45 1-1v-2c0-.55-.45-1-1-1s-1 .45-1 1zm7.66-13.88l1.41-1.41c.39-.39.39-1.03 0-1.41-.39-.39-1.03-.39-1.41 0l-1.41 1.41c-.39.39-.39 1.03 0 1.41.39.39 1.03.39 1.41 0zM4.93 19.07l1.41-1.41c.39-.39.39-1.03 0-1.41-.39-.39-1.03-.39-1.41 0l-1.41 1.41c-.39.39-.39 1.03 0 1.41.39.39 1.03.39 1.41 0zm14.14 0c.39.39 1.03.39 1.41 0 .39-.39.39-1.03 0-1.41l-1.41-1.41c-.39-.39-1.03-.39-1.41 0-.39.39-.39 1.03 0 1.41l1.41 1.41zM6.34 6.34c.39.39 1.03.39 1.41 0 .39-.39.39-1.03 0-1.41L6.34 3.51c-.39-.39-1.03-.39-1.41 0-.39.39-.39 1.03 0 1.41l1.41 1.42z"/></svg>
                    <div class="toggle-slot"><div class="toggle-button"></div></div>
                    <svg viewBox="0 0 24 24"><path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9c0-.46-.04-.92-.1-1.36-.98 1.37-2.58 2.26-4.4 2.26-3.03 0-5.5-2.47-5.5-5.5 0-1.82.89-3.42 2.26-4.4C12.92 3.04 12.46 3 12 3z"/></svg>
                </div>
                
                <div class="header-nav">
                    <a href="index.php" class="btn">← <?= t('dashboard') ?></a>
                    <a href="config.php" class="btn"><?= t('appearance_tabs') ?></a>
                </div>
            </div>
        </header>

        <div class="admin-panel" id="form-panel">
            <h2><?= $editMode ? t('edit') . ' Serviço' : t('add_service') ?></h2>
            <form method="POST" action="admin.php">
                <input type="hidden" name="action" value="<?= $editMode ? 'edit_tool' : 'add_tool' ?>">
                <?php if ($editMode): ?><input type="hidden" name="tool_id" value="<?= $editTool['id'] ?>"><?php endif; ?>

                <div class="form-group">
                    <label><?= t('Nome do Serviço') ?>:</label>
                    <input type="text" name="name" value="<?= $editMode ? htmlspecialchars($editTool['name']) : '' ?>" required>
                </div>
                
                <div class="form-group">
                    <label><?= t('Categoria / Aba') ?>:</label>
                    <select name="category_id" required>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= ($editMode && $editTool['category_id'] == $cat['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label><?= t('URL de Destino') ?>:</label>
                    <input type="text" name="url" value="<?= $editMode ? htmlspecialchars($editTool['url']) : '' ?>" required>
                </div>
                <div class="form-group">
                    <label><?= t('Ícone') ?> (URL / /icons):</label>
                    <input type="text" name="icon_url" value="<?= $editMode ? htmlspecialchars($editTool['icon_url']) : '' ?>">
                </div>
                <div class="form-group">
                    <label><?= t('Descrição Curta') ?>:</label>
                    <textarea name="description" rows="2"><?= $editMode ? htmlspecialchars($editTool['description']) : '' ?></textarea>
                </div>
                
                <div>
                    <button type="submit" class="btn"><?= $editMode ? t('save_changes') : t('add_service') ?></button>
                    <?php if ($editMode): ?><a href="admin.php" class="btn"><?= t('Cancelar') ?></a><?php endif; ?>
                </div>
            </form>
        </div>

        <div class="admin-panel">
            <h2><?= t('Serviços Cadastrados') ?></h2>
            <table>
                <thead>
                    <tr>
                        <th><?= t('Ícone') ?></th>
                        <th><?= t('Nome do Serviço') ?></th>
                        <th><?= t('Categoria / Aba') ?></th>
                        <th><?= t('Ações') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tools as $tool): ?>
                        <tr style="<?= ($editMode && $editTool['id'] == $tool['id']) ? 'background: rgba(255,255,255,0.05);' : '' ?>">
                            <td>
                                <?php $resIco = resolveIconUrl($tool['icon_url']); if(!empty($resIco)): ?>
                                    <img src="<?= $resIco ?>" style="width:32px; height:32px; object-fit:contain" alt="">
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:bold"><?= htmlspecialchars($tool['name']) ?></td>
                            <td><?= htmlspecialchars($tool['cat_name']) ?></td>
                            <td>
                                <div class="action-buttons">
                                    <a href="admin.php?edit=<?= $tool['id'] ?>#form-panel" class="btn" style="padding:0.3rem 0.6rem; font-size:0.8rem"><?= t('edit') ?></a>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="action" value="delete_tool">
                                        <input type="hidden" name="tool_id" value="<?= $tool['id'] ?>">
                                        <button type="submit" class="btn btn-danger" style="padding:0.3rem 0.6rem; font-size:0.8rem" onclick="return confirm('<?= t('delete') ?> \'<?= htmlspecialchars($tool['name']) ?>\'?');"><?= t('delete') ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

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
        });
    </script>
</body>
</html>