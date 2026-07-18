<?php
// index.php
require_once 'db.php';

// ==========================================
// INTERCEPTADOR DE PING (SEGURANÇA SSRF APLICADA)
// ==========================================
if (isset($_GET['action']) && $_GET['action'] === 'ping') {
    header('Content-Type: application/json');
    $status = 'offline';
    
    // Recebe apenas o ID em vez de host/porta
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if ($id > 0) {
        // Busca a URL oficial direto no banco de dados
        $stmt = $pdo->prepare("SELECT url FROM tools WHERE id = ?");
        $stmt->execute([$id]);
        $tool = $stmt->fetch();
        
        if ($tool && !empty($tool['url'])) {
            $parsedUrl = parse_url($tool['url']);
            $host = $parsedUrl['host'] ?? null;
            $port = $parsedUrl['port'] ?? (isset($parsedUrl['scheme']) && $parsedUrl['scheme'] === 'https' ? 443 : 80);
            
            if ($host) {
                // @fsockopen com timeout de 1s, suprimindo warnings visuais em caso de erro (host offline)
                $fp = @fsockopen($host, $port, $errno, $errstr, 1);
                if ($fp) {
                    fclose($fp);
                    $status = 'online';
                }
            }
        }
    }
    
    echo json_encode(['status' => $status]);
    exit;
}

// ==========================================
// CARREGAMENTO DE DADOS (CONFIGURAÇÕES E FERRAMENTAS)
// ==========================================
$settings = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$settings) {
    // Fallback caso a tabela esteja vazia por algum motivo
    $settings = ['bg_color' => '#1e1e2e', 'bg_image' => '', 'text_color' => '#cdd6f4', 'portal_name' => 'Meu Portal', 'favicon' => ''];
}

$categories = $pdo->query("SELECT * FROM categories ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Organiza as ferramentas agrupando pelo ID da categoria
$toolsByCategory = [];
$stmt = $pdo->query("SELECT * FROM tools ORDER BY name ASC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $catId = $row['category_id'] ?: 1; // Fallback de segurança para a categoria 1
    if (!isset($toolsByCategory[$catId])) {
        $toolsByCategory[$catId] = [];
    }
    $toolsByCategory[$catId][] = $row;
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($langCode ?? 'pt') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($settings['portal_name']) ?></title>
    
    <!-- INÍCIO DO FAVICON -->
    <?php $favicon = resolveIconUrl($settings['favicon']); if(!empty($favicon)): ?>
        <link rel="icon" href="<?= $favicon ?>">
    <?php endif; ?>
    <!-- FIM DO FAVICON -->

    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <style>
        :root { 
            --bg-color: <?= htmlspecialchars($settings['bg_color']) ?>; 
            --bg-image: url('<?= htmlspecialchars($settings['bg_image']) ?>'); 
            --text-color: <?= htmlspecialchars($settings['text_color']) ?>; 
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="logo">
            <h1><?= htmlspecialchars($settings['portal_name']) ?></h1>
        </div>
        <div class="topbar-actions">
            <button id="themeToggle" class="btn" title="Alternar Tema">🌓</button>
            <a href="admin.php" class="btn"><?= t('manage_services') ?? '⚙️ Serviços' ?></a>
            <a href="config.php" class="btn"><?= t('appearance_tabs') ?? '🎨 Aparência' ?></a>
        </div>
    </header>

    <main class="container">
        <?php foreach ($categories as $category): ?>
            <?php if (!empty($toolsByCategory[$category['id']])): ?>
                <section class="category-section">
                    <h2 class="category-title"><?= htmlspecialchars($category['name']) ?></h2>
                    <div class="grid">
                        <?php foreach ($toolsByCategory[$category['id']] as $tool): ?>
                            <!-- Atributo data-id utilizado pelo JS para acionar o Ping Seguro -->
                            <div class="card service-card" data-id="<?= $tool['id'] ?>">
                                <a href="<?= htmlspecialchars($tool['url']) ?>" target="_blank" class="card-link">
                                    <div class="card-icon">
                                        <img src="<?= resolveIconUrl($tool['icon_url']) ?>" alt="Icon">
                                    </div>
                                    <div class="card-content">
                                        <h3><?= htmlspecialchars($tool['name']) ?></h3>
                                        <?php if (!empty($tool['description'])): ?>
                                            <p><?= htmlspecialchars($tool['description']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </a>
                                <!-- Container do status do Ping -->
                                <div class="ping-indicator">
                                    <span class="ping-status" title="Checando status...">⏳</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        <?php endforeach; ?>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // ==========================================
            // LÓGICA DE PING VIA FETCH API
            // ==========================================
            const services = document.querySelectorAll('.service-card');
            
            services.forEach(service => {
                const id = service.getAttribute('data-id');
                const statusElement = service.querySelector('.ping-status');
                
                if (id && statusElement) {
                    fetch(`index.php?action=ping&id=${id}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.status === 'online') {
                                statusElement.innerHTML = '🟢';
                            } else {
                                statusElement.innerHTML = '🔴';
                            }
                        })
                        .catch(error => {
                            statusElement.innerHTML = '🔴';
                        });
                }
            });

            // ==========================================
            // LÓGICA DE TOGGLE DE TEMA (LIGHT/DARK)
            // ==========================================
            const themeToggle = document.getElementById('themeToggle');
            if (themeToggle) {
                // Recupera preferência ao carregar a página
                const savedTheme = localStorage.getItem('portal_theme');
                if (savedTheme) {
                    document.documentElement.style.setProperty('color-scheme', savedTheme);
                }

                // Ação do botão
                themeToggle.addEventListener('click', () => {
                    const currentTheme = document.documentElement.style.getPropertyValue('color-scheme') || 'dark';
                    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                    
                    document.documentElement.style.setProperty('color-scheme', newTheme);
                    localStorage.setItem('portal_theme', newTheme);
                });
            }
        });
    </script>
</body>
</html>