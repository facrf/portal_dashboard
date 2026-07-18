<?php
// admin.php
require_once 'db.php';

// ==========================================
// EXPORTAÇÃO (Método GET, mas seguro pois apenas lê dados)
// ==========================================
if (isset($_GET['export']) && $_GET['export'] === 'json') {
    $exportData = [
        'settings' => $pdo->query("SELECT * FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC),
        'categories' => $pdo->query("SELECT * FROM categories")->fetchAll(PDO::FETCH_ASSOC),
        'tools' => $pdo->query("SELECT * FROM tools")->fetchAll(PDO::FETCH_ASSOC)
    ];
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="portal_backup.json"');
    echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ==========================================
// PROCESSAMENTO DE AÇÕES DE ESCRITA COM PROTEÇÃO CSRF
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Validação do Token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("<div style='background: #ffebe9; color: #ff4d4d; padding: 20px; text-align: center; border-radius: 8px; margin: 40px auto; max-width: 400px;'>⚠️ Erro CSRF. Ação não autorizada.</div>");
    }

    $action = $_POST['action'] ?? '';

    // Ação: Adicionar ou Editar Serviço
    if ($action === 'save_tool') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $name = $_POST['name'];
        $url = $_POST['url'];
        $icon_url = $_POST['icon_url'];
        $desc = $_POST['description'] ?? '';
        $catId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : 1;

        if ($id) {
            $stmt = $pdo->prepare("UPDATE tools SET name=?, url=?, icon_url=?, description=?, category_id=? WHERE id=?");
            $stmt->execute([$name, $url, $icon_url, $desc, $catId, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO tools (name, url, icon_url, description, category_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $url, $icon_url, $desc, $catId]);
        }
        header("Location: admin.php");
        exit;
    }

    // Ação: Excluir Serviço
    if ($action === 'delete_tool' && !empty($_POST['id'])) {
        $stmt = $pdo->prepare("DELETE FROM tools WHERE id = ?");
        $stmt->execute([(int)$_POST['id']]);
        header("Location: admin.php");
        exit;
    }

    // Ação: Adicionar Categoria
    if ($action === 'add_category' && !empty($_POST['category_name'])) {
        $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->execute([$_POST['category_name']]);
        header("Location: admin.php");
        exit;
    }

    // Ação: Excluir Categoria (Com Fallback Seguro para ID 1)
    if ($action === 'delete_category' && !empty($_POST['id'])) {
        $catId = (int)$_POST['id'];
        if ($catId !== 1) { // Protege a categoria Geral de ser apagada
            $pdo->exec("UPDATE tools SET category_id = 1 WHERE category_id = $catId");
            $pdo->exec("DELETE FROM categories WHERE id = $catId");
        }
        header("Location: admin.php");
        exit;
    }

    // Ação: Importar Backup
    if ($action === 'import_data' && isset($_FILES['import_file'])) {
        $fileData = file_get_contents($_FILES['import_file']['tmp_name']);
        $json = json_decode($fileData, true);
        if ($json) {
            // Se for Heimdall (apps array)
            if (isset($json['apps'])) {
                foreach ($json['apps'] as $app) {
                    $stmt = $pdo->prepare("INSERT INTO tools (name, url, icon_url, category_id) VALUES (?, ?, ?, 1)");
                    $stmt->execute([$app['title'] ?? 'App', $app['url'] ?? '#', $app['icon'] ?? '']);
                }
            }
            // Se for Backup Nativo (tools array)
            elseif (isset($json['tools'])) {
                foreach ($json['tools'] as $tool) {
                    $stmt = $pdo->prepare("INSERT INTO tools (name, url, icon_url, description, category_id) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$tool['name'], $tool['url'], $tool['icon_url'], $tool['description'], $tool['category_id'] ?? 1]);
                }
            }
        }
        header("Location: admin.php");
        exit;
    }
}

// ==========================================
// CARREGAMENTO DOS DADOS PARA EXIBIÇÃO
// ==========================================
$settings = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$categories = $pdo->query("SELECT * FROM categories ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$tools = $pdo->query("SELECT tools.*, categories.name as category_name FROM tools LEFT JOIN categories ON tools.category_id = categories.id ORDER BY tools.name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($langCode) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel de Administração</title>
    
    <!-- FAVICON -->
    <?php $faviconUrl = resolveIconUrl($settings['favicon']); if(!empty($faviconUrl)): ?>
        <link rel="icon" href="<?= $faviconUrl ?>">
    <?php endif; ?>

    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <style>:root { --bg-color: <?= htmlspecialchars($settings['bg_color']) ?>; --bg-image: url('<?= htmlspecialchars($settings['bg_image']) ?>'); --text-color: <?= htmlspecialchars($settings['text_color']) ?>; }</style>
</head>
<body>
    <header class="topbar">
        <h2>Gerenciamento de Serviços</h2>
        <div class="topbar-actions">
            <a href="index.php" class="btn">← Dashboard</a>
            <a href="config.php" class="btn">Aparência</a>
        </div>
    </header>

    <main class="container">
        <!-- BLOCO 1: NOVO SERVIÇO -->
        <section class="admin-section">
            <h3>Adicionar / Editar Serviço</h3>
            <form method="POST" action="admin.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save_tool">
                <input type="hidden" name="id" value="">
                
                <div class="form-group grid-2-col">
                    <div><label>Nome:</label><input type="text" name="name" required></div>
                    <div>
                        <label>Categoria:</label>
                        <select name="category_id">
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group"><label>URL:</label><input type="url" name="url" required></div>
                <div class="form-group"><label>Ícone (URL ou arquivo local em /icons):</label><input type="text" name="icon_url"></div>
                <div class="form-group"><label>Descrição:</label><input type="text" name="description"></div>
                <button type="submit" class="btn primary">Salvar Serviço</button>
            </form>
        </section>

        <!-- BLOCO 2: CATEGORIAS -->
        <section class="admin-section">
            <h3>Categorias</h3>
            <form method="POST" action="admin.php" style="display:flex; gap:10px; margin-bottom: 20px;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="add_category">
                <input type="text" name="category_name" placeholder="Nova Categoria" required style="flex:1;">
                <button type="submit" class="btn primary">Adicionar</button>
            </form>
            <ul style="list-style: none; padding: 0;">
                <?php foreach ($categories as $cat): ?>
                    <li style="display:flex; justify-content:space-between; align-items: center; margin-bottom:10px; padding: 10px; background: rgba(0,0,0,0.1); border-radius: 8px;">
                        <?= htmlspecialchars($cat['name']) ?>
                        <?php if($cat['id'] != 1): ?>
                            <!-- EXCLUSÃO VIA FORMULÁRIO POST SEGURO CONTRA CSRF -->
                            <form method="POST" action="admin.php" style="display:inline; margin:0;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                <button type="submit" class="btn" style="background:#dc3545; padding: 5px 10px; font-size: 0.9em;" onclick="return confirm('Excluir? Serviços voltarão para Geral.')">Excluir</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <!-- BLOCO 3: LISTA DE SERVIÇOS -->
        <section class="admin-section">
            <h3>Serviços Cadastrados</h3>
            <ul style="list-style: none; padding: 0;">
                <?php foreach ($tools as $tool): ?>
                    <li style="display:flex; justify-content:space-between; align-items: center; margin-bottom:10px; background: rgba(0,0,0,0.2); padding: 10px; border-radius: 8px;">
                        <div>
                            <strong><?= htmlspecialchars($tool['name']) ?></strong> <small>(<?= htmlspecialchars($tool['category_name']) ?>)</small><br>
                            <small><?= htmlspecialchars($tool['url']) ?></small>
                        </div>
                        <div style="display:flex; gap: 10px;">
                            <!-- EXCLUSÃO DE FERRAMENTA VIA FORM POST -->
                            <form method="POST" action="admin.php" style="display:inline; margin:0;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="delete_tool">
                                <input type="hidden" name="id" value="<?= $tool['id'] ?>">
                                <button type="submit" class="btn" style="background:#dc3545; padding: 5px 10px; font-size: 0.9em;" onclick="return confirm('Excluir este serviço?')">Excluir</button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <!-- BLOCO 4: BACKUP E IMPORTAÇÃO -->
        <section class="admin-section">
            <h3>Backup e Importação</h3>
            <div style="display:flex; gap: 20px; align-items: flex-end; flex-wrap: wrap;">
                <div>
                    <a href="admin.php?export=json" class="btn primary">Baixar Backup (JSON)</a>
                </div>
                <form method="POST" action="admin.php" enctype="multipart/form-data" style="display:flex; gap: 10px; align-items: center;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="import_data">
                    <input type="file" name="import_file" accept=".json" required>
                    <button type="submit" class="btn">Importar</button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>