<!-- Developed with care by FACRF - https://github.com/facrf -->
<?php $favicon = resolveIconUrl($settings['favicon'] ?? ''); ?>
<link rel="icon" type="<?= str_ends_with(strtolower($favicon), '.svg') || $favicon === '' ? 'image/svg+xml' : 'image/png' ?>" href="<?= $favicon !== '' ? $favicon : 'assets/icon.svg' ?>">
<link rel="apple-touch-icon" href="<?= $favicon !== '' ? $favicon : 'assets/icon.svg' ?>">
<link rel="manifest" href="manifest.webmanifest">
<meta name="theme-color" content="<?= validatedColor((string) ($settings['bg_color'] ?? ''), '#1e1e2e') ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/../style.css') ?>">
<style>
:root {
    --bg-color: <?= validatedColor((string) ($settings['bg_color'] ?? ''), '#1e1e2e') ?>;
    --bg-image: <?= cssImageReference((string) ($settings['bg_image'] ?? '')) ?>;
    --text-color: <?= validatedColor((string) ($settings['text_color'] ?? ''), '#cdd6f4') ?>;
}
</style>
<script>
try {
    const t = localStorage.getItem('theme');
    if (t === 'light' || (!t && window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches)) {
        document.documentElement.classList.add('light-theme');
        document.addEventListener('DOMContentLoaded', () => document.body.classList.add('light-theme'));
    }
} catch (_) {}
</script>
<script id="portal-ui-config" type="application/json"><?= json_encode([
    'csrf' => $_SESSION['csrf_token'], 'saving' => t('saving'), 'saved' => t('saved'), 'save_error' => t('save_error')
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<script src="assets/ui.js?v=<?= filemtime(__DIR__ . '/../assets/ui.js') ?>" defer></script>
