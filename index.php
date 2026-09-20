<?php
/**
 * Portal Dashboard
 *
 * @author    FACRF
 * @copyright 2026 FACRF
 * @link      https://github.com/facrf/portal_dashboard
 */
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
            $host = trim($params['host']);
            $port = intval($params['port']);

            // Limpa possíveis protocolos inseridos no host
            $host = preg_replace('~^https?://~i', '', $host);
            $host = preg_replace('~^udp://~i', '', $host);

            if (filter_var($host, FILTER_VALIDATE_IP) || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $host)) {
                if ($port < 1 || $port > 65535) return false;
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
            $url = trim($params['url']);
            if (strpos($url, '://') === false) {
                $url = 'http://' . $url;
            }
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (filter_var($url, FILTER_VALIDATE_URL) && in_array($scheme, ['http', 'https'], true)) {
                $context = stream_context_create([
                    'http' => [
                        'method' => 'HEAD',
                        'timeout' => 2,
                        'ignore_errors' => true,
                        'follow_location' => 0,
                        'max_redirects' => 0
                    ],
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                        'allow_self_signed' => false
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

function pingParametersForUrl(string $url): array {
    $hasScheme = strpos($url, '://') !== false;
    $candidate = $hasScheme ? $url : 'tcp://' . $url;
    $parts = parse_url($candidate);
    if ($parts !== false && !empty($parts['host'])) {
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ((!$hasScheme && $port !== null) || $scheme === 'udp' || ($hasScheme && $scheme === 'tcp') || ($port !== null && !in_array($port, [80, 443], true))) {
            return ['host' => (string) $parts['host'], 'port' => $port ?? ($scheme === 'udp' ? 123 : 80)];
        }
    }
    return ['url' => $url];
}

// ==========================================
// INTERCEPTADOR DE PING (AJAX API) - PROTEGIDO CONTRA SSRF
// ==========================================
$healthAction = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
if (in_array($healthAction, ['ping', 'status'], true)) {
    // Libera o lock da sessão para evitar que o fsockopen (lento) trave outras requisições AJAX (como o Drag & Drop)
    session_write_close();

    header('Content-Type: application/json');

    if ($healthAction === 'status') {
        $idsRaw = is_string($_GET['ids'] ?? null) ? $_GET['ids'] : '';
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $idsRaw)), fn($id) => $id > 0)));
        if ($ids === [] || count($ids) > 10) jsonResponse(['status' => 'error', 'msg' => 'Lista de serviços inválida.'], 422);

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, url FROM tools WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        $registeredTools = $stmt->fetchAll();
        $cacheStmt = $pdo->prepare("SELECT status, checked_at FROM health_cache WHERE tool_id = ?");
        $saveCache = $pdo->prepare("INSERT INTO health_cache (tool_id, status, checked_at) VALUES (?, ?, ?) ON CONFLICT(tool_id) DO UPDATE SET status=excluded.status, checked_at=excluded.checked_at");
        $results = [];
        $now = time();

        foreach ($registeredTools as $tool) {
            $toolId = (int) $tool['id'];
            $cacheStmt->execute([$toolId]);
            $cached = $cacheStmt->fetch();
            if ($cached && $now - (int) $cached['checked_at'] <= 45) {
                $online = (bool) $cached['status'];
                $checkedAt = (int) $cached['checked_at'];
            } else {
                $online = Pinger::check(pingParametersForUrl($tool['url']));
                $checkedAt = $now;
                $saveCache->execute([$toolId, $online ? 1 : 0, $checkedAt]);
            }
            $results[(string) $toolId] = ['status' => $online ? 'ok' : 'error', 'checked_at' => $checkedAt];
        }
        jsonResponse(['status' => 'ok', 'results' => $results]);
    }

    // Compatibilidade com clientes antigos: lista branca baseada no banco de dados.
    $isAllowed = false;
    $pingParams = [];
    
    if (is_string($_GET['url'] ?? null) && $_GET['url'] !== '') {
        // Só permite se a exata URL existir nos cadastros
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tools WHERE url = ?");
        $stmt->execute([$_GET['url']]);
        if ($stmt->fetchColumn() > 0) {
            $isAllowed = true;
            $pingParams['url'] = $_GET['url'];
        }
    } elseif (is_string($_GET['host'] ?? null) && $_GET['host'] !== '' && is_scalar($_GET['port'] ?? null)) {
        // Compara host e porta analisados, evitando correspondência parcial via LIKE.
        $requestedHost = strtolower(rtrim(trim($_GET['host']), '.'));
        $requestedPort = (int) $_GET['port'];
        foreach ($pdo->query("SELECT url FROM tools")->fetchAll(PDO::FETCH_COLUMN) as $registeredUrl) {
            $parsedUrl = parse_url(strpos($registeredUrl, '://') === false ? 'tcp://' . $registeredUrl : $registeredUrl);
            $registeredHost = strtolower(rtrim((string) ($parsedUrl['host'] ?? ''), '.'));
            $registeredPort = $parsedUrl['port'] ?? null;
            if ($registeredHost === $requestedHost && (int) $registeredPort === $requestedPort) {
                $isAllowed = true;
                $pingParams['host'] = $requestedHost;
                $pingParams['port'] = $requestedPort;
                break;
            }
        }
    }

    if (!$isAllowed) {
        // Se o atacante tentar pingar um IP/Porta interno não mapeado no painel, bloqueia.
        echo json_encode(['status' => 'error', 'msg' => 'Alvo não cadastrado.']);
        exit;
    }

    $isOnline = Pinger::check($pingParams);
    echo json_encode(['status' => $isOnline ? 'ok' : 'error']);
    exit;
}

