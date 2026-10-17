// DOM + Chrome accessibility tree only. No application URL, database or attempt.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const cdp = 'http://127.0.0.1:8816';
(async () => {
    const tab = await fetch(`${cdp}/json/new?about:blank`, {method: 'PUT'}).then(response => response.json());
    const socket = new WebSocket(tab.webSocketDebuggerUrl);
    await new Promise(resolve => socket.addEventListener('open', resolve, {once: true}));
    let sequence = 0;
    const pending = new Map();
    const send = (method, params = {}) => new Promise((resolve, reject) => {
        const id = ++sequence;
        const timer = setTimeout(() => { pending.delete(id); reject(Error(`Timeout: ${method}`)); }, 10000);
        pending.set(id, {resolve, reject, timer});
        socket.send(JSON.stringify({id, method, params}));
    });
    socket.addEventListener('message', event => {
        const data = JSON.parse(event.data);
        const item = pending.get(data.id);
        if (!item) return;
        pending.delete(data.id); clearTimeout(item.timer);
        if (data.error) item.reject(Error(data.error.message)); else item.resolve(data.result);
    });
    const evaluate = async expression => {
        const result = await send('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true});
        if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        return result.result.value;
    };
    try {
        const source = fs.readFileSync('assets/js/dictation_feedback.js', 'utf8');
        const moduleUrl = `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
        await evaluate(`(async()=>{window.renderer=await import(${JSON.stringify(moduleUrl)});window.box=document.createElement('div');document.body.append(window.box)})()`);
        const cases = [
            {name: 'passage lexical', copy: 'chatt', issues: [{start: 0, end: 5, domain: 'lexical'}], expected: ['« chatt ». Signalement 1 : orthographe à revoir. Rejoindre l’autocorrection.']},
            {name: 'ponctuation écrite', copy: '?!', issues: [{start: 0, end: 2, domain: 'punctuation'}], expected: ['« ?! ». Signalement 1 : ponctuation à revoir. Rejoindre l’autocorrection.']},
            {name: 'omissions au même point', copy: 'L’ ouvre.', issues: [{start: 3, end: 3, domain: 'lexical'}, {start: 3, end: 3, domain: 'punctuation'}], expected: ['Signalement 1 : orthographe à revoir, point d’insertion. Rejoindre l’autocorrection.', 'Signalement 2 : ponctuation à revoir, point d’insertion. Rejoindre l’autocorrection.']},
            {name: 'superposition et UTF-16', copy: '😀 chatt', issues: [{start: 3, end: 8, domain: 'lexical'}, {start: 3, end: 8, domain: 'punctuation'}], expected: ['« chatt ». Signalement 1 : orthographe à revoir ; Signalement 2 : ponctuation à revoir. Rejoindre l’autocorrection.']},
            {name: 'HTML saisi inerte', copy: '<b>chatt</b>', issues: [{start: 0, end: 12, domain: 'lexical'}], expected: ['« <b>chatt</b> ». Signalement 1 : orthographe à revoir. Rejoindre l’autocorrection.']},
        ];
        for (const item of cases) {
            const result = await evaluate(`(()=>{
                window.activations=[];window.renderer.renderFeedback(window.box,${JSON.stringify(item.copy)},${JSON.stringify(item.issues)},(start,end)=>window.activations.push([start,end]));
                const buttons=[...window.box.querySelectorAll('button')];buttons.forEach(button=>button.click());
                return {text:window.box.textContent,names:buttons.map(button=>button.getAttribute('aria-label')),activations:window.activations,htmlElements:window.box.querySelectorAll('b,script').length};
            })()`);
            assert.equal(result.text, item.copy, 'original text');
            assert.deepEqual(result.names, item.expected, 'DOM accessible labels');
            assert.equal(result.htmlElements, 0);
            const intervals = item.name === 'superposition et UTF-16' ? [[3, 8]] : item.issues.map(issue => [issue.start, issue.end]);
            assert.deepEqual(result.activations, intervals, 'unchanged activation offsets');
            const tree = await send('Accessibility.getFullAXTree');
            const names = tree.nodes.filter(node => !node.ignored && node.role?.value === 'button').map(node => node.name.value);
            assert.deepEqual(names, item.expected, 'Chrome computed accessible names');
            console.log(`OK ${item.name} : nom calculé, texte et offsets`);
        }
        console.log('OK contrôle DOM et arbre d’accessibilité Chrome ; aucun essai avec lecteur d’écran, aucune tentative consommée.');
    } finally {
        await send('Page.close').catch(() => {}); socket.close();
        for (const item of pending.values()) clearTimeout(item.timer);
    }
})().catch(error => {console.error(error); process.exitCode = 1;});
