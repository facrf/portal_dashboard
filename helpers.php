<?php
/**
 * Validação e respostas compartilhadas pelo Portal Dashboard.
 */

function requireCsrfToken(array $input): void {
    $token = $input['csrf_token'] ?? null;
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        throw new InvalidArgumentException(t('csrf_invalid'));
    }
}

function inputString(array $input, string $key, int $maxLength, bool $required = false): string {
    $value = $input[$key] ?? '';
    if (!is_string($value)) {
        throw new InvalidArgumentException(t('field_invalid', ['field' => $key]));
    }
    $value = trim($value);
    if ($required && $value === '') {
        throw new InvalidArgumentException(t('field_required', ['field' => $key]));
    }
    if (mb_strlen($value, 'UTF-8') > $maxLength) {
        throw new InvalidArgumentException(t('field_long', ['field' => $key]));
    }
    return $value;
}

function inputId(array $input, string $key): int {
    $value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($value === false) {
        throw new InvalidArgumentException(t('id_invalid', ['field' => $key]));
    }
    return (int) $value;
}

function inputInt(array $input, string $key, int $min, int $max, int $default): int {
    $value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT);
    if ($value === false) return $default;
    return max($min, min($max, (int) $value));
}

function validatedColor(string $value, string $fallback = '#007bff'): string {
    return preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtolower($value) : $fallback;
}

function validatedToolUrl(string $value): string {
    $value = trim($value);
    if ($value === '' || mb_strlen($value, 'UTF-8') > 2048) {
        throw new InvalidArgumentException(t('url_invalid'));
    }
    if (preg_match('/^\s*(?:javascript|vbscript|data):/i', $value)) {
        throw new InvalidArgumentException(t('protocol_invalid'));
    }

    $candidate = strpos($value, '://') === false ? 'tcp://' . $value : $value;
    $parts = parse_url($candidate);
    if ($parts === false || empty($parts['host'])) {
        throw new InvalidArgumentException(t('url_invalid'));
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https', 'tcp', 'udp'], true)) {
        throw new InvalidArgumentException(t('protocol_invalid'));
    }
    if (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) {
        throw new InvalidArgumentException(t('port_invalid'));
    }
    return $value;
}

function validatedIconReference(string $value): string {
    $value = trim($value);
    if (mb_strlen($value, 'UTF-8') > 2048 || preg_match('/^\s*(?:javascript|vbscript):/i', $value)) {
        throw new InvalidArgumentException(t('icon_invalid'));
    }
    if (str_starts_with(strtolower($value), 'data:') && !preg_match('~^data:image/(?:png|gif|jpeg|webp|svg\+xml);~i', $value)) {
        throw new InvalidArgumentException(t('image_only'));
    }
    return $value;
}

function validateUsername(string $username): string {
    if (!preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $username)) {
        throw new InvalidArgumentException(t('username_invalid'));
    }
    return $username;
}

function validatePassword(string $password, bool $required): string {
    if ($password === '' && !$required) return '';
    $length = strlen($password);
    if ($length < 10 || $length > 72) {
        throw new InvalidArgumentException(t('password_invalid'));
    }
    return $password;
}

function jsonResponse(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sessionIsValid(array $session, array $user, int $lifetime, int $now): bool {
    $issued = (int) ($session['authenticated_at'] ?? 0);
    return $issued > 0 && $issued <= $now && $now - $issued < $lifetime
        && (int) ($session['session_version'] ?? 0) === (int) $user['session_version'];
}

function validatedHealthSettings(array $input): array {
    $method = inputString($input, 'health_method', 8) ?: 'auto';
    if (!in_array($method, ['auto', 'http', 'tcp', 'ntp'], true)) {
        throw new InvalidArgumentException(t('health_method_invalid'));
    }
    $url = inputString($input, 'health_url', 2048);
    if ($url !== '') validatedToolUrl($url);
    $codes = inputString($input, 'health_codes', 100) ?: '200-399';
    foreach (explode(',', $codes) as $range) {
        if (!preg_match('/^([1-5][0-9]{2})(?:-([1-5][0-9]{2}))?$/', trim($range), $m)
            || (isset($m[2]) && (int) $m[2] < (int) $m[1])) {
            throw new InvalidArgumentException(t('http_codes_invalid'));
        }
    }
    return [$method, $url, $codes];
}

function acceptsHttpCode(int $code, string $allowed): bool {
    foreach (explode(',', $allowed) as $range) {
        $bounds = array_map('intval', explode('-', trim($range)));
        if ($code >= $bounds[0] && $code <= ($bounds[1] ?? $bounds[0])) return true;
    }
    return false;
}

function saveSortOrder(PDO $pdo, string $table, array $input): void {
    if (!in_array($table, ['tools', 'categories'], true)) throw new LogicException('Tabela inválida.');
    $limit = $table === 'tools' ? 5000 : 500;
    $orders = json_decode(inputString($input, 'orders', 300000, true), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($orders) || count($orders) > $limit) throw new InvalidArgumentException(t('order_invalid'));
    $normalized = [];
    foreach ($orders as $order) {
        if (!is_array($order)) throw new InvalidArgumentException(t('order_invalid'));
        $id = inputId($order, 'id');
        $position = filter_var($order['order'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $limit - 1]]);
        if ($position === false || isset($normalized[$id])) throw new InvalidArgumentException(t('order_invalid'));
        $normalized[$id] = (int) $position;
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE {$table} SET sort_order = ? WHERE id = ?");
        foreach ($normalized as $id => $position) $stmt->execute([$position, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function cssImageReference(string $value): string {
    if ($value === '') return 'none';
    // Escapa no contexto CSS; entidades HTML não são decodificadas dentro de <style>.
    $escaped = str_replace(["\\", "'", "\n", "\r", '<', '>'], ['\\\\', "\\'", '\\a ', '\\d ', '\\3c ', '\\3e '], $value);
    return "url('" . $escaped . "')";
}
