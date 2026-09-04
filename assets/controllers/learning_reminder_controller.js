import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

export default class extends Controller {
    static values = {
        upsertUrl: String,
        upsertToken: String,
        disableUrl: String,
        disableToken: String,
        reminder: Object,
    };

    static targets = [
        'section',
        'card',
        'cardBadge',
        'cardDescription',
        'cardStatus',
        'openButton',
        'openButtonLabel',
        'disableButton',
        'rejectButton',
        'modal',
        'closeButton',
        'form',
        'step',
        'stepHeading',
        'stepIndicator',
        'frequency',
        'dailyFields',
        'dailyTime',
        'weeklyFields',
        'weeklyTime',
        'weeklyDay',
        'onceFields',
        'onceDate',
        'onceTime',
        'validationSummary',
        'error',
        'summary',
        'previousButton',
        'nextButton',
        'finishButton',
        'finishLabel',
        'finishSpinner',
        'timezoneLabel',
        'courseContentTitle',
    ];

    connect() {
        this.currentStep = 1;
        this.openingElement = null;
        this.isPreparingCache = false;
        this.isSubmitting = false;
        this.isDisabling = false;
        this.requestController = null;
        this.defaultValidationSummary = this.validationSummaryTarget.textContent.trim();

        this.errorTargets.forEach((error) => {
            error.dataset.defaultMessage = error.textContent.trim();
        });

        this.bootstrapModal = Modal.getOrCreateInstance(this.modalTarget, {
            backdrop: true,
            focus: true,
            keyboard: true,
        });

        this.reset();
        this.renderCard();
    }

    disconnect() {
        this.isPreparingCache = true;
        this.abortRequest();
        this.isSubmitting = false;
        this.isDisabling = false;
        this.setSubmissionBusy(false);
        this.setDisableBusy(false);

        if (this.bootstrapModal) {
            this.bootstrapModal.hide();
            this.bootstrapModal.dispose();
            this.bootstrapModal = null;
        }

        this.openingElement = null;
    }

    open(event) {
        event.preventDefault();

        this.reset();
        this.openingElement = event.currentTarget;
        this.isPreparingCache = false;

        this.ensureModal().show(this.openingElement);
    }

    reject(event) {
        event.preventDefault();

        this.sectionTarget.hidden = true;
        this.courseContentTitleTarget.focus();
    }

