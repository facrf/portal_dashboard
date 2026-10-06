<?php
/** Leitura, prévia e aplicação transacional de backups e serviços externos. */
require_once __DIR__ . '/health.php';

function normalizeImportedTool(array $tool, string $category, int $position): array {
    [$method, $healthUrl, $codes] = validatedHealthSettings($tool);
    $url = validatedToolUrl(inputString($tool, 'url', 2048, true));
    healthTarget(['url' => $url, 'health_url' => $healthUrl, 'health_method' => $method]);
    return [
        'category_key' => $category, 'name' => inputString($tool, 'name', 120, true),
        'url' => $url, 'icon_url' => validatedIconReference(inputString($tool, 'icon_url', 2048)),
        'description' => inputString($tool, 'description', 500),
        'sort_order' => inputInt($tool, 'sort_order', 0, 4999, $position),
        'tag_name' => inputString($tool, 'tag_name', 30),
        'tag_color' => validatedColor(inputString($tool, 'tag_color', 7)),
        'health_method' => $method, 'health_url' => $healthUrl, 'health_codes' => $codes
    ];
}

function normalizeImportedSettings(array $s): array {
    $language = inputString($s, 'language', 2) ?: 'pt';
    if (!in_array($language, ['pt', 'en', 'es'], true)) throw new InvalidArgumentException(t('language_invalid'));
    return [
        'portal_name' => inputString($s, 'portal_name', 120) ?: 'Meu Portal',
        'favicon' => validatedIconReference(inputString($s, 'favicon', 2048)),
        'bg_color' => validatedColor(inputString($s, 'bg_color', 7), '#1e1e2e'),
        'bg_image' => validatedIconReference(inputString($s, 'bg_image', 2048)),
        'text_color' => validatedColor(inputString($s, 'text_color', 7), '#cdd6f4'),
        'language' => $language, 'footer_text' => inputString($s, 'footer_text', 5000),
        'session_days' => inputInt($s, 'session_days', 1, 365, 7),
        'brute_max_attempts' => inputInt($s, 'brute_max_attempts', 1, 50, 5),
        'brute_lockout_time' => inputInt($s, 'brute_lockout_time', 1, 86400, 900),
        'show_clock' => inputInt($s, 'show_clock', 0, 1, 1),
        'show_greeting' => inputInt($s, 'show_greeting', 0, 1, 1),
        'greeting_name' => inputString($s, 'greeting_name', 80) ?: 'Administrador'
    ];
}

