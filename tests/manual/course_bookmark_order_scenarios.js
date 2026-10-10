// Executed by the local CDP harness, using the real application and Turbo.
window.runBookmarkOrderScenarios = async function (beforeFix) {
    const report = [];
    const events = [];
    const originalFetch = window.fetch;
    const base = '/courses/formation-en-orthographe/quiz-section/';
    const A = base + 'lecture-gratuite', B = base + 'quiz-cours';
    let gate = null;
    let pageGate = null;
    let staleReaderResponse = null;
    let failNext = false;
    let failRefresh = false;
    const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
    const wait = async (condition, label) => {
        for (let i = 0; i < 150; ++i) {
            if (condition()) return;
            await pause(40);
        }
        throw Error('Timeout: ' + label + ' ' + JSON.stringify(events));
    };
    const check = (condition, label) => { if (!condition) throw Error(label); report.push(label); };
    const hold = mode => {
        let release;
        const promise = new Promise(resolve => { release = resolve; });
        gate = { mode, promise, release, captured: false, processed: false, returned: false };
        return gate;
    };
    window.fetch = async function (url, options) {
        if (failRefresh && new URL(url, location.href).pathname === '/mes-resultats' && options?.method !== 'POST') {
            throw new TypeError('Controlled resume refresh failure');
        }
        if (staleReaderResponse && new URL(url, location.href).pathname === B && options?.method !== 'POST') {
            const response = staleReaderResponse;
            staleReaderResponse = null;
            return response;
        }
        if (pageGate && !pageGate.captured && new URL(url, location.href).pathname === pageGate.path) {
            const current = pageGate;
            current.captured = true;
            const response = await originalFetch.call(this, url, options);
            current.processed = true;
            await current.promise;
            return response;
        }
        if (!String(url).endsWith('/bookmark') || options?.method !== 'POST') return originalFetch.call(this, url, options);
        if (failNext) {
            failNext = false;
            events.push(['network-failure', String(url)]);
            throw new TypeError('Controlled offline failure');
        }
        const current = gate && !gate.captured ? gate : null;
        if (current) { current.captured = true; current.url = String(url); }
        if (current?.mode === 'send') await current.promise;
        events.push(['send', String(url), options.headers?.['X-Course-Bookmark-Version'] ?? null]);
        const response = await originalFetch.call(this, url, options);
        events.push(['server-response', String(url), response.status]);
        if (current) current.processed = true;
        if (current?.mode === 'response') await current.promise;
        if (current) current.returned = true;
        return response;
    };
    const serverLink = async () => {
        const html = await originalFetch('/ma-formation', { cache: 'no-store' }).then(r => r.text());
        return new DOMParser().parseFromString(html, 'text/html').querySelector('section[aria-labelledby="resume-course-title"] a')?.getAttribute('href');
    };
    const visit = async path => {
        const old = document.body;
        window.Turbo.visit(path);
        await wait(() => location.pathname === path && document.body !== old && !document.documentElement.hasAttribute('aria-busy'), 'visit ' + path);
    };
    try {
        events.push(['scenario', 'A envoyé après B']);
        let delayed = hold('send');
        await visit(A);
        await wait(() => delayed.captured, 'A captured before send');
        const start = events.length;
        await visit(B);
        await wait(() => events.slice(start).some(e => e[0] === 'server-response' && e[2] === 204), 'B committed');
        check(await serverLink() === B, 'A retenu AVANT envoi ; B est déjà enregistré côté serveur');
        delayed.release();
        await wait(() => delayed.returned, 'late A returned');
        check(await serverLink() === (beforeFix ? A : B), beforeFix
            ? 'BUG REPRODUIT : traitement tardif de A remplace B en base'
            : 'CORRIGÉ : traitement tardif de A refusé ; B reste en base');
        if (!beforeFix) check(events.at(-1)[2] === 409, 'Le serveur répond 409 à l’écriture A obsolète');

        events.push(['scenario', 'réponse A livrée après B']);
        delayed = hold('response');
        await visit(A);
        await wait(() => delayed.processed, 'A committed, response held');
        check(await serverLink() === A, 'A est enregistré AVANT de retenir sa réponse HTTP');
        const secondStart = events.length;
        await visit(B);
        await wait(() => events.slice(secondStart).some(e => e[0] === 'server-response' && e[2] === 204), 'B committed after A');
        delayed.release();
        await wait(() => delayed.returned, 'old response released');
        check(await serverLink() === B, 'Un simple retard de réponse de A ne modifie pas B en base');

        for (const destination of ['/mes-resultats', '/ma-formation']) {
            events.push(['scenario', 'écriture en attente, page déjà affichée', destination]);
            // Seed B before each scenario, then enter A without waiting for its write.
            const seed = events.length;
            await visit(B);
            await wait(() => events.slice(seed).some(e => e[0] === 'server-response' && e[2] === 204), 'seed B');
            delayed = hold('send');
            await visit(A);
            await wait(() => delayed.captured, 'pending A');
            await visit(destination);
            const link = () => [...document.querySelectorAll('main a, section[aria-labelledby="resume-course-title"] a')]
                .find(a => /Reprendre (mon|ce) cours/.test(a.textContent))?.getAttribute('href');
            check(link() === B, destination + ' reste accessible pendant l’écriture de A');
            delayed.release();
            await wait(() => delayed.returned, 'A settled on destination');
            check(await serverLink() === A, 'A confirmé en base depuis ' + destination);
            if (beforeFix) {
                await pause(200);
                check(link() === B, 'BUG REPRODUIT : lien B encore affiché sur ' + destination);
            } else {
                await wait(() => link() === A, 'resume link refreshed');
                check(true, 'CORRIGÉ : lien actualisé vers A sur ' + destination + ' sans navigation supplémentaire');
            }
        }
        if (!beforeFix) {
            events.push(['scenario', 'dernière consultation B sur version ancienne']);
            // Real B HTML produced before A commits, delivered only afterwards.
            const earlierB = await originalFetch(B, { cache: 'no-store' });
            delayed = hold('response');
            await visit(A);
            await wait(() => delayed.processed, 'A committed');
            staleReaderResponse = earlierB;
            const conflictStart = events.length;
            await visit(B);
            await wait(() => events.slice(conflictStart).some(e => e[0] === 'server-response' && e[2] === 409)
                && events.slice(conflictStart).some(e => e[0] === 'server-response' && e[2] === 204), 'latest B retries conflict');
            delayed.release();
            await wait(() => delayed.returned, 'A response finally delivered');
            check(await serverLink() === B, 'B réessaie son conflit avec la version serveur ; réponse A tardive sans effet');

            for (const destination of ['/mes-resultats', '/ma-formation']) {
                events.push(['scenario', 'écriture réussie avant rendu HTML ancien', destination]);
                const seed = events.length;
                await visit(B);
                await wait(() => events.slice(seed).some(e => e[0] === 'server-response' && e[2] === 204), 'seed B');
                delayed = hold('send');
                await visit(A);
                await wait(() => delayed.captured, 'A held');
                let release;
                pageGate = { path: destination, captured: false, processed: false, promise: new Promise(resolve => { release = resolve; }) };
                const navigation = visit(destination);
                await wait(() => pageGate.processed, 'old HTML received but not rendered');
                delayed.release();
                await wait(() => delayed.returned, 'A committed before render');
                release();
                await navigation;
                pageGate = null;
                await wait(() => document.querySelector('[data-course-resume] a')?.getAttribute('href') === A, 'late HTML refreshed');
                check(true, destination + ' : HTML ancien livré après succès, bloc néanmoins actualisé vers A');
            }

            events.push(['scenario', 'échec réseau']);
            failNext = true;
            await visit(B);
            await wait(() => !failNext, 'network failure');
            await visit('/mes-resultats');
            check(await serverLink() === A && location.pathname === '/mes-resultats', 'Échec réseau : ancienne reprise conservée, navigation utilisable');

            events.push(['scenario', 'écriture réussie, rafraîchissement impossible']);
            delayed = hold('send');
            await visit(B);
            await wait(() => delayed.captured, 'B pending');
            await visit('/mes-resultats');
            failRefresh = true;
            delayed.release();
            await wait(() => delayed.returned && document.querySelector('[data-course-resume]')?.hidden, 'obsolete link hidden');
            check(await serverLink() === B, 'Écriture réussie mais GET en panne : lien périmé masqué, aucun bouton inactif');
            failRefresh = false;
            await visit('/ma-formation');
            check(document.querySelector('[data-course-resume] a')?.getAttribute('href') === B, 'Navigation suivante : reprise courante de nouveau affichée');

            events.push(['scenario', 'changement de compte pendant A']);
            delayed = hold('send');
            await visit(A);
            await wait(() => delayed.captured, 'old account request held');
            await originalFetch('/__lot3/profile/admin');
            delayed.release();
            await wait(() => delayed.returned, 'old account request refused');
            check(events.at(-1)[2] === 403, 'POST de l’ancien compte refusé après changement de session');
            await visit('/ma-formation');
            check(!document.querySelector('[data-course-resume] a'), 'Aucune reprise de l’ancien compte injectée dans le nouveau');
        }
        return { report, events };
    } finally {
        gate?.release();
        window.fetch = originalFetch;
    }
};
