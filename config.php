<?php
// config.php
require_once 'db.php';

// ==========================================
// PROCESSAMENTO DE FORMULÁRIOS (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // PROTEÇÃO CSRF GLOBAL DO CONFIG.PHP
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Ação bloqueada: Token CSRF inválido.");
    }

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
                            $pdo->beginTransaction();

                            $pdo->exec("DELETE FROM tools");
                            $pdo->exec("DELETE FROM categories");
                            
                            $catMap = []; 
                            foreach ($json['categories'] as $c) {
                                $name = $c['name'] ?? 'Categoria Recuperada';
                                $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
                                $stmt->execute([$name]);
                                $catMap[$c['id'] ?? 0] = $pdo->lastInsertId();
                            }
                            
                            foreach ($json['tools'] as $t) {
                                $newCatId = $catMap[$t['category_id'] ?? null] ?? 1;
                                $name = $t['name'] ?? 'Serviço Recuperado';
                                $url = $t['url'] ?? '';
                                $icon = $t['icon_url'] ?? '';
                                $desc = $t['description'] ?? '';

                                $stmt = $pdo->prepare("INSERT INTO tools (name, url, icon_url, description, category_id) VALUES (?, ?, ?, ?, ?)");
                                $stmt->execute([$name, $url, $icon, $desc, $newCatId]);
                            }
                            
                            if (isset($json['settings']) && is_array($json['settings'])) {
                                $s = $json['settings'];
                                $footer = $s['footer_text'] ?? '';
                                
                                $allowedLangs = ['pt', 'es', 'en'];
                                $importLang = (isset($s['language']) && in_array($s['language'], $allowedLangs)) ? $s['language'] : 'pt';

                                $stmt = $pdo->prepare("UPDATE settings SET portal_name=?, favicon=?, bg_color=?, bg_image=?, text_color=?, language=?, footer_text=? WHERE id=1");
                                $stmt->execute([
                                    $s['portal_name'] ?? 'Meu Portal', 
                                    $s['favicon'] ?? '', 
                                    $s['bg_color'] ?? '#000000', 
                                    $s['bg_image'] ?? '', 
                                    $s['text_color'] ?? '#ffffff', 
                                    $importLang, 
                                    $footer
                                ]);
                            }

                            $pdo->commit();

                        } catch (Exception $e) {
                            $pdo->rollBack();
                            die("Erro na importação. O banco de dados foi preservado. Detalhe: " . htmlspecialchars($e->getMessage()));
                        }
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
                        $pdo->exec("INSERT INTO categories (name) VALUES ('Importado: Heimdall')");
                        $catId = $pdo->lastInsertId();
                        
                        foreach ($items as $item) {
                            if (!is_array($item)) continue;
                            $name = $item['title'] ?? $item['name'] ?? 'App';
                            $url = $item['url'] ?? '';
                            $icon = $item['icon'] ?? '';
                            $desc = $item['description'] ?? '';
                            
                            if (!empty($url) || !empty($name)) {
                                $stmt = $pdo->prepare("INSERT INTO tools (name, url, icon_url, description, category_id) VALUES (?, ?, ?, ?, ?)");
                                $stmt->execute([$name, $url, $icon, $desc, $catId]);
                            }
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
                $currentCatId = 1;
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
                            $stmt->execute([$currentApp, $currentAppProps['href'] ?? '', $currentAppProps['icon'] ?? '', $currentAppProps['description'] ?? '', $currentCatId]);
                            $currentApp = null;
                            $currentAppProps = [];
                        }

                        $catOrAppName = trim($m[1], " '\"");
                        
                        if ($indent === 0) {
                            $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
                            $stmt->execute([$catOrAppName . ' (Homepage)']);
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
                    $stmt = $pdo->prepare("INSERT INTO tools (name, url, icon_url, description, category_id) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$currentApp, $currentAppProps['href'] ?? '', $currentAppProps['icon'] ?? '', $currentAppProps['description'] ?? '', $currentCatId]);
                }
            }
            header("Location: config.php?import_success=1"); 
            exit;
        }
    }

    // Salvar Configurações Visuais
    if (isset($_POST['action']) && $_POST['action'] === 'update_settings') {
        
        $allowedLangs = ['pt', 'es', 'en'];
        $lang = in_array($_POST['language'], $allowedLangs) ? $_POST['language'] : 'pt';

        $stmt = $pdo->prepare("UPDATE settings SET portal_name=?, favicon=?, bg_color=?, bg_image=?, text_color=?, language=? WHERE id=1");
        $stmt->execute([$_POST['portal_name'], $_POST['favicon'], $_POST['bg_color'], $_POST['bg_image'], $_POST['text_color'], $lang]);
        
        header("Location: config.php?success=1"); exit;
    }
    
    // Ações de Categoria
    if (isset($_POST['action']) && $_POST['action'] === 'add_category') {
        $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)"); $stmt->execute([$_POST['cat_name']]); header("Location: config.php"); exit;
    }
    if (isset($_POST['action']) && $_POST['action'] === 'edit_category') {
        $stmt = $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?"); $stmt->execute([$_POST['cat_name'], $_POST['cat_id']]); header("Location: config.php"); exit;
    }
    
    // Excluir Categoria (COM CORREÇÃO SQL INJECTION)
    if (isset($_POST['action']) && $_POST['action'] === 'delete_category') {
        $catId = $_POST['cat_id'];
        
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
}

