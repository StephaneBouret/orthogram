// node tests/manual/sidebar_browser_check.cjs [path-to-Chrome]
// Real Bootstrap, Stimulus, Turbo and HTTP mutations on an isolated SQLite fixture.
const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const run = `sidebar-browser-${randomBytes(6).toString('hex')}`;
const artifacts = path.join(root, 'var', run);
fs.mkdirSync(artifacts);
const chrome = process.argv[2] || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const base = 'http://127.0.0.1:8851';
const debug = 'http://127.0.0.1:8852';
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
const serverLog = fs.openSync(path.join(artifacts, 'server.log'), 'w');
const server = spawn('php', ['-d', 'memory_limit=512M', '-d', 'xdebug.mode=off', '-S', '127.0.0.1:8851', '-t', 'public', 'tests/manual/sidebar_browser_router.php'], {
    cwd: root, env: { ...process.env, SIDEBAR_BROWSER_RUN: run }, windowsHide: true, stdio: ['ignore', serverLog, serverLog],
});
const browser = spawn(chrome, ['--headless=new', '--disable-gpu', '--no-first-run', '--disable-extensions', '--remote-debugging-port=8852', `--user-data-dir=${path.join(artifacts, 'chrome')}`, 'about:blank'], { windowsHide: true, stdio: 'ignore' });
let ws;
const report = [];
async function main() {
    let tab;
    for (let i = 0; i < 100; i++) {
        try { tab = await fetch(`${debug}/json/new?about:blank`, { method: 'PUT' }).then(r => r.json()); break; } catch { await pause(100); }
    }
    if (!tab) throw Error('Chrome did not start');
    ws = new WebSocket(tab.webSocketDebuggerUrl);
    await new Promise(resolve => ws.addEventListener('open', resolve, { once: true }));
    let sequence = 0;
    const pending = new Map();
    ws.addEventListener('message', event => {
        const data = JSON.parse(event.data);
        const request = pending.get(data.id);
        if (!request) return;
        pending.delete(data.id); clearTimeout(request.timer);
        data.error ? request.reject(Error(data.error.message)) : request.resolve(data.result);
    });
    const send = (method, params = {}) => new Promise((resolve, reject) => {
        const id = ++sequence;
        const timer = setTimeout(() => { pending.delete(id); reject(Error(`Timeout ${method}`)); }, 30000);
        pending.set(id, { resolve, reject, timer }); ws.send(JSON.stringify({ id, method, params }));
    });
    const ev = async expression => {
        const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        return result.result.value;
    };
    const wait = async expression => {
        for (let i = 0; i < 200; i++) { if (await ev(expression)) return; await pause(100); }
        throw Error(`Not ready: ${expression}`);
    };
    const check = async (expression, label) => { assert.ok(await ev(expression), label); report.push(label); console.log(`OK ${label}`); };
    const click = selector => ev(`document.querySelector(${JSON.stringify(selector)}).click()`);
    const summary = '/courses/quiz-formation';
    const visit = async route => {
        await ev(`window.Turbo.visit(${JSON.stringify(route)})`);
        await wait(`location.pathname === ${JSON.stringify(route)} && document.readyState === 'complete' && !!document.querySelector('[data-sidebar-section]')`);
        await pause(150);
    };
    const panel = (prefix, slug) => `document.querySelector('#${prefix}-collapse-' + window.sectionIds[${JSON.stringify(slug)}])`;
    const open = (prefix, slug) => `${panel(prefix, slug)}.classList.contains('show')`;
    const toggle = async (prefix, slug) => {
        await ev(`document.querySelector('[aria-controls="${prefix}-collapse-' + window.sectionIds[${JSON.stringify(slug)}] + '"]').click()`);
        await wait(`!${panel(prefix, slug)}.classList.contains('collapsing')`);
    };
    const validate = async () => {
        await ev('window.readerNode = document.querySelector("#lesson-content")');
        await click('#completion-button button');
        await wait(`document.querySelector('#completion-button button')?.textContent.includes('Terminé') && !document.querySelector('#completion-button button').disabled`);
        await check('window.readerNode === document.querySelector("#lesson-content")', 'Validation manuelle sans remplacement du cours');
    };
    await send('Page.enable'); await send('Runtime.enable');
    await send('Page.addScriptToEvaluateOnNewDocument', { source: 'window.browserErrors = []; addEventListener("error", e => browserErrors.push(e.message)); addEventListener("unhandledrejection", e => browserErrors.push(String(e.reason)));' });
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
    await send('Page.navigate', { url: base + summary });
    await wait('!!window.Turbo && !!document.querySelector("[data-sidebar-section]")');
    await ev(`window.sectionIds = Object.fromEntries([...document.querySelectorAll('#customer-sidebar nav')].map(nav => [nav.querySelector('h2').textContent.trim() === 'Quiz section' ? 'quiz' : nav.querySelector('h2').textContent.trim(), nav.querySelector('[data-sidebar-section]').dataset.sidebarSection]));`);
    const ids = await ev('window.sectionIds');
    await send('Page.addScriptToEvaluateOnNewDocument', { source: `window.sectionIds = ${JSON.stringify(ids)};` });
    for (const prefix of ['desktop', 'mobile']) await check(`${open(prefix, 'intro')} && !${open(prefix, 'empty')} && !${open(prefix, 'exercise')}`, `${prefix}: seule l’introduction est ouverte`);
    await check('document.querySelectorAll("[data-sidebar-section].show").length === 2', 'Une seule section ouverte par menu');
    await visit(summary + '/intro/first');
    await toggle('desktop', 'intro'); await toggle('desktop', 'exercise');
    await validate();
    await check(`!${open('desktop', 'intro')} && ${open('desktop', 'exercise')} && ${open('mobile', 'intro')} && !${open('mobile', 'exercise')}`, 'Validation partielle : choix indépendants conservés, menu masqué inclus');
    await visit(summary + '/intro/second');
    await toggle('desktop', 'last');
    await validate();
    for (const prefix of ['desktop', 'mobile']) await check(`!${open(prefix, 'intro')} && ${open(prefix, 'exercise')} && !${open(prefix, 'empty')} && !${open(prefix, 'done')}`, `${prefix}: transition saute la section vide et la section terminée`);
    await check(open('desktop', 'last'), 'Autre section ouverte manuellement conservée');
    await visit(summary + '/exercise/exercise');
    await ev('window.exerciseNode = document.querySelector("[data-controller=click-words]")');
    await click('[data-token-id="yes"]'); await click('[data-click-words-target="submit"]');
    await wait('document.querySelector(".exercise-score")?.textContent.includes("1/1")');
    await check(`window.exerciseNode === document.querySelector('[data-controller=click-words]') && !${open('desktop', 'exercise')} && ${open('desktop', 'quiz')} && !${open('mobile', 'exercise')} && ${open('mobile', 'quiz')}`, 'Exercice réussi : corrigé conservé et prochaine section ouverte');
    await toggle('desktop', 'exercise'); await toggle('desktop', 'quiz');
    await click('.exercise-retry-button'); await click('[data-token-id="yes"]'); await click('[data-click-words-target="submit"]');
    await wait('document.querySelector(".exercise-score")?.textContent.includes("1/1")');
    await check(`${open('desktop', 'exercise')} && !${open('desktop', 'quiz')}`, 'Exercice déjà terminé : aucune nouvelle transition');
    await visit(summary + '/quiz-section/quiz-cours');
    await wait(`!!document.querySelector('[data-action="quiz#start"]')`);
    await ev('window.quizNode = document.querySelector("[data-controller=quiz]")');
    await click('[data-action="quiz#start"]');
    for (let i = 0; i < 2; i++) {
        await wait('!!document.querySelector("[data-quiz-target=choice]") && !document.querySelector("[data-controller=quiz]").hasAttribute("aria-busy")');
        await click('[data-quiz-target="choice"]'); await click('[data-action="quiz#answer"]');
        await wait(`!!document.querySelector('[data-action="quiz#${i ? 'finish' : 'resume'}"]') && !document.querySelector('[data-controller=quiz]').hasAttribute('aria-busy')`);
        if (!i) await click('[data-action="quiz#resume"]');
    }
    await ev(`window.originalFetch = window.fetch; window.fetch = async (url, options) => {
        const response = await originalFetch(url, options);
        if (String(url).endsWith('/quiz/finish')) { await response.text(); window.fetch = originalFetch; throw Error('Réponse perdue après enregistrement'); }
        return response;
    };`);
    await click('[data-action="quiz#finish"]');
    await wait(`!!document.querySelector('[data-action="quiz#restart"]')`);
    await check(`window.quizNode === document.querySelector('[data-controller=quiz]') && document.querySelector('[data-quiz-target=stage]').textContent.includes('Votre résultat') && !${open('desktop', 'quiz')} && ${open('desktop', 'last')} && !${open('mobile', 'quiz')} && ${open('mobile', 'last')}`, 'Quiz terminé sans rechargement : bilan conservé, suite ouverte');
    await check(`document.querySelector('[data-quiz-target=error]').textContent.includes('récupéré')`, 'Fin de quiz enregistrée malgré une réponse perdue : progression récupérée');
    await toggle('desktop', 'quiz'); await toggle('desktop', 'last');
    await click('[data-action="quiz#restart"]');
    for (let i = 0; i < 2; i++) {
        await wait('!!document.querySelector("[data-quiz-target=choice]") && !document.querySelector("[data-controller=quiz]").hasAttribute("aria-busy")');
        await click('[data-quiz-target="choice"]'); await click('[data-action="quiz#answer"]');
        await wait(`!!document.querySelector('[data-action="quiz#${i ? 'finish' : 'resume'}"]') && !document.querySelector('[data-controller=quiz]').hasAttribute('aria-busy')`);
        if (!i) await click('[data-action="quiz#resume"]');
    }
    await click('[data-action="quiz#finish"]');
    await wait(`!!document.querySelector('[data-action="quiz#restart"]')`);
    await check(`${open('desktop', 'quiz')} && !${open('desktop', 'last')}`, 'Nouvelle tentative du quiz terminé : choix manuels conservés');
    await visit(summary + '/last/last'); await validate();
    await check('document.querySelectorAll("[data-sidebar-section].show").length === 0', 'Dernière section terminée : aucune ouverture automatique');
    await visit(summary);
    await check('document.querySelectorAll("[data-sidebar-section].show").length === 0', 'Sommaire entièrement terminé replié');
    await visit(summary + '/intro/first');
    await check(`${open('desktop', 'intro')} && ${open('mobile', 'intro')}`, 'Lien direct vers un cours terminé : section courante ouverte');
    await visit(summary + '/exercise');
    await check(`${open('desktop', 'exercise')} && ${open('mobile', 'exercise')}`, 'Page de section terminée : section courante ouverte');
    await ev('history.back()');
    await wait(`location.pathname === '${summary}/intro/first' && ${open('desktop', 'intro')}`);
    await check(`${open('mobile', 'intro')} && !${open('desktop', 'exercise')}`, 'Retour Turbo : états recalculés');
    await toggle('desktop', 'exercise');
    await send('Page.reload');
    await wait(`document.readyState === 'complete' && !!window.Turbo && ${open('desktop', 'intro')}`);
    await check(`!${open('desktop', 'exercise')}`, 'Rechargement : choix manuels réinitialisés');
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 900, deviceScaleFactor: 1, mobile: false });
    await click('.course-mobile-menu-toggle');
    await check('!document.querySelector(".course-mobile-menu-panel").classList.contains("d-none")', 'Menu mobile visible');
    await ev(`document.querySelector('[aria-controls="mobile-collapse-' + sectionIds.exercise + '"]').focus()`);
    await check(`document.activeElement.getAttribute('aria-controls') === 'mobile-collapse-' + sectionIds.exercise`, 'Focus clavier sur le bouton mobile');
    await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, text: '\r' });
    await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
    await wait(open('mobile', 'exercise'));
    await check(`document.querySelector('[aria-controls="mobile-collapse-' + sectionIds.exercise + '"]').getAttribute('aria-expanded') === 'true' && !document.querySelector('[aria-controls="mobile-collapse-' + sectionIds.exercise + '"] .opened').classList.contains('d-none')`, 'Clavier Entrée, aria-expanded et chevron cohérents');
    await send('Input.dispatchKeyEvent', { type: 'keyDown', key: ' ', code: 'Space', windowsVirtualKeyCode: 32 });
    await send('Input.dispatchKeyEvent', { type: 'keyUp', key: ' ', code: 'Space', windowsVirtualKeyCode: 32 });
    await wait(`!${open('mobile', 'exercise')} && !${panel('mobile', 'exercise')}.classList.contains('collapsing')`);
    await check(`document.querySelector('[aria-controls="mobile-collapse-' + sectionIds.exercise + '"]').getAttribute('aria-expanded') === 'false'`, 'Clavier Espace : une seule bascule après plusieurs navigations');
    await check('new Set([...document.querySelectorAll("[id]")].map(e => e.id)).size === document.querySelectorAll("[id]").length', 'Identifiants uniques');
    await check('browserErrors.length === 0', 'Aucune erreur JavaScript');
    fs.writeFileSync(path.join(artifacts, 'report.json'), JSON.stringify(report, null, 2));
    console.log(`Report: ${artifacts}`);
}
main().catch(error => { console.error(error); process.exitCode = 1; }).finally(() => {
    ws?.close(); browser.kill(); server.kill(); fs.closeSync(serverLog);
});
