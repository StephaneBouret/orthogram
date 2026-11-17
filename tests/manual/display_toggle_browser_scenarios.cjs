// Run through sidebar_browser_check.cjs: real Chrome, Symfony, Stimulus and isolated fixtures.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

module.exports = async ({ send, ev, wait, check, mouseClick, visit, artifacts }) => {
    const summary = '/courses/quiz-formation';
    const selector = '.course-display-toggle';
    const button = `document.querySelector('${selector}')`;
    const active = 'document.querySelector(".sidebar-container").classList.contains("is-fullscreen")';
    const visible = `getComputedStyle(${button}).display !== 'none'`;
    const key = async (key, code, windowsVirtualKeyCode, text) => {
        await send('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode, ...(text ? { text } : {}) });
        await send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode });
    };
    const size = async width => {
        await send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: false });
        await ev('new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
    };
    const screenshot = async name => {
        await ev('scrollTo({top: 0, behavior: "instant"})');
        const result = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(artifacts, `${name}.png`), Buffer.from(result.data, 'base64'));
    };
    const nameIs = async label => {
        const remote = await send('Runtime.evaluate', { expression: button });
        const tree = await send('Accessibility.getPartialAXTree', { objectId: remote.result.objectId });
        const node = tree.nodes.find(n => n.role?.value === 'button');
        assert.equal(node?.name?.value, label, 'Nom accessible identique au libellé visible');
        await check(`${button}.textContent.trim() === ${JSON.stringify(label)} && !${button}.hasAttribute('aria-pressed') && !${button}.hasAttribute('aria-expanded')`, `Action nommée « ${label} », sans état ARIA contradictoire`);
    };
    const layout = async label => {
        await check(`(() => {
            const b = ${button}.getBoundingClientRect();
            const h = document.querySelector('.course-content-heading').getBoundingClientRect();
            const main = document.querySelector('.main-content').getBoundingClientRect();
            return b.height >= 44 && b.height <= 48 && b.left >= main.left - 1 && b.right <= main.right + 1
                && (b.left >= h.right - 1 || b.top >= h.bottom - 1) && document.documentElement.scrollWidth <= innerWidth;
        })()`, `${label}: hauteur 44–48 px, aucun chevauchement ni débordement`);
    };
    const contrastReport = [];
    const contrast = async label => {
        const values = await ev(`(() => {
            const parse = color => {
                const ctx = document.createElement('canvas').getContext('2d');
                ctx.fillStyle = color; ctx.fillRect(0, 0, 1, 1);
                const p = [...ctx.getImageData(0, 0, 1, 1).data]; return [p[0], p[1], p[2], p[3] / 255];
            };
            const blend = (fg, bg) => fg.slice(0, 3).map((v, i) => v * fg[3] + bg[i] * (1 - fg[3]));
            const background = el => {
                const layers = []; for (; el; el = el.parentElement) layers.unshift(parse(getComputedStyle(el).backgroundColor));
                return layers.reduce((bg, fg) => blend(fg, bg), [255, 255, 255]);
            };
            const luminance = rgb => rgb.slice(0, 3).map(v => { v /= 255; return v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4; }).reduce((s, v, i) => s + v * [.2126, .7152, .0722][i], 0);
            const ratio = (a, b) => { const x = luminance(a), y = luminance(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); };
            const el = ${button}, css = getComputedStyle(el), bg = background(el), outer = background(el.parentElement);
            return { text: ratio(blend(parse(css.color), bg), bg), icon: ratio(blend(parse(getComputedStyle(el.querySelector('svg')).fill), bg), bg),
                borderInner: ratio(parse(css.borderTopColor), bg), borderOuter: ratio(parse(css.borderTopColor), outer),
                focus: ratio(parse(css.outlineColor), outer), color: css.color, background: css.backgroundColor, border: css.borderTopColor };
        })()`);
        assert.ok(values.text >= 4.5 && values.icon >= 3 && values.borderInner >= 3 && values.borderOuter >= 3, `${label}: ${JSON.stringify(values)}`);
        if (label.endsWith(' focus')) assert.ok(values.focus >= 3, `${label}: contraste du focus`);
        contrastReport.push({ label, ...values });
    };

    for (const profile of ['subscriber', 'anonymous']) {
        for (const route of [summary, summary + '/intro/first']) {
            await size(1440);
            await visit(route + '?profile=' + profile, route);
            await wait(`${button}?.querySelector('[data-fullscreen-target="label"]')?.textContent === 'Agrandir l’affichage'`);
            const page = route === summary ? 'sommaire' : 'titre-long';
            await check(`document.querySelectorAll('${selector}').length === 1`, `${profile} ${page}: un seul bouton`);
            for (const theme of ['light', 'dark']) {
                await ev(`document.documentElement.setAttribute('data-bs-theme', '${theme}'); window.displayDocument = document; window.displayContent = document.querySelector('.main-content'); window.displayUrl = location.href;
                    window.displayWidth = displayContent.getBoundingClientRect().width; window.displayFont = getComputedStyle(displayContent).fontSize; window.displayButtonWidth = ${button}.getBoundingClientRect().width;`);
                await ev('new Promise(resolve => setTimeout(resolve, 250))');
                await check(`document.documentElement.dataset.bsTheme === '${theme}'`, `${profile} ${page}: thème ${theme} appliqué`);
                const label = `${profile} ${page} ${theme}`;
                await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 1, y: 1 });
                await ev(`${button}.blur()`);
                await layout(label);
                await nameIs('Agrandir l’affichage');
                await contrast(label + ' repos');
                if (profile === 'subscriber') await screenshot(`display-${page}-${theme}-normal`);
                const restingBg = await ev(`getComputedStyle(${button}).backgroundColor`);
                const point = await ev(`(() => { const r = ${button}.getBoundingClientRect(); return {x: r.x + r.width / 2, y: r.y + r.height / 2}; })()`);
                await send('Input.dispatchMouseEvent', { type: 'mouseMoved', ...point });
                await wait(`getComputedStyle(${button}).backgroundColor !== ${JSON.stringify(restingBg)}`);
                // Wait for Bootstrap's color transition before measuring its final state.
                await ev('new Promise(resolve => setTimeout(resolve, 200))');
                await contrast(label + ' survol');
                await send('Input.dispatchMouseEvent', { type: 'mousePressed', button: 'left', clickCount: 1, ...point });
                await check(`${button}.matches(':active') && getComputedStyle(${button}).boxShadow !== 'none'`, `${label}: activation distincte`);
                await contrast(label + ' activation');
                await send('Input.dispatchMouseEvent', { type: 'mouseReleased', button: 'left', clickCount: 1, ...point });
                await nameIs('Afficher le menu');
                await check(`${active} && document.activeElement === ${button} && getComputedStyle(document.querySelector('#customer-sidebar')).display === 'none'`, `${label}: clic agrandit et conserve le focus`);
                const dimensions = await ev(`({before: displayWidth, after: displayContent.getBoundingClientRect().width, fontBefore: displayFont, fontAfter: getComputedStyle(displayContent).fontSize, buttonBefore: displayButtonWidth, buttonAfter: ${button}.getBoundingClientRect().width})`);
                assert.ok(dimensions.after > dimensions.before && dimensions.fontBefore === dimensions.fontAfter && dimensions.buttonBefore === dimensions.buttonAfter, `${label}: ${JSON.stringify(dimensions)}`);
                await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 1, y: 1 });
                await ev('new Promise(resolve => setTimeout(resolve, 200))');
                await layout(label + ' agrandi');
                await contrast(label + ' agrandi');
                if (profile === 'subscriber') await screenshot(`display-${page}-${theme}-agrandi`);
                await key('Escape', 'Escape', 27);
                await check(`!${active} && document.activeElement === ${button} && ${button}.matches(':focus-visible')`, `${label}: Échap rend le menu et le focus visible au même bouton`);
                // A genuine Tab traversal reaches the button (not a scripted focus).
                await key('Tab', 'Tab', 9);
                await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9, modifiers: 8 });
                await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9, modifiers: 8 });
                await check(`document.activeElement === ${button} && ${button}.matches(':focus-visible') && getComputedStyle(${button}).outlineStyle === 'solid' && getComputedStyle(${button}).outlineWidth === '3px'`, `${label}: focus clavier visible`);
                await contrast(label + ' focus');
                if (profile === 'subscriber' && page === 'sommaire') await screenshot(`display-${theme}-focus`);
                await key('Enter', 'Enter', 13, '\r');
                await check(`${active} && document.activeElement === ${button}`, `${label}: Entrée agrandit`);
                await key(' ', 'Space', 32);
                await check(`!${active} && document.activeElement === ${button}`, `${label}: Espace restaure`);
                await key(' ', 'Space', 32);
                await check(active, `${label}: Espace agrandit`);
                await key('Enter', 'Enter', 13, '\r');
                await check(`!${active}`, `${label}: Entrée restaure`);
                await check(`window.displayDocument === document && window.displayContent === document.querySelector('.main-content') && window.displayUrl === location.href && !document.fullscreenElement`, `${label}: aucun rechargement, remplacement, navigation ou plein écran navigateur`);

                for (const width of [1024, 800, 768]) {
                    await size(width);
                    await check(visible, `${label} ${width}px: contrôle visible`);
                    await layout(`${label} ${width}px normal`);
                    if (width === 768) {
                        await check(`${button}.getBoundingClientRect().top >= document.querySelector('.course-content-heading').getBoundingClientRect().bottom`, `${label}: bouton sous le titre à 768 px`);
                        if (profile === 'subscriber' && page === 'titre-long') await screenshot(`display-${theme}-768px-normal`);
                    }
                    await mouseClick(selector);
                    await layout(`${label} ${width}px agrandi`);
                    await key('Escape', 'Escape', 27);
                }
                await mouseClick(selector);
                await size(767);
                await wait(`!${active}`);
                await check(`!(${visible}) && document.activeElement.matches('.course-mobile-menu-toggle')`, `${label}: passage au mobile rétablit la vue normale et un focus visible`);
                for (const width of [767, 390]) {
                    await size(width);
                    await check(`!(${visible}) && document.documentElement.scrollWidth <= innerWidth`, `${label} ${width}px: aucun bouton ajouté ni débordement`);
                    await mouseClick('.course-mobile-menu-toggle');
                    await check('document.querySelector(".course-mobile-menu").classList.contains("is-open")', `${label} ${width}px: menu mobile ouvre`);
                    if (profile === 'subscriber' && page === 'titre-long' && width === 390) await screenshot(`display-${theme}-mobile-menu`);
                    await mouseClick('.course-mobile-menu-toggle');
                    await check('!document.querySelector(".course-mobile-menu").classList.contains("is-open")', `${label} ${width}px: menu mobile ferme`);
                }
                await size(1440);
                await check(`!${active} && getComputedStyle(document.querySelector('#customer-sidebar')).display !== 'none'`, `${label}: retour ordinateur normal`);
                await ev('document.querySelector("#customer-sidebar button").focus()');
                await check('document.activeElement.matches("#customer-sidebar button")', `${label}: focus initial dans la sidebar`);
                await size(767);
                await wait('document.activeElement.matches(".course-mobile-menu-toggle")');
                await check('document.activeElement.matches(".course-mobile-menu-toggle")', `${label}: focus de la sidebar transféré au menu mobile lors du redimensionnement`);
                await size(1440);
            }
        }
    }
    await visit(summary + '/exercise/exercise?profile=subscriber', summary + '/exercise/exercise');
    await mouseClick('[data-token-id="yes"]');
    await ev('window.answerNode = document.querySelector("[data-token-id=yes]"); window.exerciseNode = document.querySelector("[data-controller=click-words]"); window.answerMarkup = answerNode.outerHTML;');
    for (const theme of ['light', 'dark']) {
        await ev(`document.documentElement.setAttribute('data-bs-theme', '${theme}')`);
        await mouseClick(selector);
        await screenshot(`display-exercice-${theme}-agrandi`);
        await key('Escape', 'Escape', 27);
        await check('window.answerNode === document.querySelector("[data-token-id=yes]") && window.answerMarkup === answerNode.outerHTML && window.exerciseNode === document.querySelector("[data-controller=click-words]")', `${theme}: réponse en cours et exercice conservés après bascules`);
    }
    await mouseClick('[data-click-words-target="submit"]');
    await wait('document.querySelector(".exercise-score")?.textContent.includes("1/1")');
    await check('document.querySelector(".exercise-score").textContent.includes("1/1")', 'Réponse conservée réellement soumise : score 1/1');
    await check('browserErrors.length === 0', 'Aucune erreur JavaScript après la recette du bouton');
    fs.writeFileSync(path.join(artifacts, 'display-contrasts.json'), JSON.stringify(contrastReport, null, 2));
};
