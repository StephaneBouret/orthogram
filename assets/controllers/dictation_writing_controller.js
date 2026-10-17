import { Controller } from '@hotwired/stimulus';
import { renderFeedback } from '../js/dictation_feedback.js';
import { createCopyLocator, revealSelection } from '../js/dictation_navigation.js';

export default class extends Controller {
    static targets = ['copy', 'count', 'status', 'retry', 'recovery', 'complete', 'writing', 'select', 'previous', 'next', 'playButton', 'fullButton', 'audioStatus', 'submit', 'feedback', 'navigationStatus'];
    static values = { url: String, token: String, revision: Number, segments: Array, transitionUrl: String, transitionToken: String, phase: String, issues: Array, initial: Object };

    connect() {
        this.alive = true;
        this.confirmed = this.copyTarget.value;
        this.confirmedCount = this.countTarget.textContent;
        this.operation = null;
        this.transitionIntent = null;
        this.transitionOperation = null;
        this.inFlight = false;
        this.blocked = null;
        this.recoveryTarget.hidden = true;
        this.index = 0;
        this.audioEpoch = 0;
        this.copyTarget.disabled = false;
        this.submitTarget.disabled = false;
        if (this.hasFeedbackTarget) {
            // JSON preserves CR/CRLF from older/API copies; the HTML parser normalizes them.
            const initialCopy = this.initialValue.copy;
            renderFeedback(this.feedbackTarget, initialCopy, this.issuesValue, (start, end) => {
                const field = this.copyTarget;
                if (this.locatedCopy !== field.value) {
                    this.locatedCopy = field.value;
                    this.locate = createCopyLocator(initialCopy, field.value);
                }
                const range = this.locate(start, end);
                const previous = [field.selectionStart, field.selectionEnd, field.selectionDirection];
                field.focus({ preventScroll: true });
                if (range) {
                    field.setSelectionRange(...range);
                    revealSelection(field, ...range);
                    this.navigationStatusTarget.textContent = 'Passage retrouvé dans votre autocorrection. Les signalements restent ceux de la première copie.';
                } else {
                    field.setSelectionRange(...previous);
                    this.navigationStatusTarget.textContent = 'Ce passage a été modifié ou ne peut plus être localisé avec certitude. Retrouvez-le dans votre autocorrection.';
                    field.scrollIntoView({ block: 'center', behavior: 'instant' });
                }
            });
        }
        this.listeners = new AbortController();
        const options = { signal: this.listeners.signal };
        window.addEventListener('beforeunload', event => {
            if (this.dirty()) { event.preventDefault(); event.returnValue = ''; }
        }, options);
        document.addEventListener('turbo:before-visit', event => {
            if (this.dirty() && !window.confirm('Votre copie n’est pas entièrement enregistrée. Quitter cette page ?')) event.preventDefault();
        }, options);
        window.addEventListener('pageshow', event => {
            if (event.persisted) {
                if (this.dirty()) this.fail('conflict', 'Page restaurée : téléchargez votre copie avant de recharger pour retrouver la révision enregistrée.');
                else window.location.reload();
            }
        }, options);
        this.element.addEventListener('keydown', event => this.shortcut(event), options);
        for (const audio of [this.completeTarget, this.writingTarget]) {
            audio.addEventListener('play', () => this.updateButtons(), options);
            audio.addEventListener('pause', () => {
                if (audio === this.activeAudio && audio.paused) {
                    clearInterval(this.boundTimer);
                    this.audioTargetMessage('Lecture en pause.');
                }
                this.updateButtons();
            }, options);
            audio.addEventListener('playing', () => {
                if (audio !== this.activeAudio) { audio.pause(); return; }
                if (audio.paused) return;
                clearInterval(this.boundTimer);
                this.boundTimer = setInterval(() => this.boundary(), 20);
                this.updateButtons();
                this.audioTargetMessage(audio === this.writingTarget ? `Lecture du segment ${this.index + 1}.` : 'Lecture complète.');
            }, options);
            audio.addEventListener('emptied', () => this.updateButtons(), options);
            audio.addEventListener('error', () => { this.stop(); this.audioTargetMessage('Lecture impossible. Vérifiez votre connexion et votre session, puis réessayez.'); }, options);
            audio.addEventListener('ended', () => {
                if (audio !== this.activeAudio) return;
                this.stop();
                this.audioTargetMessage(audio === this.completeTarget ? 'Fin de l’écoute complète.' : `Fin du segment ${this.index + 1}.`);
            }, options);
        }
        this.writingTarget.addEventListener('timeupdate', () => this.boundary(), options);
        this.updateButtons();
    }

