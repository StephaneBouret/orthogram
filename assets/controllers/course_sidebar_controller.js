import { Controller } from '@hotwired/stimulus';
import { syncSidebarTrigger } from '../js/course_sidebar.js';

export default class extends Controller {
    connect() {
        this.onCollapse = (event) => {
            const panel = event.target;
            syncSidebarTrigger(this.element, panel, event.type === 'shown.bs.collapse');
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
