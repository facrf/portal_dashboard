<?php
// ==========================================
// MOTOR MULTI-IDIOMAS (i18n)
// ==========================================
$langCode = 'pt';
$allowedLangs = ['pt', 'es', 'en'];
try {
    $settingsLang = $pdo->query("SELECT language FROM settings LIMIT 1")->fetchColumn();
    if ($settingsLang && in_array($settingsLang, $allowedLangs)) { $langCode = $settingsLang; }
} catch (PDOException $e) {}

$langCode = basename($langCode);
$langFile = __DIR__ . "/lang/{$langCode}.php";
$langData = file_exists($langFile) ? include($langFile) : include(__DIR__ . "/lang/pt.php");

function t(string $key, array $parameters = []): string {
    global $langData;
    $text = $langData[$key] ?? $key;
    foreach ($parameters as $name => $value) $text = str_replace('{' . $name . '}', (string) $value, $text);
    return $text;
}

// ==========================================
// RESOLUÇÃO DE ÍCONES
// ==========================================
function resolveIconUrl($icon) {
    $icon = trim($icon);
    if (empty($icon)) return '';
    if (preg_match('~^(https?://|data:|/|\\.\\./|\\./)~i', $icon) || strpos($icon, '/') !== false) { 
        return htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); 
    }
    return htmlspecialchars('icons/' . $icon, ENT_QUOTES, 'UTF-8');
}
