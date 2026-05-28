import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        visibleLabel: { type: String, default: 'Afficher' },
        hiddenLabel: { type: String, default: 'Masquer' },
        buttonClasses: { type: Array, default: ['toggle-password-button'] },
    };

    connect() {
        this.visible = false;
        this.button = this.createButton();
        this.element.insertAdjacentElement('afterend', this.button);
        this.updateButton();
    }

    disconnect() {
        this.button?.remove();
    }

    toggle() {
        this.visible = !this.visible;
        this.element.type = this.visible ? 'text' : 'password';
        this.updateButton();
    }

    createButton() {
        const button = document.createElement('button');
        button.type = 'button';
        button.classList.add(...this.buttonClassesValue);
        button.addEventListener('click', () => this.toggle());

        return button;
    }

    updateButton() {
        const label = this.visible ? this.hiddenLabelValue : this.visibleLabelValue;
        const icon = this.visible ? 'bi-eye-slash' : 'bi-eye';

        this.button.innerHTML = `<i class="bi ${icon}" aria-hidden="true"></i><span class="visually-hidden">${label}</span>`;
        this.button.setAttribute('aria-label', label);
        this.button.setAttribute('title', label);
        this.button.setAttribute('aria-pressed', String(this.visible));
    }
}
