import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['audio', 'form', 'buttons', 'status'];
    static values = { unsaved: Boolean };

    connect() {
        this.alive = true;
        this.dirty = this.unsavedValue;
        this.deletePending = false;
        this.element.querySelectorAll('[data-delete-draft]').forEach(button => { button.disabled = false; });
        this.numberSegments();
        this.beforeUnload = event => {
            if (this.dirty || this.deletePending) { event.preventDefault(); event.returnValue = ''; }
        };
        window.addEventListener('beforeunload', this.beforeUnload);
    }

    disconnect() {
        this.alive = false;
        window.removeEventListener('beforeunload', this.beforeUnload);
        this.cancelStop();
    }

    changed() { this.dirty = true; }
    numberSegments() {
        this.element.querySelectorAll('[data-collection="segments"] [data-rows] > [data-row]').forEach((row, index) => {
            row.querySelector('[data-segment-number]').textContent = index + 1;
        });
    }
    submitting() {
        // Segment rows have no independent identity. Submit their displayed order explicitly.
        this.element.querySelectorAll('[data-collection="segments"] [data-rows] > [data-row]').forEach((row, index) => {
            const collection = row.closest('[data-collection]');
            const oldName = `${collection.dataset.name}[${row.dataset.index}]`;
            const newName = `${collection.dataset.name}[${index}]`;
            const oldId = `${collection.dataset.id}_${row.dataset.index}`;
            const newId = `${collection.dataset.id}_${index}`;
            row.querySelectorAll('[name]').forEach(input => {
                if (input.name.startsWith(oldName + '[')) input.name = newName + input.name.slice(oldName.length);
            });
            row.querySelectorAll('[id],[for],[aria-describedby],[aria-labelledby],[aria-errormessage]').forEach(element => {
                for (const attribute of ['id', 'for', 'aria-describedby', 'aria-labelledby', 'aria-errormessage']) {
                    if (!element.hasAttribute(attribute)) continue;
                    element.setAttribute(attribute, element.getAttribute(attribute).split(' ').map(value =>
                        value === oldId || value.startsWith(oldId + '_') ? newId + value.slice(oldId.length) : value
                    ).join(' '));
                }
            });
            row.dataset.index = index;
        });
        this.dirty = false;
    }

    guardOtherAction(event) {
        if (this.dirty) {
            event.preventDefault();
            this.statusTarget.textContent = 'Enregistrez les modifications du brouillon avant cette action.';
        }
    }

    async deleteDraft(event) {
        event.preventDefault();
        if (this.deletePending) return;
        const form = event.currentTarget;
        const button = form.querySelector('[data-delete-draft]');
        const question = `Supprimer définitivement le brouillon version ${form.dataset.versionNumber} ?\nCette action est irréversible.`
            + (this.dirty ? '\nLes modifications non enregistrées de cette page seront également perdues.' : '');
        if (!window.confirm(question)) { button.focus(); return; }
        const data = new FormData(form);
        const controls = [...this.element.querySelectorAll('input,textarea,select,button')].map(control => [control, control.disabled]);
        controls.forEach(([control]) => { control.disabled = true; });
        this.deletePending = true;
        this.statusTarget.textContent = 'Suppression du brouillon…';
        const abort = new AbortController();
        const timeout = setTimeout(() => abort.abort(), 15000);
        try {
            const response = await fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store', redirect: 'error', headers: { Accept: 'application/json' }, signal: abort.signal });
            const result = await response.json();
            if (!this.alive) return;
            if (!response.ok) {
                this.statusTarget.textContent = `${result.message || 'La suppression a été refusée.'} Votre saisie reste conservée sur cette page.`;
                return;
            }
            if (result.redirect !== form.dataset.returnUrl) throw new Error('Invalid deletion acknowledgement');
            this.dirty = false;
            this.deletePending = false;
            window.location.assign(result.redirect);
        } catch {
            if (this.alive) this.statusTarget.textContent = 'Suppression non confirmée. Votre saisie reste conservée. Préservez-la avant de vérifier la liste des versions ; aucun nouvel essai automatique.';
        } finally {
            clearTimeout(timeout);
            this.deletePending = false;
            controls.forEach(([control, disabled]) => { control.disabled = disabled; });
            if (this.alive) button.focus();
        }
    }

    add(event) {
        const collection = event.currentTarget.closest('[data-collection]');
        // Form keys may have holes after a failed submission. They are not referenceIndex values.
        const index = Math.max(Number(collection.dataset.next || 0), ...Array.from(
            collection.querySelectorAll('[data-rows] > [data-row]'), row => Number(row.dataset.index) + 1
        ));
        collection.dataset.next = index + 1;
        const row = document.createElement(collection.dataset.collection === 'segments' ? 'fieldset' : 'div');
        row.dataset.row = '';
        row.dataset.index = index;
        row.className = 'border rounded p-3 mb-2';
        row.innerHTML = collection.dataset.prototype.replaceAll('__name__', index);
        if (collection.dataset.collection === 'segments') {
            const legend = document.createElement('legend');
            legend.className = 'h5';
            legend.innerHTML = 'Segment <span data-segment-number></span>';
            row.prepend(legend);
            row.append(this.buttonsTarget.content.cloneNode(true));
        } else {
            const remove = document.createElement('button');
            remove.type = 'button'; remove.className = 'btn btn-secondary';
            remove.textContent = 'Supprimer la variante'; remove.dataset.action = 'dictation-editor#remove';
            row.append(remove);
        }
        collection.querySelector('[data-rows]').append(row);
        this.numberSegments();
        row.querySelector('input,select,textarea')?.focus();
        this.changed();
    }

    remove(event) {
        const collection = event.currentTarget.closest('[data-collection]');
        event.currentTarget.closest('[data-row]').remove();
        this.numberSegments();
        collection.querySelector(':scope > button')?.focus();
        this.changed();
    }

    move(event) {
        const row = event.currentTarget.closest('[data-row]');
        if (event.currentTarget.dataset.direction === '-1' && row.previousElementSibling) {
            row.previousElementSibling.before(row);
        } else if (event.currentTarget.dataset.direction === '1' && row.nextElementSibling) {
            row.nextElementSibling.after(row);
        }
        event.currentTarget.focus();
        this.numberSegments();
        this.changed();
    }

    capture(event) {
        if (!this.hasAudioTarget || !Number.isFinite(this.audioTarget.duration)) return;
        const row = event.currentTarget.closest('[data-row]');
        row.querySelector(`input[data-bound="${event.currentTarget.dataset.bound}"]`).value = Math.floor(this.audioTarget.currentTime * 1000);
        this.changed();
    }

    async play(event) {
        if (!this.hasAudioTarget) return;
        const row = event.currentTarget.closest('[data-row]');
        const start = Number(row.querySelector('input[data-bound="start"]').value) / 1000;
        const end = Number(row.querySelector('input[data-bound="end"]').value) / 1000;
        if (!(start >= 0 && end > start && end <= this.audioTarget.duration)) {
            this.statusTarget.textContent = 'Vérifiez les bornes du segment et attendez le chargement de la piste.';
            return;
        }
        this.cancelStop();
        this.audioTarget.currentTime = start;
        this.end = end;
        try {
            await this.audioTarget.play();
            this.timer = window.setInterval(() => this.stopAtEnd(), 20);
        } catch {
            this.statusTarget.textContent = 'La lecture audio a échoué.';
        }
    }

    stopAtEnd() {
        if (this.end !== undefined && this.audioTarget.currentTime >= this.end) {
            this.audioTarget.pause();
            this.audioTarget.currentTime = this.end;
            this.cancelStop();
        }
    }

    seeked() { this.stopAtEnd(); }
    cancelStop() { clearInterval(this.timer); this.end = undefined; }
}
