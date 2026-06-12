import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['button', 'panel'];

    connect() {
        this.onKeydown = this.handleKeydown.bind(this);
        this.onDocumentClick = this.handleDocumentClick.bind(this);
        document.addEventListener('keydown', this.onKeydown);
        document.addEventListener('click', this.onDocumentClick);
        this.close();
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
        document.removeEventListener('click', this.onDocumentClick);
    }

    toggle(event) {
        event.preventDefault();
        event.stopPropagation();

        this.isOpen() ? this.close() : this.open();
    }

    closeOnNavigate() {
        this.close();
    }

    handleKeydown(event) {
        if (event.key === 'Escape' && this.isOpen()) {
            this.close();
            this.buttonTarget?.focus();
        }
    }

    handleDocumentClick(event) {
        if (this.isOpen() && !this.element.contains(event.target)) {
            this.close();
        }
    }

    open() {
        this.panelTarget.classList.remove('d-none');
        this.element.classList.add('is-open');
        this.buttonTarget.setAttribute('aria-expanded', 'true');
    }

    close() {
        this.panelTarget.classList.add('d-none');
        this.element.classList.remove('is-open');
        this.buttonTarget.setAttribute('aria-expanded', 'false');
    }

    isOpen() {
        return this.element.classList.contains('is-open');
    }
}
