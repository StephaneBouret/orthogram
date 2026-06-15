import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['result', 'submit', 'token'];
    static values = {
        submitUrl: String,
    };

    connect() {
        this.selected = new Set();
    }

    toggle(event) {
        const token = event.currentTarget;
        const tokenId = token.dataset.tokenId;

        if (!tokenId) {
            return;
        }

        this.clearCorrection();

        if (this.selected.has(tokenId)) {
            this.selected.delete(tokenId);
            token.classList.remove('word-token--selected');
            token.setAttribute('aria-pressed', 'false');

            return;
        }

        this.selected.add(tokenId);
        token.classList.add('word-token--selected');
        token.setAttribute('aria-pressed', 'true');
    }

    async submit() {
        this.submitTarget.disabled = true;

        try {
            const response = await fetch(this.submitUrlValue, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ selected: Array.from(this.selected) }),
            });
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Impossible de corriger cet exercice.');
            }

            this.applyCorrection(data);
        } catch (error) {
            this.showError(error.message);
        } finally {
            this.submitTarget.disabled = false;
        }
    }

    reset(event) {
        if (event) {
            event.preventDefault();
        }

        this.selected.clear();
        this.tokenTargets.forEach((token) => {
            token.classList.remove('word-token--selected', 'word-token--correct', 'word-token--wrong', 'word-token--missed');
            token.setAttribute('aria-pressed', 'false');
        });
        this.resultTarget.innerHTML = '';
        this.element.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    applyCorrection(data) {
        this.clearCorrection(false);

        (data.items || []).forEach((item) => {
            const token = this.tokenTargets.find((candidate) => candidate.dataset.tokenId === item.tokenId);

            if (!token) {
                return;
            }

            token.classList.add(`word-token--${item.status}`);
        });

        this.renderResult(data);
    }

    clearCorrection(clearResult = true) {
        this.tokenTargets.forEach((token) => {
            token.classList.remove('word-token--correct', 'word-token--wrong', 'word-token--missed');
        });

        if (clearResult) {
            this.resultTarget.innerHTML = '';
        }
    }

    renderResult(data) {
        const items = data.items || [];
        const corrections = items.map((item) => {
            const label = this.statusLabel(item.status);

            return `<li class="exercise-result-item exercise-result-item--${this.escapeHtml(item.status)}"><strong>${label}</strong> ${this.escapeHtml(item.explanation || '')}</li>`;
        }).join('');

        this.resultTarget.innerHTML = `
            <div class="exercise-result-panel">
                <p class="exercise-score">Score : ${data.score}/${data.total} (${data.percentage} %)</p>
                ${data.attempt ? `<p class="exercise-attempt-saved">Résultat enregistré. Tentative n°${data.attempt.number}.</p>` : ''}
                ${corrections ? `<ul class="exercise-corrections">${corrections}</ul>` : ''}
                <button type="button" class="btn btn-outline-grey exercise-retry-button" data-action="click->click-words#reset">Repasser l’exercice</button>
            </div>
        `;
    }

    showError(message) {
        this.resultTarget.innerHTML = `
            <div class="exercise-result-panel exercise-result-panel--error">
                ${this.escapeHtml(message)}
            </div>
        `;
    }

    statusLabel(status) {
        return {
            correct: 'Bonne réponse :',
            wrong: 'Erreur :',
            missed: 'Réponse oubliée :',
        }[status] || 'Correction :';
    }

    escapeHtml(value) {
        const element = document.createElement('span');
        element.textContent = value;

        return element.innerHTML;
    }
}
