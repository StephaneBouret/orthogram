// Targeted form regressions. Only run against serve_dictation_lot2b_browser.php.
// --observe-before records the unmodified implementation's actual behavior.
const path = require('node:path');
const origin = 'http://127.0.0.1:8815';
const cdp = 'http://127.0.0.1:8816';
const observe = process.argv.includes('--observe-before');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const draftUrl = origin + '/admin/dictees/versions/2';

(async () => {
    const tab = await fetch(`${cdp}/json/new?about:blank`, { method: 'PUT' }).then(r => r.json());
    const ws = new WebSocket(tab.webSocketDebuggerUrl);
    await new Promise(resolve => ws.addEventListener('open', resolve, { once: true }));
    let sequence = 0, allowLeave = false, documentStatus = null;
    const pending = new Map(), dialogs = [], errors = [];
    const send = (method, params = {}) => new Promise((resolve, reject) => {
        const id = ++sequence;
        const timer = setTimeout(() => { pending.delete(id); reject(Error('CDP timeout: ' + method)); }, 15000);
        pending.set(id, { resolve, reject, timer }); ws.send(JSON.stringify({ id, method, params }));
    });
    ws.addEventListener('message', event => {
        const data = JSON.parse(event.data);
        if (data.method === 'Page.javascriptDialogOpening') {
            dialogs.push(data.params.type);
            send('Page.handleJavaScriptDialog', { accept: allowLeave }).catch(e => errors.push(e.message));
        }
        if (data.method === 'Network.responseReceived' && data.params.type === 'Document') documentStatus = data.params.response.status;
        if (data.method === 'Runtime.exceptionThrown') errors.push(data.params.exceptionDetails.exception?.description || data.params.exceptionDetails.text);
        if (data.id && pending.has(data.id)) {
            const item = pending.get(data.id); pending.delete(data.id); clearTimeout(item.timer);
            data.error ? item.reject(Error(data.error.message)) : item.resolve(data.result);
        }
    });
    const evaluate = async expression => {
        const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        return result.result.value;
    };
    const wait = async expression => {
        for (let i = 0; i < 100; i++) {
            try { if (await evaluate(`Boolean(${expression})`)) return; }
            catch (error) { if (!/context|document|Cannot read properties of null/i.test(error.message)) throw error; }
            await delay(100);
        }
        throw Error('Timeout: ' + expression + '\n' + await evaluate(`JSON.stringify({url:location.href,status:document.body.innerText.slice(-4000),invalid:[...document.querySelectorAll(':invalid')].map(el=>el.id)})`));
    };
    const check = async (expression, label) => {
        if (!await evaluate(expression)) throw Error(label);
        console.log('OK ' + label);
    };
    const click = async selector => {
        await evaluate(`document.querySelector(${JSON.stringify(selector)}).scrollIntoView({block:'center',behavior:'instant'})`);
        await delay(200);
        const position = await evaluate(`(() => {
            const el = document.querySelector(${JSON.stringify(selector)});
            const r = el.getBoundingClientRect(); return {x:r.x + r.width / 2, y:r.y + r.height / 2};
        })()`);
        await send('Input.dispatchMouseEvent', {type:'mousePressed', button:'left', clickCount:1, ...position});
        await send('Input.dispatchMouseEvent', {type:'mouseReleased', button:'left', clickCount:1, ...position});
    };
    const mark = async () => {
        const value = String(++sequence);
        await evaluate(`window.formDocumentMarker = ${JSON.stringify(value)}`);
        return value;
    };
    const reloaded = marker => wait(`window.formDocumentMarker !== ${JSON.stringify(marker)} && document.readyState === 'complete' && document.querySelector('form[name="form"]')`);
    const load = async (url = draftUrl) => {
        allowLeave = true;
        const marker = await mark();
        await send('Page.navigate', {url}); await reloaded(marker); allowLeave = false;
    };
    const field = async (selector, value) => evaluate(`(() => {
        const el = document.querySelector(${JSON.stringify(selector)}); el.value = ${JSON.stringify(value)};
        el.dispatchEvent(new Event('input', {bubbles:true})); el.dispatchEvent(new Event('change', {bubbles:true}));
    })()`);
    const submit = async (button = '#form_save') => {
        const marker = await mark(); await click(button); await reloaded(marker); return documentStatus;
    };
    const uploadFile = async () => {
        const doc = await send('DOM.getDocument');
        const input = await send('DOM.querySelector', {nodeId:doc.root.nodeId, selector:'#file-complete'});
        await send('DOM.setFileInputFiles', {nodeId:input.nodeId, files:[path.resolve('tests/Fixtures/Dictation/tone.mp3')]});
    };
    const refusal = async () => {
        await load(); await field('#form_text', 'Texte non enregistré.');
        const revision = await evaluate(`document.querySelector('#form_revision').value`);
        if (await submit('#form_publish') !== 409) throw Error('Expected publication refusal 409');
        await check(`document.querySelector('#form_text').value === 'Texte non enregistré.' && document.querySelector('#form_revision').value === ${JSON.stringify(revision)}`, 'refus 409 : texte et révision soumis conservés');
        return revision;
    };
    const protectedActions = async label => {
        const marker = await mark();
        await click('form[action$="/clone"] button'); await delay(250);
        await check(`window.formDocumentMarker === ${JSON.stringify(marker)} && document.querySelector('[role="status"]').textContent.includes('Enregistrez')`, label + ' : duplication bloquée sans retoucher les champs');
        await uploadFile();
        await click('form[action$="/audio/complete"] button'); await delay(250);
        await check(`window.formDocumentMarker === ${JSON.stringify(marker)}`, label + ' : import bloqué sans retoucher les champs');
        const count = dialogs.length;
        await click('.dictation-editor > a'); await delay(300);
        if (dialogs.length !== count + 1 || dialogs.at(-1) !== 'beforeunload') throw Error(label + ' : avertissement de navigation absent');
        await check(`window.formDocumentMarker === ${JSON.stringify(marker)}`, label + ' : navigation annulée conserve le formulaire');
    };
    const rows = kind => `[data-collection="${kind}"] [data-rows] > [data-row]`;
    const add = kind => click(`[data-collection="${kind}"] > button`);
    const keys = kind => evaluate(`Array.from(document.querySelectorAll('${rows(kind)}')).map(row => row.querySelector('[name]').name.match(/\\[${kind}\\]\\[(\\d+)\\]/)[1])`);
    const consistent = kind => `(() => {
        const root = document.querySelector('[data-collection="${kind}"] [data-rows]');
        const fields = [...root.querySelectorAll('input[name],select[name],textarea[name]')];
        const ids = [...root.querySelectorAll('[id]')].map(el=>el.id);
        return new Set(ids).size === ids.length && new Set(fields.map(el=>el.name)).size === fields.length
            && fields.every(el => el.id === el.name.replaceAll('[','_').replaceAll(']',''))
            && [...root.querySelectorAll('label[for]')].every(label => root.querySelectorAll('[id="'+label.htmlFor+'"]').length === 1);
    })()`;
    const variants = async () => {
        await load();
        for (let i = 0; i < 3; i++) {
            await add('variants');
            await field(`${rows('variants')}:last-child select`, String(i));
            await field(`${rows('variants')}:last-child textarea`, ['Un', 'université', 'ferme'][i]);
        }
        await click(`${rows('variants')}:nth-child(2) button[data-action$="#remove"]`);
        await field('#form_title', ''); await evaluate(`document.querySelector('form[name="form"]').noValidate = true`);
        if (await submit() !== 422) throw Error('Expected variant validation error 422');
        console.log('VARIANTES après suppression et 422 : ' + JSON.stringify(await keys('variants')));
        await add('variants');
        const after = await keys('variants');
        console.log('VARIANTES après ajout : ' + JSON.stringify(after));
        if (observe) return;
        await check(consistent('variants'), 'variantes : names, ids et labels uniques/cohérents après ajout');
        await check(`document.querySelectorAll('${rows('variants')} textarea')[0].value === 'Un' && document.querySelectorAll('${rows('variants')} textarea')[1].value === 'ferme'`, 'variantes : anciennes valeurs et ordre préservés');
        await field(`${rows('variants')}:last-child select`, '1');
        await field(`${rows('variants')}:last-child textarea`, 'université');
        await field('#form_title', 'Brouillon synthétique');
        if (await submit() !== 200) throw Error('Expected saved variant redirect');
        await check(`JSON.stringify([...document.querySelectorAll('${rows('variants')} select')].map(el=>el.value)) === '["0","2","1"]' && JSON.stringify([...document.querySelectorAll('${rows('variants')} textarea')].map(el=>el.value)) === '["Un","ferme","université"]'`, 'trois variantes enregistrées sans écrasement ; referenceIndex distinct des indices du formulaire');
        await check(consistent('variants'), 'variantes : cohérence après rechargement');
    };
    const conflict = async () => {
        await load();
        const revision = await evaluate(`document.querySelector('#form_revision').value`);
        await field('#form_text', 'Saisie locale en conflit.');
        // A separate HTTP request saves the draft while this page retains its original revision.
        const status = await evaluate(`(async () => {
            const html = await fetch(location.href).then(r=>r.text());
            const other = new DOMParser().parseFromString(html, 'text/html').querySelector('form[name="form"]');
            other.querySelector('#form_title').value = 'Titre concurrent';
            const data = new FormData(other); data.set('form[save]', '');
            return (await fetch(location.href, {method:'POST',body:data})).status;
        })()`);
        if (status !== 200 || await submit() !== 409) throw Error('Expected saved concurrent request then conflict 409');
        await check(`document.querySelector('#form_text').value === 'Saisie locale en conflit.'
            && document.querySelector('#form_revision').value === ${JSON.stringify(revision)}
            && [...document.querySelectorAll('input[name="revision"]')].every(el=>el.value === ${JSON.stringify(revision)})`, 'conflit : saisie et révision initiale conservées dans tous les formulaires');
        await protectedActions('Conflit de révision 409');
        const stored = await evaluate(`fetch(location.href).then(r=>r.text()).then(html=>new DOMParser().parseFromString(html,'text/html').querySelector('#form_text').value)`);
        if (stored !== 'L’école ouvre.') throw Error('Conflict silently saved local text');
    };
    const segments = async () => {
        await load();
        await field(`${rows('segments')}:first-child input[data-bound="end"]`, '500');
        for (const [start, end] of [[600,1000],[1200,1500]]) {
            await add('segments');
            await field(`${rows('segments')}:last-child input[data-bound="start"]`, String(start));
            await field(`${rows('segments')}:last-child input[data-bound="end"]`, String(end));
        }
        await click(`${rows('segments')}:nth-child(2) button[data-action$="#remove"]`);
        await field('#form_title', '');
        // Native submission covers a sparse server response even without the JS order normalization.
        // The normal submit path (including normalization after a move) is exercised below.
        const marker = await mark(); allowLeave = true;
        await evaluate(`document.querySelector('form[name="form"]').submit()`);
        await reloaded(marker); allowLeave = false;
        if (documentStatus !== 422) throw Error('Expected segment validation error 422');
        console.log('SEGMENTS après suppression et 422 : ' + JSON.stringify(await keys('segments')));
        await add('segments');
        if (JSON.stringify(await keys('segments')) !== '["0","2","3"]') throw Error('Segment key collision');
        await check(consistent('segments'), 'segments : names, ids et labels uniques/cohérents après ajout');
        await check(`JSON.stringify([...document.querySelectorAll('${rows('segments')} input[data-bound="end"]')].slice(0,2).map(el=>el.value)) === '["500","1500"]'`, 'segments : anciennes valeurs et ordre conservés');
        await field(`${rows('segments')}:last-child input[data-bound="start"]`, '1600');
        await field(`${rows('segments')}:last-child input[data-bound="end"]`, '1900');
        // Move twice to restore chronological order with nonconsecutive keys; then save normally.
        await click(`${rows('segments')}:last-child button[data-direction="-1"]`);
        await click(`${rows('segments')}:nth-child(2) button[data-direction="1"]`);
        await field('#form_title', 'Segments conservés');
        await evaluate(`document.querySelector('form[name="form"]').addEventListener('submit', event => {
            if (!(${consistent('segments')})) { event.preventDefault(); throw Error('Segment names/ids/labels inconsistent during submit'); }
        }, {once:true})`);
        if (await submit() !== 200) throw Error('Expected successful segment save');
        await check(`JSON.stringify([...document.querySelectorAll('${rows('segments')} input[data-bound="start"]')].map(el=>el.value)) === '["0","1200","1600"]'
            && JSON.stringify([...document.querySelectorAll('${rows('segments')} input[data-bound="end"]')].map(el=>el.value)) === '["500","1500","1900"]'`, 'trois segments enregistrés sans écrasement et dans leur ordre');
        await check(consistent('segments'), 'segments : cohérence après normalisation et rechargement');
        // Also exercise the normal JS submission after deletion, followed by a new row and move.
        await click(`${rows('segments')}:nth-child(2) button[data-action$="#remove"]`);
        await field('#form_title', '');
        await evaluate(`document.querySelector('form[name="form"]').noValidate = true`);
        if (await submit() !== 422) throw Error('Expected normal segment submission failure');
        await add('segments');
        await check(consistent('segments'), 'segments : ajout après suppression et soumission JS refusée');
        await field(`${rows('segments')}:last-child input[data-bound="start"]`, '600');
        await field(`${rows('segments')}:last-child input[data-bound="end"]`, '1000');
        await click(`${rows('segments')}:last-child button[data-direction="-1"]`);
        await field('#form_title', 'Ordre des segments corrigé');
        await evaluate(`document.querySelector('form[name="form"]').addEventListener('submit', event => {
            if (!(${consistent('segments')})) { event.preventDefault(); throw Error('Moved segment names/ids/labels inconsistent during submit'); }
        }, {once:true})`);
        if (await submit() !== 200) throw Error('Expected moved segments saved');
        await check(`JSON.stringify([...document.querySelectorAll('${rows('segments')} input[data-bound="start"]')].map(el=>el.value)) === '["0","600","1600"]'`, 'segments : suppression, erreur, ajout et déplacement conservés après enregistrement');
    };
    try {
        await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
        await send('Emulation.setDeviceMetricsOverride', {width:1400,height:1100,deviceScaleFactor:1,mobile:false});
        await refusal();
        if (observe) {
            await click('form[action$="/clone"] button');
            await wait(`location.pathname !== '/admin/dictees/versions/2' && document.querySelector('#form_text')`);
            console.log('AVANT : duplication acceptée après 409, navigation vers ' + await evaluate('location.pathname'));
            await refusal(); await uploadFile(); const marker = await mark();
            await click('form[action$="/audio/complete"] button'); await reloaded(marker);
            console.log('AVANT : import après 409, texte réaffiché = ' + await evaluate(`JSON.stringify(document.querySelector('#form_text').value)`));
        } else {
            await protectedActions('Publication refusée 409');
            const count = dialogs.length;
            if (await submit() !== 200) throw Error('Expected successful save');
            const marker = await mark(); await click('.dictation-editor > a');
            await wait(`window.formDocumentMarker !== ${JSON.stringify(marker)} && document.readyState === 'complete'`);
            if (dialogs.length !== count) throw Error('Dirty state persisted after save');
            console.log('OK enregistrement réussi : état propre, navigation sans avertissement');
            // Restore the canonical reference for the collection scenarios.
            await load(); await field('#form_text', 'L’école ouvre.'); await submit();
            await field('#form_title', ''); await evaluate(`document.querySelector('form[name="form"]').noValidate = true`);
            if (await submit() !== 422) throw Error('Expected validation failure');
            await protectedActions('Validation refusée 422');
        }
        await variants();
        if (!observe) { await conflict(); await segments(); }
        if (errors.length) throw Error(errors.join('\n'));
        console.log(observe ? 'REPRODUCTION AVANT CORRECTION TERMINÉE' : 'OK scénarios de formulaire ciblés');
    } finally {
        allowLeave = true;
        await send('Page.close').catch(() => {});
        ws.close();
        for (const item of pending.values()) clearTimeout(item.timer);
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
