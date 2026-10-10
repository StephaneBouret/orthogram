// Personal course pages must always reflect the current account and bookmark,
// including links from outside the reader (the prefetch cache is separate from
// Turbo's snapshot cache).
document.addEventListener('turbo:before-prefetch', event => {
    const url = new URL(event.target.href, window.location.href);
    if (url.origin === window.location.origin
        && /^\/(?:courses(?:\/|$)|ma-formation\/?$|mes-resultats(?:\/|$))/.test(url.pathname)) {
        event.preventDefault();
    }
});

// Native browser back/forward cache is independent of Turbo's no-cache meta.
window.addEventListener('pageshow', event => {
    if (event.persisted && document.querySelector('meta[name="turbo-cache-control"][content="no-cache"]')) {
        window.location.reload();
    }
});
