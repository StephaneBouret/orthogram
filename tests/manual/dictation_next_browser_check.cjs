// Node 22+, Chrome CDP on 8816 (or CDP_URL). No database or installed npm packages.
// Real Stimulus/Turbo, controls from the Twig template, and the repository MP3 fixture.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const cdp = process.env.CDP_URL || 'http://127.0.0.1:8816';
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const read = file => fs.readFileSync(path.join(root, file));
const segments = [{ start: 0, end: 600 }, { start: 600, end: 1200 }, { start: 1200, end: 1800 }];
const template = read('templates/dictation/write.html.twig').toString();
const controls = template.slice(template.indexOf('<audio '), template.indexOf('</p>', template.indexOf('role="status" data-dictation-writing-target="audioStatus"')) + 4)
    .replace(/\{\{ path\('app_dictation_audio'.*?\}\}/g, '/tone.mp3')
    .replace(/\{% for segment in writing.segments %\}.*?\{% endfor %\}/s,
        segments.map((_, i) => `<option value="${i}">Segment ${i + 1} / 3</option>`).join(''));
assert.ok(controls.includes('dictation-writing#next') && !controls.includes('{{'));
const head = `<meta charset="utf-8"><script type="importmap">{"imports":{"@hotwired/stimulus":"/stimulus.js"}}</script>
<script type="module">
import { Application } from '/stimulus.js';
import Controller from '/assets/controllers/dictation_writing_controller.js';
import '/turbo.js';
window.app = Application.start(); app.register('dictation-writing', Controller);
window.errors = [];
addEventListener('unhandledrejection', e => errors.push(String(e.reason)));
</script>`;
const html = `${head}<main data-controller="dictation-writing" data-dictation-writing-segments-value='${JSON.stringify(segments)}'>
<textarea data-dictation-writing-target="copy">Copie conservée</textarea>
<span data-dictation-writing-target="count">Avancement</span><span data-dictation-writing-target="status"></span>
<button data-dictation-writing-target="retry"></button><div data-dictation-writing-target="recovery"></div>
<button data-dictation-writing-target="submit"></button>${controls}</main><a id="away" href="/away">Quitter</a>`;
const files = new Map([
    ['/stimulus.js', 'assets/vendor/@hotwired/stimulus/stimulus.index.js'],
    ['/turbo.js', 'assets/vendor/@hotwired/turbo/turbo.index.js'],
    ['/assets/controllers/dictation_writing_controller.js', 'assets/controllers/dictation_writing_controller.js'],
    ['/assets/js/dictation_feedback.js', 'assets/js/dictation_feedback.js'],
    ['/assets/js/dictation_navigation.js', 'assets/js/dictation_navigation.js'],
    ['/tone.mp3', 'tests/Fixtures/Dictation/tone.mp3'],
]);
const server = http.createServer((req, res) => {
    const file = files.get(req.url);
    if (file) {
        const body = read(file);
        const type = file.endsWith('.mp3') ? 'audio/mpeg' : 'text/javascript';
        const range = req.headers.range?.match(/^bytes=(\d+)-(\d*)$/);
        if (range) {
            const start = Number(range[1]), end = range[2] ? Number(range[2]) : body.length - 1;
            res.writeHead(206, { 'Content-Type': type, 'Content-Range': `bytes ${start}-${end}/${body.length}`, 'Accept-Ranges': 'bytes' });
            res.end(body.subarray(start, end + 1));
        } else { res.writeHead(200, { 'Content-Type': type }); res.end(body); }
    } else if (req.url === '/' || req.url === '/away') {
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end(req.url === '/' ? html : `${head}<p id="arrived">Autre page</p>`);
    } else { res.writeHead(404); res.end(); }
});

(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    let ws, tab;
    const pending = new Map(), exceptions = [];
    let sequence = 0;
    try {
        tab = await fetch(`${cdp}/json/new?about:blank`, { method: 'PUT' }).then(r => r.json());
        ws = new WebSocket(tab.webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { ws.addEventListener('open', resolve, { once: true }); ws.addEventListener('error', reject, { once: true }); });
        const send = (method, params = {}) => new Promise((resolve, reject) => {
            const id = ++sequence;
            const timer = setTimeout(() => { pending.delete(id); reject(Error(`CDP timeout: ${method}`)); }, 15000);
            pending.set(id, { resolve, reject, timer }); ws.send(JSON.stringify({ id, method, params }));
        });
        ws.addEventListener('message', event => {
            const data = JSON.parse(event.data);
            if (data.method === 'Runtime.exceptionThrown') exceptions.push(data.params.exceptionDetails.exception?.description || data.params.exceptionDetails.text);
            if (!pending.has(data.id)) return;
            const item = pending.get(data.id); pending.delete(data.id); clearTimeout(item.timer);
            if (data.error) item.reject(Error(data.error.message)); else item.resolve(data.result);
        });
        const evaluate = async expression => {
            const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
            if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
            return result.result.value;
        };
        const check = async (expression, label) => { assert.ok(await evaluate(expression), label); console.log('OK ' + label); };
        const wait = async expression => {
            for (let i = 0; i < 150; ++i) { if (await evaluate(expression)) return; await delay(50); }
            throw Error('Timeout: ' + expression);
        };
        const click = async action => {
            const point = await evaluate(`(() => { const el = document.querySelector('[data-action="dictation-writing#${action}"]'); const r = el.getBoundingClientRect(); return {x:r.x+r.width/2,y:r.y+r.height/2}; })()`);
            await send('Input.dispatchMouseEvent', { type: 'mousePressed', button: 'left', clickCount: 1, ...point });
            await send('Input.dispatchMouseEvent', { type: 'mouseReleased', button: 'left', clickCount: 1, ...point });
        };
        const key = async (key, code, modifiers, vk) => {
            await send('Input.dispatchKeyEvent', { type: 'rawKeyDown', key, code, modifiers, windowsVirtualKeyCode: vk });
            await send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, modifiers, windowsVirtualKeyCode: vk });
        };
        const icons = label => check(`[[c.playButtonTarget,w],[c.fullButtonTarget,f]].every(([b,a]) => {
            const playing=c.activeAudio===a&&!a.paused&&!a.ended, icon=b.querySelector('[data-audio-icon]');
            return icon.classList.contains('bi-pause-fill')===playing && icon.classList.contains('bi-play-fill')===!playing
                && b.title===b.getAttribute('aria-label') && b.title.startsWith(playing?'Mettre en pause':'Lire');
        }) && c.playButtonTarget.title.endsWith('le segment '+(c.index+1)) && c.selectTarget.value===String(c.index)`, label);
        await send('Runtime.enable'); await send('Page.enable');
        console.log((await send('Browser.getVersion')).product);
        await send('Page.navigate', { url: `http://127.0.0.1:${server.address().port}/` });
        await wait(`!!window.app?.getControllerForElementAndIdentifier(document.querySelector('main'),'dictation-writing')?.alive`);
        await evaluate(`window.c=app.getControllerForElementAndIdentifier(document.querySelector('main'),'dictation-writing'); window.w=c.writingTarget; window.f=c.completeTarget; w.playbackRate=.25;`);
        await check('w.paused && f.paused && w.readyState===0', 'aucune lecture au chargement, MP3 non préchargé');
        await click('next');
        await wait('!w.paused && w.currentTime>.6');
        await check('c.index===1 && w.currentTime<.9 && f.paused', 'Suivant à froid : lecture depuis le début du segment 2');
        await icons('lecture : sélection, icônes et noms accessibles');
        await wait('w.paused');
        await check('c.index===1 && Math.abs(w.currentTime-1.2)<.01 && c.audioStatusTarget.textContent.includes("Fin du segment 2")', 'arrêt à la borne sans enchaînement');
        await icons('fin de segment : Play disponible');
        await click('next'); await wait('!w.paused');
        await check('c.index===2 && w.currentTime>=1.2 && w.currentTime<1.5', 'Suivant après fin : lecture du segment 3');
        await check('c.nextTarget.disabled', 'Suivant désactivé au dernier segment');
        await evaluate('window.epoch=c.audioEpoch; window.position=w.currentTime; c.next();');
        await check('!w.paused && c.audioEpoch===epoch && w.currentTime>=position && c.index===2', 'appel direct au dernier segment sans effet sur la lecture');
        await click('previous');
        await check('w.paused && c.index===1 && w.currentTime===.6', 'Précédent sélectionne sans démarrer');
        await click('toggle'); await wait('!w.paused && w.currentTime>.65'); await click('toggle');
        await check('w.paused && w.currentTime>.6', 'pause au milieu du segment');
        await click('next'); await wait('!w.paused');
        await check('c.index===2 && w.currentTime>=1.2 && w.currentTime<1.5', 'Suivant depuis pause repart au début du suivant');
        await evaluate('c.choose(0)'); await click('toggle'); await wait('!w.paused && w.currentTime>.05');
        await click('next');
        await check('!w.paused && c.index===1 && w.currentTime>=.6 && w.currentTime<.9 && f.paused', 'Suivant interrompt la lecture courante et démarre le suivant');
        await click('full'); await wait('!f.paused');
        await check('w.paused', 'Écoute complète exclusive');
        await click('next'); await wait('!w.paused');
        await check('f.paused && c.index===2', 'Suivant arrête aussi la piste complète');
        await icons('changement de piste synchronisé');
        await evaluate(`c.choose(0); c.copyTarget.focus(); c.copyTarget.setSelectionRange(2,7,'backward');`);
        await key('F8', 'F8', 2, 119);
        await wait('!w.paused');
        await check('c.index===1', 'Ctrl+F8 lance le suivant');
        await key('F8', 'F8', 8, 119);
        await check('c.index===0 && w.paused', 'Maj+F8 conserve Précédent sans lecture');
        await key('ArrowRight', 'ArrowRight', 9, 39);
        await wait('!w.paused');
        await check(`c.index===1 && document.activeElement===c.copyTarget && c.copyTarget.value==='Copie conservée' && c.copyTarget.selectionStart===2 && c.copyTarget.selectionEnd===7 && c.copyTarget.selectionDirection==='backward' && !c.dirty()`, 'Alt+Maj+Droite : lecture, saisie et sélection préservées');
        await evaluate(`c.selectTarget.value='0'; c.selectTarget.dispatchEvent(new Event('change',{bubbles:true}));`);
        await check('c.index===0 && w.paused && w.currentTime===0', 'sélecteur inchangé : aucune lecture automatique');

        // Delay only settlement of play(): native playback still obeys native pause/seek.
        // Resolve/reject obsolete promises after a newer playback has already started.
        await evaluate(`window.nativePlay=w.play.bind(w); window.jobs=[]; window.starts=[];
            w.play=()=>{ starts.push(w.currentTime); const native=nativePlay();
                return new Promise((resolve,reject)=>{ native.catch(reject); jobs.push({resolve,reject}); }); };
            c.next(); c.next();`);
        await wait('!w.paused && w.currentTime>1.2');
        await check('c.index===2 && starts.length===2 && starts[0]===.6 && starts[1]===1.2 && f.paused', 'clics rapides : seul le dernier segment sélectionné joue');
        await evaluate('jobs[1].resolve(); jobs[0].resolve();'); await delay(50);
        await check('!w.paused && c.index===2 && w.currentTime>=1.2', 'résolution obsolète ne relance ni ne déplace le lecteur');
        await icons('icônes après résolutions inversées');
        await evaluate(`c.choose(0); jobs=[]; c.next(); c.next(); jobs[1].resolve(); jobs[0].reject(new DOMException('Interrupted','AbortError'));`);
        await delay(50);
        await check('!w.paused && c.index===2 && !c.audioStatusTarget.textContent.includes("impossible")', 'rejet obsolète ne suspend pas la dernière lecture');
        await evaluate(`c.choose(0); jobs=[]; c.next(); c.previous(); jobs[0].resolve();`); await delay(50);
        await check('w.paused && c.index===0 && w.currentTime===0', 'Précédent annule une lecture encore en attente');
        await evaluate(`w.play=()=>Promise.reject(new DOMException('Autoplay denied','NotAllowedError')); c.next();`);
        await wait('c.audioStatusTarget.textContent.includes("impossible")');
        await check('w.paused && f.paused && c.index===1 && w.currentTime===.6', 'refus audio : sélection conservée, lecteur arrêté');
        await icons('refus audio : Play disponible');
        await evaluate('w.play=nativePlay'); await click('toggle'); await wait('!w.paused');
        await check('c.index===1 && w.currentTime>=.6 && w.currentTime<.9', 'relance manuelle après refus');
        await evaluate(`c.choose(0); w.play=()=>{throw new Error('Synchronous failure')}; c.next();`);
        await check('w.paused && c.audioStatusTarget.textContent.includes("impossible")', 'exception synchrone du démarrage gérée');
        await evaluate(`w.play=nativePlay; c.choose(0); c.next(); c.next(); c.next();`);
        await wait('w.paused');
        await check('c.index===2 && Math.abs(w.currentTime-1.8)<.01 && c.nextTarget.disabled', 'clics natifs rapides : arrêt final, aucun retour au premier');
        await icons('arrêt final synchronisé');

        await evaluate(`c.choose(0); window.release=null; w.play=()=>{const native=nativePlay(); return new Promise((resolve,reject)=>{native.catch(reject); window.release=resolve;});}; c.next();`);
        await wait('!w.paused && w.currentTime>.6');
        await evaluate(`document.querySelector('#away').click()`);
        await wait('!!document.querySelector("#arrived") && !c.alive');
        await evaluate('release()'); await delay(100);
        await check('w.paused && f.paused && c.activeAudio===null && c.listeners.signal.aborted', 'navigation Turbo : médias arrêtés, écouteurs supprimés, promesse obsolète ignorée');
        await check('errors.length===0', 'aucune promesse rejetée non gérée');
        assert.deepEqual(exceptions, [], 'aucune exception JavaScript');
        console.log('PASS — lecteur réel et courses asynchrones ; aucune base de données utilisée.');
    } finally {
        for (const item of pending.values()) clearTimeout(item.timer);
        if (ws) ws.close();
        if (tab) await fetch(`${cdp}/json/close/${tab.id}`).catch(() => {});
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