    disconnect() {
        this.alive = false;
        clearTimeout(this.debounce);
        clearTimeout(this.timeout);
        this.request?.abort();
        this.listeners.abort();
        this.stop();
    }

    dirty() { return this.transitionIntent !== null || this.operation !== null || this.inFlight || this.copyTarget.value !== this.confirmed; }

    input() {
        if (this.blocked === 'validation') { this.blocked = null; this.retryTarget.hidden = true; this.submitTarget.disabled = false; }
        clearTimeout(this.debounce);
        if (!this.blocked && !this.dirty()) { this.showConfirmed(); return; }
        this.countTarget.textContent = 'Avancement : décompte en attente d’enregistrement…';
        if (!this.blocked) this.statusTarget.textContent = 'Modifications non enregistrées…';
        this.debounce = setTimeout(() => this.save(), 600);
    }

    showConfirmed() {
        this.statusTarget.textContent = 'Enregistré';
        this.countTarget.textContent = this.confirmedCount;
        this.retryTarget.hidden = true;
        this.recoveryTarget.hidden = true;
        this.submitTarget.disabled = this.transitionIntent !== null;
    }

    insert(event) {
        if (this.transitionIntent !== null) return;
        const field = this.copyTarget;
        field.setRangeText(event.currentTarget.dataset.character, field.selectionStart, field.selectionEnd, 'end');
        field.focus();
        this.input();
    }

