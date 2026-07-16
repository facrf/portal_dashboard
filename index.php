<?php
// index.php
require_once 'db.php';

// Salva o texto do bloco de notas do rodapé
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_footer') {
    $stmt = $pdo->prepare("UPDATE settings SET footer_text = ? WHERE id = 1");
    $stmt->execute([$_POST['footer_text']]);
    header("Location: index.php");
    exit;
}

$settings = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch();
$bgImageStyle = !empty($settings['bg_image']) ? "url('" . htmlspecialchars($settings['bg_image']) . "')" : 'none';
$currentLang = $settings['language'] ?? 'pt';

$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
$toolsList = $pdo->query("SELECT * FROM tools ORDER BY name ASC")->fetchAll();

$groupedTools = [];
foreach ($categories as $cat) { $groupedTools[$cat['id']] = []; }
foreach ($toolsList as $tool) {
    $cid = $tool['category_id'];
    if (isset($groupedTools[$cid])) { $groupedTools[$cid][] = $tool; }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($settings['portal_name']) ?></title>
    
    <?php $favicon = resolveIconUrl($settings['favicon']); if(!empty($favicon)): ?>
        <link rel="icon" href="<?= $favicon ?>">
    <?php endif; ?>
    
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <style>
        :root {
            --bg-color: <?= htmlspecialchars($settings['bg_color']) ?>;
            --bg-image: <?= $bgImageStyle ?>;
            --text-color: <?= htmlspecialchars($settings['text_color']) ?>;
        }
    </style>
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
            <h1><?= htmlspecialchars($settings['portal_name']) ?></h1>
            <div class="header-controls">
                
                <div class="theme-toggle-wrapper" onclick="toggleTheme()" title="Toggle Theme">
                    <svg viewBox="0 0 24 24"><path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zm0 8c-1.65 0-3-1.35-3-3s1.35-3 3-3 3 1.35 3 3-1.35 3-3 3zm9-4h-2c-.55 0-1 .45-1 1s.45 1 1 1h2c.55 0 1-.45 1-1s-.45-1-1-1zM4 12c0 .55-.45 1-1 1H1c-.55 0-1-.45-1-1s.45-1 1-1h2c.55 0 1 .45 1 1zm7-9V1c0-.55-.45-1-1-1s-1 .45-1 1v2c0 .55.45 1 1 1s1-.45 1-1zm0 18v2c0 .55-.45 1 1 1s1-.45 1-1v-2c0-.55-.45-1-1-1s-1 .45-1 1zm7.66-13.88l1.41-1.41c.39-.39.39-1.03 0-1.41-.39-.39-1.03-.39-1.41 0l-1.41 1.41c-.39.39-.39 1.03 0 1.41.39.39 1.03.39 1.41 0zM4.93 19.07l1.41-1.41c.39-.39.39-1.03 0-1.41-.39-.39-1.03-.39-1.41 0l-1.41 1.41c-.39.39-.39 1.03 0 1.41.39.39 1.03.39 1.41 0zm14.14 0c.39.39 1.03.39 1.41 0 .39-.39.39-1.03 0-1.41l-1.41-1.41c-.39-.39-1.03-.39-1.41 0-.39.39-.39 1.03 0 1.41l1.41 1.41zM6.34 6.34c.39.39 1.03.39 1.41 0 .39-.39.39-1.03 0-1.41L6.34 3.51c-.39-.39-1.03-.39-1.41 0-.39.39-.39 1.03 0 1.41l1.41 1.42z"/></svg>
                    <div class="toggle-slot"><div class="toggle-button"></div></div>
                    <svg viewBox="0 0 24 24"><path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9c0-.46-.04-.92-.1-1.36-.98 1.37-2.58 2.26-4.4 2.26-3.03 0-5.5-2.47-5.5-5.5 0-1.82.89-3.42 2.26-4.4C12.92 3.04 12.46 3 12 3z"/></svg>
                </div>

                <div class="header-nav">
                    <a href="admin.php" class="btn"><?= t('settings') ?></a>
                    <a href="config.php" class="btn"><?= t('appearance_tabs') ?></a>
                </div>
            </div>
        </header>

        <div class="dashboard-grid">
            <?php foreach ($categories as $cat): 
                if (empty($groupedTools[$cat['id']])) continue; 
            ?>
                <div class="category-column">
                    <h2><?= htmlspecialchars($cat['name']) ?></h2>
                    <div class="category-items">
                        
                        <?php foreach ($groupedTools[$cat['id']] as $tool): ?>
                            <a href="<?= htmlspecialchars($tool['url']) ?>" class="card tool-card" target="_blank" data-url="<?= htmlspecialchars($tool['url']) ?>">
                                
                                <div class="status-badge status-ping">PING...</div>
                                
                                <div class="card-top">
                                    <?php $iconPath = resolveIconUrl($tool['icon_url']); if (!empty($iconPath)): ?>
                                        <img src="<?= $iconPath ?>" alt="">
                                    <?php endif; ?>
                                    
                                    <div class="card-content">
                                        <h3><?= htmlspecialchars($tool['name']) ?></h3>
                                        <?php if (!empty($tool['description'])): ?>
                                            <p><?= htmlspecialchars($tool['description']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="error-block">
                                    <strong>!</strong> <?= t('status_error') ?> / Offline
                                </div>
                            </a>
                        <?php endforeach; ?>
                        
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <footer class="notes-section">
            <form method="POST" class="notes-form">
                <input type="hidden" name="action" value="update_footer">
                <label for="footer_text"><?= t('notes') ?></label>
                <textarea name="footer_text" id="footer_text" placeholder="..."><?= htmlspecialchars($settings['footer_text'] ?? '') ?></textarea>
                <button type="submit" class="btn"><?= t('save_notes') ?></button>
            </form>
        </footer>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", () => {
    
    // 1. Efeito PROC para o botão salvar do Bloco de Notas
    const notesForm = document.querySelector('.notes-form');
    if (notesForm) {
        notesForm.addEventListener('input', () => {
            const btn = notesForm.querySelector('button[type="submit"]');
            if (btn && !btn.classList.contains('btn-glow')) {
                btn.classList.add('btn-glow');
            }
        });
    }

    // 2. Sistema de Checagem Assíncrona Inteligente (HTTP ou TCP Port)
    const cards = document.querySelectorAll('.tool-card');
    const txtRunning = '<?= t('status_running') ?>';
    const txtError = '<?= t('status_error') ?>';
    
    cards.forEach(card => {
        const urlStr = card.getAttribute('data-url').trim();
        const badge = card.querySelector('.status-badge');
        const errorBlock = card.querySelector('.error-block');

        let queryUrl = '';

        try {
            // Tenta processar como URL válida
            let urlObj = new URL(urlStr);
            
            // Se a URL tiver uma porta definida (e não for porta web padrão 80/443)
            if (urlObj.port && urlObj.port !== '80' && urlObj.port !== '443') {
                queryUrl = `ping.php?host=${encodeURIComponent(urlObj.hostname)}&port=${urlObj.port}`;
            } else {
                queryUrl = 'ping.php?url=' + encodeURIComponent(urlStr);
            }
        } catch (e) {
            // Fallback caso seja apenas IP:PORTA sem "http://" cadastrado
            const portMatch = urlStr.match(/:(\d+)$/);
            if (portMatch) {
                const parts = urlStr.split(':');
                const host = parts[0].replace('//', '');
                const port = portMatch[1];
                queryUrl = `ping.php?host=${encodeURIComponent(host)}&port=${port}`;
            } else {
                queryUrl = 'ping.php?url=' + encodeURIComponent(urlStr);
            }
        }

        fetch(queryUrl)
            .then(response => response.json())
            .then(data => {
                if (data.status === 'ok') {
                    badge.textContent = txtRunning;
                    badge.className = 'status-badge status-ok';
                    errorBlock.style.display = 'none';
                } else {
                    badge.textContent = txtError;
                    badge.className = 'status-badge status-error';
                    errorBlock.style.display = 'block'; 
                }
            })
            .catch(() => {
                badge.textContent = txtError;
                badge.className = 'status-badge status-error';
                errorBlock.style.display = 'block';
            });
    });
});
    </script>
</body>
</html>