<?php
// index.php
require_once 'db.php';

// ==========================================
// CLASSE PINGER INTEGRADA (Substitui ping.php)
// ==========================================
class Pinger {
    /**
     * Envia uma requisição UDP real para validar se o Servidor NTP está online.
     * Envia um pacote de 48 bytes (padrão do protocolo NTP) e aguarda resposta.
     */
    private static function testNtpServer($host, $port = 123) {
        $fp = @fsockopen("udp://$host", $port, $errno, $errstr, 1.5);
        if (!$fp) return false;

        stream_set_timeout($fp, 1, 500000);
        $packet = "\x1b" . str_repeat("\0", 47);
        $write = @fwrite($fp, $packet);
        
        if ($write === false) { 
            fclose($fp); 
            return false; 
        }

        $response = @fread($fp, 48);
        fclose($fp);

        return (!empty($response) && strlen($response) >= 48);
    }

    /**
     * Executa a checagem com base nos parâmetros GET
     */
    public static function check($params) {
        // 1. Método de teste para portas (Bancos de dados, NTP, etc.)
        if (isset($params['host']) && isset($params['port'])) {
            $host = filter_var($params['host'], FILTER_SANITIZE_URL);
            $port = intval($params['port']);

            // Limpa possíveis protocolos inseridos no host
            $host = preg_replace('~^https?://~i', '', $host);
            $host = preg_replace('~^udp://~i', '', $host);

            if (!empty($host) && $port > 0) {
                if ($port === 123) {
                    return self::testNtpServer($host, $port);
                } else {
                    // Teste padrão TCP para portas de Bancos de Dados, etc.
                    $connection = @fsockopen($host, $port, $errno, $errstr, 1.5);
                    if (is_resource($connection)) {
                        fclose($connection);
                        return true;
                    }
                }
            }
            return false;
        }

        // 2. Método padrão: teste HTTP HEAD (Para sites e web apps normais)
        if (isset($params['url'])) {
            $url = filter_var($params['url'], FILTER_SANITIZE_URL);
            if ($url) {
                $context = stream_context_create([
                    'http' => [
                        'method' => 'HEAD',
                        'timeout' => 2,
                        'ignore_errors' => true
                    ],
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false
                    ]
                ]);

                $headers = @get_headers($url, 1, $context);
                if ($headers !== false) {
                    preg_match('/HTTP\/\d(?:\.\d)?\s+(\d+)/', $headers[0], $matches);
                    $code = isset($matches[1]) ? intval($matches[1]) : 0;
                    if ($code > 0) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}

// ==========================================
// INTERCEPTADOR DE PING (AJAX API) - PROTEGIDO CONTRA SSRF
// ==========================================
if (isset($_GET['action']) && $_GET['action'] === 'ping') {
    header('Content-Type: application/json');

    // PROTEÇÃO CONTRA SSRF: Lista Branca baseada no banco de dados
    $isAllowed = false;
    
    if (!empty($_GET['url'])) {
        // Só permite se a exata URL existir nos cadastros
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tools WHERE url = ?");
        $stmt->execute([$_GET['url']]);
        $isAllowed = $stmt->fetchColumn() > 0;
    } elseif (!empty($_GET['host'])) {
        // Se for IP/Host + Porta, verifica se o host faz parte de alguma URL cadastrada
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tools WHERE url LIKE ?");
        $stmt->execute(['%' . $_GET['host'] . '%']);
        $isAllowed = $stmt->fetchColumn() > 0;
    }

    if (!$isAllowed) {
        // Se o atacante tentar pingar um IP/Porta interno não mapeado no painel, bloqueia.
        echo json_encode(['status' => 'error', 'msg' => 'Alvo não cadastrado.']);
        exit;
    }

    $isOnline = Pinger::check($_GET);
    echo json_encode(['status' => $isOnline ? 'ok' : 'error']);
    exit;
}

// ==========================================
// LÓGICA PADRÃO DA PÁGINA
// ==========================================

// Salva o texto do bloco de notas do rodapé - PROTEGIDO CONTRA CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_footer') {
    
    // Validação de segurança Anti-CSRF
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Requisição inválida.");
    }

    $stmt = $pdo->prepare("UPDATE settings SET footer_text = ? WHERE id = 1");
    $stmt->execute([$_POST['footer_text']]);
    header("Location: index.php");
    exit;
}

$settings = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch();

// Força ENT_QUOTES para barrar injeção de aspas simples no CSS
$bgImageStyle = !empty($settings['bg_image']) ? "url('" . htmlspecialchars($settings['bg_image'], ENT_QUOTES, 'UTF-8') . "')" : 'none';
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
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- Bloqueia o vazamento de rastreamento do Referer para imagens e ícones externos -->
    <meta name="referrer" content="no-referrer">
    
    <title><?= htmlspecialchars($settings['portal_name'], ENT_QUOTES, 'UTF-8') ?></title>
    
