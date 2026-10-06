<?php
/** Monitoramento dos destinos cadastrados, com cache e reserva por serviço. */
function healthTarget(array $tool): array {
    $url = $tool['health_url'] ?: $tool['url'];
    $method = $tool['health_method'] ?? 'auto';
    $hasScheme = strpos($url, '://') !== false;
    $parts = parse_url($hasScheme ? $url : 'tcp://' . $url);
    if ($parts === false || empty($parts['host'])) throw new InvalidArgumentException(t('target_invalid'));
    $scheme = strtolower($parts['scheme'] ?? '');
    if ($method === 'auto') {
        $method = $scheme === 'udp' ? 'ntp' : ($scheme === 'tcp' && ($hasScheme || isset($parts['port'])) ? 'tcp' : 'http');
    }
    $port = $parts['port'] ?? ($method === 'ntp' ? 123 : ($scheme === 'https' ? 443 : 80));
    if ($method === 'http') {
        if (!$hasScheme) $url = 'http://' . $url;
        if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException(t('http_target_invalid'));
        }
    }
    $host = trim($parts['host'], '[]');
    if (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.?$/i', $host)) {
        throw new InvalidArgumentException(t('host_invalid'));
    }
    return ['method' => $method, 'url' => $url, 'host' => $host, 'port' => $port];
}

function checkHealth(array $tool): bool {
    try { $target = healthTarget($tool); } catch (InvalidArgumentException $e) { return false; }
    if ($target['method'] === 'http') {
        $curl = curl_init($target['url']);
        curl_setopt_array($curl, [
            CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_TIMEOUT_MS => 2000, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Portal-Dashboard-Health/1.0'
        ]);
        curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $ok = curl_errno($curl) === 0 && acceptsHttpCode($code, $tool['health_codes'] ?? '200-399');
        curl_close($curl);
        return $ok;
    }
    $host = strpos($target['host'], ':') !== false ? '[' . $target['host'] . ']' : $target['host'];
    $transport = $target['method'] === 'ntp' ? 'udp' : 'tcp';
    $fp = @stream_socket_client("{$transport}://{$host}:{$target['port']}", $errno, $errstr, 1.5);
    if (!$fp) return false;
    if ($transport === 'tcp') { fclose($fp); return true; }
    stream_set_timeout($fp, 1, 500000);
    $written = @fwrite($fp, "\x1b" . str_repeat("\0", 47));
    $response = $written === 48 ? @fread($fp, 48) : false;
    fclose($fp);
    if (!is_string($response) || strlen($response) < 48) return false;
    $mode = ord($response[0]) & 7;
    $stratum = ord($response[1]);
    return $mode === 4 && $stratum >= 1 && $stratum <= 15;
}

function cachedHealth(PDO $pdo, array $tool): array {
    $now = time();
    $id = (int) $tool['id'];
    $read = $pdo->prepare('SELECT status, checked_at FROM health_cache WHERE tool_id = ?');
    $read->execute([$id]);
    $cached = $read->fetch();
    if ($cached && $now - (int) $cached['checked_at'] <= 45) {
        return ['status' => $cached['status'] ? 'ok' : 'error', 'checked_at' => (int) $cached['checked_at']];
    }
    $lease = $now + 8;
    $claim = $pdo->prepare('INSERT INTO health_cache (tool_id, status, checked_at, lease_until) VALUES (?, 0, 0, ?)
        ON CONFLICT(tool_id) DO UPDATE SET lease_until=excluded.lease_until
        WHERE health_cache.checked_at < ? AND health_cache.lease_until <= ?');
    $claim->execute([$id, $lease, $now - 45, $now]);
    if ($claim->rowCount() === 0) {
        return ['status' => 'unknown', 'checked_at' => (int) ($cached['checked_at'] ?? 0), 'retry_after' => 3];
    }
    $online = checkHealth($tool);
    // UPDATE impede que uma checagem antiga recrie cache apagado ao editar o serviço.
    $save = $pdo->prepare('UPDATE health_cache SET status=?, checked_at=?, lease_until=0 WHERE tool_id=? AND lease_until=? AND EXISTS (SELECT 1 FROM tools WHERE id=? AND url=? AND health_method=? AND health_url=? AND health_codes=?)');
    $save->execute([$online ? 1 : 0, time(), $id, $lease, $id, $tool['url'], $tool['health_method'] ?? 'auto', $tool['health_url'] ?? '', $tool['health_codes'] ?? '200-399']);
    return ['status' => $save->rowCount() ? ($online ? 'ok' : 'error') : 'unknown', 'checked_at' => time()];
}

function handleHealthRequest(PDO $pdo): void {
    $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
    if (!in_array($action, ['ping', 'status'], true)) return;
    session_write_close();
    header('Cache-Control: no-store');
    if ($action === 'status') {
        $raw = inputString($_GET, 'ids', 100, true);
        if (!preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*){0,9}$/', $raw)) {
            jsonResponse(['status' => 'error', 'msg' => t('ids_invalid')], 422);
        }
        $ids = array_values(array_unique(array_map('intval', explode(',', $raw))));
        $query = $pdo->prepare('SELECT * FROM tools WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $query->execute($ids);
        $results = [];
        foreach ($query->fetchAll() as $tool) $results[(string) $tool['id']] = cachedHealth($pdo, $tool);
        jsonResponse(['status' => 'ok', 'results' => $results]);
    }
    // Clientes antigos compartilham o mesmo cache e a configuração do serviço.
    $tool = null;
    if (is_string($_GET['url'] ?? null)) {
        $query = $pdo->prepare('SELECT * FROM tools WHERE url = ? LIMIT 1');
        $query->execute([$_GET['url']]);
        $tool = $query->fetch();
    } elseif (is_string($_GET['host'] ?? null) && is_scalar($_GET['port'] ?? null)) {
        $host = strtolower(trim(trim($_GET['host']), '[]'));
        $port = filter_var($_GET['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        foreach ($pdo->query('SELECT * FROM tools')->fetchAll() as $candidate) {
            try { $target = healthTarget($candidate); } catch (InvalidArgumentException $e) { continue; }
            if (strtolower($target['host']) === $host && $target['port'] === $port) { $tool = $candidate; break; }
        }
    }
    if (!$tool) jsonResponse(['status' => 'error', 'msg' => t('target_missing')], 403);
    jsonResponse(cachedHealth($pdo, $tool));
}