function buildImportPlan(string $content, string $extension): array {
    if (strlen($content) > 5 * 1024 * 1024) throw new InvalidArgumentException(t('import_size_error'));
    $plan = ['replace' => false, 'categories' => [], 'tools' => [], 'settings' => null, 'errors' => [], 'ignored' => 0];
    if ($extension === 'json') {
        $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new InvalidArgumentException(t('json_invalid'));
        if (($data['format'] ?? '') === 'meu_portal_v1') {
            $plan['replace'] = true;
            if (!is_array($data['categories'] ?? null) || !is_array($data['tools'] ?? null)) {
                throw new InvalidArgumentException(t('backup_incomplete'));
            }
            if (count($data['categories']) > 500 || count($data['tools']) > 5000) {
                throw new InvalidArgumentException(t('import_limits'));
            }
            foreach ($data['categories'] as $i => $category) {
                if (!is_array($category)) throw new InvalidArgumentException(t('category_invalid'));
                $key = (string) inputId($category, 'id');
                if (isset($plan['categories'][$key])) throw new InvalidArgumentException(t('category_duplicate'));
                $plan['categories'][$key] = ['name' => inputString($category, 'name', 120, true), 'sort_order' => inputInt($category, 'sort_order', 0, 499, $i)];
            }
            if (!$plan['categories']) $plan['categories']['fallback'] = ['name' => 'Geral', 'sort_order' => 0];
            foreach ($data['tools'] as $i => $tool) {
                if (!is_array($tool)) throw new InvalidArgumentException(t('service_invalid'));
                $key = (string) inputId($tool, 'category_id');
                if (!isset($plan['categories'][$key])) throw new InvalidArgumentException(t('service_orphan'));
                $plan['tools'][] = normalizeImportedTool($tool, $key, $i);
            }
            if (isset($data['settings'])) {
                if (!is_array($data['settings'])) throw new InvalidArgumentException(t('settings_invalid'));
                $plan['settings'] = normalizeImportedSettings($data['settings']);
            }
            return $plan;
        }
        $items = $data['apps'] ?? $data;
        if (!is_array($items) || !array_is_list($items)) throw new InvalidArgumentException(t('heimdall_invalid'));
        $plan['categories']['heimdall'] = ['name' => 'Importado: Heimdall', 'sort_order' => 0];
        if (count($items) > 5000) throw new InvalidArgumentException(t('services_limit'));
        foreach ($items as $i => $item) {
            try {
                if (!is_array($item)) throw new InvalidArgumentException(t('service_invalid'));
                if (!is_string($item['url'] ?? null) || trim($item['url']) === '') { $plan['ignored']++; continue; }
                $item['name'] = $item['title'] ?? $item['name'] ?? 'App';
                $item['icon_url'] = $item['icon'] ?? '';
                $plan['tools'][] = normalizeImportedTool($item, 'heimdall', $i);
            } catch (InvalidArgumentException $e) { $plan['errors'][] = t('import_item_error', ['index' => $i + 1, 'message' => $e->getMessage()]); }
        }
    } elseif (in_array($extension, ['yaml', 'yml'], true)) {
        if (!function_exists('yaml_parse')) throw new InvalidArgumentException(t('yaml_missing'));
        if (substr_count($content, "\n") > 20000) throw new InvalidArgumentException(t('yaml_lines_limit'));
        // Sem aliases, âncoras ou tags explícitas: evita grafos recursivos e desserialização.
        if (preg_match('/(?:^|[\s\[\]{},:])(?:[&*][A-Za-z0-9_-]+|!)/m', $content)) {
            throw new InvalidArgumentException(t('yaml_features_invalid'));
        }
        ini_set('yaml.decode_php', '0');
        $documents = 0;
        $data = @yaml_parse($content, 0, $documents);
        if ($documents !== 1 || !is_array($data) || !array_is_list($data)) throw new InvalidArgumentException(t('yaml_invalid'));
        $seen = 0;
        foreach ($data as $group) {
            if (!is_array($group)) throw new InvalidArgumentException(t('yaml_group_invalid'));
            foreach ($group as $name => $items) {
                if (!is_string($name) || !is_array($items) || !array_is_list($items)) throw new InvalidArgumentException(t('yaml_category_invalid'));
                $key = 'homepage-' . count($plan['categories']);
                $category = inputString(['name' => $name], 'name', 109, true) . ' (Homepage)';
                $plan['categories'][$key] = ['name' => $category, 'sort_order' => count($plan['categories'])];
                if (count($plan['categories']) > 500) throw new InvalidArgumentException(t('categories_limit'));
                foreach ($items as $entry) {
                    if (!is_array($entry)) { $plan['ignored']++; continue; }
                    foreach ($entry as $app => $props) {
                        if (++$seen > 5000) throw new InvalidArgumentException(t('services_limit'));
                        try {
                            if (!is_string($app) || !is_array($props)) throw new InvalidArgumentException(t('yaml_service_invalid'));
                            if (!isset($props['href'])) { $plan['ignored']++; continue; }
                            $tool = ['name' => $app, 'url' => $props['href'], 'icon_url' => $props['icon'] ?? '', 'description' => $props['description'] ?? ''];
                            $plan['tools'][] = normalizeImportedTool($tool, $key, $seen - 1);
                        } catch (InvalidArgumentException $e) { $plan['errors'][] = t('import_item_error', ['index' => $seen, 'message' => $e->getMessage()]); }
                    }
                }
            }
        }
    } else { throw new InvalidArgumentException(t('format_invalid')); }
    if (!$plan['tools']) throw new InvalidArgumentException(t('import_empty'));
    // Categorias sem serviços válidos não são criadas na importação externa.
    $used = array_fill_keys(array_column($plan['tools'], 'category_key'), true);
    $plan['categories'] = array_intersect_key($plan['categories'], $used);
    return $plan;
}

function importFingerprint(PDO $pdo): string {
    $data = [];
    foreach (['settings', 'categories', 'tools'] as $table) $data[$table] = $pdo->query("SELECT * FROM {$table} ORDER BY id")->fetchAll();
    return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
}

function applyImportPlan(PDO $pdo, array $plan): void {
    $pdo->beginTransaction();
    try {
        // Adquire o lock de escrita antes de comparar a prévia com o estado atual.
        $pdo->exec('UPDATE settings SET id=id WHERE id=1');
        if (isset($plan['fingerprint']) && !hash_equals($plan['fingerprint'], importFingerprint($pdo))) {
            throw new InvalidArgumentException(t('import_changed'));
        }
        if ($plan['replace']) {
            $pdo->exec('DELETE FROM health_cache');
            $pdo->exec('DELETE FROM tools');
            $pdo->exec('DELETE FROM categories');
        }
        $map = [];
        $categoryInsert = $pdo->prepare('INSERT INTO categories (name, sort_order) VALUES (?, ?)');
        foreach ($plan['categories'] as $key => $category) {
            $categoryInsert->execute([$category['name'], $category['sort_order']]);
            $map[$key] = (int) $pdo->lastInsertId();
        }
        $insert = $pdo->prepare('INSERT INTO tools (category_id, name, url, icon_url, description, sort_order, tag_name, tag_color, health_method, health_url, health_codes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($plan['tools'] as $tool) {
            $key = $tool['category_key'];
            unset($tool['category_key']);
            $insert->execute(array_merge([$map[$key]], array_values($tool)));
        }
        if ($plan['settings'] !== null) {
            $settings = $plan['settings'];
            $assignments = implode(', ', array_map(fn($column) => $column . '=?', array_keys($settings)));
            $pdo->prepare('UPDATE settings SET ' . $assignments . ' WHERE id=1')->execute(array_values($settings));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
