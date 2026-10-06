/* Testa confirmação e restauração da ordem sem dependências de navegador. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function element(id) {
    return { dataset: { id }, isConnected: true, focus() {} };
}
function container(children) {
    return {
        children, inert: false, attributes: {},
        querySelectorAll() { return this.children; },
        setAttribute(key, value) { this.attributes[key] = value; },
        removeAttribute(key) { delete this.attributes[key]; },
        append(child) { this.children = this.children.filter(item => item !== child); this.children.push(child); }
    };
}
const config = { csrf: 'token', saving: 'saving', saved: 'saved', save_error: 'failed' };
const feedback = { textContent: '', classList: { toggle() {} } };
global.document = {
    getElementById(id) { return id === 'portal-ui-config' ? { textContent: JSON.stringify(config) } : feedback; },
    body: { classList: { add() {}, toggle() {}, contains() { return false; } } }, activeElement: element('focus')
};
global.window = {};
global.localStorage = { getItem() { throw new Error('storage blocked'); } };
let respond;
global.fetch = () => new Promise(resolve => { respond = resolve; });
vm.runInThisContext(fs.readFileSync('assets/ui.js', 'utf8'));

(async () => {
    const a = element('1'), b = element('2'), c = element('3');
    const table = container([a, b, c]);
    const controller = window.Portal.createOrderController([table], 'admin.php', 'reorder_tools', 'tr');
    table.children = [b, a, c];
    const success = controller.save();
    assert.equal(table.inert, true);
    respond({ ok: true, async json() { return { status: 'ok' }; } });
    await success;
    assert.equal(table.inert, false);
    assert.equal(feedback.textContent, 'saved');
    table.children = [c, b, a];
    const failure = controller.save();
    respond({ ok: false, async json() { return { status: 'error' }; } });
    await failure;
    assert.deepEqual(table.children.map(item => item.dataset.id), ['2', '1', '3']);
    assert.equal(feedback.textContent, 'failed');
    assert.equal(table.inert, false);
    global.fetch = async () => { throw new Error('offline'); };
    table.children = [a, c, b];
    await controller.save();
    assert.deepEqual(table.children.map(item => item.dataset.id), ['2', '1', '3']);
    console.log('JavaScript: confirmação, erro HTTP, rede indisponível e restauração passaram.');
})().catch(error => { console.error(error); process.exitCode = 1; });
