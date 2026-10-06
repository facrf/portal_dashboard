<!-- Developed with care by FACRF - https://github.com/facrf -->
<?php $favicon = resolveIconUrl($settings['favicon'] ?? ''); if ($favicon !== ''): ?>
<link rel="icon" href="<?= $favicon ?>">
<?php endif; ?>
<meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/../style.css') ?>">
<style>
:root {
    --bg-color: <?= validatedColor((string) ($settings['bg_color'] ?? ''), '#1e1e2e') ?>;
    --bg-image: <?= cssImageReference((string) ($settings['bg_image'] ?? '')) ?>;
    --text-color: <?= validatedColor((string) ($settings['text_color'] ?? ''), '#cdd6f4') ?>;
}
</style>
<script id="portal-ui-config" type="application/json"><?= json_encode([
    'csrf' => $_SESSION['csrf_token'], 'saving' => t('saving'), 'saved' => t('saved'), 'save_error' => t('save_error')
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<script src="assets/ui.js?v=<?= filemtime(__DIR__ . '/../assets/ui.js') ?>" defer></script>