    async save() {
        if (!this.alive || this.inFlight || this.blocked) return;
        clearTimeout(this.debounce);
        if (this.transitionIntent !== null && this.operation === null) { this.sendTransition(); return; }
        // Equality never skips an uncertain operation: its exact receipt must be checked first.
        if (!this.dirty()) { this.showConfirmed(); return; }
        // Keep the exact operation until acknowledged, including across network retries.
        this.operation ??= { revision: this.revisionValue, copy: this.copyTarget.value };
        const operation = this.operation;
        this.inFlight = true;
        this.statusTarget.textContent = 'Enregistrement…';
        this.retryTarget.hidden = true;
        this.request = new AbortController();
        this.timeout = setTimeout(() => this.request.abort(), 15000);
        let canContinue = false;
        try {
            const response = await fetch(this.urlValue, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.tokenValue, Accept: 'application/json' },
                body: JSON.stringify(operation), signal: this.request.signal,
            });
            if (!this.alive) return;
            const data = await response.json();
            if (!response.ok) {
                if (response.status === 409) this.fail('conflict', 'Conflit : cette copie a changé dans un autre onglet ou n’est plus modifiable. Votre saisie est conservée ici. Téléchargez-la avant de recharger ; aucune fusion automatique ne sera faite.');
                else if ([413, 422].includes(response.status)) {
                    this.operation = null; // Explicit rejection: the server did not save this operation.
                    if (this.copyTarget.value === operation.copy) {
                        this.fail('validation', `${data.message} Votre saisie est conservée. Corrigez-la pour réessayer.`);
                    } else {
                        // The correction already happened while the rejected request was in flight.
                        canContinue = true;
                    }
                } else this.fail('retry', data.message || 'Enregistrement refusé. Votre saisie est conservée.');
                return;
            }
            if (!Number.isInteger(data.revision) || data.revision !== operation.revision + 1 || !Number.isInteger(data.wordCount) || !Number.isInteger(data.referenceWordCount)) throw new Error('Invalid acknowledgement');
            this.revisionValue = data.revision;
            this.confirmed = operation.copy;
            this.confirmedCount = `Avancement : ${data.wordCount} / ${data.referenceWordCount} mots`;
            this.operation = null;
            canContinue = true;
            if (this.copyTarget.value === this.confirmed) {
                this.showConfirmed();
            } else this.statusTarget.textContent = 'Modifications non enregistrées…';
        } catch {
            if (this.alive) this.fail('retry', 'Confirmation non reçue. Votre saisie est conservée. Réessayez : la même opération sera vérifiée avant les nouvelles modifications.');
        } finally {
            clearTimeout(this.timeout);
            this.inFlight = false;
            if (this.alive && canContinue) this.save();
        }
    }

    fail(kind, message) {
        this.blocked = kind;
        this.recoveryTarget.hidden = false;
        this.statusTarget.textContent = message;
        this.retryTarget.hidden = kind !== 'retry';
        this.submitTarget.disabled = true;
        if (['validation', 'conflict'].includes(kind) && this.transitionOperation === null) {
            this.transitionIntent = null;
            this.lockEditor(false);
        }
    }

    retry() { if (this.blocked === 'retry') { this.blocked = null; this.save(); } }

    submit() {
        if (this.transitionIntent !== null || (this.blocked && this.blocked !== 'validation')) return;
        this.blocked = null;
        this.transitionIntent = this.copyTarget.value;
        clearTimeout(this.debounce);
        this.lockEditor(true);
        this.save(); // A pending/uncertain SAVE must be acknowledged before assigning the revision.
    }

    lockEditor(locked) {
        this.copyTarget.readOnly = locked;
        this.copyTarget.setAttribute('aria-busy', String(locked));
        this.submitTarget.disabled = locked || this.blocked !== null;
        for (const button of this.element.querySelectorAll('[data-character]')) button.disabled = locked;
    }

    async sendTransition() {
        this.transitionOperation ??= { revision: this.revisionValue, copy: this.transitionIntent };
        const operation = this.transitionOperation;
        this.inFlight = true;
        this.statusTarget.textContent = 'Validation de votre copie…';
        this.retryTarget.hidden = true;
        this.request = new AbortController();
        this.timeout = setTimeout(() => this.request.abort(), 15000);
        try {
            const response = await fetch(this.transitionUrlValue, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.transitionTokenValue, Accept: 'application/json' },
                body: JSON.stringify(operation), signal: this.request.signal,
            });
            if (!this.alive) return;
            const data = await response.json();
            if (!response.ok) {
                if ([413, 422, 409].includes(response.status)) {
                    this.transitionIntent = null;
                    this.transitionOperation = null;
                    this.fail(response.status === 409 ? 'conflict' : 'validation', `${data.message} Votre saisie est conservée. ${response.status === 409 ? 'Téléchargez-la avant de recharger.' : 'Corrigez-la puis réessayez.'}`);
                    this.lockEditor(false);
                } else this.fail('retry', data.message || 'Confirmation non reçue. Réessayez la même opération.');
                return;
            }
            const states = this.phaseValue === 'writing' ? ['self_correction', 'awaiting_courses'] : ['awaiting_courses'];
            if (!Number.isInteger(data.revision) || data.revision < operation.revision + 1 || !states.includes(data.state)) throw new Error('Invalid transition acknowledgement');
            this.transitionIntent = null;
            this.transitionOperation = null;
            this.confirmed = this.copyTarget.value;
            this.inFlight = false;
            window.location.reload();
        } catch {
            if (this.alive) this.fail('retry', 'Confirmation non reçue. Votre copie reste protégée. Réessayez : la même opération sera vérifiée, sans nouvelle évaluation.');
        } finally {
            clearTimeout(this.timeout);
            this.inFlight = false;
        }
    }

    recover() {
        const url = URL.createObjectURL(new Blob([this.copyTarget.value], { type: 'text/plain;charset=utf-8' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = 'ma-copie.txt';
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    shortcut(event) {
        if (event.defaultPrevented || event.metaKey || event.isComposing || event.repeat || event.getModifierState('AltGraph')) return;
        const key = event.key && event.key !== 'Unidentified' ? event.key : event.code;
        let action;
        // Function-key alternatives do not depend on Alt+Shift reaching the browser.
        if (key === 'F8' && !event.altKey && !(event.ctrlKey && event.shiftKey)) {
            action = event.shiftKey ? () => this.previous() : event.ctrlKey ? () => this.next() : () => this.toggle();
        } else if (event.altKey && event.shiftKey && !event.ctrlKey) {
            // Retain the original shortcuts, including keyboards that omit KeyboardEvent.code.
            action = { ' ': () => this.toggle(), Space: () => this.toggle(), ArrowLeft: () => this.previous(), ArrowRight: () => this.next() }[key];
        }
        if (action) { event.preventDefault(); action(); }
    }

    select() { this.choose(Number(this.selectTarget.value)); }
    previous() { this.choose(this.index - 1); }
    next() { this.choose(this.index + 1); }
    choose(index) {
        if (index < 0 || index >= this.segmentsValue.length) return;
        this.stop();
        this.index = index;
        this.selectTarget.value = String(index);
        this.writingTarget.currentTime = this.segmentsValue[index].start / 1000;
        this.updateButtons();
        this.audioTargetMessage(`Segment ${index + 1} sélectionné. Lancez la lecture quand vous le souhaitez.`);
    }
    full() {
        if (this.activeAudio === this.completeTarget && !this.completeTarget.paused) { this.stop(); return; }
        if (this.completeTarget.ended) this.completeTarget.currentTime = 0;
        this.play(this.completeTarget);
    }
    toggle() {
        if (this.activeAudio === this.writingTarget && !this.writingTarget.paused) { this.stop(); return; }
        const segment = this.segmentsValue[this.index];
        if (this.writingTarget.currentTime < segment.start / 1000 || this.writingTarget.currentTime >= segment.end / 1000) this.writingTarget.currentTime = segment.start / 1000;
        this.play(this.writingTarget);
    }
    replay() {
        this.stop();
        this.writingTarget.currentTime = this.segmentsValue[this.index].start / 1000;
        this.play(this.writingTarget);
    }
    async play(audio) {
        this.stop();
        const epoch = this.audioEpoch;
        this.activeAudio = audio;
        this.audioTargetMessage('Chargement de la piste…');
        try {
            const started = audio.play();
            this.updateButtons();
            await started;
            if (!this.alive || epoch !== this.audioEpoch) return;
            this.updateButtons();
        } catch {
            if (this.alive && epoch === this.audioEpoch) { this.stop(); this.audioTargetMessage('Lecture impossible. Réessayez ou vérifiez votre connexion et votre session.'); }
        }
    }
    boundary() {
        if (this.activeAudio !== this.writingTarget) return;
        const end = this.segmentsValue[this.index].end / 1000;
        if (this.writingTarget.currentTime >= end) {
            this.stop();
            this.writingTarget.currentTime = end;
            this.audioTargetMessage(`Fin du segment ${this.index + 1}.`);
        }
    }
    stop() {
        ++this.audioEpoch;
        clearInterval(this.boundTimer);
        if (this.activeAudio) this.audioTargetMessage('Lecture en pause.');
        this.completeTarget.pause();
        this.writingTarget.pause();
        this.activeAudio = null;
        this.updateButtons();
    }
    updateButtons() {
        this.previousTarget.disabled = this.index === 0;
        this.nextTarget.disabled = this.index === this.segmentsValue.length - 1;
        this.updateAudioButton(this.playButtonTarget, this.writingTarget, `le segment ${this.index + 1}`);
        this.updateAudioButton(this.fullButtonTarget, this.completeTarget, 'l’écoute complète');
    }
    updateAudioButton(button, audio, name) {
        const playing = this.activeAudio === audio && !audio.paused && !audio.ended;
        const label = playing ? `Mettre en pause ${name}` : `Lire ${name}`;
        button.setAttribute('aria-label', label);
        button.title = label;
        const icon = button.querySelector('[data-audio-icon]');
        icon.classList.toggle('bi-play-fill', !playing);
        icon.classList.toggle('bi-pause-fill', playing);
    }
    audioTargetMessage(message) { this.audioStatusTarget.textContent = message; }
}
