// Native Chrome CDP, no added test framework. See quiz_results_lot2.md for setup.
const fs = require('node:fs');
const origin = process.env.RESULTS_TEST_ORIGIN || 'http://127.0.0.1:8803';
const cdp = process.env.RESULTS_TEST_CDP || 'http://127.0.0.1:8804';
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

(async () => {
    const tab = await fetch(`${cdp}/json/new?about:blank`, { method: 'PUT' }).then(response => response.json());
    const ws = new WebSocket(tab.webSocketDebuggerUrl);
    await new Promise(resolve => ws.addEventListener('open', resolve, { once: true }));
    let sequence = 0;
    const pending = new Map(), errors = [], external = [];
    ws.addEventListener('message', event => {
        const data = JSON.parse(event.data);
        if (data.method === 'Runtime.exceptionThrown') errors.push(data.params.exceptionDetails.exception?.description || data.params.exceptionDetails.text);
        if (data.method === 'Runtime.consoleAPICalled' && data.params.type === 'error') errors.push(data.params.args.map(arg => arg.value || arg.description).join(' '));
        if (data.method === 'Network.requestWillBeSent' && /^https?:/.test(data.params.request.url) && !data.params.request.url.startsWith(origin + '/')) external.push(data.params.request.url);
        if (data.id && pending.has(data.id)) {
            const { resolve, reject } = pending.get(data.id);
            pending.delete(data.id);
            data.error ? reject(data.error) : resolve(data.result);
        }
    });
    const send = (method, params = {}) => new Promise((resolve, reject) => {
        const id = ++sequence;
        pending.set(id, { resolve, reject });
        ws.send(JSON.stringify({ id, method, params }));
    });
    const evaluate = async expression => {
        const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        return result.result.value;
    };
    const wait = async expression => {
        for (let i = 0; i < 100; i++) {
            if (await evaluate(`Boolean(${expression})`)) return;
            await delay(150);
        }
        throw Error('Timeout: ' + expression + '\n' + errors.join('\n'));
    };
    const check = async (expression, label) => {
        if (!await evaluate(expression)) throw Error(label);
        console.log('OK ' + label);
    };
    const screenshot = async name => {
        await delay(100);
        const result = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(`var/results-${name}.png`, Buffer.from(result.data, 'base64'));
    };
    const ready = `window.resultsChart && document.querySelectorAll('main canvas').length === 3 && [...document.querySelectorAll('main canvas')].every(canvas => resultsChart.getChart(canvas)?.width > 0)`;
    try {
        await send('Page.enable');
        await send('Runtime.enable');
        await send('Network.enable');
        await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 960, deviceScaleFactor: 1, mobile: false });
        await send('Page.navigate', { url: origin + '/mes-resultats' });
        await wait(`document.querySelector('main.quiz-results') && window.Turbo`);
        await evaluate(`(async () => { window.resultsChart = (await import('chart.js')).Chart; localStorage.setItem('theme', 'light'); document.documentElement.setAttribute('data-bs-theme', 'light'); window.resultsDocumentMarker = true; return true; })()`);
        await wait(ready);
        await check(`JSON.stringify([...document.querySelectorAll('main canvas')].map(c => resultsChart.getChart(c).data.datasets[0].data)) === '[[0,2],[1,1],[2,0]]'`, 'anneaux 0 / 50 / 100 %, valeurs exactes');
        await check(`Object.values(resultsChart.instances).length === 3`, 'une instance UX par canvas');
        await check(`[...document.querySelectorAll('main canvas')].every(c => c.getAttribute('aria-label') && document.getElementById(c.getAttribute('aria-describedby'))?.textContent.includes('incorrecte'))`, 'noms accessibles et légendes HTML');
        await check(`[...document.querySelectorAll('main canvas')].every(c => resultsChart.getChart(c).data.datasets[0].backgroundColor[0] === getComputedStyle(c).getPropertyValue('--results-correct').trim())`, 'couleurs CSS résolues avant rendu');
        await check(`resultsChart.getChart(document.querySelectorAll('main canvas')[0]).getDatasetMeta(0).data[0].circumference === 0 && resultsChart.getChart(document.querySelectorAll('main canvas')[2]).getDatasetMeta(0).data[1].circumference === 0`, 'aucun secteur artificiel à 0 et 100 %');
        await check(`document.documentElement.scrollWidth <= innerWidth`, 'ordinateur sans débordement');
        await screenshot('desktop-light');
        await evaluate(`document.querySelector('[data-action="theme#toggle"]').click()`);
        await wait(`document.documentElement.dataset.bsTheme === 'dark' && resultsChart.getChart(document.querySelector('main canvas')).data.datasets[0].backgroundColor[0] === '#78b99c'`);
        await screenshot('desktop-dark');
        console.log('OK thème sombre et mise à jour des anneaux');
        await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
        await delay(200);
        await check(`document.documentElement.scrollWidth <= innerWidth`, 'mobile sans débordement');
        await check(`[...document.querySelectorAll('.results-actions .btn')].every(button => button.getBoundingClientRect().height >= 44)`, 'boutons mobiles accessibles');
        await screenshot('mobile-dark');
        await evaluate(`document.querySelector('.navbar-toggler').click()`);
        await wait(`document.querySelector('#navbarColor04').classList.contains('show')`);
        await check(`document.querySelector('nav a[href="/ma-formation"]').closest('li').nextElementSibling.querySelector('a').getAttribute('href') === '/mes-resultats'`, 'navigation mobile : Mes résultats après Ma formation');
        await evaluate(`document.querySelector('[data-action="theme#toggle"]').click(); document.querySelector('.navbar-toggler').click()`);
        await wait(`document.documentElement.dataset.bsTheme === 'light' && !document.querySelector('#navbarColor04').classList.contains('show')`);
        await screenshot('mobile-light');
        for (const width of [320, 768, 1024]) {
            await send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
            await delay(200);
            await check(`document.documentElement.scrollWidth <= innerWidth`, `${width}px sans débordement`);
        }
        for (let cycle = 0; cycle < 2; cycle++) {
            await evaluate(`document.querySelector('main a[href*="/tentatives/"]').click()`);
            await wait(`location.pathname.includes('/tentatives/') && document.querySelector('main details') && Object.keys(resultsChart.instances).length === 0`);
            await check(`window.resultsDocumentMarker === true`, 'navigation Turbo réelle et destruction des graphiques au départ');
            await evaluate('history.back()');
            await wait(`location.pathname === '/mes-resultats' && ${ready}`);
            await check(`Object.keys(resultsChart.instances).length === 3`, 'retour Turbo sans canvas ni instance doublés');
        }
        if (errors.length) throw Error('Console: ' + errors.join('\n'));
        // Existing global address autocomplete imports this CDN before our page runs.
        const unexpected = external.filter(url => !/^https:\/\/unpkg\.com\/@googlemaps\/extended-component-library@0\.6(?:\.|$)/.test(url));
        if (unexpected.length) throw Error('Ressources externes inattendues: ' + unexpected.join('\n'));
        console.log('OK aucune erreur console, graphiques servis localement');
        if (external.length) console.log('NOTE import Google Maps préexistant : ' + [...new Set(external)].join(', '));
    } finally {
        ws.close();
        await fetch(`${cdp}/json/close/${tab.id}`);
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
