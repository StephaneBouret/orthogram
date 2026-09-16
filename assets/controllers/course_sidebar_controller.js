import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.onCollapse = (event) => {
            const panel = event.target;
            const trigger = this.element.querySelector(`[aria-controls="${panel.id}"]`);
            if (!trigger) return;

            const expanded = event.type === 'shown.bs.collapse';
            trigger.querySelector('.closed').classList.toggle('d-none', expanded);
            trigger.querySelector('.opened').classList.toggle('d-none', !expanded);
        };
        this.element.addEventListener('shown.bs.collapse', this.onCollapse);
        this.element.addEventListener('hidden.bs.collapse', this.onCollapse);
        // These pages opt out of Turbo snapshots in their <head>: every visit,
        // including history restoration, gets fresh progress and collapse defaults.
    }

    disconnect() {
        this.element.removeEventListener('shown.bs.collapse', this.onCollapse);
        this.element.removeEventListener('hidden.bs.collapse', this.onCollapse);
    }
}
