// Slice only the original UTF-16 string. Each character is rendered once, even
// when several issues overlap. Point markers contain no replacement text.
export function renderFeedback(container, copy, issues, activate) {
    const ordered = issues.map((issue, index) => ({ ...issue, number: index + 1 }))
        .sort((a, b) => a.start - b.start || a.end - b.end || a.number - b.number);
    const boundaries = [...new Set([0, copy.length, ...ordered.flatMap(issue => [issue.start, issue.end])])].sort((a, b) => a - b);
    const fragment = document.createDocumentFragment();
    const button = (text, matches, start, end) => {
        const element = document.createElement('button');
        element.type = 'button';
        element.className = 'dictation-issue';
        element.classList.toggle('dictation-omission', start === end);
        element.classList.toggle('dictation-punctuation', matches.some(issue => issue.domain === 'punctuation'));
        element.classList.toggle('dictation-overlap', matches.some(issue => issue.domain === 'punctuation') && matches.some(issue => issue.domain === 'lexical'));
        element.textContent = text;
        const description = matches.map(issue => `Signalement ${issue.number} : ${issue.domain === 'punctuation' ? 'ponctuation à revoir' : 'orthographe à revoir'}${issue.start === issue.end ? ', point d’insertion' : ''}`).join(' ; ');
        const passage = start !== end ? `« ${text} ». ` : '';
        element.setAttribute('aria-label', `${passage}${description}. Rejoindre l’autocorrection.`);
        element.title = description;
        if (matches.some(issue => issue.domain === 'punctuation')) {
            const help = document.createElement('span');
            help.className = 'dictation-issue-help';
            help.setAttribute('aria-hidden', 'true');
            // CSS content keeps the copy's textContent exact. The accessible name
            // already contains this same information alongside the learner passage.
            element.append(help);
            const placeHelp = () => {
                const bounds = container.getBoundingClientRect();
                const buttonBounds = element.getBoundingClientRect();
                const helpBounds = help.getBoundingClientRect();
                const left = Math.max(bounds.left + 4, Math.min(buttonBounds.left, bounds.right - helpBounds.width - 4));
                const below = buttonBounds.top - helpBounds.height < bounds.top;
                help.style.left = `${left - buttonBounds.left}px`;
                help.style.top = below ? '100%' : 'auto';
                help.style.bottom = below ? 'auto' : '100%';
            };
            element.addEventListener('mouseenter', placeHelp);
            element.addEventListener('focus', placeHelp);
        }
        element.addEventListener('click', () => activate(start, end));
        fragment.append(element);
    };
    boundaries.forEach((start, index) => {
        for (const issue of ordered.filter(issue => issue.start === start && issue.end === start)) button('', [issue], start, start);
        const end = boundaries[index + 1];
        if (end === undefined) return;
        const text = copy.slice(start, end);
        const matches = ordered.filter(issue => issue.start <= start && issue.end >= end && issue.start !== issue.end);
        if (matches.length) button(text, matches, start, end);
        else fragment.append(document.createTextNode(text));
    });
    container.replaceChildren(fragment);
}
