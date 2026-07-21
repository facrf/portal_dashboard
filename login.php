<?php
require_once 'db.php';

// 1. PRIMEIRO: Verifica a requisição de Logout (Agora via POST e com proteção CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'logout') {
    if (!empty($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        session_destroy();
        setcookie(session_name(), '', time() - 3600, '/');
        header("Location: login.php");
        exit;
    } else {
        die("Tentativa de logout bloqueada por falha de segurança (CSRF).");
    }
}

// 2. DEPOIS: Se já estiver logado, redireciona
if (!empty($_SESSION['logged_in'])) {
    header("Location: index.php");
    exit;
}

$userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$isFirstAccess = ($userCount == 0);
$error = '';

// ==========================================
// SISTEMA ANTI BRUTE-FORCE (RATE LIMITING)
// ==========================================
$ip = getClientIp();
$maxAttempts = 5;
$lockoutTime = 900; // 15 minutos em segundos

$stmt = $pdo->prepare("SELECT attempts, last_attempt FROM login_attempts WHERE ip = ?");
$stmt->execute([$ip]);
$attemptData = $stmt->fetch();

if ($attemptData && $attemptData['attempts'] >= $maxAttempts) {
    $timePassed = time() - $attemptData['last_attempt'];
    if ($timePassed < $lockoutTime) {
        $remaining = ceil(($lockoutTime - $timePassed) / 60);
        // Exibe tela estática para não consumir recursos renderizando a página completa
        die("<div style='background:#1e1e2e; color:#ff4d4d; padding:2rem; text-align:center; font-family:sans-serif; border-radius: 8px; max-width: 500px; margin: 10vh auto; border: 1px solid rgba(255,77,77,0.3);'>
            <h2>Acesso Bloqueado</h2>
            <p>Muitas tentativas falhas de login detectadas por este IP.</p>
            <p>Tente novamente em <b>{$remaining} minutos</b>.</p>
        </div>");
    } else {
        // Passou o tempo de castigo, perdoa o IP
        $pdo->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);
        $attemptData = null; 
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validação CSRF
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Sessão expirada ou requisição inválida.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

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
                die("<div style='background:#1e1e2e; color:#ff4d4d; padding:2rem; text-align:center; font-family:sans-serif; border-radius: 8px; max-width: 500px; margin: 10vh auto;'>
                    <h2>Ação Bloqueada</h2>
                    <p>Por segurança, o cadastro inicial exige acesso local e não aceita cabeçalhos de proxy não confiável.</p>
                    <p>Acesse diretamente pela LAN (ex: <i>192.168.x.x</i>) ou configure corretamente
                    <code>PORTAL_TRUSTED_PROXIES</code> antes de realizar o setup.</p>
                </div>");
            }

            if (empty($username) || empty($password)) {
                $error = "Preencha todos os campos.";
            } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
                $error = "O usuário deve conter apenas letras, números, traços e underscores.";
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
                $stmt->execute([$username, $hash]);

                session_regenerate_id(true);
                $_SESSION['logged_in'] = true;
                $_SESSION['username'] = $username;
                header("Location: index.php");
                exit;
            }
        } else {
            // Login padrão
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                
                // Login com sucesso: reseta os bloqueios do IP e previne Fixação de Sessão
                $pdo->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);
                session_regenerate_id(true); 
                
                $_SESSION['logged_in'] = true;
                $_SESSION['username'] = $user['username'];
                header("Location: index.php");
                exit;
                
            } else {
                // Falha no login: Incrementa a tabela de Brute Force
                if ($attemptData) {
                    $pdo->prepare("UPDATE login_attempts SET attempts = attempts + 1, last_attempt = ? WHERE ip = ?")->execute([time(), $ip]);
                } else {
                    $pdo->prepare("INSERT INTO login_attempts (ip, attempts, last_attempt) VALUES (?, 1, ?)")->execute([$ip, time()]);
                }
                
                // Delay aleatório suave (0.5 a 1s) para mitigar Timing Attacks de varredura
                usleep(rand(500000, 1000000)); 
                $error = "Usuário ou senha incorretos.";
            }
        }
    }
}

$settings = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch();
$currentLang = $settings['language'] ?? 'pt';
?>

<!-- ... O Restante do HTML do seu login.php vem aqui para baixo ... -->
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isFirstAccess ? 'Primeiro Acesso' : 'Login' ?> - <?= htmlspecialchars($settings['portal_name']) ?></title>
    
    <?php $favicon = resolveIconUrl($settings['favicon']); if(!empty($favicon)): ?>
        <link rel="icon" href="<?= $favicon ?>">
    <?php endif; ?>

    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <style>
        :root { 
            --bg-color: <?= htmlspecialchars($settings['bg_color']) ?>; 
            --bg-image: <?= !empty($settings['bg_image']) ? "url('" . htmlspecialchars($settings['bg_image']) . "')" : 'none' ?>; 
            --text-color: <?= htmlspecialchars($settings['text_color']) ?>; 
        }
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
            <h2><?= $isFirstAccess ? 'Configurar Administrador' : 'Acesso Restrito' ?></h2>
            
            <?php if ($isFirstAccess): ?>
                <p style="opacity: 0.8; font-size: 0.9rem; margin-bottom: 1.5rem;">Crie o primeiro usuário para acessar o painel.</p>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="error-msg"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div class="form-group">
                    <label>Usuário:</label>
                    <input type="text" name="username" required autofocus autocomplete="username">
                </div>
                <div class="form-group">
                    <label>Senha:</label>
                    <input type="password" name="password" required autocomplete="<?= $isFirstAccess ? 'new-password' : 'current-password' ?>">
                </div>
                <button type="submit" class="btn btn-glow"><?= $isFirstAccess ? 'Cadastrar e Entrar' : 'Entrar' ?></button>
            </form>
        </div>
    </div>
</body>
</html>