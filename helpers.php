<?php
/**
 * Validação e respostas compartilhadas pelo Portal Dashboard.
 */

function requireCsrfToken(array $input): void {
    $token = $input['csrf_token'] ?? null;
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        throw new InvalidArgumentException('Ação bloqueada: token CSRF inválido.');
    }
}

function inputString(array $input, string $key, int $maxLength, bool $required = false): string {
    $value = $input[$key] ?? '';
    if (!is_string($value)) {
        throw new InvalidArgumentException("Campo inválido: {$key}.");
    }
    $value = trim($value);
    if ($required && $value === '') {
        throw new InvalidArgumentException("Campo obrigatório: {$key}.");
    }
    if (mb_strlen($value, 'UTF-8') > $maxLength) {
        throw new InvalidArgumentException("Campo muito longo: {$key}.");
    }
    return $value;
}

function inputId(array $input, string $key): int {
    $value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($value === false) {
        throw new InvalidArgumentException("Identificador inválido: {$key}.");
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
        throw new InvalidArgumentException('URL de destino inválida.');
    }
    if (preg_match('/^\s*(?:javascript|vbscript|data):/i', $value)) {
        throw new InvalidArgumentException('O protocolo informado não é permitido.');
    }

    $candidate = strpos($value, '://') === false ? 'tcp://' . $value : $value;
    $parts = parse_url($candidate);
    if ($parts === false || empty($parts['host'])) {
        throw new InvalidArgumentException('URL de destino inválida.');
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https', 'tcp', 'udp'], true)) {
        throw new InvalidArgumentException('O protocolo informado não é permitido.');
    }
    if (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) {
        throw new InvalidArgumentException('Porta inválida.');
    }
    return $value;
}

function validatedIconReference(string $value): string {
    $value = trim($value);
    if (mb_strlen($value, 'UTF-8') > 2048 || preg_match('/^\s*(?:javascript|vbscript):/i', $value)) {
        throw new InvalidArgumentException('Referência de ícone inválida.');
    }
    if (str_starts_with(strtolower($value), 'data:') && !preg_match('~^data:image/(?:png|gif|jpeg|webp|svg\+xml);~i', $value)) {
        throw new InvalidArgumentException('Somente imagens são aceitas como data URI.');
    }
    return $value;
}

function validateUsername(string $username): string {
    if (!preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $username)) {
        throw new InvalidArgumentException('O usuário deve ter até 64 caracteres e conter somente letras, números, ponto, traço ou underscore.');
    }
    return $username;
}

function validatePassword(string $password, bool $required): string {
    if ($password === '' && !$required) return '';
    $length = strlen($password);
    if ($length < 10 || $length > 72) {
        throw new InvalidArgumentException('A senha deve ter entre 10 e 72 caracteres.');
    }
    return $password;
}

function jsonResponse(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
