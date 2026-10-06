<?php
/**
 * Portal Dashboard
 *
 * @author    FACRF
 * @copyright 2026 FACRF
 * @link      https://github.com/facrf/portal_dashboard
 */
require_once 'db.php';

// Somente destinos administrativos internos são aceitos.
$next = $_POST['next'] ?? $_GET['next'] ?? 'config.php';
if (!in_array($next, ['admin.php', 'config.php'], true)) {
    $next = 'config.php';
}

// 1. PRIMEIRO: Verifica a requisição de Logout (Agora via POST e com proteção CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'logout') {
    $logoutToken = $_POST['csrf_token'] ?? null;
    if (is_string($logoutToken) && hash_equals($_SESSION['csrf_token'] ?? '', $logoutToken)) {
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
    } else {
        http_response_code(403);
        die(t('csrf_invalid'));
    }
}

// 2. DEPOIS: Se já estiver logado, redireciona
if ($isAuthenticated) {
    header('Location: ' . $next);
    exit;
}

$userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$isFirstAccess = ($userCount == 0);
$error = '';

$settings = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch();

// ==========================================
// SISTEMA ANTI BRUTE-FORCE (RATE LIMITING)
// ==========================================
$ip = getClientIp();
$maxAttempts = (int)($settings['brute_max_attempts'] ?? 5);
$lockoutTime = (int)($settings['brute_lockout_time'] ?? 900);
$pdo->prepare("DELETE FROM login_attempts WHERE last_attempt < ?")
    ->execute([time() - max(86400, $lockoutTime * 2)]);

$stmt = $pdo->prepare("SELECT attempts, last_attempt FROM login_attempts WHERE ip = ?");
$stmt->execute([$ip]);
$attemptData = $stmt->fetch();

if ($attemptData && $attemptData['attempts'] >= $maxAttempts) {
    $timePassed = time() - $attemptData['last_attempt'];
    if ($timePassed < $lockoutTime) {
        $remaining = ceil(($lockoutTime - $timePassed) / 60);
        http_response_code(429);
        header('Retry-After: ' . ($lockoutTime - $timePassed));
        die(htmlspecialchars(t('login_blocked_help') . ' ' . t('login_wait', ['minutes' => $remaining]), ENT_QUOTES, 'UTF-8'));
    } else {
        // Passou o tempo de castigo, perdoa o IP
        $pdo->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);
        $attemptData = null; 
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validação CSRF
    $submittedToken = $_POST['csrf_token'] ?? null;
    if (!is_string($submittedToken) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $error = t('session_invalid');
    } else {
        $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

        if ($isFirstAccess) {
            
            // ==========================================
            // PREVENÇÃO DE SEQUESTRO DO BOOTSTRAP INICIAL
            // ==========================================
            // O primeiro cadastro exige que tanto o cliente quanto o proxy sejam locais/privados.
            // Assim, LAN direta e proxy local funcionam, mas um acesso público encaminhado é bloqueado.
            $peerIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $isLocalSetup = isLocalOrPrivateIp($peerIp)
                && isLocalOrPrivateIp($ip)
                && !hasUntrustedProxyHeaders();

            if (!$isLocalSetup) {
                http_response_code(403);
                die(htmlspecialchars(t('setup_blocked'), ENT_QUOTES, 'UTF-8'));
            }

            try {
                validateUsername($username);
                validatePassword($password, true);
                $pdo->exec('BEGIN IMMEDIATE');
                if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
                    $pdo->exec('ROLLBACK');
                    throw new InvalidArgumentException(t('setup_already_done'));
                }
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
                $stmt->execute([$username, $hash]);
                $userId = (int) $pdo->lastInsertId();
                $pdo->exec('COMMIT');

                startAuthenticatedSession(['id' => $userId, 'username' => $username, 'session_version' => 1]);
                header('Location: ' . $next);
                exit;
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        } else {
            // Login padrão
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if (strlen($password) > 72) {
                $user = false; // Bloqueia senhas gigantes para evitar DoS no password_verify
            }

            if ($user && password_verify($password, $user['password'])) {
                
                // Login com sucesso: reseta os bloqueios do IP e previne Fixação de Sessão
                $pdo->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);
                startAuthenticatedSession($user);
                header('Location: ' . $next);
                exit;
                
            } else {
                // Falha no login: Incrementa a tabela de Brute Force
                $pdo->prepare("INSERT INTO login_attempts (ip, attempts, last_attempt) VALUES (?, 1, ?)
                    ON CONFLICT(ip) DO UPDATE SET attempts = attempts + 1, last_attempt = excluded.last_attempt")
                    ->execute([$ip, time()]);
                
                // Delay aleatório suave (0.5 a 1s) para mitigar Timing Attacks de varredura
                usleep(rand(500000, 1000000)); 
                $error = t('login_invalid');
            }
        }
    }
}

$currentLang = $settings['language'] ?? 'pt';
?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <!-- Developed with care by FACRF - https://github.com/facrf -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isFirstAccess ? t('first_access') : t('login_title') ?> - <?= htmlspecialchars($settings['portal_name'], ENT_QUOTES, 'UTF-8') ?></title>
    
    <?php require __DIR__ . '/templates/head-assets.php'; ?>
    <style>
        .login-container {
            max-width: 400px;
            margin: 10vh auto;
            background: rgba(0, 0, 0, 0.4);
            padding: 2.5rem;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            text-align: center;
        }
        .login-container h2 { margin-top: 0; margin-bottom: 1.5rem; }
        .login-container .form-group { text-align: left; }
        .login-container input { width: 100%; box-sizing: border-box; }
        .login-container button { width: 100%; margin-top: 1rem; padding: 0.8rem; }
        .error-msg { background: rgba(220, 53, 69, 0.2); color: #ff6b6b; padding: 10px; border-radius: 6px; margin-bottom: 15px; border: 1px solid rgba(220, 53, 69, 0.4); }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <h2><?= $isFirstAccess ? t('setup_admin') : t('restricted_access') ?></h2>
            
            <?php if ($isFirstAccess): ?>
                <p style="opacity: 0.8; font-size: 0.9rem; margin-bottom: 1.5rem;"><?= t('setup_help') ?></p>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="error-msg"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-group">
                    <label for="login-username-1"><?= t('username') ?>:</label>
                    <input id="login-username-1" type="text" name="username" required maxlength="64" autofocus autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="login-password-2"><?= t('password') ?>:</label>
                    <input id="login-password-2" type="password" name="password" required minlength="10" maxlength="72" autocomplete="<?= $isFirstAccess ? 'new-password' : 'current-password' ?>">
                </div>
                <button type="submit" class="btn btn-glow"><?= $isFirstAccess ? t('setup_submit') : t('login_submit') ?></button>
            </form>
            <p><a href="index.php" class="btn">← <?= t('dashboard') ?></a></p>
        </div>
    </div>
</body>
</html>
