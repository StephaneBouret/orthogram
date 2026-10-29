import { Controller } from '@hotwired/stimulus';
import { updateCourseProgress } from '../js/course_sidebar.js';

export default class extends Controller {
    static targets = ['error'];

    connect() {
        this.request = null;
    }

    disconnect() {
        this.request?.abort();
        this.request = null;
    }

    async submit(event) {
        event.preventDefault();
        if (this.request) return;
        const form = event.target;
        const button = form.querySelector('button[type="submit"]');
        const restoreFocus = document.activeElement === button;
        const request = new AbortController();
        this.request = request;
        button.disabled = true;
        this.errorTarget.hidden = true;
        try {
            const response = await fetch(form.action, {
                method: 'POST', body: new FormData(form), signal: request.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok || response.redirected || !response.headers.get('content-type')?.includes('application/json')) {
                throw new Error('Impossible de confirmer la validation. Rechargez la page pour vérifier son état.');
            }
            const data = await response.json();
            if (this.request !== request || !this.element.isConnected) return;
            updateCourseProgress(data.progress);
            const template = document.createElement('template');
            template.innerHTML = data.completion;
            this.element.replaceChildren(...template.content.firstElementChild.childNodes);
            if (restoreFocus) this.element.querySelector('button[type="submit"]')?.focus({ preventScroll: true });
        } catch (error) {
            if (this.request !== request || !this.element.isConnected) return;
            this.errorTarget.textContent = error.message || 'Connexion interrompue. Rechargez la page pour vérifier la validation.';
            this.errorTarget.hidden = false;
        } finally {
            if (this.request === request) {
                this.request = null;
                button.disabled = false;
            }
        }
    }
}
