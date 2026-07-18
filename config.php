<?php
// config.php
require_once 'db.php';

// ==========================================
// PROCESSAMENTO DE FORMULÁRIO COM PROTEÇÃO CSRF
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Validação do Token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("
            <div style='background: rgba(220, 53, 69, 0.15); border: 1px solid rgba(220, 53, 69, 0.3); color: #ff4d4d; padding: 20px; text-align: center; border-radius: 8px; max-width: 400px; margin: 40px auto; font-family: sans-serif;'>
                ⚠️ Ação bloqueada: Token CSRF inválido ou expirado. Volte e recarregue a página.
            </div>
        ");
    }

    $portal_name = $_POST['portal_name'] ?? 'Meu Portal';
    $language    = $_POST['language'] ?? 'pt';
    $favicon     = $_POST['favicon'] ?? '';
    $bg_color    = $_POST['bg_color'] ?? '#1e1e2e';
    $bg_image    = $_POST['bg_image'] ?? '';
    $text_color  = $_POST['text_color'] ?? '#cdd6f4';
    
    $stmt = $pdo->prepare("UPDATE settings SET portal_name=?, language=?, favicon=?, bg_color=?, bg_image=?, text_color=? WHERE id=1");
    $stmt->execute([$portal_name, $language, $favicon, $bg_color, $bg_image, $text_color]);
    
    header("Location: config.php?success=1");
    exit;
}

$settings = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($langCode) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('appearance_tabs') ?? 'Configurações' ?></title>
    
    <!-- FAVICON -->
    <?php $faviconUrl = resolveIconUrl($settings['favicon']); if(!empty($faviconUrl)): ?>
        <link rel="icon" href="<?= $faviconUrl ?>">
    <?php endif; ?>

    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <style>:root { --bg-color: <?= htmlspecialchars($settings['bg_color']) ?>; --bg-image: url('<?= htmlspecialchars($settings['bg_image']) ?>'); --text-color: <?= htmlspecialchars($settings['text_color']) ?>; }</style>
</head>
<body>
    <header class="topbar">
        <h2><?= t('appearance_tabs') ?? 'Configurações' ?></h2>
        <div class="topbar-actions">
            <a href="index.php" class="btn">← Dashboard</a>
            <a href="admin.php" class="btn"><?= t('manage_services') ?? 'Serviços' ?></a>
        </div>
    </header>

    <main class="container">
        <?php if (isset($_GET['success'])): ?>
            <div class="alert success" style="background: rgba(64, 160, 43, 0.2); color: #40a02b; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center;">
                Configurações salvas com sucesso!
            </div>
        <?php endif; ?>

        <form method="POST" action="config.php" class="config-form">
            <!-- TOKEN CSRF -->
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <div class="form-group">
                <label>Nome do Portal:</label>
                <input type="text" name="portal_name" value="<?= htmlspecialchars($settings['portal_name']) ?>" required>
            </div>
            
            <div class="form-group">
                <label>Idioma:</label>
                <select name="language">
                    <option value="pt" <?= $settings['language'] == 'pt' ? 'selected' : '' ?>>Português</option>
                    <option value="en" <?= $settings['language'] == 'en' ? 'selected' : '' ?>>English</option>
                    <option value="es" <?= $settings['language'] == 'es' ? 'selected' : '' ?>>Español</option>
                </select>
            </div>

            <div class="form-group">
                <label>Favicon (Nome do arquivo em /icons ou URL):</label>
                <input type="text" name="favicon" value="<?= htmlspecialchars($settings['favicon']) ?>">
            </div>

            <div class="form-group grid-2-col">
                <div>
                    <label>Cor de Fundo:</label>
                    <input type="color" name="bg_color" value="<?= htmlspecialchars($settings['bg_color']) ?>">
                </div>
                <div>
                    <label>Cor do Texto:</label>
                    <input type="color" name="text_color" value="<?= htmlspecialchars($settings['text_color']) ?>">
                </div>
            </div>

            <div class="form-group">
                <label>URL da Imagem de Fundo (opcional):</label>
                <input type="text" name="bg_image" value="<?= htmlspecialchars($settings['bg_image']) ?>">
            </div>

            <button type="submit" class="btn primary">Salvar Alterações</button>
        </form>
    </main>
</body>
</html>