"""Exercita o portal real em um contêiner descartável com banco vazio."""
import http.cookiejar
import json
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:18080').rstrip('/')


def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


def request(opener, path, data=None, headers=None):
    if isinstance(data, dict):
        data = urllib.parse.urlencode(data).encode()
    try:
        response = opener.open(urllib.request.Request(BASE + path, data=data, headers=headers or {}), timeout=20)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, response.read().decode(), response.headers


def token(page):
    match = re.search(r'name="csrf_token" value="([a-f0-9]+)"', page)
    assert match, 'Token CSRF ausente'
    return match.group(1)


def upload(opener, csrf, content):
    boundary = 'PortalIntegrationBoundary'
    fields = [('csrf_token', csrf), ('action', 'import')]
    parts = [f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n' for name, value in fields]
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="import_file"; filename="backup.json"\r\nContent-Type: application/json\r\n\r\n{json.dumps(content)}\r\n--{boundary}--\r\n')
    return request(opener, '/config.php', ''.join(parts).encode(), {'Content-Type': 'multipart/form-data; boundary=' + boundary})


visitor = client()
for attempt in range(100):
    try:
        status, page, headers = request(visitor, '/index.php')
        if status == 200:
            break
    except (urllib.error.URLError, ConnectionError):
        pass
    time.sleep(.1)
else:
    raise AssertionError('Servidor não iniciou')
assert 'Developed with care by FACRF' in page
assert 'Content-Security-Policy' in headers
for path in ['/db_data/bd.db', '/db_data/bd.db-wal', '/db_data/bd.db-shm', '/tests/run.php', '/templates/head-assets.php', '/database.php', '/.git/config']:
    assert request(visitor, path)[0] == 403, 'Arquivo acessível: ' + path
assert request(visitor, '/admin.php')[1].find('login-username-1') >= 0
assert request(visitor, '/index.php', {'action': 'reorder_tools', 'orders': '[]'})[0] == 403

admin = client()
csrf = token(request(admin, '/login.php')[1])
status, page, _ = request(admin, '/login.php', {'username': 'integration_admin', 'password': 'integration-password-123', 'csrf_token': csrf, 'next': 'admin.php'})
assert status == 200 and 'health_method' in page, 'Bootstrap falhou'
csrf = token(page)
category = re.search(r'<option value="(\d+)"', page).group(1)
assert request(admin, '/admin.php', {'action': 'add_tool', 'csrf_token': 'wrong'})[0] == 403
status, page, _ = request(admin, '/admin.php', {
    'action': 'add_tool', 'csrf_token': csrf, 'name': 'Integration service', 'url': 'https://example.test',
    'category_id': category, 'health_method': 'http', 'health_url': 'http://127.0.0.1/', 'health_codes': '200-399',
})
assert status == 200
public = request(visitor, '/index.php')[1]
id_ = re.search(r'data-id="(\d+)"', public).group(1)
status, data, _ = request(visitor, '/index.php?action=status&ids=' + id_)
assert status == 200 and json.loads(data)['results'][id_]['status'] == 'ok'
assert json.loads(request(visitor, '/index.php?action=ping&url=https%3A%2F%2Fexample.test')[1])['status'] == 'ok'
assert request(visitor, '/index.php?action=ping&url=http%3A%2F%2Funregistered.test')[0] == 403
assert request(visitor, '/index.php?action=status&ids=1,invalid')[0] == 422

# Prévia não grava dados; confirmação aplica uma única vez e mantém as contas.
csrf = token(request(admin, '/config.php')[1])
backup = {'format': 'meu_portal_v1', 'categories': [{'id': 10, 'name': 'Imported category'}], 'tools': [{'name': 'Imported service', 'url': 'https://example.test', 'category_id': 10}]}
status, preview, _ = upload(admin, csrf, backup)
assert status == 200 and 'confirm_import' in preview
assert 'Integration service' in request(visitor, '/index.php')[1]
assert 'Imported service' not in request(visitor, '/index.php')[1]
nonce = re.search(r'name="import_nonce" value="([a-f0-9]+)"', preview).group(1)
status, page, _ = request(admin, '/config.php', {'action': 'confirm_import', 'csrf_token': csrf, 'import_nonce': nonce})
assert status == 200 and 'Imported service' in request(visitor, '/index.php')[1]
assert 'Integration service' not in request(visitor, '/index.php')[1]
assert request(admin, '/config.php', {'action': 'confirm_import', 'csrf_token': csrf, 'import_nonce': nonce})[0] == 422

# Trocar a senha revoga também a sessão de outro navegador.
second = client()
csrf2 = token(request(second, '/login.php')[1])
status, page, _ = request(second, '/login.php', {'username': 'integration_admin', 'password': 'integration-password-123', 'csrf_token': csrf2, 'next': 'admin.php'})
assert 'health_method' in page
user_id = re.search(r'edit_user=(\d+)', page).group(1)
csrf = token(request(admin, '/admin.php')[1])
request(admin, '/admin.php', {'action': 'edit_user', 'user_id': user_id, 'username': 'integration_admin', 'password': 'changed-password-123', 'csrf_token': csrf})
assert 'login-username-1' in request(second, '/admin.php')[1], 'Outra sessão permaneceu válida após trocar senha'
assert 'login-username-1' in request(admin, '/admin.php')[1], 'Sessão atual permaneceu válida após trocar senha'
print('HTTP: portal público, proteção de arquivos, CSRF, bootstrap, cache, prévia, restauração e revogação passaram.')
