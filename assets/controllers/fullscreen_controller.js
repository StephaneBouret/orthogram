import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['sidebar', 'openButton', 'closeButton'];

    connect() {
        this.onKeydown = this.handleKeydown.bind(this);
        document.addEventListener('keydown', this.onKeydown);
        this.apply(false);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
        this.apply(false);
    }

    toggle(event) {
        event?.preventDefault();
        this.apply(!this.isActive());
    }

    handleKeydown(event) {
        if (event.key === 'Escape' && this.isActive()) {
            this.apply(false);
        }
    }

    apply(active) {
        this.element.classList.toggle(this.activeClass, active);

        if (this.hasSidebarTarget) {
            this.sidebarTarget.classList.toggle('d-none', active);
        }

        if (this.hasOpenButtonTarget) {
            this.openButtonTarget.classList.toggle('d-none', active);
            this.openButtonTarget.setAttribute('aria-pressed', active ? 'true' : 'false');
        }

        if (this.hasCloseButtonTarget) {
            this.closeButtonTarget.classList.toggle('d-none', !active);
            this.closeButtonTarget.setAttribute('aria-pressed', active ? 'true' : 'false');
        }
    }

    isActive() {
        return this.element.classList.contains(this.activeClass);
    }

    get activeClass() {
        return 'is-fullscreen';
    }
}
