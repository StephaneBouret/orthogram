import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['title', 'stage', 'error', 'choice', 'validate'];
    static values = { stateUrl: String, startUrl: String, answerUrl: String, finishUrl: String, restartUrl: String, token: String };

    connect() {
        this.active = true;
        this.generation = (this.generation || 0) + 1;
        this.busy = false;
        this.needsSync = false;
        this.load();
    }

    disconnect() {
        this.active = false;
        this.generation += 1;
        this.abort?.abort();
    }

    beforeCache() {
        this.disconnect();
        this.busy = false;
        this.element.removeAttribute('aria-busy');
        this.stageTarget.replaceChildren(this.node('p', 'Chargement du quiz…'));
        this.errorTarget.hidden = true;
    }

    node(tag, text, className) {
        const element = document.createElement(tag);
        if (text !== undefined && text !== null) element.textContent = text;
        if (className) element.className = className;
        return element;
    }

    button(text, action, primary = true) {
        const button = this.node('button', text, `btn ${primary ? 'btn-dark' : 'btn-outline-grey'}`);
        button.type = 'button';
        button.dataset.action = `quiz#${action}`;
        return button;
    }

    async request(url, payload) {
        if (!this.active) throw new DOMException('Navigation interrompue', 'AbortError');
        const generation = this.generation;
        this.abort = new AbortController();
        const response = await fetch(url, {
            method: payload === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { Accept: 'application/json', ...(payload === undefined ? {} : { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.tokenValue }) },
            body: payload === undefined ? undefined : JSON.stringify(payload), signal: this.abort.signal,
        });
        if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) {
            const error = new Error('Votre session a peut-être expiré. Rechargez la page pour vous reconnecter.');
            error.status = response.status;
            throw error;
        }
        let data;
        try {
            data = await response.json();
        } catch {
            throw new Error('Réponse du serveur illisible. Réessayez ; votre progression sera vérifiée.');
        }
        if (!this.active || generation !== this.generation) throw new DOMException('Navigation interrompue', 'AbortError');
        if (!response.ok) {
            const error = new Error(data.error || 'Le quiz est momentanément indisponible. Réessayez.');
            error.status = response.status;
            throw error;
        }
        return data;
    }

    async load() {
        await this.run(async () => {
            this.state = await this.request(this.stateUrlValue);
            return () => this.intro();
        });
    }

    async run(operation) {
        if (this.busy || !this.active) return;
        this.busy = true;
        const generation = this.generation;
        this.errorTarget.hidden = true;
        this.element.setAttribute('aria-busy', 'true');
        const controls = [...this.stageTarget.querySelectorAll('button, input')];
        const disabled = controls.map(control => control.disabled);
        controls.forEach(control => { control.disabled = true; });
        try {
            const render = await operation();
            if (this.active && generation === this.generation) render?.();
        } catch (error) {
            if (this.active && generation === this.generation && error.name !== 'AbortError') {
                this.errorTarget.textContent = error.message || 'Connexion interrompue. Réessayez.';
                this.errorTarget.hidden = false;
                if (!this.state) this.stageTarget.replaceChildren(this.button('Réessayer', 'load'));
            }
        } finally {
            if (this.active && generation === this.generation) {
                this.busy = false;
                this.element.removeAttribute('aria-busy');
                controls.forEach((control, index) => { if (control.isConnected) control.disabled = disabled[index]; });
                this.selection();
            }
        }
    }

    intro() {
        this.titleTarget.textContent = this.state.title;
        if (this.state.completed) return this.summary();
        const stage = this.stageTarget;
        stage.replaceChildren(this.node('p', `${this.state.total} question${this.state.total > 1 ? 's' : ''} · ${this.state.validated} réponse(s) validée(s)`));
        if (!this.state.attemptId) {
            stage.append(this.button('Commencer le quiz', 'start'));
        } else if (this.state.validated === this.state.total) {
            stage.append(this.button('Voir mon résultat', 'finish'));
        } else {
            stage.append(this.button('Reprendre le quiz', 'resume'));
        }
    }

    start() { this.mutate('start'); }
    restart() { this.mutate('restart', { attemptId: this.state.attemptId }); }
    finish() { this.mutate('finish', { attemptId: this.state.attemptId }); }

    async resume() {
        await this.run(async () => {
            this.state = await this.request(this.stateUrlValue);
            return () => this.current();
        });
    }

    current() {
        if (this.state.completed) return this.summary();
        if (!this.state.question) return this.intro();
        this.question();
    }

    question() {
        const question = this.state.question;
        this.displayedQuestionId = question.id;
        const stage = this.stageTarget;
        const heading = this.node('h3', 'Question ', 'h5');
        heading.append(this.node('span', this.state.validated + 1, 'quiz-progress-number'), ' sur ', this.node('span', this.state.total, 'quiz-progress-number'));
        heading.tabIndex = -1;
        stage.replaceChildren(heading);
        const fieldset = this.node('fieldset');
        fieldset.append(this.node('legend', question.title, 'h5 quiz-text'));
        if (question.text) fieldset.append(this.node('p', question.text, 'h5 quiz-text quiz-phrase'));
        fieldset.append(this.node('p', question.multiple ? 'Plusieurs réponses' : 'Une seule réponse', 'text-muted'));
        question.answers.forEach(answer => {
            const label = this.node('label', null, 'quiz-choice');
            const input = this.node('input');
            input.type = question.multiple ? 'checkbox' : 'radio';
            input.name = `quiz-${this.state.attemptId}-${question.id}`;
            input.value = String(answer.id);
            input.dataset.quizTarget = 'choice';
            input.dataset.action = 'change->quiz#selection';
            label.append(input, this.node('span', answer.content, 'quiz-text'));
            fieldset.append(label);
        });
        const validate = this.button('Valider ma réponse', 'answer');
        validate.dataset.quizTarget = 'validate';
        validate.disabled = true;
        stage.append(fieldset, validate);
        heading.focus();
    }

    selection() {
        if (this.hasValidateTarget) this.validateTarget.disabled = this.busy || !this.choiceTargets.some(input => input.checked);
    }

    answer() {
        const selectedIds = this.choiceTargets.filter(input => input.checked).map(input => Number(input.value));
        if (!selectedIds.length) return;
        this.mutate('answer', { attemptId: this.state.attemptId, questionId: this.displayedQuestionId, selectedIds });
    }

    // A failed POST may have committed. Read first; only retry if it is still pending.
    // If both requests fail, leave the original DOM and its checked inputs untouched.
    async mutate(action, payload = {}) {
        await this.run(async () => {
            if (this.needsSync) {
                const fresh = await this.request(this.stateUrlValue);
                this.needsSync = false;
                if (fresh.attemptId !== this.state.attemptId || fresh.validated !== this.state.validated || fresh.completed !== this.state.completed) {
                    this.state = fresh;
                    return () => fresh.completed ? window.location.reload() : this.current();
                }
                this.state = fresh;
            }
            try {
                this.state = await this.request(this[`${action}UrlValue`], payload);
            } catch (error) {
                if (error.name === 'AbortError') throw error;
                this.needsSync = true;
                try {
                    const fresh = await this.request(this.stateUrlValue);
                    this.needsSync = false;
                    const changed = fresh.attemptId !== this.state?.attemptId || fresh.validated !== this.state?.validated || fresh.completed !== this.state?.completed;
                    this.state = fresh;
                    if (changed) return () => {
                        if (fresh.completed) return window.location.reload();
                        this.current();
                        this.errorTarget.textContent = 'L’état enregistré a été récupéré. Vous pouvez poursuivre.';
                        this.errorTarget.hidden = false;
                    };
                } catch (readError) {
                    if (readError.name === 'AbortError') throw readError;
                }
                throw error;
            }
            return () => {
                if (action === 'finish') {
                    // Navigation refreshes the existing server-rendered lesson/sidebar progress.
                    window.location.reload();
                } else if (action === 'answer') {
                    const review = this.state.review.find(item => item.id === payload.questionId);
                    this.correction(review);
                } else {
                    this.titleTarget.textContent = this.state.title;
                    this.current();
                }
            };
        });
    }

    reviewContent(question) {
        const content = this.node('div');
        content.append(this.node('p', question.title, 'h5 fw-bold quiz-text'));
        if (question.text) content.append(this.node('p', question.text, 'h5 quiz-text quiz-phrase'));
        const list = this.node('ul', null, 'quiz-review');
        question.answers.forEach(answer => {
            const selected = question.selectedIds.includes(answer.id);
            const labels = [selected ? 'Votre choix' : null, answer.correct ? 'Bonne réponse' : (selected ? 'Réponse incorrecte' : null)].filter(Boolean);
            const item = this.node('li', answer.content, 'quiz-text');
            if (labels.length) {
                item.append(' — ', this.node('span', labels.join(' · '), `quiz-answer-feedback ${answer.correct ? 'is-correct' : 'is-incorrect'}`));
            }
            list.append(item);
        });
        content.append(list);
        if (question.explanation) content.append(this.node('p', question.explanation, 'quiz-text'));
        return content;
    }

    correction(question) {
        if (!question) return this.current();
        const heading = this.node('h3', question.correct ? 'Bonne réponse !' : 'Réponse incorrecte', 'h5');
        heading.tabIndex = -1;
        heading.setAttribute('role', 'status');
        this.stageTarget.replaceChildren(heading, this.reviewContent(question));
        this.stageTarget.append(this.state.validated === this.state.total
            ? this.button('Voir mon résultat', 'finish') : this.button('Question suivante', 'resume'));
        heading.focus();
    }

    summary() {
        const heading = this.node('h3', `Votre résultat : ${this.state.score} sur ${this.state.total}`, 'h5');
        heading.tabIndex = -1;
        const percentage = this.state.percentage;
        this.stageTarget.replaceChildren(heading, this.node('p', percentage == null ? 'Pourcentage indisponible' : `${percentage} % de bonnes réponses`, 'quiz-percentage'), this.node('p', `${this.state.score} bonne(s) réponse(s) · ${this.state.total - this.state.score} question(s) à revoir. Ce cours est terminé.`));
        this.state.review.forEach((question, index) => {
            const details = this.node('details', null, 'course-correction');
            details.append(this.node('summary', `Question ${index + 1} — ${question.correct ? 'Bonne réponse' : 'À revoir'}${question.theme ? ' · ' + question.theme : ''}`), this.reviewContent(question));
            this.stageTarget.append(details);
        });
        this.stageTarget.append(this.button('Recommencer le quiz', 'restart', false));
        heading.focus();
    }
}