// ==========================================
// LÓGICA PADRÃO DA PÁGINA
// ==========================================

// Salva o texto do bloco de notas do rodapé - PROTEGIDO CONTRA CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCsrfToken($_POST);
    } catch (InvalidArgumentException $e) {
        die(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }

    if (isset($_POST['action']) && $_POST['action'] === 'logout') {
        session_destroy();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
        header("Location: index.php");
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'update_footer') {
        try {
            $footerText = inputString($_POST, 'footer_text', 5000);
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            die(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
        $stmt = $pdo->prepare("UPDATE settings SET footer_text=? WHERE id=1");
        $stmt->execute([$footerText]);
        header("Location: index.php");
        exit;
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'reorder_tools' && isset($_POST['orders'])) {
        $ordersRaw = is_string($_POST['orders']) ? $_POST['orders'] : '';
        $orders = json_decode($ordersRaw, true);
        if (is_array($orders) && count($orders) <= 5000) {
            $pdo->beginTransaction();
            foreach ($orders as $order) {
                if (is_array($order)) {
                    $id = filter_var($order['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    $position = filter_var($order['order'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4999]]);
                    if ($id === false || $position === false) continue;
                    $stmt = $pdo->prepare("UPDATE tools SET sort_order = ? WHERE id = ?");
                    $stmt->execute([(int) $position, (int) $id]);
                }
            }
            $pdo->commit();
            jsonResponse(['status' => 'ok']);
        }
        jsonResponse(['status' => 'error', 'msg' => 'Ordenação inválida.'], 422);
    }
}
$settings = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch();

// Força ENT_QUOTES para barrar injeção de aspas simples no CSS
$bgImageStyle = !empty($settings['bg_image']) ? "url('" . htmlspecialchars($settings['bg_image'], ENT_QUOTES, 'UTF-8') . "')" : 'none';
$currentLang = $settings['language'] ?? 'pt';

$categories = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$categoriesMap = [];
foreach ($categories as $c) {
    $categoriesMap[$c['id']] = $c['name'];
}

$tools = $pdo->query("SELECT * FROM tools ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

$groupedTools = [];
foreach ($categories as $cat) { $groupedTools[$cat['id']] = []; }
foreach ($tools as $tool) {
    $cid = $tool['category_id'];
    if (isset($groupedTools[$cid])) { $groupedTools[$cid][] = $tool; }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <!-- Developed with care by FACRF - https://github.com/facrf -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- Bloqueia o vazamento de rastreamento do Referer para imagens e ícones externos -->
    <meta name="referrer" content="no-referrer">
    
    <title><?= htmlspecialchars($settings['portal_name'], ENT_QUOTES, 'UTF-8') ?></title>
    
    <?php $favicon = resolveIconUrl($settings['favicon']); if(!empty($favicon)): ?>
        <link rel="icon" href="<?= $favicon ?>">
    <?php endif; ?>
    
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <style>
        :root {
            --bg-color: <?= validatedColor((string) ($settings['bg_color'] ?? ''), '#1e1e2e') ?>;
            --bg-image: <?= $bgImageStyle ?>;
            --text-color: <?= validatedColor((string) ($settings['text_color'] ?? ''), '#cdd6f4') ?>;
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
            
            <div class="search-container">
                <input type="text" id="searchInput" class="search-input" placeholder="<?= htmlspecialchars(t('search'), ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
                <svg class="search-icon" viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
            </div>

            <div class="header-controls">
                <button type="button" class="theme-toggle-wrapper" onclick="toggleTheme()" title="Toggle Theme" aria-label="Toggle Theme">
                    <svg viewBox="0 0 24 24"><path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zm0 8c-1.65 0-3-1.35-3-3s1.35-3 3-3 3 1.35 3 3-1.35 3-3 3zm9-4h-2c-.55 0-1 .45-1 1s.45 1 1 1h2c.55 0 1-.45 1-1s-.45-1-1-1zM4 12c0 .55-.45 1-1 1H1c-.55 0-1-.45-1-1s.45-1 1-1h2c.55 0 1 .45 1 1zm7-9V1c0-.55-.45-1-1-1s-1 .45-1 1v2c0 .55.45 1 1 1s1-.45 1-1zm0 18v2c0 .55-.45 1 1 1s1-.45 1-1v-2c0-.55-.45-1-1-1s-1 .45-1 1zm7.66-13.88l1.41-1.41c.39-.39.39-1.03 0-1.41-.39-.39-1.03-.39-1.41 0l-1.41 1.41c-.39.39-.39 1.03 0 1.41.39.39 1.03.39 1.41 0zM4.93 19.07l1.41-1.41c.39-.39.39-1.03 0-1.41-.39-.39-1.03-.39-1.41 0l-1.41 1.41c-.39.39-.39 1.03 0 1.41.39.39 1.03.39 1.41 0zm14.14 0c.39.39 1.03.39 1.41 0 .39-.39.39-1.03 0-1.41l-1.41-1.41c-.39-.39-1.03-.39-1.41 0-.39.39-.39 1.03 0 1.41l1.41 1.41zM6.34 6.34c.39.39 1.03.39 1.41 0 .39-.39.39-1.03 0-1.41L6.34 3.51c-.39-.39-1.03-.39-1.41 0-.39.39-.39 1.03 0 1.41l1.41 1.42z"/></svg>
                    <div class="toggle-slot"><div class="toggle-button"></div></div>
                    <svg viewBox="0 0 24 24"><path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9c0-.46-.04-.92-.1-1.36-.98 1.37-2.58 2.26-4.4 2.26-3.03 0-5.5-2.47-5.5-5.5 0-1.82.89-3.42 2.26-4.4C12.92 3.04 12.46 3 12 3z"/></svg>
                </button>

                <div class="header-nav">
                    <?php if ($isAuthenticated): ?>
                    <a href="admin.php" class="btn"><?= t('settings') ?></a>
                    <?php endif; ?>
                    <a href="config.php" class="btn"><?= t('appearance_tabs') ?></a>
                    <?php if ($isAuthenticated): ?>
                    <form method="POST" action="login.php" style="display: inline; margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="btn btn-danger"><?= t('logout') ?></button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <?php 
        $showClock = !isset($settings['show_clock']) || $settings['show_clock'] == 1;
        $showGreeting = !isset($settings['show_greeting']) || $settings['show_greeting'] == 1;
        if ($showClock || $showGreeting): 
        ?>
        <div class="clock-widget" style="text-align: center; margin: 2rem 0; color: var(--text-color);">
            <?php if ($showClock): ?>
            <div id="clock-time" style="font-size: 3.5rem; font-weight: bold; letter-spacing: 2px; text-shadow: 0 4px 15px rgba(0,0,0,0.2);">--:--</div>
            <div id="clock-date" style="font-size: 1.1rem; opacity: 0.8; margin-top: 5px;"></div>
            <?php endif; ?>
            
            <?php if ($showGreeting): ?>
            <div id="clock-greeting" style="font-size: 1.2rem; margin-top: 10px; font-weight: 500; opacity: 0.9;"></div>
            <?php endif; ?>
        </div>
        <script>
            function updateClock() {
                const now = new Date();
                
                <?php if ($showClock): ?>
                const hours = String(now.getHours()).padStart(2, '0');
                const minutes = String(now.getMinutes()).padStart(2, '0');
                document.getElementById('clock-time').textContent = `${hours}:${minutes}`;
                
                const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
                document.getElementById('clock-date').textContent = now.toLocaleDateString('<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>', options);
                <?php endif; ?>
                
                <?php if ($showGreeting): ?>
                let greeting = <?= json_encode(t('greeting_evening')) ?>;
                if (now.getHours() >= 5 && now.getHours() < 12) greeting = <?= json_encode(t('greeting_morning')) ?>;
                else if (now.getHours() >= 12 && now.getHours() < 18) greeting = <?= json_encode(t('greeting_afternoon')) ?>;
                
                document.getElementById('clock-greeting').textContent = greeting + <?= json_encode($isAuthenticated ? ', ' . ($settings['greeting_name'] ?? 'Administrador') . '.' : '!') ?>;
                <?php endif; ?>
            }
            setInterval(updateClock, 1000);
            updateClock();
        </script>
        <?php endif; ?>

        <div class="dashboard-grid">
            <?php foreach ($categories as $cat): 
                if (empty($groupedTools[$cat['id']])) continue; 
            ?>
                <div class="category-column">
                    <h2><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <div class="category-items">
                        
                        <?php foreach ($groupedTools[$cat['id']] as $tool): 
                            $safeUrl = $tool['url'];
                            if (preg_match('/^\s*(javascript|vbscript|data):/i', $safeUrl)) {
                                $safeUrl = '#blocked';
                            }
                            $safeUrl = htmlspecialchars($safeUrl, ENT_QUOTES, 'UTF-8');
                            $toolNameLower = htmlspecialchars(mb_strtolower($tool['name']), ENT_QUOTES, 'UTF-8');
                            $toolDescLower = htmlspecialchars(mb_strtolower($tool['description'] ?? ''), ENT_QUOTES, 'UTF-8');

                            // Aceita somente a cor hexadecimal gerada pelo seletor do painel.
                            $toolTagColor = trim((string) ($tool['tag_color'] ?? ''));
                            if (!preg_match('/^#[0-9a-f]{6}$/i', $toolTagColor)) {
                                $toolTagColor = '#6366f1';
                            }

                            // Define automaticamente texto claro ou escuro para manter o contraste da tag.
                            $tagRed = hexdec(substr($toolTagColor, 1, 2));
                            $tagGreen = hexdec(substr($toolTagColor, 3, 2));
                            $tagBlue = hexdec(substr($toolTagColor, 5, 2));
                            $toolTagTextColor = (($tagRed * 299 + $tagGreen * 587 + $tagBlue * 114) / 1000) > 160
                                ? '#111827'
                                : '#ffffff';
                        ?>
                            <a href="<?= $safeUrl ?>" draggable="<?= $isAuthenticated ? 'true' : 'false' ?>" class="card tool-card" target="_blank" rel="noopener noreferrer" data-id="<?= $tool['id'] ?>" data-url="<?= $safeUrl ?>" data-name="<?= $toolNameLower ?>" data-desc="<?= $toolDescLower ?>">
                                <div class="status-badge status-ping">PING...</div>
                                
                                <div class="card-top">
                                    <?php $iconPath = resolveIconUrl($tool['icon_url']); if (!empty($iconPath)): ?>
                                        <div class="card-icon-wrapper">
                                            <img src="<?= $iconPath ?>" alt="" loading="lazy">
                                        </div>
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

                                <?php if (!empty($tool['tag_name'])): ?>
                                    <div class="card-meta">
                                        <span
                                            class="tool-tag"
                                            style="--tool-tag-color: <?= $toolTagColor ?>; --tool-tag-text-color: <?= $toolTagTextColor ?>;"
                                        ><?= htmlspecialchars($tool['tag_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                        
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php $hasNotice = trim((string) ($settings['footer_text'] ?? '')) !== ''; ?>
        <?php if ($isAuthenticated || $hasNotice): ?>
        <footer class="notes-section" aria-labelledby="notices-title">
            <section class="notice-board">
                <h2 id="notices-title"><?= t('notices') ?></h2>
                <?php if ($hasNotice): ?>
                    <p class="notice-content"><?= htmlspecialchars($settings['footer_text'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php else: ?>
                    <p class="notice-help"><?= t('notices_empty') ?></p>
                <?php endif; ?>
            </section>
            <?php if ($isAuthenticated): ?>
            <details class="notice-editor" <?= !$hasNotice ? 'open' : '' ?>>
                <summary><?= t('edit_notices') ?></summary>
                <form method="POST" class="notes-form">
                <input type="hidden" name="action" value="update_footer">
                
                <!-- Token adicionado aqui (Proteção CSRF) -->
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                
                <label for="footer_text"><?= t('notices') ?></label>
                <p id="notices-help" class="notice-help"><?= t('notices_help') ?></p>
                <textarea name="footer_text" id="footer_text" maxlength="5000" aria-describedby="notices-help" placeholder="<?= htmlspecialchars(t('notices_placeholder'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($settings['footer_text'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                <button type="submit" class="btn"><?= t('publish_notices') ?></button>
                </form>
            </details>
            <?php endif; ?>
        </footer>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", () => {
    
            // 1. Destaca a publicação quando o aviso é editado
            const notesForm = document.querySelector('.notes-form');
            if (notesForm) {
                notesForm.addEventListener('input', () => {
                    const btn = notesForm.querySelector('button[type="submit"]');
                    if (btn && !btn.classList.contains('btn-glow')) {
                        btn.classList.add('btn-glow');
                    }
                });
            }

            // 2. Filtro de Busca Instantânea
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('input', (e) => {
                    const query = e.target.value.toLowerCase().trim();
                    const categoryColumns = document.querySelectorAll('.category-column');
                    
                    categoryColumns.forEach(column => {
                        let hasVisibleCards = false;
                        const toolCards = column.querySelectorAll('.tool-card');
                        
                        toolCards.forEach(card => {
                            const name = card.getAttribute('data-name') || '';
                            const desc = card.getAttribute('data-desc') || '';
                            if (name.includes(query) || desc.includes(query)) {
                                card.style.display = 'flex';
                                hasVisibleCards = true;
                            } else {
                                card.style.display = 'none';
                            }
                        });
                        
                        column.style.display = hasVisibleCards ? 'block' : 'none';
                    });
                });
                
                // Busca Global (DuckDuckGo) ao pressionar Enter se não houver cards visíveis
                searchInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        const term = e.target.value.trim();
                        if (!term) return;
                        
                        let hasVisible = false;
                        document.querySelectorAll('.tool-card').forEach(card => {
                            if (card.style.display !== 'none') hasVisible = true;
                        });
                        
                        if (!hasVisible) {
                            window.open(`https://duckduckgo.com/?q=${encodeURIComponent(term)}`, '_blank');
                        }
                    }
                });
            }

            // 3. Checagem em lotes, com cache no servidor para evitar uma requisição por card.
            const cards = document.querySelectorAll('.tool-card');
            const txtRunning = <?= json_encode(t('status_running')) ?>;
            const txtError = <?= json_encode(t('status_error')) ?>;
            const cardMap = new Map(Array.from(cards, card => [card.dataset.id, card]));
            const ids = Array.from(cardMap.keys());

            function renderHealth(card, online) {
                const badge = card.querySelector('.status-badge');
                const errorBlock = card.querySelector('.error-block');
                badge.textContent = online ? txtRunning : txtError;
                badge.className = `status-badge ${online ? 'status-ok' : 'status-error'}`;
                errorBlock.style.display = online ? 'none' : 'block';
            }

            for (let offset = 0; offset < ids.length; offset += 5) {
                const chunk = ids.slice(offset, offset + 5);
                fetch(`index.php?action=status&ids=${encodeURIComponent(chunk.join(','))}`)
                    .then(response => {
                        if (!response.ok) throw new Error('health request failed');
                        return response.json();
                    })
                    .then(data => {
                        chunk.forEach(id => renderHealth(cardMap.get(id), data.results?.[id]?.status === 'ok'));
                    })
                    .catch(() => chunk.forEach(id => renderHealth(cardMap.get(id), false)));
            }
        <?php if ($isAuthenticated): ?>
        // 4. Drag & Drop nativo para reordenar cards no dashboard
        let draggedCard = null;

        function saveCardOrder() {
            const cards = document.querySelectorAll('.tool-card');
            const orders = [];
            cards.forEach((card, index) => {
                orders.push({ id: card.getAttribute('data-id'), order: index });
            });
            
            const formData = new FormData();
            formData.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>');
            formData.append('action', 'reorder_tools');
            formData.append('orders', JSON.stringify(orders));
            
            fetch('index.php', {
                method: 'POST',
                body: formData,
                keepalive: true
            });
        }

        cards.forEach(card => {
            card.addEventListener('dragstart', function(e) {
                draggedCard = this;
                e.dataTransfer.effectAllowed = 'move';
                setTimeout(() => this.style.opacity = '0.5', 0);
            });

            card.addEventListener('dragend', function() {
                this.style.opacity = '1';
                draggedCard = null;
            });

            card.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                
                const bounding = this.getBoundingClientRect();
                // Usa eixo X (horizontal) e eixo Y (vertical) se for grid. O mais comum é verificar posição no Grid, mas X é eficiente
                const offsetX = bounding.x + (bounding.width / 2);
                if (e.clientX - offsetX > 0) {
                    this.style.borderRight = '2px solid #007bff';
                    this.style.borderLeft = '';
                } else {
                    this.style.borderLeft = '2px solid #007bff';
                    this.style.borderRight = '';
                }
            });

            card.addEventListener('dragleave', function() {
                this.style.borderRight = '';
                this.style.borderLeft = '';
            });

            card.addEventListener('drop', function(e) {
                e.preventDefault();
                this.style.borderRight = '';
                this.style.borderLeft = '';
                
                if (draggedCard && draggedCard !== this) {
                    // Para evitar que um card seja arrastado para outra categoria:
                    const dragCat = draggedCard.closest('.category-items');
                    const dropCat = this.closest('.category-items');
                    
                    if (dragCat === dropCat) {
                        const bounding = this.getBoundingClientRect();
                        const offsetX = bounding.x + (bounding.width / 2);
                        
                        if (e.clientX - offsetX > 0) {
                            dropCat.insertBefore(draggedCard, this.nextSibling);
                        } else {
                            dropCat.insertBefore(draggedCard, this);
                        }
                        saveCardOrder();
                    }
                }
            });
        });
        <?php endif; ?>
    });
    </script>
</body>
</html>
