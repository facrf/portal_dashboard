/* Valida os scripts e os nomes de campos nas páginas renderizadas pelo PHP. */
const assert = require('node:assert/strict');
const vm = require('node:vm');
const base = (process.argv[2] || 'http://127.0.0.1:80').replace(/\/$/, '');
const cookies = new Map();
async function request(path, fields) {
    const response = await fetch(base + path, {
        method: fields ? 'POST' : 'GET', redirect: 'manual',
        headers: { Cookie: Array.from(cookies, ([key, value]) => `${key}=${value}`).join('; ') },
        body: fields ? new URLSearchParams(fields) : undefined
    });
    for (const header of response.headers.getSetCookie()) {
        const pair = header.split(';')[0];
        const position = pair.indexOf('=');
        cookies.set(pair.slice(0, position), pair.slice(position + 1));
    }
    if (response.status === 302) return request('/' + response.headers.get('location').replace(/^\//, ''));
    const page = await response.text();
    assert.equal(response.status, 200, `HTTP ${response.status}: ${path}`);
    return page;
}
function csrf(page) {
    return page.match(/name="csrf_token" value="([a-f0-9]+)"/)[1];
}
function validate(page, name) {
    for (const match of page.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/g)) {
        if (!/application\/json/.test(match[1]) && !/\bsrc=/.test(match[1])) new vm.Script(match[2], { filename: name });
    }
    const identifiers = Array.from(page.matchAll(/\bid="([^"]+)"/g), match => match[1]);
    assert.equal(new Set(identifiers).size, identifiers.length, `${name}: IDs duplicados`);
    for (const label of page.matchAll(/<label\b([^>]*)>/g)) {
        const target = label[1].match(/\bfor="([^"]+)"/);
        assert.ok(target && identifiers.includes(target[1]), `${name}: label sem campo associado`);
    }
    assert.ok(page.includes('Developed with care by FACRF'), `${name}: assinatura ausente`);
}
(async () => {
    let page = await request('/login.php');
    validate(page, 'login.php');
    page = await request('/login.php', { username: 'integration_admin', password: 'changed-password-123', csrf_token: csrf(page), next: 'admin.php' });
    for (const language of ['en', 'es', 'pt']) {
        const config = await request('/config.php');
        await request('/config.php', { action: 'update_settings', csrf_token: csrf(config), language, portal_name: 'Integration portal', bg_color: '#1e1e2e', text_color: '#cdd6f4', show_clock: '1', show_greeting: '1', greeting_name: 'Integration' });
        for (const path of ['/index.php', '/admin.php', '/config.php']) {
            page = await request(path);
            assert.ok(page.includes(`lang="${language}"`));
            validate(page, path);
        }
    }
    console.log('Páginas renderizadas: scripts válidos, labels associados e IDs únicos nos três idiomas.');
})().catch(error => { console.error(error); process.exitCode = 1; });
