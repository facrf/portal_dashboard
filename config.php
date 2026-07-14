<?php
// config.php
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'update_settings') {
        $stmt = $pdo->prepare("UPDATE settings SET portal_name=?, favicon=?, bg_color=?, bg_image=?, text_color=? WHERE id=1");
        $stmt->execute([$_POST['portal_name'], $_POST['favicon'], $_POST['bg_color'], $_POST['bg_image'], $_POST['text_color']]);
        header("Location: config.php?success=1"); exit;
    }
    if (isset($_POST['action']) && $_POST['action'] === 'add_category') {
        $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)"); $stmt->execute([$_POST['cat_name']]); header("Location: config.php"); exit;
    }
    if (isset($_POST['action']) && $_POST['action'] === 'edit_category') {
        $stmt = $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?"); $stmt->execute([$_POST['cat_name'], $_POST['cat_id']]); header("Location: config.php"); exit;
    }
    if (isset($_POST['action']) && $_POST['action'] === 'delete_category') {
        $catId = $_POST['cat_id'];
        $fallbackCat = $pdo->query("SELECT id FROM categories WHERE id != $catId LIMIT 1")->fetchColumn();
        if (!$fallbackCat) { $pdo->exec("INSERT INTO categories (name) VALUES ('Geral')"); $fallbackCat = $pdo->lastInsertId(); }
        $pdo->prepare("UPDATE tools SET category_id = ? WHERE category_id = ?")->execute([$fallbackCat, $catId]);
        $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$catId]); header("Location: config.php"); exit;
    }
}

$editCatMode = false; $editCat = null;
if (isset($_GET['edit_cat'])) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?"); $stmt->execute([$_GET['edit_cat']]); $editCat = $stmt->fetch(); if ($editCat) $editCatMode = true;
}

$settings = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch();
$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();

