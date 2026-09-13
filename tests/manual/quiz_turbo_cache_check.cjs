// Chrome CDP on 8794 + isolated fixture server var/quiz_lot2_browser_router.php on 8793.
// Uses actual Turbo navigation and inspects the snapshot at SnapshotCache.put.
const fs = require('node:fs');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const url = 'http://127.0.0.1:8793/courses/quiz-formation/quiz-section/quiz-cours';

(async () => {
    const tab = await fetch('http://127.0.0.1:8794/json/new?about:blank', { method: 'PUT' }).then(r => r.json());
    const ws = new WebSocket(tab.webSocketDebuggerUrl);
    await new Promise(resolve => ws.addEventListener('open', resolve, { once: true }));
    let sequence = 0;
    const pending = new Map(), errors = [];
    ws.addEventListener('message', event => {
        const data = JSON.parse(event.data);
        if (data.method === 'Runtime.exceptionThrown') errors.push(data.params.exceptionDetails.text);
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
        for (let i = 0; i < 150; i++) {
            if (await evaluate(expression)) return;
            await delay(100);
        }
        throw Error('Timeout: ' + expression);
    };
    const check = async (expression, label) => {
        if (!await evaluate(expression)) throw Error(label + '\n' + JSON.stringify(await evaluate('({eventChecks: window.eventChecks, cacheChecks: window.cacheChecks, abortCount: window.abortCount})')));
        console.log('OK ' + label);
    };
    const idle = () => wait(`document.querySelector('[data-controller="quiz"]') && !document.querySelector('[data-controller="quiz"]').hasAttribute('aria-busy')`);
    const click = action => evaluate(`document.querySelector('[data-action="quiz#${action}"]').click()`);
    const back = async () => {
        await evaluate('history.back()');
        await wait(`location.href === ${JSON.stringify(url)} && !!document.querySelector('[data-action="quiz#resume"]')`);
        await idle();
    };
    const away = async () => {
        await evaluate(`document.querySelector('#lesson-navigation a[rel="next"]').click()`);
        await wait(`location.pathname.endsWith('/cours-suivant') && window.cacheChecks.length > 0`);
    };
    try {
        await send('Page.enable');
        await send('Runtime.enable');
        await send('Network.enable');
        await send('Network.setCacheDisabled', { cacheDisabled: true });
        await send('Page.navigate', { url });
        await wait(`!!document.querySelector('[data-action="quiz#start"]')`);
        await idle();
        await check(`document.querySelector('[data-quiz-target="title"]').textContent.includes('<img')`, 'fixture de test isolée');
        await click('start');
        await wait(`!!document.querySelector('[data-quiz-target="choice"]')`);
        await idle();
        // Observe on window, after Stimulus's document listener on every reconnect.
        // No direct beforeCache() call and no synthetic Turbo event.
        await evaluate(`
            window.cacheChecks = []; window.eventChecks = []; window.cacheOrder = [];
            window.observeQuiz = root => root && ({
                text: root.querySelector('[data-quiz-target="stage"]').textContent.trim(),
                controls: root.querySelectorAll('[data-quiz-target="stage"] input, [data-quiz-target="stage"] button').length,
                errorHidden: root.querySelector('[data-quiz-target="error"]').hidden,
                busy: root.hasAttribute('aria-busy'), aborted: window.heldSignal?.aborted ?? null
            });
            window.addEventListener('turbo:before-cache', () => {
                const root = document.querySelector('[data-controller="quiz"]');
                if (root) { window.cacheOrder.push('event'); window.eventChecks.push(window.observeQuiz(root)); }
            });
            const cache = window.Turbo.session.view.snapshotCache;
            const originalPut = cache.put.bind(cache);
            cache.put = (location, snapshot) => {
                const root = snapshot.element.querySelector('[data-controller="quiz"]');
                if (root) { window.cacheOrder.push('put'); window.cacheChecks.push(window.observeQuiz(root)); }
                return originalPut(location, snapshot);
            };
            window.realQuizFetch = window.fetch;
        `);
        await evaluate(`document.querySelector('[data-quiz-target="choice"]').click()`);
        await click('answer');
        await wait(`!!document.querySelector('[data-action="quiz#resume"]')`);
        await idle();
        await away();
        await check(`window.cacheOrder.join() === 'event,put' && window.eventChecks[0].text === 'Chargement du quiz…' && window.cacheChecks[0].text === 'Chargement du quiz…' && window.cacheChecks[0].controls === 0 && window.cacheChecks[0].errorHidden`, 'correction effacée pendant before-cache puis snapshot réellement nettoyé');
        await back();
        await click('resume');
        await wait(`document.querySelectorAll('[data-quiz-target="choice"]').length === 3`);
        await idle();
        // Hold a fetch before dispatch to the server; honour its actual AbortSignal.
        await evaluate(`
            window.cacheChecks = []; window.eventChecks = []; window.cacheOrder = [];
            window.abortCount = 0; window.heldSettled = false; window.oldQuizRoot = document.querySelector('[data-controller="quiz"]');
            window.fetch = (input, options) => {
                if (!String(input).endsWith('/answer')) return window.realQuizFetch(input, options);
                window.heldSignal = options.signal;
                return new Promise((resolve, reject) => options.signal.addEventListener('abort', () => {
                    window.abortCount++; window.heldSettled = true;
                    reject(new DOMException('Interrupted', 'AbortError'));
                }, {once: true}));
            };
            document.querySelector('[data-quiz-target="choice"]').click();
        `);
        await click('answer');
        await check(`!window.heldSettled && !window.heldSignal.aborted && window.oldQuizRoot.getAttribute('aria-busy') === 'true'`, 'validation réellement en attente lors du départ');
        await away();
        await check(`window.eventChecks[0].aborted && window.abortCount === 1 && window.cacheChecks[0].controls === 0 && window.cacheChecks[0].text === 'Chargement du quiz…'`, 'requête annulée dès before-cache, aucun choix conservé dans le snapshot');
        await check(`!window.eventChecks[0].busy && !window.cacheChecks[0].busy`, 'aria-busy retiré avant la mise en cache');
        await evaluate('window.fetch = window.realQuizFetch');
        await back();
        await click('resume');
        await wait(`document.querySelectorAll('[data-quiz-target="choice"]').length === 3`);
        await idle();
        await check(`document.querySelector('[data-quiz-target="stage"]').textContent.includes('Question 2 sur 2') && !document.querySelector('[data-quiz-target="choice"]:checked')`, 'retour après annulation : même question attendue, interface utilisable');
        // Send the real POST, but hold its already received response. Deliberately
        // ignore abort when releasing it to test the late-response generation guard.
        await evaluate(`
            window.cacheChecks = []; window.eventChecks = []; window.cacheOrder = [];
            window.oldQuizRoot = document.querySelector('[data-controller="quiz"]');
            window.fetch = async (input, options) => {
                if (!String(input).endsWith('/answer')) return window.realQuizFetch(input, options);
                window.heldSignal = options.signal;
                const response = await window.realQuizFetch(input, options);
                if (!response.ok) throw Error('Fixture POST failed');
                const body = await response.text();
                window.postCommitted = true;
                // Its body is independent of the aborted network response, so the
                // controller must reject the late success via its lifecycle guard.
                return new Promise(resolve => {
                    window.releaseLate = () => resolve(new Response(body, {status: response.status, headers: response.headers}));
                });
            };
            document.querySelector('[data-quiz-target="choice"]').click();
        `);
        await click('answer');
        await wait('window.postCommitted === true');
        await away();
        await check(`window.eventChecks[0].aborted && !window.cacheChecks[0].busy`, 'réponse HTTP retenue : signal annulé avant stockage du snapshot');
        await evaluate('window.fetch = window.realQuizFetch');
        await evaluate('history.back()');
        await wait(`!!document.querySelector('[data-action="quiz#finish"]')`);
        await idle();
        await evaluate('window.releaseLate()');
        await delay(250);
        await check(`window.oldQuizRoot.querySelector('[data-quiz-target="stage"]').textContent.trim() === 'Chargement du quiz…' && window.cacheChecks[0].text === 'Chargement du quiz…' && !!document.querySelector('[data-action="quiz#finish"]') && !document.querySelector('[data-quiz-target="stage"]').textContent.includes('SECRET')`, 'réponse tardive ignorée après reconnexion, progression relue sur le serveur');
        fs.writeFileSync('var/quiz-turbo-cache-report.json', JSON.stringify(await evaluate('({eventChecks, cacheChecks, cacheOrder, abortCount})'), null, 2));
        if (errors.length) throw Error(errors.join('\n'));
        console.log('OK aucune exception JavaScript');
    } finally {
        await send('Page.close');
        ws.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; setTimeout(() => process.exit(1), 100); });
