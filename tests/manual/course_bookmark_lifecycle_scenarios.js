// Isolated browser tests of the actual module source. Lifecycle events here are
// simulated; the CDP harness separately checks a native history restoration.
window.runBookmarkLifecycleScenarios = async function (source) {
    const report = [];
    const pause = () => new Promise(resolve => setTimeout(resolve, 0));
    const wait = async condition => {
        for (let i = 0; i < 100; ++i) {
            if (condition()) return;
            await pause();
        }
        throw Error('Lifecycle callback did not settle');
    };
    const check = (condition, label) => {
        if (!condition) throw Error(label);
        report.push('PASS [événements simulés] ' + label);
    };
    const deferred = () => {
        let resolve, reject;
        const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
        return { promise, resolve, reject };
    };
    const response = (status, version) => new Response(null, {
        status, headers: { 'X-Course-Bookmark-Version': String(version) },
    });
    const html = (version, label) => `<section data-course-resume data-bookmark-account="u" data-bookmark-version="${version}"><a>${label}</a></section>`;
    const fixture = async run => {
        const frame = document.createElement('iframe');
        document.body.append(frame);
        const win = frame.contentWindow;
        win.document.body.innerHTML = html(0, 'initial');
        const url = URL.createObjectURL(new Blob([source], { type: 'text/javascript' }));
        try {
            const module = await win.eval(`import(${JSON.stringify(url)})`);
            const lifecycle = () => {
                win.dispatchEvent(new win.PageTransitionEvent('pagehide', { persisted: true }));
                win.dispatchEvent(new win.PageTransitionEvent('pageshow', { persisted: true }));
            };
            const visit = version => module.recordBookmark({ account: 'u', version, url: '/bookmark', token: 'fixture' });
            await run({ win, visit, lifecycle, slot: win.document.querySelector('[data-course-resume]') });
        } finally {
            URL.revokeObjectURL(url);
            frame.remove();
        }
    };

    await fixture(async ({ win, visit, lifecycle }) => {
        const pending = deferred();
        let calls = 0;
        win.fetch = () => { calls++; return calls === 1 ? pending.promise : Promise.resolve(response(204, 2)); };
        const old = visit(0);
        lifecycle();
        pending.resolve(response(409, 1));
        await old;
        check(calls === 1, 'Un ancien POST 409 ne reprend aucune tentative après restauration');
        await visit(2); // The successful response is deliberately not a newer version: no fragment GET.
        check(calls === 2, 'Une nouvelle consultation peut envoyer son POST après restauration');
    });

    await fixture(async ({ win, visit, lifecycle, slot }) => {
        const pending = deferred();
        let calls = 0;
        win.fetch = () => { calls++; return pending.promise; };
        const old = visit(0);
        lifecycle();
        pending.resolve(response(204, 10));
        await old;
        win.document.dispatchEvent(new win.Event('turbo:load'));
        await pause();
        check(calls === 1 && !slot.hidden && slot.textContent === 'initial',
            'Un ancien POST 204 ne confirme aucun état et ne rafraîchit pas la page restaurée');
        win.dispatchEvent(new win.PageTransitionEvent('pagehide', { persisted: false }));
        await visit(10);
        check(calls === 1, 'Aucune nouvelle opération pendant que le document est inactif');
    });

    for (const stage of ['headers', 'body', 'failure']) {
        await fixture(async ({ win, visit, lifecycle, slot }) => {
            const oldGet = deferred(), newGet = deferred();
            let posts = 0, gets = 0;
            win.fetch = (url, options) => {
                if (options.method === 'POST') return Promise.resolve(response(204, ++posts));
                gets++;
                if (gets > 2) throw Error('Unexpected duplicate refresh');
                if (gets === 2) return newGet.promise;
                return stage === 'body'
                    ? Promise.resolve({ ok: true, redirected: false, text: () => oldGet.promise })
                    : oldGet.promise;
            };
            await visit(0);
            await wait(() => gets === 1);
            await pause(); // The first GET has reached fetch() or response.text().
            lifecycle();
            await visit(1);
            await wait(() => gets === 2);
            if (stage === 'failure') oldGet.reject(new TypeError('Controlled old GET failure'));
            else oldGet.resolve(stage === 'body' ? html(99, 'obsolete') : new Response(html(99, 'obsolete')));
            await pause();
            await pause();
            win.document.dispatchEvent(new win.Event('turbo:load'));
            await pause();
            check(slot.textContent === 'initial' && slot.dataset.bookmarkVersion === '0' && slot.hidden,
                `Ancien GET (${stage}) : aucun état ancien injecté après restauration`);
            check(gets === 2, `Ancien finally (${stage}) : ni relance, ni déverrouillage du nouveau GET`);
            newGet.resolve(new Response(html(2, 'current')));
            await wait(() => slot.textContent === 'current' && !slot.hidden);
            check(gets === 2 && slot.dataset.bookmarkVersion === '2',
                `Nouveau GET (${stage}) : actualisation courante réussie`);
        });
    }
    return report;
};