$bgColorValue = !empty($settings['bg_color']) ? htmlspecialchars($settings['bg_color']) : '#000000';
$textColorValue = !empty($settings['text_color']) ? htmlspecialchars($settings['text_color']) : '#ffffff';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Aparência & Abas</title>
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <style>:root { --bg-color: <?= $bgColorValue ?>; --bg-image: url('<?= htmlspecialchars($settings['bg_image']) ?>'); --text-color: <?= $textColorValue ?>; }</style>
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
            <h1>Aparência & Categorias</h1>
            <div class="header-controls">
                <div class="theme-toggle-wrapper" onclick="toggleTheme()" title="Modo Claro/Escuro">
                    <svg viewBox="0 0 24 24"><path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zm0 8c-1.65 0-3-1.35-3-3s1.35-3 3-3 3 1.35 3 3-1.35 3-3 3zm9-4h-2c-.55 0-1 .45-1 1s.45 1 1 1h2c.55 0 1-.45 1-1s-.45-1-1-1zM4 12c0 .55-.45 1-1 1H1c-.55 0-1-.45-1-1s.45-1 1-1h2c.55 0 1 .45 1 1zm7-9V1c0-.55-.45-1-1-1s-1 .45-1 1v2c0 .55.45 1 1 1s1-.45 1-1zm0 18v2c0 .55-.45 1 1 1s1-.45 1-1v-2c0-.55-.45-1-1-1s-1 .45-1 1zm7.66-13.88l1.41-1.41c.39-.39.39-1.03 0-1.41-.39-.39-1.03-.39-1.41 0l-1.41 1.41c-.39.39-.39 1.03 0 1.41.39.39 1.03.39 1.41 0zM4.93 19.07l1.41-1.41c.39-.39.39-1.03 0-1.41-.39-.39-1.03-.39-1.41 0l-1.41 1.41c-.39.39-.39 1.03 0 1.41.39.39 1.03.39 1.41 0zm14.14 0c.39.39 1.03.39 1.41 0 .39-.39.39-1.03 0-1.41l-1.41-1.41c-.39-.39-1.03-.39-1.41 0-.39.39-.39 1.03 0 1.41l1.41 1.41zM6.34 6.34c.39.39 1.03.39 1.41 0 .39-.39.39-1.03 0-1.41L6.34 3.51c-.39-.39-1.03-.39-1.41 0-.39.39-.39 1.03 0 1.41l1.41 1.42z"/></svg>
                    <div class="toggle-slot"><div class="toggle-button"></div></div>
                    <svg viewBox="0 0 24 24"><path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9c0-.46-.04-.92-.1-1.36-.98 1.37-2.58 2.26-4.4 2.26-3.03 0-5.5-2.47-5.5-5.5 0-1.82.89-3.42 2.26-4.4C12.92 3.04 12.46 3 12 3z"/></svg>
                </div>
                <div class="header-nav">
                    <a href="index.php" class="btn">← Dashboard</a>
                    <a href="admin.php" class="btn">Ferramentas</a>
                </div>
            </div>
        </header>

        <div class="admin-panel">
            <h2>Configurações do Portal</h2>
            <form method="POST">
                <input type="hidden" name="action" value="update_settings">
                
                <div class="form-group">
                    <label>Nome do Portal:</label>
                    <input type="text" name="portal_name" value="<?= htmlspecialchars($settings['portal_name']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Favicon (Ícone do Navegador - /icons ou URL):</label>
                    <input type="text" name="favicon" value="<?= htmlspecialchars($settings['favicon']) ?>">
                </div>

                <div style="display: flex; gap: 2rem; flex-wrap: wrap; margin-bottom: 1rem;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Cor de Fundo:</label>
                        <input type="color" name="bg_color" value="<?= $bgColorValue ?>" required>
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Cor do Texto Principal:</label>
                        <input type="color" name="text_color" value="<?= $textColorValue ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>URL / Nome Imagem de Fundo:</label>
                    <input type="text" name="bg_image" value="<?= htmlspecialchars($settings['bg_image']) ?>" placeholder="Ex: fundo.jpg ou http://site.com/img.png">
                </div>
                
                <button type="submit" class="btn">Salvar Configurações</button>
            </form>
        </div>

        <div class="admin-panel" id="cat-panel">
            <h2>Gerenciar Categorias (Abas)</h2>
            <form method="POST" style="display:flex; gap:10px; align-items:flex-end; margin-bottom: 2rem;">
                <input type="hidden" name="action" value="<?= $editCatMode ? 'edit_category' : 'add_category' ?>">
                <?php if ($editCatMode): ?><input type="hidden" name="cat_id" value="<?= $editCat['id'] ?>"><?php endif; ?>
                
                <div class="form-group" style="flex:1; margin-bottom:0;">
                    <label><?= $editCatMode ? 'Editar Nome da Categoria:' : 'Nova Categoria:' ?></label>
                    <input type="text" name="cat_name" value="<?= $editCatMode ? htmlspecialchars($editCat['name']) : '' ?>" required>
                </div>
                <button type="submit" class="btn"><?= $editCatMode ? 'Salvar Aba' : 'Adicionar' ?></button>
                <?php if ($editCatMode): ?><a href="config.php" class="btn">Cancelar</a><?php endif; ?>
            </form>

            <table>
                <thead><tr><th>Nome da Categoria</th><th>Ações</th></tr></thead>
                <tbody>
                    <?php foreach ($categories as $cat): ?>
                        <tr style="<?= ($editCatMode && $editCat['id'] == $cat['id']) ? 'background: rgba(255,255,255,0.05);' : '' ?>">
                            <td style="font-weight: bold;"><?= htmlspecialchars($cat['name']) ?></td>
                            <td>
                                <div class="action-buttons">
                                    <a href="config.php?edit_cat=<?= $cat['id'] ?>#cat-panel" class="btn" style="padding:0.3rem 0.6rem; font-size:0.8rem">Editar</a>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="action" value="delete_category">
                                        <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
                                        <button type="submit" class="btn btn-danger" style="padding:0.3rem 0.6rem; font-size:0.8rem" onclick="return confirm('Excluir aba? Serviços serão movidos para outra.');">Excluir</button>
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
                    // Não aplica no botão de excluir (btn-danger)
                    if (btn && !btn.classList.contains('btn-danger') && !btn.classList.contains('btn-glow')) {
                        btn.classList.add('btn-glow');
                    }
                });
            });
        });
    </script>
</body>
</html>