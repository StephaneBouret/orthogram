import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

export default class extends Controller {
    static values = {
        upsertUrl: String,
        upsertToken: String,
        disableUrl: String,
        disableToken: String,
        googleUrl: String,
        icsUrl: String,
        calendarToken: String,
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
        'calendarButton',
        'calendarDetails',
        'separateFirstNotice',
        'recurringDetails',
        'calendarStatus',
        'calendarLink',
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
        this.invalidateCalendar();
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
        this.recurringDetailsTarget.hidden = this.selectedFrequency() === 'once';
        this.showStep(2, true);
    }

    previous(event) {
        event.preventDefault();
        this.invalidateCalendar();
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
        this.invalidateCalendar();
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
        } else {
            this.invalidateCalendar();
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
        this.invalidateCalendar();
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

            // The server alone checks future instants in the selected timezone,
            // including nonexistent and ambiguous local times.
        }

        if (!firstInvalidControl) {
            return true;
        }

        this.validationSummaryTarget.hidden = false;
        this.showStep(1);
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
        this.invalidateCalendar();
        this.calendarDetailsTarget.open = false;
        this.recurringDetailsTarget.hidden = true;
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
        const timezone = this.usedTimezone();
        this.timezoneLabelTarget.textContent = timezone === 'Europe/Paris'
            ? 'Horaire indiqué à l’heure de Paris.'
            : `Fuseau horaire : ${timezone}.`;
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
        let hasPastTime = false;

        violations.forEach((violation) => {
            const key = this.errorKeyForProperty(
                violation.propertyPath ?? '',
                violation.title ?? '',
            );
            const controls = this.controlsForErrorKey(key);
            const message = key === 'once-future'
                ? 'Cet horaire est passé. Choisissez une nouvelle heure.'
                : violation.title;
            hasPastTime ||= key === 'once-future';

            if (key && controls.length > 0) {
                const invalidControl = this.markInvalid(
                    key,
                    controls,
                    message,
                );
                firstInvalidControl ??= invalidControl;
            }
        });

