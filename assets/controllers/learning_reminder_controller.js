import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

export default class extends Controller {
    static targets = [
        'section',
        'modal',
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
        'courseContentTitle',
    ];

    connect() {
        this.currentStep = 1;
        this.openingElement = null;
        this.isPreparingCache = false;
        this.bootstrapModal = Modal.getOrCreateInstance(this.modalTarget, {
            backdrop: true,
            focus: true,
            keyboard: true,
        });

        this.reset();
    }

    disconnect() {
        this.isPreparingCache = true;

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

    finish(event) {
        event.preventDefault();

        if (this.currentStep === 1) {
            this.next(event);
            return;
        }

        this.bootstrapModal?.hide();
    }

    focusInitial() {
        this.frequencyTargets[0]?.focus();
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
        this.validationSummaryTarget.hidden = true;

        this.errorTargets.forEach((error) => {
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

    markInvalid(errorKey, controls) {
        const error = this.errorTargets.find(
            (candidate) => candidate.dataset.learningReminderErrorFor === errorKey,
        );

        if (error) {
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
        this.updateConditionalFields();
        this.showStep(1);
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
