<!-- Developed with care by FACRF - https://github.com/facrf -->
<?php if ($importError !== ''): ?>
<p role="alert" class="import-error"><?= htmlspecialchars($importError, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if (isset($_SESSION['import_preview'])): $preview = $_SESSION['import_preview']; $plan = $preview['plan']; ?>
<section class="import-preview" aria-labelledby="import-preview-title">
    <h3 id="import-preview-title"><?= t('preview_import') ?></h3>
    <p><?= t($plan['replace'] ? 'import_replace' : 'import_append') ?></p>
    <p><?= t('categories_count') ?>: <?= count($plan['categories']) ?> · <?= t('services_count') ?>: <?= count($plan['tools']) ?> · <?= t('ignored_count') ?>: <?= $plan['ignored'] ?> · <?= t('errors_count') ?>: <?= count($plan['errors']) ?></p>
    <?php if ($plan['errors']): ?>
    <details><summary><?= t('import_errors') ?></summary><ul>
        <?php foreach ($plan['errors'] as $error): ?><li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?>
    </ul></details>
    <?php endif; ?>
    <details><summary><?= t('import_services') ?></summary><ul>
        <?php foreach ($plan['tools'] as $tool): ?><li><?= htmlspecialchars($tool['name'] . ' — ' . $tool['url'], ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?>
    </ul></details>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="import_nonce" value="<?= htmlspecialchars($preview['nonce'], ENT_QUOTES, 'UTF-8') ?>">
        <button class="btn btn-glow" name="action" value="confirm_import" type="submit"><?= t('confirm_import') ?></button>
        <button class="btn" name="action" value="cancel_import" type="submit"><?= t('Cancelar') ?></button>
    </form>
</section>
<?php endif; ?>