    <?php $favicon = resolveIconUrl($settings['favicon']); if(!empty($favicon)): ?>
        <link rel="icon" href="<?= htmlspecialchars($favicon, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <style>
        :root {
            --bg-color: <?= htmlspecialchars($settings['bg_color'], ENT_QUOTES, 'UTF-8') ?>;
            --bg-image: <?= $bgImageStyle ?>;
            --text-color: <?= htmlspecialchars($settings['text_color'], ENT_QUOTES, 'UTF-8') ?>;
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
            <h1><?= htmlspecialchars($settings['portal_name'], ENT_QUOTES, 'UTF-8') ?></h1>
            <div class="header-controls">
                
                <div class="theme-toggle-wrapper" onclick="toggleTheme()" title="Toggle Theme">
                    <svg viewBox="0 0 24 24"><path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zm0 8c-1.65 0-3-1.35-3-3s1.35-3 3-3 3 1.35 3 3-1.35 3-3 3zm9-4h-2c-.55 0-1 .45-1 1s.45 1 1 1h2c.55 0 1-.45 1-1s-.45-1-1-1zM4 12c0 .55-.45 1-1 1H1c-.55 0-1-.45-1-1s.45-1 1-1h2c.55 0 1 .45 1 1zm7-9V1c0-.55-.45-1-1-1s-1 .45-1 1v2c0 .55.45 1 1 1s1-.45 1-1zm0 18v2c0 .55-.45 1 1 1s1-.45 1-1v-2c0-.55-.45-1-1-1s-1 .45-1 1zm7.66-13.88l1.41-1.41c.39-.39.39-1.03 0-1.41-.39-.39-1.03-.39-1.41 0l-1.41 1.41c-.39.39-.39 1.03 0 1.41.39.39 1.03.39 1.41 0zM4.93 19.07l1.41-1.41c.39-.39.39-1.03 0-1.41-.39-.39-1.03-.39-1.41 0l-1.41 1.41c-.39.39-.39 1.03 0 1.41.39.39 1.03.39 1.41 0zm14.14 0c.39.39 1.03.39 1.41 0 .39-.39.39-1.03 0-1.41l-1.41-1.41c-.39-.39-1.03-.39-1.41 0-.39.39-.39 1.03 0 1.41l1.41 1.41zM6.34 6.34c.39.39 1.03.39 1.41 0 .39-.39.39-1.03 0-1.41L6.34 3.51c-.39-.39-1.03-.39-1.41 0-.39.39-.39 1.03 0 1.41l1.41 1.42z"/></svg>
                    <div class="toggle-slot"><div class="toggle-button"></div></div>
                    <svg viewBox="0 0 24 24"><path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9c0-.46-.04-.92-.1-1.36-.98 1.37-2.58 2.26-4.4 2.26-3.03 0-5.5-2.47-5.5-5.5 0-1.82.89-3.42 2.26-4.4C12.92 3.04 12.46 3 12 3z"/></svg>
                </div>

                <div class="header-nav">
                    <a href="admin.php" class="btn"><?= t('settings') ?></a>
                    <a href="config.php" class="btn"><?= t('appearance_tabs') ?></a>
                   <form method="POST" action="login.php" style="display: inline; margin: 0;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="logout">
        <button type="submit" class="btn btn-danger" style="margin-left: 10px;"><?= t('logout') ?></button>
    </form>
                </div>
            </div>
        </header>

        <div class="dashboard-grid">
            <?php foreach ($categories as $cat): 
                if (empty($groupedTools[$cat['id']])) continue; 
            ?>
                <div class="category-column">
                    <h2><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <div class="category-items">
                        
                        <?php foreach ($groupedTools[$cat['id']] as $tool): 
                            
                            // 1. Sanitização contra URI javascript: e data: (Evita XSS)
                            $safeUrl = $tool['url'];
                            if (preg_match('/^\s*(javascript|vbscript|data):/i', $safeUrl)) {
                                $safeUrl = '#blocked';
                            }
                            $safeUrl = htmlspecialchars($safeUrl, ENT_QUOTES, 'UTF-8');
                        ?>
                            <!-- 2. Adiciona rel="noopener noreferrer" para evitar Tabnabbing e Tracking -->
                            <a href="<?= $safeUrl ?>" class="card tool-card" target="_blank" rel="noopener noreferrer" data-url="<?= $safeUrl ?>">
                                
                                <div class="status-badge status-ping">PING...</div>
                                
                                <div class="card-top">
                                    <?php $iconPath = resolveIconUrl($tool['icon_url']); if (!empty($iconPath)): ?>
                                        <img src="<?= htmlspecialchars($iconPath, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                    <?php endif; ?>
                                    
                                    <div class="card-content">
                                        <h3><?= htmlspecialchars($tool['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                        <?php if (!empty($tool['description'])): ?>
                                            <p><?= htmlspecialchars($tool['description'], ENT_QUOTES, 'UTF-8') ?></p>
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
                
                <!-- Token adicionado aqui (Proteção CSRF) -->
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                
                <label for="footer_text"><?= t('notes') ?></label>
                <textarea name="footer_text" id="footer_text" placeholder="..."><?= htmlspecialchars($settings['footer_text'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
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
            const txtRunning = '<?= htmlspecialchars(t('status_running'), ENT_QUOTES, 'UTF-8') ?>';
            const txtError = '<?= htmlspecialchars(t('status_error'), ENT_QUOTES, 'UTF-8') ?>';
            
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
                        queryUrl = `index.php?action=ping&host=${encodeURIComponent(urlObj.hostname)}&port=${urlObj.port}`;
                    } else {
                        queryUrl = 'index.php?action=ping&url=' + encodeURIComponent(urlStr);
                    }
                } catch (e) {
                    // Fallback caso seja apenas IP:PORTA sem "http://" cadastrado
                    const portMatch = urlStr.match(/:(\d+)$/);
                    if (portMatch) {
                        const parts = urlStr.split(':');
                        const host = parts[0].replace('//', '');
                        const port = portMatch[1];
                        queryUrl = `index.php?action=ping&host=${encodeURIComponent(host)}&port=${port}`;
                    } else {
                        queryUrl = 'index.php?action=ping&url=' + encodeURIComponent(urlStr);
                    }
                }

                // Proteção extra no Front-End para não disparar ping em links bloqueados
                if (urlStr === '#blocked') {
                    badge.textContent = txtError;
                    badge.className = 'status-badge status-error';
                    errorBlock.style.display = 'block';
                    return;
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