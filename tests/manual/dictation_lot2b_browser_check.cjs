// Run only against serve_dictation_lot2b_browser.php and an isolated headless Chrome profile.
const fs = require('node:fs');
const origin = 'http://127.0.0.1:8815';
const cdp = 'http://127.0.0.1:8816';
const draftId = Number(process.argv[2] || 2);
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

(async () => {
    const tab = await fetch(`${cdp}/json/new?about:blank`, { method: 'PUT' }).then(r => r.json());
    const ws = new WebSocket(tab.webSocketDebuggerUrl);
    await new Promise(resolve => ws.addEventListener('open', resolve, { once: true }));
    let sequence = 0;
    const pending = new Map(), errors = [];
    ws.addEventListener('message', event => {
        const data = JSON.parse(event.data);
        if (data.method === 'Runtime.exceptionThrown') errors.push(data.params.exceptionDetails.exception?.description || data.params.exceptionDetails.text);
        if (data.method === 'Runtime.consoleAPICalled' && data.params.type === 'error') errors.push(data.params.args.map(a => a.value || a.description).join(' '));
        if (data.method === 'Log.entryAdded' && data.params.entry.level === 'error') errors.push(data.params.entry.text);
        if (data.id && pending.has(data.id)) {
            const { resolve, reject } = pending.get(data.id); pending.delete(data.id);
            data.error ? reject(data.error) : resolve(data.result);
        }
    });
    const send = (method, params = {}) => new Promise((resolve, reject) => {
        const id = ++sequence; pending.set(id, { resolve, reject }); ws.send(JSON.stringify({ id, method, params }));
    });
    const evaluate = async expression => {
        const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        return result.result.value;
    };
    const wait = async expression => {
        for (let i = 0; i < 100; i++) { if (await evaluate(`Boolean(${expression})`)) return; await delay(100); }
        throw Error('Timeout: ' + expression + '\n' + errors.join('\n'));
    };
    const check = async (expression, label) => {
        if (!await evaluate(expression)) throw Error(label);
        console.log('OK ' + label);
    };
    const screenshot = async name => {
        await delay(300); // Let the existing EasyAdmin color transitions settle.
        const result = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
        fs.writeFileSync(`var/dictation-${name}.png`, Buffer.from(result.data, 'base64'));
    };
    try {
        await send('Page.enable'); await send('Runtime.enable'); await send('Log.enable');
        await send('Emulation.setDeviceMetricsOverride', { width: 1400, height: 1100, deviceScaleFactor: 1, mobile: false });
        await send('Page.navigate', { url: origin + '/admin/dictees/versions/' + draftId });
        await wait(`document.querySelector('[data-controller="dictation-editor"]') && document.querySelectorAll('audio').length === 2 && [...document.querySelectorAll('audio')].every(a => a.readyState >= 1)`);
        await check(`document.querySelectorAll('audio').length === 2 && [...document.querySelectorAll('audio')].every(a => a.duration >= 2 && a.duration < 2.1)`, 'deux pistes MP3, durées décodées par Chrome');
        await evaluate(`document.querySelector('[data-ea-color-scheme="light"]').click()`);
        await wait(`document.body.dataset.eaColorScheme === 'light' || document.documentElement.dataset.eaColorScheme === 'light' || document.body.classList.contains('ea-light-scheme')`);
        await screenshot('light');
        await evaluate(`document.querySelector('[data-ea-color-scheme="dark"]').click()`);
        await wait(`document.body.dataset.eaColorScheme === 'dark' || document.documentElement.dataset.eaColorScheme === 'dark' || document.body.classList.contains('ea-dark-scheme')`);
        await wait(`getComputedStyle(document.querySelector('.dictation-editor .btn-secondary')).color === getComputedStyle(document.querySelector('.dictation-editor')).color`);
        await check(`getComputedStyle(document.querySelector('.dictation-editor .btn-secondary')).color === getComputedStyle(document.querySelector('.dictation-editor')).color`, 'contraste des boutons secondaires en thème sombre');
        await screenshot('dark');
        console.log('OK thèmes clair et sombre');
        await evaluate(`document.querySelector('[data-collection="segments"] > button').focus()`);
        await send('Input.dispatchKeyEvent', { type: 'rawKeyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
        await send('Input.dispatchKeyEvent', { type: 'char', text: '\r', unmodifiedText: '\r', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
        await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
        await wait(`document.querySelectorAll('[data-collection="segments"] [data-row]').length === 2`);
        await check(`document.activeElement.matches('input[data-bound="start"]')`, 'ajout au clavier et focus sur début');
        await evaluate(`document.querySelector('[data-collection="segments"] [data-row]:last-child [data-action$="#remove"]').click()`);
        await evaluate(`document.querySelector('audio[data-dictation-editor-target="audio"]').currentTime = 0.4`);
        await delay(150);
        await evaluate(`document.querySelector('[data-action$="#capture"][data-bound="start"]').click()`);
        await check(`Number(document.querySelector('input[data-bound="start"]').value) >= 390`, 'report de position courante');
        await evaluate(`document.querySelector('input[data-bound="start"]').value = 0; document.querySelector('[data-action$="#play"]').click()`);
        await delay(1400);
        await check(`document.querySelector('audio[data-dictation-editor-target="audio"]').paused && Math.abs(document.querySelector('audio[data-dictation-editor-target="audio"]').currentTime - 1) < 0.06`, 'lecture de segment arrêtée à 1 000 ms');
        await evaluate(`document.querySelector('form[action$="/clone"] button').click()`);
        await check(`document.querySelector('[role="status"]').textContent.includes('Enregistrez') && location.pathname.endsWith('/${draftId}')`, 'saisie non enregistrée protégée avant duplication');
        await evaluate(`document.querySelector('#form_save').click()`);
        await wait(`document.querySelector('.alert-success')`);
        await evaluate(`document.querySelector('#form_publish').click()`);
        await wait(`document.body?.textContent.includes('Publiée : contenu figé')`);
        await check(`document.querySelector('#form_text').disabled && !document.querySelector('#form_save')`, 'publication puis consultation immuable');
        await evaluate(`document.querySelector('form[action$="/clone"] button').click()`);
        await wait(`location.pathname.endsWith('/${draftId + 1}') && document.querySelector('#form_save')`);
        await check(`document.querySelectorAll('audio').length === 2 && document.querySelectorAll('[data-collection="segments"] [data-row]').length === 1`, 'nouvelle version avec références audio et segments conservés');
        await evaluate(`document.querySelector('[data-collection="segments"] > button').click()`);
        await evaluate(`const rows = document.querySelectorAll('[data-collection="segments"] [data-row]');
            rows[0].querySelector('input[data-bound="start"]').value = 1100;
            rows[0].querySelector('input[data-bound="end"]').value = 1800;
            rows[1].querySelector('input[data-bound="start"]').value = 0;
            rows[1].querySelector('input[data-bound="end"]').value = 1000;
            rows[1].querySelector('[data-direction="-1"]').click();
            document.querySelector('#form_save').click();`);
        await wait(`document.querySelector('.alert-success')`);
        await check(`JSON.stringify([...document.querySelectorAll('[data-collection="segments"] input[data-bound="start"]')].map(i => i.value)) === '["0","1100"]'`, 'ordre déplacé des segments enregistré et rechargé');
        if (errors.length) throw Error(errors.join('\n'));
        console.log('OK aucune exception JavaScript');
    } finally {
        ws.close();
        await fetch(`${cdp}/json/close/${tab.id}`);
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
