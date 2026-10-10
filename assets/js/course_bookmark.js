// One coordinator for the lifetime of this Turbo document, not one per reader.
// No browser storage, client clock or user-supplied URL is persisted.
let latest = null;
let confirmed = null;
let active = true;
let refreshing = false;
let generation = 0;

export async function recordBookmark(visit) {
    if (!active) return;
    const startedIn = generation;
    latest = visit;
    let version = visit.version;
    try {
        // Bounded retries let the latest visit win a conflict with an older
        // pending write. Other tabs/devices compete by atomic commit order.
        for (let attempt = 0; attempt < 4 && active && startedIn === generation && latest === visit; ++attempt) {
            const account = document.querySelector('[data-bookmark-account]')?.dataset.bookmarkAccount;
            if (account !== undefined && account !== visit.account) return;
            const response = await fetch(visit.url, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store', keepalive: true,
                headers: { 'X-CSRF-TOKEN': visit.token, 'X-Course-Bookmark-Version': String(version) },
            });
            if (!active || startedIn !== generation) return;
            const received = response.headers.get('X-Course-Bookmark-Version');
            const next = received === null ? NaN : Number(received);
            if (!Number.isSafeInteger(next) || next <= version) return;
            if (response.status === 204) {
                if (!active || latest?.account !== visit.account) return;
                if (!confirmed || confirmed.account !== visit.account || next > confirmed.version) {
                    confirmed = { account: visit.account, version: next };
                }
                void refreshResume();
                return;
            }
            if (response.status !== 409) return;
            version = next;
        }
    } catch {
        // An ambiguous network failure is not retried blindly. A later visit
        // can resolve any conflict; navigation never waits for this request.
    }
}

async function refreshResume(attempt = 0) {
    const slot = document.querySelector('[data-course-resume]');
    if (!active || !slot || !confirmed || slot.dataset.bookmarkAccount !== confirmed.account
        || Number(slot.dataset.bookmarkVersion) >= confirmed.version) return;
    const startedIn = generation;
    const hadFocus = slot.contains(document.activeElement);
    // Once the write is confirmed, do not leave a known obsolete link usable,
    // even if the follow-up GET fails. Normal navigation remains available.
    slot.hidden = true;
    if (refreshing) return;
    refreshing = true;
    const href = window.location.href;
    try {
        // Refresh only the resume fragment, preserving focus, section collapse,
        // charts and scroll. The server rechecks access and builds current URLs.
        const response = await fetch(href, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html' } });
        if (!active || startedIn !== generation || !response.ok || response.redirected) return;
        const html = new DOMParser().parseFromString(await response.text(), 'text/html');
        const fresh = html.querySelector('[data-course-resume]');
        if (!active || startedIn !== generation || window.location.href !== href || document.querySelector('[data-course-resume]') !== slot
            || !fresh || fresh.dataset.bookmarkAccount !== slot.dataset.bookmarkAccount) return;
        slot.innerHTML = fresh.innerHTML;
        slot.dataset.bookmarkVersion = fresh.dataset.bookmarkVersion;
        slot.hidden = confirmed?.account === slot.dataset.bookmarkAccount && Number(slot.dataset.bookmarkVersion) < confirmed.version;
        if (hadFocus && !slot.hidden && document.activeElement === document.body) slot.querySelector('a')?.focus({ preventScroll: true });
    } catch {
        // Keep the normal page usable when the refresh itself cannot reach the server.
    } finally {
        // An old callback must neither release a new generation's lock nor retry.
        if (active && startedIn === generation) {
            refreshing = false;
            // Covers a second commit, or a Turbo render, occurring during this GET.
            if (attempt < 2) void refreshResume(attempt + 1);
        }
    }
}

document.addEventListener('turbo:load', () => { void refreshResume(); });
window.addEventListener('pagehide', () => {
    active = false;
    ++generation;
    latest = null;
    confirmed = null;
    refreshing = false;
});
window.addEventListener('pageshow', event => {
    // BFCache restores the module too. Resume new visits, never pre-departure work.
    if (event.persisted) active = true;
});
