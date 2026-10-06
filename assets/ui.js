/* Comportamentos compartilhados da interface. */
(() => {
    const config = JSON.parse(document.getElementById('portal-ui-config').textContent);
    window.toggleTheme = () => {
        document.body.classList.toggle('light-theme');
        try { localStorage.setItem('theme', document.body.classList.contains('light-theme') ? 'light' : 'dark'); } catch (_) { /* Armazenamento opcional. */ }
    };
    try { if (localStorage.getItem('theme') === 'light') document.body.classList.add('light-theme'); } catch (_) { /* Tema padrão. */ }
    function message(text, failed = false) {
        let region = document.getElementById('save-feedback');
        if (!region) {
            region = document.createElement('p');
            region.id = 'save-feedback';
            region.setAttribute('role', 'status');
            region.setAttribute('aria-live', 'polite');
            document.body.append(region);
        }
        region.textContent = text;
        region.classList.toggle('failed', failed);
    }
    window.Portal = {
        createOrderController(containers, endpoint, action, selector) {
            const snapshot = () => containers.map(container => Array.from(container.children));
            let saved = snapshot();
            let saving = false;
            return { async save() {
                if (saving) return;
                saving = true;
                const focus = document.activeElement;
                const next = snapshot();
                const items = containers.flatMap(container => Array.from(container.querySelectorAll(selector)));
                const form = new FormData();
                form.append('csrf_token', config.csrf);
                form.append('action', action);
                form.append('orders', JSON.stringify(items.map((item, order) => ({ id: item.dataset.id, order }))));
                containers.forEach(container => { container.inert = true; container.setAttribute('aria-busy', 'true'); });
                message(config.saving);
                const abort = new AbortController();
                const timeout = setTimeout(() => abort.abort(), 10000);
                try {
                    const response = await fetch(endpoint, { method: 'POST', body: form, signal: abort.signal });
                    const data = await response.json();
                    if (!response.ok || data.status !== 'ok') throw new Error('save failed');
                    saved = next;
                    message(config.saved);
                } catch (_) {
                    saved.forEach((children, index) => children.forEach(child => containers[index].append(child)));
                    message(config.save_error, true);
                } finally {
                    clearTimeout(timeout);
                    containers.forEach(container => { container.inert = false; container.removeAttribute('aria-busy'); });
                    if (focus && focus.isConnected) focus.focus();
                    saving = false;
                }
            } };
        },
        async monitor(cards, running, error, unknown) {
            const list = Array.from(cards);
            let cursor = 0;
            function render(card, state) {
                const badge = card.querySelector('.status-badge');
                badge.textContent = state === 'ok' ? running : (state === 'error' ? error : unknown);
                badge.className = `status-badge ${state === 'ok' ? 'status-ok' : state === 'error' ? 'status-error' : 'status-ping'}`;
                card.querySelector('.error-block').style.display = state === 'error' ? 'block' : 'none';
            }
            async function worker() {
                while (cursor < list.length) {
                    const chunk = list.slice(cursor, cursor += 5);
                    for (let attempt = 0; attempt < 3; attempt++) {
                        const abort = new AbortController();
                        const timeout = setTimeout(() => abort.abort(), 15000);
                        let pending = false;
                        try {
                            const response = await fetch(`index.php?action=status&ids=${encodeURIComponent(chunk.map(card => card.dataset.id).join(','))}`, { signal: abort.signal });
                            if (!response.ok) throw new Error('health failed');
                            const data = await response.json();
                            chunk.forEach(card => {
                                const state = data.results?.[card.dataset.id]?.status || 'unknown';
                                render(card, state);
                                pending ||= state === 'unknown';
                            });
                        } catch (_) { chunk.forEach(card => render(card, 'unknown')); }
                        finally { clearTimeout(timeout); }
                        if (!pending || attempt === 2) break;
                        await new Promise(resolve => setTimeout(resolve, 3000));
                    }
                }
            }
            await Promise.all([worker(), worker()]);
        }
    };
})();
