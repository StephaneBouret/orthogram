// Native Chrome CDP. Start quiz_history_browser_router.php on 8803 and Chrome CDP on 8804.
const fs = require('node:fs');
const origin = 'http://127.0.0.1:8803';
const cdp = 'http://127.0.0.1:8804';
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const retouches = process.env.QUIZ_HISTORY_RETOUCHES === '1';

(async () => {
    const tab = await fetch(`${cdp}/json/new?about:blank`, { method: 'PUT' }).then(r => r.json());
    const ws = new WebSocket(tab.webSocketDebuggerUrl);
    await new Promise(resolve => ws.addEventListener('open', resolve, { once: true }));
    let sequence = 0;
    const pending = new Map(), errors = [];
    ws.addEventListener('message', event => {
        const data = JSON.parse(event.data);
        if (data.method === 'Runtime.exceptionThrown') errors.push(data.params.exceptionDetails.exception?.description || data.params.exceptionDetails.text);
        if (data.method === 'Runtime.consoleAPICalled' && data.params.type === 'error') errors.push(data.params.args.map(arg => arg.value || arg.description).join(' '));
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
        const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        if (r.exceptionDetails) throw Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
        return r.result.value;
    };
    const wait = async expression => {
        for (let i = 0; i < 100; i++) {
            if (await evaluate(`Boolean(${expression})`)) return;
            await delay(150);
        }
        throw Error('Timeout ' + expression + '\n' + errors.join('\n'));
    };
    const check = async (expression, label) => {
        if (!await evaluate(expression)) throw Error(label);
        console.log('OK ' + label);
    };
    const screenshot = async name => {
        const previousY = await evaluate('scrollY');
        if (name.startsWith('mobile-')) {
            // Chrome may omit an offscreen overflow layer in a full-page capture.
            // Paint the table in the real viewport first; viewport shots below are
            // the authoritative checks for its visible rows and horizontal scroll.
            await scrollToTable(await evaluate('innerWidth'));
        }
        const metrics = await send('Page.getLayoutMetrics');
        const width = await evaluate('document.documentElement.clientWidth');
        const result = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true,
            clip: { x: 0, y: 0, width, height: metrics.cssContentSize.height, scale: 1 } });
        fs.writeFileSync(`var/history-${name}.png`, Buffer.from(result.data, 'base64'));
        if (name.startsWith('mobile-')) {
            await evaluate(`window.scrollTo(0, ${previousY})`);
            await painted();
        }
    };
    const viewportScreenshot = async name => {
        const result = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        fs.writeFileSync(`var/history-table-${name}.png`, Buffer.from(result.data, 'base64'));
    };
    const painted = async () => {
        await evaluate('new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
        await delay(150);
    };
    const scrollToTable = async width => {
        const distance = await evaluate(`document.querySelector('#results-history-heading').getBoundingClientRect().top - 24`);
        await send('Input.dispatchMouseEvent', { type: 'mouseWheel', x: width / 2, y: 600, deltaX: 0, deltaY: distance });
        await painted();
        await check(`(() => {
            const table = document.querySelector('.results-table').getBoundingClientRect();
            return scrollY > 0 && table.top >= 0 && table.bottom <= innerHeight;
        })()`, `${width}px : tableau entier dans la zone visible après défilement réel`);
    };
    const tableViewportChecks = async width => {
        await evaluate(`document.documentElement.dataset.bsTheme = 'light'; localStorage.setItem('theme', 'light')`);
        await send('Page.reload');
        await wait(`document.querySelector('.results-table') && document.documentElement.dataset.bsTheme === 'light'`);
        await evaluate(`(async () => { window.historyCharts = (await import('chart.js')).Chart; })()`);
        await wait(ready);
        await scrollToTable(width);
        for (const phase of ['reload-light', 'toggle-dark', 'toggle-light']) {
            if (phase.startsWith('toggle')) {
                await evaluate(`document.querySelector('[data-action="theme#toggle"]').click()`);
                await wait(`document.documentElement.dataset.bsTheme === '${phase.endsWith('light') ? 'light' : 'dark'}'`);
                await painted();
            }
            const region = await evaluate(`(() => { const r = document.querySelector('.results-table-scroll').getBoundingClientRect(); return {x: ${width} / 2, y: r.top + 100}; })()`);
            // Return to Date, then scroll across the overflowing table with input events.
            await send('Input.dispatchMouseEvent', { type: 'mouseWheel', ...region, deltaX: -500, deltaY: 0 });
            await painted();
            await check(`document.querySelector('.results-table-scroll').scrollLeft === 0 && document.querySelectorAll('.results-table tbody tr').length === 5 && [...document.querySelectorAll('.results-table thead th')].map(e => e.textContent).join() === 'Date,Résultat,Évolution,Détail'`, `${width}px ${phase} : en-têtes et cinq lignes, début du tableau`);
            await viewportScreenshot(`${width}-${phase}-dates`);
            await send('Input.dispatchMouseEvent', { type: 'mouseWheel', ...region, deltaX: 500, deltaY: 0 });
            await painted();
            await check(`(() => {
                const region = document.querySelector('.results-table-scroll');
                return region.scrollLeft > 0 && [...region.querySelectorAll('tbody a')].every(a => {
                    const r = a.getBoundingClientRect();
                    return r.left >= 0 && r.right <= innerWidth && r.top >= 0 && r.bottom <= innerHeight
                        && a.contains(document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2));
                });
            })()`, `${width}px ${phase} : défilement horizontal réel, cinq liens Voir visibles et atteignables`);
            await viewportScreenshot(`${width}-${phase}-details`);
        }
        const target = await evaluate(`(() => { const a = document.querySelector('tbody a'), r = a.getBoundingClientRect(); return {x:r.x+r.width/2, y:r.y+r.height/2, url:a.href}; })()`);
        await send('Input.dispatchMouseEvent', { type: 'mousePressed', x: target.x, y: target.y, button: 'left', clickCount: 1 });
        await send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: target.x, y: target.y, button: 'left', clickCount: 1 });
        await wait(`location.href === ${JSON.stringify(target.url)} && document.querySelector('#tentative-selectionnee').textContent.includes('90 %')`);
        await check(`document.querySelector('tr.is-selected').textContent.includes('Tentative 5')`, `${width}px : le lien Voir sélectionne réellement la tentative cliquée`);
        // Preserve the selection used by the remainder of the existing Turbo checks.
        await evaluate('history.back()');
        await wait(`${ready} && document.querySelector('#tentative-selectionnee').textContent.includes('60 %')`);
    };
    const ready = `document.querySelector('.results-line canvas') && historyCharts.getChart(document.querySelector('.results-line canvas'))?.width > 0 && Object.keys(historyCharts.instances).length === 2`;
    const line = `historyCharts.getChart(document.querySelector('.results-line canvas'))`;
    const labels = `(() => {
        const chart = ${line}, ctx = chart.ctx, original = ctx.fillText, drawn = [];
        ctx.fillText = function(text, x, y, ...args) {
            if (/^\\d+ %$/.test(text)) drawn.push({text, x, y, width: ctx.measureText(text).width});
            return original.call(this, text, x, y, ...args);
        };
        try { chart.draw(); } finally { ctx.fillText = original; }
        return drawn;
    })()`;
    const enter = async () => {
        await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
        await send('Input.dispatchKeyEvent', { type: 'char', text: '\r', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
        await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
    };
    const sectionChecks = async () => {
        await wait(`document.querySelectorAll('details.results-section[open]').length === 2 && Object.keys(historyCharts.instances).length === 6`);
        await check(`document.querySelector('.results-section-count').textContent === '2 tests' && document.querySelectorAll('details.results-section')[0].querySelectorAll('article').length === 2`, 'compteur : deux cartes, pas huit tentatives');
        await check(`getComputedStyle(document.querySelector('.results-section-name')).color === 'rgb(243, 151, 27)' && getComputedStyle(document.querySelector('.results-section-title')).fontWeight === '700'`, 'titre gras, orange réel du logo');
        await evaluate(`document.querySelector('.results-section summary').focus()`);
        await enter();
        await wait(`!document.querySelector('.results-section').open`);
        await check(`!document.querySelector('.results-section').open && document.querySelectorAll('.results-section')[1].open && getComputedStyle(document.activeElement).outlineStyle === 'solid'`, 'repli au clavier indépendant, focus visible');
        await enter();
        await wait(`document.querySelector('.results-section').open`);
        await wait(`[...document.querySelectorAll('.results-ring canvas')].every(c => c.getBoundingClientRect().width === 96 && historyCharts.getChart(c).width === 96 && historyCharts.getChart(c).height === 96)`);
        await check(`Object.keys(historyCharts.instances).length === 6`, 'réouverture : six anneaux de 96 px sans doublon');
        await check(`document.querySelector('.results-change--up').textContent.includes('+20 points par rapport à la précédente') && document.querySelector('.results-change--down').textContent.includes('-50 points par rapport à la précédente') && document.querySelector('.results-change--stable').textContent.includes('Stable par rapport à la précédente') && [...document.querySelectorAll('.results-change > span')].every(s => s.getAttribute('aria-hidden') === 'true')`, 'hausse, baisse, stable : libellés entiers et flèches décoratives');
        await check(`(() => {
            const lum = rgb => rgb.match(/\\d+/g).slice(0, 3).map(Number).map(v => v / 255).map(v => v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4).reduce((n, v, i) => n + v * [.2126, .7152, .0722][i], 0);
            return [...document.querySelectorAll('.results-change')].every(e => {
                const fg = lum(getComputedStyle(e).color), bg = lum(getComputedStyle(e.closest('article')).backgroundColor);
                return (Math.max(fg, bg) + .05) / (Math.min(fg, bg) + .05) >= 4.5;
            });
        })()`, 'contraste des mentions supérieur à 4,5:1');
    };
    try {
        await send('Page.enable'); await send('Runtime.enable');
        await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 1000, deviceScaleFactor: 1, mobile: false });
        await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
        await send('Page.navigate', { url: origin + '/mes-resultats' });
        await wait(`document.querySelectorAll('main a[href*="/historique/"]').length === ${retouches ? 6 : 3} && window.Turbo`);
        const historyUrls = await evaluate(`[...document.querySelectorAll('main a[href*="/historique/"]')].map(a => a.href)`);
        await evaluate(`(async () => { window.historyCharts = (await import('chart.js')).Chart; window.historyMarker = true; })()`);
        if (retouches) {
            for (const width of [1366, 390]) {
                await send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 500 });
                for (const theme of ['light', 'dark']) {
                    await evaluate(`document.documentElement.dataset.bsTheme = '${theme}'; localStorage.setItem('theme', '${theme}')`);
                    await sectionChecks();
                    await evaluate('document.activeElement.blur(); window.scrollTo(0,0)');
                    await screenshot(`results-${width}-${theme}`);
                }
            }
            await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 1000, deviceScaleFactor: 1, mobile: false });
        }
        await evaluate(`document.querySelector('main a[href*="/historique/"]').click()`);
        await wait(ready);
        await check(`document.querySelector('.results-gain').textContent.includes('+50 points') && document.querySelectorAll('tbody tr').length === 5`, 'cinq tentatives terminées, gain +50, active exclue');
        await check(`JSON.stringify(${line}.data.datasets[0].data.map(p => p.y)) === '[40,60,50,70,90]'`, 'points réels, baisse intermédiaire conservée');
        await check(`JSON.stringify(${labels}.map(p => p.text).sort()) === '["40 %","50 %","60 %","70 %","90 %"]'`, 'pourcentages réellement dessinés près des cinq points');
        await check(`${line}.scales.x.ticks.every(t => t.label === '18/09') && new Set(${line}.getDatasetMeta(0).data.map(p => p.x)).size === 5`, 'dates seules, cinq positions distinctes le même jour');
        await check(`${line}.options.scales.y.min === 0 && ${line}.options.scales.y.max === 100 && ${line}.options.scales.x.type === 'category' && ${line}.data.datasets[0].tension === 0 && ${line}.options.animation === false`, 'axes, segments droits, mouvement réduit respecté');
        await evaluate(`window.historySelections = [...document.querySelectorAll('tbody a')].reverse().map(a => a.href)`);
        const selections = await evaluate('historySelections');
        await evaluate(`document.documentElement.setAttribute('data-bs-theme', 'light'); localStorage.setItem('theme', 'light'); window.scrollTo(0,0)`);
        await delay(100);
        await screenshot('desktop-light');
        const hover = await evaluate(`(() => { const chart = ${line}; const p = chart.getDatasetMeta(0).data[2]; const rect = chart.canvas.getBoundingClientRect(); return {x:rect.x+p.x,y:rect.y+p.y}; })()`);
        await send('Input.dispatchMouseEvent', { type: 'mouseMoved', ...hover });
        await wait(`${line}.tooltip.title?.[0]?.includes('Tentative 3')`);
        await check(`${line}.tooltip.body[0].lines[0] === '5/10 · 50 %'`, 'infobulle date/heure, numéro, score et pourcentage');
        await evaluate(`document.querySelector('[data-action="theme#toggle"]').click()`);
        await wait(`${line}.data.datasets[0].borderColor === '#82c8e0'`);
        await check(`${line}.options.scales.x.ticks.color === getComputedStyle(document.querySelector('.results-line canvas')).getPropertyValue('--orthogram-muted').trim()`, 'courbe et axes mis à jour en sombre');
        await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 0, y: 0 });
        await delay(100);
        await screenshot('desktop-dark');
        // Use actual keyboard activation of an ordinary GET link.
        await evaluate(`[...document.querySelectorAll('tbody a')].reverse()[1].focus()`);
        await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
        await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
        await wait(`${ready} && document.querySelector('#tentative-selectionnee').textContent.includes('60 %')`);
        await check(`document.querySelector('tr.is-selected a').getAttribute('aria-current') === 'true' && ${line}.data.datasets[0].pointRadius[1] === 7`, 'sélection au clavier, ligne accessible et point mis en évidence');
        await check(`historyCharts.getChart(document.querySelector('.results-ring canvas')).data.datasets[0].data.join() === '6,4'`, 'bilan et anneau suivent la sélection');
        await evaluate(`document.querySelector('#tentative-selectionnee a').click()`);
        await wait(`location.pathname.includes('/tentatives/') && document.querySelector('main details') && Object.keys(historyCharts.instances).length === 0`);
        await check(`document.querySelector('main').textContent.includes('6 sur 10')`, 'correction exacte de la tentative ancienne');
        await evaluate(`document.querySelector('main > a').click()`);
        await wait(`${ready} && document.querySelector('#tentative-selectionnee').textContent.includes('60 %')`);
        await check(`window.historyMarker === true`, 'retour explicite par Turbo vers la même sélection');
        if (retouches) {
            await evaluate(`document.querySelector('main > a').click()`);
            await sectionChecks();
            await evaluate('history.back()');
            await wait(ready);
            await check(`${labels}.length === 5 && ${line}.config.plugins.filter(p => p.id === 'result-percentages').length === 1`, 'retour Turbo : étiquettes et plugin unique');
        }
        await send('Page.reload');
        await wait(`document.querySelector('tr.is-selected')`);
        await check(`document.querySelector('#tentative-selectionnee').textContent.includes('60 %')`, 'sélection conservée après rechargement');
        await evaluate(`(async () => { window.historyCharts = (await import('chart.js')).Chart; })()`);
        for (const width of [390, 320]) {
            await send('Emulation.setDeviceMetricsOverride', { width, height: 844, deviceScaleFactor: 1, mobile: true });
            await send('Page.reload');
            await wait(`document.querySelector('tbody tr')`);
            await evaluate(`(async () => { window.historyCharts = (await import('chart.js')).Chart; })()`);
            await wait(ready);
            await delay(200);
            await evaluate('window.scrollTo(0,0)');
            await check(`document.documentElement.scrollWidth <= document.documentElement.clientWidth && innerWidth <= document.documentElement.clientWidth && document.querySelector('.results-table-scroll').scrollWidth > document.querySelector('.results-table-scroll').clientWidth`, `${width}px : tableau défilant sans débordement de page`);
            if (width === 390) {
                for (const theme of ['light', 'dark']) {
                    await evaluate(`document.documentElement.dataset.bsTheme = '${theme}'; localStorage.setItem('theme', '${theme}')`);
                    await delay(100);
                    await screenshot(`mobile-${theme}`);
                }
            }
            await tableViewportChecks(width);
        }
        for (let cycle = 0; cycle < 2; cycle++) {
            await evaluate(`document.querySelector('#tentative-selectionnee a').click()`);
            await wait(`Object.keys(historyCharts.instances).length === 0 && location.pathname.includes('/tentatives/')`);
            await evaluate('history.back()');
            await wait(ready);
            await check(`document.querySelector('#tentative-selectionnee').textContent.includes('60 %')`, 'retour arrière Turbo conserve sélection et deux instances');
        }
        await send('Page.navigate', { url: historyUrls[1] });
        await wait(`document.querySelector('.results-line canvas')`);
        await evaluate(`(async () => { window.historyCharts = (await import('chart.js')).Chart; })()`);
        await wait(ready);
        await check(`JSON.stringify(${line}.data.datasets.map(d => d.data.map(p => p.y))) === '[[40,60],[50]]' && !document.querySelector('.results-gain')`, 'contenu modifié : points conservés et séries disjointes sans gain global');
        await send('Page.navigate', { url: historyUrls[2] });
        await wait(`document.querySelector('#tentative-selectionnee')`);
        await check(`document.querySelector('main').textContent.includes('Une première tentative enregistrée') && document.querySelector('#tentative-selectionnee').textContent.includes('0 %')`, 'première tentative à zéro sans gain fictif');
        if (retouches) {
            await evaluate(`(async () => { window.historyCharts = (await import('chart.js')).Chart; })()`);
            await wait(ready);
            await check(`${labels}.some(p => p.text === '0 %' && p.y > 8 && p.y < ${line}.height - 8)`, 'étiquette 0 % visible sans découpe');
            for (const [index, sign] of [[3, 'stable'], [4, 'down'], [5, 'up']]) {
                await send('Page.navigate', { url: historyUrls[index] });
                await wait(`document.querySelector('.results-line canvas')`);
                await evaluate(`(async () => { window.historyCharts = (await import('chart.js')).Chart; })()`);
                await wait(ready);
                await check(`document.querySelector('.results-gain').classList.contains('results-change--${sign}')`, `gain global ${sign} coloré selon son signe`);
                if (index === 3) await check(`${labels}.every(p => p.text === '100 %' && p.y >= 8)`, 'étiquettes 100 % au-dessus des points sans découpe');
                if (index === 5) {
                    for (const width of [1366, 390, 320]) {
                        await send('Emulation.setDeviceMetricsOverride', { width, height: 844, deviceScaleFactor: 1, mobile: width < 500 });
                        await delay(200);
                        for (const theme of ['light', 'dark']) {
                            await evaluate(`document.documentElement.dataset.bsTheme = '${theme}'`);
                            await delay(100);
                            await check(`(() => {
                                const drawn = ${labels}, chart = ${line};
                                return chart.getDatasetMeta(0).data.length === 40 && document.querySelectorAll('tbody tr').length === 40
                                    && drawn.length > 2 && drawn.length < 40 && drawn.some(p => p.text === '100 %') && drawn.some(p => p.text === '0 %')
                                    && drawn.every(p => p.x - p.width / 2 >= 0 && p.x + p.width / 2 <= chart.width && p.y >= 8)
                                    && drawn.every((p, i) => drawn.slice(i + 1).every(q => Math.abs(p.y - q.y) >= 20 || Math.abs(p.x - q.x) >= (p.width + q.width) / 2 + 9));
                            })()`, `historique dense ${width}px ${theme} : étiquettes espacées, 0/100 visibles, 40 points et lignes conservés`);
                            if (width === 390) {
                                // Capture the chart and heading rather than 40 table rows.
                                const shot = await send('Page.captureScreenshot', { format: 'png' });
                                fs.writeFileSync(`var/history-dense-mobile-${theme}.png`, Buffer.from(shot.data, 'base64'));
                            }
                        }
                    }
                }
            }
        }
        await send('Emulation.setScriptExecutionDisabled', { value: true });
        if (retouches) {
            await send('Page.navigate', { url: origin + '/mes-resultats' });
            await wait(`document.querySelector('.results-section summary')`);
            await evaluate(`document.querySelector('.results-section summary').focus()`);
            await enter();
            await wait(`!document.querySelector('.results-section').open`);
            await check(`!document.querySelector('.results-section').open && document.querySelectorAll('.results-section')[1].open`, 'sans JavaScript : repli natif au clavier indépendant');
            await enter();
            await wait(`document.querySelector('.results-section').open`);
            await check(`document.querySelector('.results-section').open`, 'sans JavaScript : réouverture native');
        }
        await send('Page.navigate', { url: selections[2] });
        await wait(`document.querySelector('tbody tr') && document.querySelector('#tentative-selectionnee').textContent.includes('50 %')`);
        await check(`document.querySelectorAll('tbody tr').length === 5 && !!document.querySelector('#tentative-selectionnee a[href*="/tentatives/"]')`, 'sans JavaScript : tableau, sélection et lien de correction disponibles');
        if (errors.length) throw Error('Console: ' + errors.join('\n'));
        console.log('OK aucune erreur console');
    } finally {
        ws.close();
        await fetch(`${cdp}/json/close/${tab.id}`);
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