        this.validationSummaryTarget.textContent =
            hasPastTime
                ? 'Cet horaire est passé. Choisissez une nouvelle heure.'
                : data.detail ?? 'Veuillez corriger les champs signalés.';
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
        if (status === 401 || status === 403) {
            return 'Votre accès ou votre session a expiré. Rechargez la page puis réessayez.';
        }
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
        this.calendarButtonTargets.forEach((button) => { button.disabled = busy; });
        this.formTarget.toggleAttribute('aria-busy', busy);
        this.closeButtonTarget.disabled = busy;
        this.previousButtonTarget.disabled = busy;
        this.nextButtonTarget.disabled = busy;
        this.finishButtonTarget.disabled = busy;
        this.finishSpinnerTarget.hidden = !busy;
        this.finishLabelTarget.textContent = busy ? 'Enregistrement…' : 'Enregistrer mon rappel';
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
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: this.usedTimezone(), year: 'numeric', month: '2-digit', day: '2-digit',
        }).formatToParts(new Date());
        const value = (type) => parts.find((part) => part.type === type).value;

        this.onceDateTarget.min = `${value('year')}-${value('month')}-${value('day')}`;
    }

    selectedFrequency() {
        return this.frequencyTargets.find((radio) => radio.checked)?.value ?? null;
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

        const date = new Date(`${this.onceDateTarget.value}T12:00:00Z`);

        const formattedDate = new Intl.DateTimeFormat('fr-FR', {
            timeZone: 'UTC',
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

    invalidateCalendar({ restoreFocus = false } = {}) {
        const linkHadFocus = this.element.ownerDocument.activeElement === this.calendarLinkTarget;
        this.calendarRequest?.abort();
        this.calendarRequest = null;
        clearTimeout(this.calendarTimer);
        if (this.calendarBlobUrl) {
            URL.revokeObjectURL(this.calendarBlobUrl);
            this.calendarBlobUrl = null;
        }
        this.calendarExpiresAt = null;
        this.calendarPayload = null;
        this.calendarLinkTarget.hidden = true;
        this.calendarLinkTarget.removeAttribute('href');
        this.calendarLinkTarget.removeAttribute('download');
        this.calendarLinkTarget.removeAttribute('target');
        this.calendarStatusTarget.textContent = '';
        this.separateFirstNoticeTarget.textContent = '';
        this.separateFirstNoticeTarget.hidden = true;
        this.calendarButtonTargets.forEach((button) => { button.disabled = this.isSubmitting; });
        if (restoreFocus && linkHadFocus) {
            this.calendarButtonTargets.find((button) =>
                !button.matches(':disabled') && button.getClientRects().length > 0
            )?.focus();
        }
    }

    async prepareCalendar(event) {
        event.preventDefault();
        if (this.isSubmitting || this.isDisabling || this.calendarRequest) {
            return;
        }
        this.invalidateCalendar();
        this.clearValidation();
        if (!this.validateFirstStep()) {
            this.showStep(1);
            return;
        }
        const format = event.currentTarget.dataset.calendarFormat;
        const payload = JSON.stringify(this.buildPayload());
        const request = new AbortController();
        this.calendarRequest = request;
        this.calendarButtonTargets.forEach((button) => { button.disabled = true; });
        this.calendarStatusTarget.textContent = 'Préparation en cours…';
        try {
            const response = await fetch(format === 'google' ? this.googleUrlValue : this.icsUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                redirect: 'error',
                signal: request.signal,
                headers: {
                    Accept: 'application/json, text/calendar',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.calendarTokenValue,
                },
                body: payload,
            });
            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                if (this.calendarRequest === request) {
                    this.calendarStatusTarget.textContent = '';
                    this.handleSaveError(response.status, data);
                }
                return;
            }
            const contentType = response.headers.get('Content-Type') ?? '';
            let href;
            let expires;
            let download = false;
            let separateFirstNotice = '';
            if (contentType.startsWith('application/json') && format === 'google' && this.selectedFrequency() === 'once') {
                const data = await response.json();
                const url = new URL(data.url);
                if (url.origin !== 'https://calendar.google.com' || url.pathname !== '/calendar/render') {
                    throw new Error('Unexpected calendar URL');
                }
                href = url.href;
                expires = Date.parse(data.expiresAt);
            } else if (contentType.startsWith('text/calendar')) {
                const blob = await response.blob();
                if (this.calendarRequest !== request) {
                    return;
                }
                href = URL.createObjectURL(blob);
                this.calendarBlobUrl = href;
                download = true;
                expires = Date.parse(response.headers.get('X-Orthogram-Expires-At'));
                separateFirstNotice = decodeURIComponent(response.headers.get('X-Orthogram-Separate-First-Notice') ?? '');
            } else {
                throw new Error('Unexpected calendar response');
            }
            if (this.calendarRequest !== request) {
                return;
            }
            if (!Number.isFinite(expires) || expires <= Date.now()) {
                throw new Error('Expired calendar preparation');
            }
            this.calendarExpiresAt = expires;
            this.calendarPayload = payload;
            this.calendarLinkTarget.href = href;
            this.calendarLinkTarget.textContent = download
                ? 'Télécharger le fichier'
                : 'Continuer dans Google Agenda';
            if (download) {
                this.calendarLinkTarget.download = 'orthogram.ics';
            } else {
                this.calendarLinkTarget.target = '_blank';
            }
            this.calendarLinkTarget.hidden = false;
            this.separateFirstNoticeTarget.textContent = separateFirstNotice;
            this.separateFirstNoticeTarget.hidden = separateFirstNotice === '';
            this.calendarStatusTarget.textContent = 'Préparation prête. Confirmez ensuite l’ajout dans votre agenda.';
            this.calendarLinkTarget.focus();
            this.calendarTimer = setTimeout(() => this.checkCalendarExpiry(), expires - Date.now());
        } catch (error) {
            if (error.name !== 'AbortError' && this.calendarRequest === request) {
                this.invalidateCalendar();
                this.showModalError('La préparation a échoué ou expiré. Rechargez la page puis réessayez.');
            }
        } finally {
            if (this.calendarRequest === request) {
                this.calendarRequest = null;
                this.calendarButtonTargets.forEach((button) => { button.disabled = this.isSubmitting; });
            }
        }
    }

    checkCalendarExpiry() {
        if (this.calendarExpiresAt && Date.now() >= this.calendarExpiresAt) {
            this.invalidateCalendar({ restoreFocus: true });
            this.calendarStatusTarget.textContent = 'La préparation a expiré. Préparez à nouveau votre export. Les fichiers téléchargés et les liens Google copiés restent utilisables.';
        }
    }

    openPreparedCalendar(event) {
        if (this.calendarExpiresAt && Date.now() >= this.calendarExpiresAt) {
            event.preventDefault();
            this.checkCalendarExpiry();
        } else if (!this.calendarExpiresAt || JSON.stringify(this.buildPayload()) !== this.calendarPayload) {
            event.preventDefault();
            this.invalidateCalendar({ restoreFocus: true });
            this.calendarStatusTarget.textContent = 'Préparez à nouveau l’export avec les valeurs actuelles.';
        }
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
