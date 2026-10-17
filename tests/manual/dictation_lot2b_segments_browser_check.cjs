// Editorial/segment checks. Run only against serve_dictation_lot2b_browser.php --long-audio.
// --observe-before records the unmodified implementation's actual behavior.
const fs = require('node:fs');
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
    const rows = '[data-collection="segments"] [data-rows] > [data-row]';
    const bound = (index, key) => `${rows}:nth-child(${index + 1}) input[data-bound="${key}"]`;
    const numbers = () => check(`JSON.stringify([...document.querySelectorAll('${rows} [data-segment-number]')].map(el=>el.textContent)) === JSON.stringify([...document.querySelectorAll('${rows}')].map((_,i)=>String(i+1)))`, 'numérotation selon l’ordre affiché');
    const error = (index, key, text) => check(`document.querySelector(${JSON.stringify(bound(index,key))}).parentElement.textContent.includes(${JSON.stringify(text)})`, `segment ${index+1}, ${key} : ${text}`);
    try {
        await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
        await send('Emulation.setDeviceMetricsOverride', {width:1400,height:1100,deviceScaleFactor:1,mobile:false});
        await load();
        await wait(`document.querySelector('audio[data-dictation-editor-target="audio"]').duration > 300`);
        for (let i=1;i<4;i++) await click('[data-collection="segments"] > button');
        const overlaps = [[0,128383],[128314,200000],[200000,260316],[260315,280000]];
        for (let i=0;i<4;i++) for (const [j,key] of ['start','end'].entries()) await field(bound(i,key), String(overlaps[i][j]));
        const revision = await evaluate(`document.querySelector('#form_revision').value`);
        if (await submit() !== 422) throw Error('Expected overlap refusal');
        if (observe) {
            console.log('AVANT : ' + await evaluate(`document.querySelector('form[name="form"] .alert-danger').textContent`));
            console.log('AVANT : erreurs locales aux bornes = ' + await evaluate(`document.querySelectorAll('${rows} .invalid-feedback').length`));
        } else {
            await numbers();
            await error(1,'start','segment 1 (128383 ms)');
            await error(3,'start','segment 3 (260316 ms)');
            await check(`JSON.stringify([...document.querySelectorAll('${rows} input')].map(el=>el.value)) === ${JSON.stringify(JSON.stringify(overlaps.flat().map(String)))} && document.querySelector('#form_revision').value === ${JSON.stringify(revision)}`, 'huit bornes et révision soumises conservées après refus');
            await check(`document.querySelector('label[for="form_text"]').textContent === 'Texte de référence de la dictée' && document.querySelector('#form_text_help').textContent.includes('correctement orthographié et ponctué') && document.querySelector('label[for="form_correction"]').textContent === 'Explications de Caroline — facultatif' && !document.querySelector('.dictation-editor').textContent.includes('UTF-16') && document.querySelector('label[for="form_reviewed"]').textContent.includes('aucune variante')`, 'libellés et aides éditoriales');
            await check(`fetch('${origin}/admin/dictees/1').then(r=>r.text()).then(html=>html.includes('Texte de référence de la dictée') && html.includes('Il sert de référence pour corriger les copies.'))`, 'création : libellé et aide identiques');
            await check(`document.querySelectorAll('${rows} input.is-invalid').length === 2 && [...document.querySelectorAll('${rows} input.is-invalid')].every(el => el.getAttribute('aria-describedby')?.split(' ').some(id=>document.getElementById(id)?.classList.contains('invalid-feedback')))`, 'erreurs liées aux champs pour les lecteurs d’écran');
            for (const theme of ['light','dark']) {
                await evaluate(`document.querySelector('[data-ea-color-scheme="${theme}"]').click()`); await delay(350);
                const capture = await send('Page.captureScreenshot', {format:'png',captureBeyondViewport:true});
                fs.writeFileSync(`var/dictation-segment-errors-${theme}.png`, Buffer.from(capture.data,'base64'));
            }
            await click('form[action$="/clone"] button');
            await check(`document.querySelector('[role="status"]').textContent.includes('Enregistrez')`, 'protection de la saisie après erreur de segments');
            await field(bound(1,'start'),'128383'); await field(bound(3,'start'),'260316');
            await field(bound(0,'end'),'0');
            if (await submit() !== 422) throw Error('Expected end <= start refusal');
            await error(0,'end','strictement supérieure au début');
            await field(bound(0,'start'),'abc'); await field(bound(0,'end'),'128383');
            if (await submit() !== 422) throw Error('Expected invalid integer refusal');
            await error(0,'start','nombre entier');
            await check(`document.querySelector(${JSON.stringify(bound(0,'start'))}).value === 'abc'`, 'valeur invalide conservée visiblement');
            await field(bound(0,'start'),'0'); await field(bound(3,'end'),'400000');
            if (await submit() !== 422) throw Error('Expected duration refusal');
            await error(3,'end','durée de la piste');
            await field(bound(3,'end'),'280000');
            if (await submit() !== 200) throw Error('Expected adjacent bounds accepted');
            await check(`JSON.stringify([...document.querySelectorAll('${rows} input[data-bound="start"]')].map(el=>el.value)) === '["0","128383","200000","260316"]'`, 'bornes adjacentes acceptées et relues sans modification');
            await click('[data-collection="segments"] > button'); await numbers();
            await click(`${rows}:nth-child(2) button[data-action$="#remove"]`); await numbers();
            await click(`${rows}:last-child button[data-direction="-1"]`); await numbers();
            await check(`document.activeElement.matches('button[data-direction="-1"]')`, 'focus clavier conservé après déplacement');
            for (const theme of ['light','dark']) {
                await evaluate(`document.querySelector('[data-ea-color-scheme="${theme}"]').click()`); await delay(350);
                await check(`getComputedStyle(document.querySelector('${rows} legend')).display !== 'none'`, 'légendes visibles : thème ' + theme);
            }
            if (errors.length) throw Error(errors.join('\n'));
            console.log('OK recette éditoriale et segments');
        }
    } finally {
        allowLeave = true; await send('Page.close').catch(()=>{}); ws.close();
        for (const item of pending.values()) clearTimeout(item.timer);
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
