import { Controller } from '@hotwired/stimulus';
import { updateCourseProgress } from '../js/course_sidebar.js';

export default class extends Controller {
    static targets = ['result', 'submit', 'token', 'completion'];
    static values = {
        submitUrl: String,
        token: String,
        courseId: Number,
        savedResult: Object,
    };

    connect() {
        this.selected = new Set(this.tokenTargets.filter((token) => token.getAttribute('aria-pressed') === 'true').map((token) => token.dataset.tokenId));
        this.submitting = false;
        this.request = null;
        this.tokenTargets.forEach((token) => { token.disabled = false; });
        if (this.hasSubmitTarget) this.submitTarget.disabled = false;
        if (!this.resultTarget.innerHTML.trim() && this.hasSavedResultValue) {
            const saved = this.savedResultValue;
            this.selected = new Set(saved.selected || []);
            this.tokenTargets.forEach((token) => {
                const selected = this.selected.has(token.dataset.tokenId);
                token.classList.toggle('word-token--selected', selected);
                token.setAttribute('aria-pressed', String(selected));
            });
            this.applyCorrection(saved);
        }
    }

    disconnect() {
        this.request?.abort();
        this.request = null;
    }

    toggle(event) {
        if (this.submitting) return;
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
        if (this.submitting) return;
        this.submitting = true;
        const request = new AbortController();
        this.request = request;
        this.submitTarget.disabled = true;
        this.tokenTargets.forEach((token) => { token.disabled = true; });

        try {
            const response = await fetch(this.submitUrlValue, {
                method: 'POST',
                signal: request.signal,
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': this.tokenValue,
                },
                body: JSON.stringify({ selected: Array.from(this.selected), ...(this.hasCourseIdValue ? { courseId: this.courseIdValue } : {}) }),
            });
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Impossible de corriger cet exercice.');
            }

            if (!this.element.isConnected || this.request !== request) return;
            this.applyCorrection(data);
            this.updateProgress(data);
        } catch (error) {
            if (this.element.isConnected && this.request === request) this.showError(error.message);
        } finally {
            if (this.request === request) {
                this.submitting = false;
                this.submitTarget.disabled = false;
                this.tokenTargets.forEach((token) => { token.disabled = false; });
            }
        }
    }

    reset(event) {
        if (event) {
            event.preventDefault();
        }
        if (this.submitting) return;
        this.element.removeAttribute('data-click-words-saved-result-value');

        this.selected.clear();
        this.tokenTargets.forEach((token) => {
            token.classList.remove('word-token--selected', 'word-token--correct', 'word-token--wrong', 'word-token--missed');
            token.setAttribute('aria-pressed', 'false');
        });
        this.resultTarget.innerHTML = '';
        this.element.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    updateProgress(data) {
        if (!this.hasCourseIdValue) return;
        if (data.courseCompleted === true) {
            if (this.hasCompletionTarget) this.completionTarget.hidden = false;
            const status = document.getElementById('completion-button');
            if (status) status.hidden = false;
        }
        updateCourseProgress(data.progress);
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
        const groups = new Map();
        this.element.querySelectorAll('.click-words-sentence[data-sentence-id]').forEach((sentence, index) => {
            groups.set(sentence.dataset.sentenceId, { title: `Question ${index + 1}`, items: [] });
        });
        items.forEach((item) => {
            if (!groups.has(item.sentenceId)) {
                // Unknown tokens (or older corrections) must not be assigned to a question.
                groups.set(item.sentenceId, { title: 'Autres corrections', items: [] });
            }
            groups.get(item.sentenceId).items.push(item);
        });
        const corrections = Array.from(groups.values()).filter((group) => group.items.length).map((group) => {
            const entries = group.items.map((item) => {
                const label = this.statusLabel(item.status);

                return `<li class="exercise-result-item exercise-result-item--${this.escapeHtml(item.status)}"><strong>${label}</strong> ${this.escapeHtml(item.explanation || '')}</li>`;
            }).join('');

            return `<div class="exercise-correction-group"><h3 class="exercise-correction-group-title">${group.title}</h3><ul class="exercise-corrections">${entries}</ul></div>`;
        }).join('');

        this.resultTarget.innerHTML = `
            <div class="exercise-result-panel">
                <p class="exercise-score">Score : ${data.score}/${data.total} (${data.percentage} %)</p>
                ${data.attempt ? `<p class="exercise-attempt-saved">Résultat enregistré. Tentative n°${data.attempt.number}.</p>` : ''}
                ${corrections ? `<div class="exercise-correction-groups">${corrections}</div>` : ''}
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