$editCatMode = false; $editCat = null;
if (isset($_GET['edit_cat'])) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?"); $stmt->execute([$_GET['edit_cat']]); $editCat = $stmt->fetch(); if ($editCat) $editCatMode = true;
}

$settings = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch();
$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();

$bgColorValue = !empty($settings['bg_color']) ? htmlspecialchars($settings['bg_color']) : '#000000';
$textColorValue = !empty($settings['text_color']) ? htmlspecialchars($settings['text_color']) : '#ffffff';
$currentLang = $settings['language'] ?? 'pt';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang) ?>">
<head>
    <meta charset="UTF-8">
    <title><?= t('appearance_tabs') ?></title>
    
    <!-- INÍCIO DO FAVICON -->
    <?php $favicon = resolveIconUrl($settings['favicon']); if(!empty($favicon)): ?>
        <link rel="icon" href="<?= $favicon ?>">
    <?php endif; ?>
    <!-- FIM DO FAVICON -->

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
            <h1><?= t('appearance_tabs') ?></h1>
            <div class="header-controls">
                <div class="theme-toggle-wrapper" onclick="toggleTheme()" title="Modo Claro/Escuro">
                    <svg viewBox="0 0 24 24"><path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zm0 8c-1.65 0-3-1.35-3-3s1.35-3 3-3 3 1.35 3 3-1.35 3-3 3zm9-4h-2c-.55 0-1 .45-1 1s.45 1 1 1h2c.55 0 1-.45 1-1s-.45-1-1-1zM4 12c0 .55-.45 1-1 1H1c-.55 0-1-.45-1-1s.45-1 1-1h2c.55 0 1 .45 1 1zm7-9V1c0-.55-.45-1-1-1s-1 .45-1 1v2c0 .55.45 1 1 1s1-.45 1-1zm0 18v2c0 .55-.45 1 1 1s1-.45 1-1v-2c0-.55-.45-1-1-1s-1 .45-1 1zm7.66-13.88l1.41-1.41c.39-.39.39-1.03 0-1.41-.39-.39-1.03-.39-1.41 0l-1.41 1.41c-.39.39-.39 1.03 0 1.41.39.39 1.03.39 1.41 0zM4.93 19.07l1.41-1.41c.39-.39.39-1.03 0-1.41-.39-.39-1.03-.39-1.41 0l-1.41 1.41c-.39.39-.39 1.03 0 1.41.39.39 1.03.39 1.41 0zm14.14 0c.39.39 1.03.39 1.41 0 .39-.39.39-1.03 0-1.41l-1.41-1.41c-.39-.39-1.03-.39-1.41 0-.39.39-.39 1.03 0 1.41l1.41 1.41zM6.34 6.34c.39.39 1.03.39 1.41 0 .39-.39.39-1.03 0-1.41L6.34 3.51c-.39-.39-1.03-.39-1.41 0-.39.39-.39 1.03 0 1.41l1.41 1.42z"/></svg>
                    <div class="toggle-slot"><div class="toggle-button"></div></div>
                    <svg viewBox="0 0 24 24"><path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9c0-.46-.04-.92-.1-1.36-.98 1.37-2.58 2.26-4.4 2.26-3.03 0-5.5-2.47-5.5-5.5 0-1.82.89-3.42 2.26-4.4C12.92 3.04 12.46 3 12 3z"/></svg>
                </div>
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
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="update_settings">
                
                <div class="form-group">
                    <label><?= t('portal_name') ?>:</label>
                    <input type="text" name="portal_name" value="<?= htmlspecialchars($settings['portal_name']) ?>" required>
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
                    <input type="text" name="favicon" value="<?= htmlspecialchars($settings['favicon']) ?>">
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
                    <input type="text" name="bg_image" value="<?= htmlspecialchars($settings['bg_image']) ?>">
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
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
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
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="<?= $editCatMode ? 'edit_category' : 'add_category' ?>">
                <?php if ($editCatMode): ?><input type="hidden" name="cat_id" value="<?= $editCat['id'] ?>"><?php endif; ?>
                
                <div class="form-group" style="flex:1; margin-bottom:0;">
                    <label><?= $editCatMode ? t('Editar Nome da Categoria') . ':' : t('Nova Categoria') . ':' ?></label>
                    <input type="text" name="cat_name" value="<?= $editCatMode ? htmlspecialchars($editCat['name']) : '' ?>" required>
                </div>
                <button type="submit" class="btn"><?= $editCatMode ? t('save_changes') : t('Adicionar') ?></button>
                <?php if ($editCatMode): ?><a href="config.php" class="btn"><?= t('Cancelar') ?></a><?php endif; ?>
            </form>

            <table>
                <thead><tr><th><?= t('Nome da Categoria') ?></th><th><?= t('Ações') ?></th></tr></thead>
                <tbody>
                    <?php foreach ($categories as $cat): ?>
                        <tr style="<?= ($editCatMode && $editCat['id'] == $cat['id']) ? 'background: rgba(255,255,255,0.05);' : '' ?>">
                            <td style="font-weight: bold;"><?= htmlspecialchars($cat['name']) ?></td>
                            <td>
                                <div class="action-buttons">
                                    <a href="config.php?edit_cat=<?= $cat['id'] ?>#cat-panel" class="btn" style="padding:0.3rem 0.6rem; font-size:0.8rem"><?= t('edit') ?></a>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
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