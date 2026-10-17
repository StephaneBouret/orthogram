import { Controller } from '@hotwired/stimulus';

// Explicit copy-free actions. A lost response replays the same revision and null copy.
export default class extends Controller {
    static targets = ['button', 'status'];
    static values = { url: String, token: String, revision: Number, action: String };

    connect() {
        this.alive = true;
        this.busy = false;
        this.operation = JSON.stringify({ revision: this.revisionValue, copy: null });
        this.buttonTarget.disabled = false;
    }

    disconnect() {
        this.alive = false;
        clearTimeout(this.timeout);
        this.request?.abort();
    }

    async submit() {
        if (this.busy) return;
        this.busy = true;
        this.buttonTarget.disabled = true;
        this.statusTarget.textContent = 'Validation en cours…';
        this.request = new AbortController();
        this.timeout = setTimeout(() => this.request.abort(), 15000);
        try {
            const response = await fetch(this.urlValue, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.tokenValue, Accept: 'application/json' },
                body: this.operation, signal: this.request.signal,
            });
            const data = await response.json();
            if (!this.alive) return;
            if (!response.ok) {
                this.statusTarget.textContent = `${data.message || 'Action indisponible.'} Rechargez la page pour retrouver votre copie.`;
                if (response.status >= 500 || [401, 403].includes(response.status)) this.buttonTarget.disabled = false;
                return;
            }
            const states = this.actionValue === 'resume' ? ['resuming', 'reviewing', 'completed'] : ['completed'];
            if (!Number.isInteger(data.revision) || data.revision < this.revisionValue + 1 || !states.includes(data.state)) throw new Error('Invalid acknowledgement');
            window.location.reload();
        } catch {
            if (this.alive) {
                this.statusTarget.textContent = 'Confirmation non reçue. Réessayez : la même opération sera vérifiée.';
                this.buttonTarget.disabled = false;
            }
        } finally {
            clearTimeout(this.timeout);
            this.busy = false;
        }
    }
}