    async disable(event) {
        event.preventDefault();

        if (this.isSubmitting || this.isDisabling) {
            return;
        }

        this.isDisabling = true;
        this.setDisableBusy(true);
        this.setCardStatus('');

        try {
            const { response, data } = await this.request(
                this.disableUrlValue,
                this.disableTokenValue,
            );

            if (!response.ok) {
                this.setCardStatus(this.messageForResponse(response.status, data), true);

                return;
            }

            this.reminderValue = data.reminder;
            this.renderCard();
            this.setCardStatus(data.message ?? 'Rappel désactivé.');
            this.openButtonTarget.focus();
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.setCardStatus(
                    'Le rappel n’a pas pu être désactivé. Vérifiez votre connexion puis réessayez.',
                    true,
                );
            }
        } finally {
            this.isDisabling = false;
            this.setDisableBusy(false);
        }
    }

    changeFrequency() {
        this.clearValidation();
        this.updateConditionalFields();
    }

    next(event) {
        event.preventDefault();

        this.clearValidation();

        if (!this.validateFirstStep()) {
            return;
        }

        this.summaryTarget.textContent = this.buildSummary();
        this.showStep(2, true);
    }

    previous(event) {
        event.preventDefault();
        this.clearValidation();
        this.showStep(1, true);
    }

    async finish(event) {
        event.preventDefault();

        if (this.currentStep === 1) {
            this.next(event);

            return;
        }

        if (this.isSubmitting || this.isDisabling) {
            return;
        }

        this.clearValidation();
        this.isSubmitting = true;
        this.setSubmissionBusy(true);

        let saved = false;

        try {
            const { response, data } = await this.request(
                this.upsertUrlValue,
                this.upsertTokenValue,
                this.buildPayload(),
            );

            if (!response.ok) {
                this.handleSaveError(response.status, data);

                return;
            }

            this.reminderValue = data.reminder;
            this.renderCard();
            this.setCardStatus(data.message ?? 'Rappel enregistré.');
            saved = true;
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.showModalError(
                    'Le rappel n’a pas pu être enregistré. Vérifiez votre connexion puis réessayez.',
                );
            }
        } finally {
            this.isSubmitting = false;
            this.setSubmissionBusy(false);
        }

        if (saved) {
            this.bootstrapModal?.hide();
        }
    }

    focusInitial() {
        const selected = this.frequencyTargets.find((frequency) => frequency.checked);

        (selected ?? this.frequencyTargets[0])?.focus();
    }

    preventCloseWhileSubmitting(event) {
        if (this.isSubmitting) {
            event.preventDefault();
        }
    }

    resetAfterClose() {
        const openingElement = this.openingElement;

        this.reset();
        this.openingElement = null;

        if (
            !this.isPreparingCache
            && openingElement?.isConnected
            && !openingElement.closest('[hidden]')
        ) {
            openingElement.focus();
        }
    }

    prepareForCache() {
        this.isPreparingCache = true;
        this.abortRequest();
        this.isSubmitting = false;
        this.isDisabling = false;
        this.setSubmissionBusy(false);
        this.setDisableBusy(false);
        this.setCardStatus('');
        this.sectionTarget.hidden = false;

        if (this.bootstrapModal) {
            this.bootstrapModal.hide();
        }

        this.reset();

        if (this.bootstrapModal) {
            this.bootstrapModal.dispose();
            this.bootstrapModal = null;
        }

        this.openingElement = null;
    }

    clearValidation() {
        this.validationSummaryTarget.textContent = this.defaultValidationSummary;
        this.validationSummaryTarget.hidden = true;

        this.errorTargets.forEach((error) => {
            error.textContent = error.dataset.defaultMessage;
            error.hidden = true;
        });

        this.formTarget.querySelectorAll('[aria-invalid="true"]').forEach((control) => {
            control.removeAttribute('aria-invalid');
            control.classList.remove('is-invalid');
        });
    }

    validateFirstStep() {
        const frequency = this.selectedFrequency();
        let firstInvalidControl = null;

        if (!frequency) {
            firstInvalidControl = this.markInvalid(
                'frequency',
                this.frequencyTargets,
            );
        }

        if (frequency === 'daily' && !this.dailyTimeTarget.value) {
            firstInvalidControl ??= this.markInvalid(
                'daily-time',
                [this.dailyTimeTarget],
            );
        }

        if (frequency === 'weekly') {
            if (!this.weeklyTimeTarget.value) {
                firstInvalidControl ??= this.markInvalid(
                    'weekly-time',
                    [this.weeklyTimeTarget],
                );
            }

            const selectedDays = this.weeklyDayTargets.filter((day) => day.checked);

            if (selectedDays.length === 0) {
                firstInvalidControl ??= this.markInvalid(
                    'weekly-days',
                    this.weeklyDayTargets,
                );
            }
        }

        if (frequency === 'once') {
            const hasDate = this.onceDateTarget.value !== '';
            const hasTime = this.onceTimeTarget.value !== '';

            if (!hasDate) {
                firstInvalidControl ??= this.markInvalid(
                    'once-date',
                    [this.onceDateTarget],
                );
            }

            if (!hasTime) {
                firstInvalidControl ??= this.markInvalid(
                    'once-time',
                    [this.onceTimeTarget],
                );
            }

            if (
                hasDate
                && hasTime
                && !this.isStrictlyFuture(
                    this.onceDateTarget.value,
                    this.onceTimeTarget.value,
                )
            ) {
                firstInvalidControl ??= this.markInvalid(
                    'once-future',
                    [this.onceDateTarget, this.onceTimeTarget],
                );
            }
        }

        if (!firstInvalidControl) {
            return true;
        }

        this.validationSummaryTarget.hidden = false;
        firstInvalidControl.focus();

        return false;
    }

    markInvalid(errorKey, controls, message = null) {
        const error = this.errorTargets.find(
            (candidate) => candidate.dataset.learningReminderErrorFor === errorKey,
        );

        if (error) {
            if (message) {
                error.textContent = message;
            }

            error.hidden = false;
        }

        controls.forEach((control) => {
            control.setAttribute('aria-invalid', 'true');
            control.classList.add('is-invalid');
        });

        return controls.find((control) => !control.disabled) ?? controls[0] ?? null;
    }

    updateConditionalFields() {
        const frequency = this.selectedFrequency();

        this.setGroupState(this.dailyFieldsTarget, frequency === 'daily');
        this.setGroupState(this.weeklyFieldsTarget, frequency === 'weekly');
        this.setGroupState(this.onceFieldsTarget, frequency === 'once');
    }

    setGroupState(group, active) {
        group.hidden = !active;

        group.querySelectorAll('input, select, textarea, button').forEach((control) => {
            control.disabled = !active;
        });
    }

    showStep(step, moveFocus = false) {
        this.currentStep = step;

        this.stepTargets.forEach((panel) => {
            panel.hidden = Number(panel.dataset.learningReminderStep) !== step;
        });

        this.stepIndicatorTarget.textContent = `Étape ${step} sur 2`;
        this.previousButtonTarget.hidden = step === 1;
        this.nextButtonTarget.hidden = step === 2;
        this.finishButtonTarget.hidden = step === 1;

        if (moveFocus) {
            const activeHeading = this.stepHeadingTargets.find(
                (heading) => Number(heading.dataset.learningReminderStep) === step,
            );

            activeHeading?.focus();
        }
    }

    reset() {
        this.formTarget.reset();
        this.currentStep = 1;
        this.summaryTarget.textContent = '';
        this.clearValidation();
        this.setMinimumDate();
        this.prefill();
        this.updateConditionalFields();
        this.updateTimezoneLabel();
        this.showStep(1);
    }

    prefill() {
        if (!this.hasStoredReminder()) {
            return;
        }

        const reminder = this.reminderValue;
        const frequency = this.frequencyTargets.find(
            (candidate) => candidate.value === reminder.frequency,
        );

        if (frequency) {
            frequency.checked = true;
        }

        if (reminder.frequency === 'daily') {
            this.dailyTimeTarget.value = reminder.reminderTime;
        }

        if (reminder.frequency === 'weekly') {
            this.weeklyTimeTarget.value = reminder.reminderTime;
            this.weeklyDayTargets.forEach((day) => {
                day.checked = reminder.weekdays.includes(Number(day.value));
            });
        }

        if (reminder.frequency === 'once') {
            this.onceDateTarget.value = reminder.scheduledDate ?? '';
            this.onceTimeTarget.value = reminder.reminderTime;
        }
    }

    buildPayload() {
        const frequency = this.selectedFrequency();
        const reminderTime = frequency === 'daily'
            ? this.dailyTimeTarget.value
            : frequency === 'weekly'
                ? this.weeklyTimeTarget.value
                : this.onceTimeTarget.value;

        return {
            frequency,
            reminderTime,
            weekdays: frequency === 'weekly'
                ? this.weeklyDayTargets
                    .filter((day) => day.checked)
                    .map((day) => Number(day.value))
                : [],
            scheduledDate: frequency === 'once' ? this.onceDateTarget.value : null,
            timezone: this.usedTimezone(),
        };
    }

    usedTimezone() {
        if (this.hasStoredReminder()) {
            return this.reminderValue.timezone;
        }

        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    }

    updateTimezoneLabel() {
        this.timezoneLabelTarget.textContent = this.usedTimezone();
    }

    hasStoredReminder() {
        return Boolean(
            this.hasReminderValue
            && this.reminderValue
            && this.reminderValue.frequency,
        );
    }

    renderCard() {
        const hasReminder = this.hasStoredReminder();
        const active = hasReminder && this.reminderValue.enabled;
        const disabled = hasReminder && !active;

        this.cardTarget.classList.toggle('is-active', active);
        this.cardTarget.classList.toggle('is-disabled', disabled);
        this.cardBadgeTarget.textContent = active
            ? 'Actif'
            : disabled
                ? 'Désactivé'
                : 'Planification';
        this.openButtonLabelTarget.textContent = active
            ? 'Modifier'
            : disabled
                ? 'Réactiver'
                : 'Commencer';
        this.disableButtonTarget.hidden = !active;
        this.rejectButtonTarget.hidden = active;

        if (active) {
            this.cardDescriptionTarget.textContent = this.reminderValue.summary;
        } else if (disabled) {
            this.cardDescriptionTarget.textContent =
                `Votre rappel est désactivé. Dernière configuration : ${this.reminderValue.summary}.`;
        } else {
            this.cardDescriptionTarget.textContent =
                this.cardDescriptionTarget.dataset.defaultText;
        }
    }

    async request(url, csrfToken, payload = null) {
        this.abortRequest();
        this.requestController = new AbortController();

        const options = {
            method: 'POST',
            credentials: 'same-origin',
            signal: this.requestController.signal,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
        };

        if (payload !== null) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(payload);
        }

        const response = await fetch(url, options);
        let data = {};

        try {
            data = await response.json();
        } catch {
            data = {};
        }

        this.requestController = null;

        return { response, data };
    }

    abortRequest() {
        this.requestController?.abort();
        this.requestController = null;
    }

    handleSaveError(status, data) {
        if (status === 422) {
            this.showServerValidation(data);

            return;
        }

        this.showModalError(this.messageForResponse(status, data));
    }

    showServerValidation(data) {
        this.showStep(1);
        this.clearValidation();

        const violations = Array.isArray(data.violations) ? data.violations : [];
        let firstInvalidControl = null;

        violations.forEach((violation) => {
            const key = this.errorKeyForProperty(
                violation.propertyPath ?? '',
                violation.title ?? '',
            );
            const controls = this.controlsForErrorKey(key);

            if (key && controls.length > 0) {
                firstInvalidControl ??= this.markInvalid(
                    key,
                    controls,
                    violation.title,
                );
            }
        });

        this.validationSummaryTarget.textContent =
            data.detail ?? 'Veuillez corriger les champs signalés.';
        this.validationSummaryTarget.hidden = false;

        (firstInvalidControl ?? this.validationSummaryTarget).focus();
    }

    errorKeyForProperty(propertyPath, message = '') {
        if (propertyPath.startsWith('frequency')) {
            return 'frequency';
        }

        if (propertyPath.startsWith('reminderTime')) {
            return `${this.selectedFrequency()}-time`;
        }

        if (propertyPath.startsWith('weekdays')) {
            return 'weekly-days';
        }

        if (propertyPath.startsWith('scheduledDate')) {
            return message.includes('strictement futures')
                ? 'once-future'
                : 'once-date';
        }

        return null;
    }

    controlsForErrorKey(key) {
        return {
            frequency: this.frequencyTargets,
            'daily-time': [this.dailyTimeTarget],
            'weekly-time': [this.weeklyTimeTarget],
            'weekly-days': this.weeklyDayTargets,
            'once-date': [this.onceDateTarget],
            'once-time': [this.onceTimeTarget],
            'once-future': [this.onceDateTarget, this.onceTimeTarget],
        }[key] ?? [];
    }

    showModalError(message) {
        this.validationSummaryTarget.textContent = message;
        this.validationSummaryTarget.hidden = false;
        this.validationSummaryTarget.focus();
    }

    messageForResponse(status, data) {
        const serverMessage = data?.error?.message ?? data?.detail ?? data?.message;

        if (serverMessage) {
            return serverMessage;
        }

        if (status === 403) {
            return 'Votre accès ou votre session a expiré. Rechargez la page puis réessayez.';
        }

        if (status === 409) {
            return 'Un rappel existe déjà. Rechargez la page puis réessayez.';
        }

        return 'Une erreur est survenue. Réessayez dans quelques instants.';
    }

    setSubmissionBusy(busy) {
        this.formTarget.toggleAttribute('aria-busy', busy);
        this.closeButtonTarget.disabled = busy;
        this.previousButtonTarget.disabled = busy;
        this.nextButtonTarget.disabled = busy;
        this.finishButtonTarget.disabled = busy;
        this.finishSpinnerTarget.hidden = !busy;
        this.finishLabelTarget.textContent = busy ? 'Enregistrement…' : 'Terminer';
    }

    setDisableBusy(busy) {
        this.cardTarget.toggleAttribute('aria-busy', busy);
        this.openButtonTarget.disabled = busy;
        this.disableButtonTarget.disabled = busy;
        this.rejectButtonTarget.disabled = busy;
        this.disableButtonTarget.textContent = busy ? 'Désactivation…' : 'Désactiver';
    }

    setCardStatus(message, error = false) {
        this.cardStatusTarget.textContent = message;
        this.cardStatusTarget.hidden = message === '';
        this.cardStatusTarget.classList.toggle('is-error', error);

        if (error) {
            this.cardStatusTarget.focus();
        }
    }

    setMinimumDate() {
        const today = new Date();
        const year = today.getFullYear();
        const month = String(today.getMonth() + 1).padStart(2, '0');
        const day = String(today.getDate()).padStart(2, '0');

        this.onceDateTarget.min = `${year}-${month}-${day}`;
    }

    selectedFrequency() {
        return this.frequencyTargets.find((radio) => radio.checked)?.value ?? null;
    }

    isStrictlyFuture(dateValue, timeValue) {
        const candidate = this.createLocalDateTime(dateValue, timeValue);

        return candidate !== null && candidate.getTime() > Date.now();
    }

    createLocalDateTime(dateValue, timeValue) {
        const dateParts = dateValue.split('-').map(Number);
        const timeParts = timeValue.split(':').map(Number);

        if (dateParts.length !== 3 || timeParts.length !== 2) {
            return null;
        }

        const [year, month, day] = dateParts;
        const [hours, minutes] = timeParts;

        if (![year, month, day, hours, minutes].every(Number.isInteger)) {
            return null;
        }

        const candidate = new Date(
            year,
            month - 1,
            day,
            hours,
            minutes,
            0,
            0,
        );

        if (
            candidate.getFullYear() !== year
            || candidate.getMonth() !== month - 1
            || candidate.getDate() !== day
            || candidate.getHours() !== hours
            || candidate.getMinutes() !== minutes
        ) {
            return null;
        }

        return candidate;
    }

    buildSummary() {
        const frequency = this.selectedFrequency();

        if (frequency === 'daily') {
            return `Tous les jours à ${this.formatTime(this.dailyTimeTarget.value)}`;
        }

        if (frequency === 'weekly') {
            const days = this.weeklyDayTargets
                .filter((day) => day.checked)
                .map((day) => day.dataset.dayLabel);

            return `Tous les ${this.joinFrench(days)} à ${this.formatTime(this.weeklyTimeTarget.value)}`;
        }

        const date = this.createLocalDateTime(
            this.onceDateTarget.value,
            this.onceTimeTarget.value,
        );

        const formattedDate = new Intl.DateTimeFormat('fr-FR', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        }).format(date);

        return `Le ${formattedDate} à ${this.formatTime(this.onceTimeTarget.value)}`;
    }

    formatTime(value) {
        const [hours, minutes] = value.split(':');

        if (minutes === '00') {
            return `${Number(hours)} h`;
        }

        return `${Number(hours)} h ${minutes}`;
    }

    joinFrench(items) {
        if (items.length <= 1) {
            return items[0] ?? '';
        }

        if (items.length === 2) {
            return `${items[0]} et ${items[1]}`;
        }

        return `${items.slice(0, -1).join(', ')} et ${items.at(-1)}`;
    }

    ensureModal() {
        if (!this.bootstrapModal) {
            this.bootstrapModal = Modal.getOrCreateInstance(this.modalTarget, {
                backdrop: true,
                focus: true,
                keyboard: true,
            });
        }

        return this.bootstrapModal;
    }
}
