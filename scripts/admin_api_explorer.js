// The Explorer runs GET requests in the browser using the viewer's normal session.
(() => {
    const form = document.getElementById('api-request');
    const base = form.closest('[data-api-base]').dataset.apiBase;
    const preset = document.getElementById('api-preset');
    const endpoint = document.getElementById('api-endpoint');
    const status = document.getElementById('api-status');
    const elapsed = document.getElementById('api-elapsed');
    const requested = document.getElementById('api-requested');
    const output = document.getElementById('api-output');
    const raw = document.getElementById('api-raw');
    const copy = document.getElementById('api-copy');
    const copyStatus = document.getElementById('api-copy-status');
    let active;
    let body = '';
    let formatted;

    function resolveEndpoint(value) {
        let path = value.trim() || base + '/health';
        // Accept /api/v1 shorthand even when the forum is installed in a subdirectory.
        if (/^\/?api\/v1(?=[/?#]|$)/.test(path)) {
            path = path.replace(/^\/?api\/v1/, base);
        } else if (!path.startsWith('/') && !/^[a-z][a-z\d+.-]*:/i.test(path)) {
            path = base + '/' + path;
        }
        const url = new URL(path, location.origin);
        if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) {
            throw new Error('Enter an HTTP or HTTPS endpoint without embedded credentials.');
        }
        url.hash = '';
        return url;
    }

    function matchPreset() {
        const option = Array.from(preset.options).find(item => item.value === endpoint.value);
        preset.value = option ? option.value : '';
    }

    function renderBody() {
        output.textContent = body === '' ? 'Empty response body.' :
            (raw.checked || formatted === undefined ? body : formatted);
    }

    async function send() {
        if (active) active.abort();
        const request = new AbortController();
        active = request;
        const start = performance.now();
        body = '';
        formatted = undefined;
        raw.disabled = copy.disabled = true;
        output.textContent = requested.textContent = elapsed.textContent = copyStatus.textContent = '';
        status.dataset.state = 'loading';
        status.textContent = 'Loading…';
        output.setAttribute('aria-busy', 'true');
        try {
            const url = resolveEndpoint(endpoint.value);
            const address = url.origin === location.origin ? url.pathname + url.search : url.href;
            endpoint.value = address;
            requested.textContent = address;
            matchPreset();
            const response = await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                referrerPolicy: 'no-referrer',
                headers: { Accept: 'application/json' },
                signal: request.signal
            });
            const text = await response.text();
            if (active !== request) return;
            body = text;
            try {
                formatted = JSON.stringify(JSON.parse(text), null, 2);
            } catch {
                // Non-JSON responses, including error pages, are shown as literal text.
            }
            raw.disabled = formatted === undefined;
            copy.disabled = text.length === 0;
            status.dataset.state = response.ok ? 'ok' : 'error';
            status.textContent = (response.status + ' ' + response.statusText).trim();
            elapsed.textContent = Math.round(performance.now() - start) + ' ms';
            renderBody();
        } catch (error) {
            if (active !== request) return;
            status.dataset.state = 'error';
            status.textContent = 'Request failed';
            output.textContent = error.message || 'Could not read the response.';
        } finally {
            if (active === request) output.removeAttribute('aria-busy');
        }
    }

    preset.addEventListener('change', () => {
        if (preset.value) endpoint.value = preset.value;
        else endpoint.focus();
    });
    endpoint.addEventListener('input', matchPreset);
    form.addEventListener('submit', event => {
        event.preventDefault();
        send();
    });
    raw.addEventListener('change', () => {
        copyStatus.textContent = '';
        renderBody();
    });
    copy.addEventListener('click', async () => {
        const text = output.textContent;
        const request = active;
        try {
            await navigator.clipboard.writeText(text);
            if (request === active) copyStatus.textContent = 'Response copied.';
        } catch {
            if (request !== active) return;
            output.focus();
            const range = document.createRange();
            range.selectNodeContents(output);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            copyStatus.textContent = 'Response selected. Use your browser’s Copy command.';
        }
    });

    document.getElementById('api-send').disabled = false;
    send();
})();
