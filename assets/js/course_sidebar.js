const panelSelector = '[data-sidebar-section]';

export function syncSidebarTrigger(root, panel, expanded) {
    const trigger = root.querySelector(`[aria-controls="${panel.id}"]`);
    if (!trigger) return;
    trigger.setAttribute('aria-expanded', String(expanded));
    trigger.classList.toggle('collapsed', !expanded);
    trigger.querySelector('.closed')?.classList.toggle('d-none', expanded);
    trigger.querySelector('.opened')?.classList.toggle('d-none', !expanded);
}

function setExpanded(root, panel, expanded) {
    panel.classList.toggle('show', expanded);
    syncSidebarTrigger(root, panel, expanded);
}

// Only used for progress updates on the same page. Navigation uses Twig defaults.
// Each menu retains its own manual choices, including while it is hidden.
export function updateCourseProgress(progress) {
    if (!progress) return;
    for (const [variant, selector] of Object.entries({ desktop: '#customer-sidebar', mobile: '.course-mobile-menu-panel' })) {
        const container = document.querySelector(selector);
        if (!container || typeof progress[variant] !== 'string') continue;
        const previous = new Map([...container.querySelectorAll(panelSelector)].map(panel => [panel.dataset.sidebarSection, {
            complete: panel.dataset.sidebarComplete === 'true',
            // Bootstrap updates aria-expanded at the beginning of an animation.
            expanded: container.querySelector(`[aria-controls="${panel.id}"]`)?.getAttribute('aria-expanded') === 'true',
        }]));
        const template = document.createElement('template');
        template.innerHTML = progress[variant];
        const fragment = template.content;
        const panels = [...fragment.querySelectorAll(panelSelector)];
        for (const panel of panels) {
            const old = previous.get(panel.dataset.sidebarSection);
            if (old) setExpanded(fragment, panel, old.expanded);
        }
        for (const [index, panel] of panels.entries()) {
            const old = previous.get(panel.dataset.sidebarSection);
            if (!old || old.complete || panel.dataset.sidebarComplete !== 'true') continue;
            setExpanded(fragment, panel, false);
            const next = panels.slice(index + 1).find(candidate => candidate.dataset.sidebarContinuable === 'true');
            if (next) setExpanded(fragment, next, true);
        }
        // Keep keyboard focus when a progress refresh replaces a sidebar control.
        const focused = container.contains(document.activeElement) ? document.activeElement : null;
        const control = focused?.getAttribute('aria-controls');
        const href = focused?.getAttribute('href');
        container.replaceChildren(fragment);
        if (control || href) {
            const replacement = [...container.querySelectorAll('button, a')].find(element => control
                ? element.getAttribute('aria-controls') === control : element.getAttribute('href') === href);
            replacement?.focus({ preventScroll: true });
        }
    }
}
