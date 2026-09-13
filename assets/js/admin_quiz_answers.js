class QuizAnswerOrder {
    constructor(element) {
        this.element = element;
        this.connect();
    }

    connect() {
        this.onCollectionChange = (event) => {
            if (event.detail?.collection === this.element) {
                this.refresh();
            }
        };
        this.onClick = (event) => this.move(event);
        this.onSubmit = () => this.refresh();
        this.form = this.element.closest('form');
        this.element.addEventListener('click', this.onClick);
        document.addEventListener('ea.collection.item-added', this.onCollectionChange);
        document.addEventListener('ea.collection.item-removed', this.onCollectionChange);
        this.form?.addEventListener('submit', this.onSubmit);
        this.refresh();
    }

    disconnect() {
        this.element.removeEventListener('click', this.onClick);
        document.removeEventListener('ea.collection.item-added', this.onCollectionChange);
        document.removeEventListener('ea.collection.item-removed', this.onCollectionChange);
        this.form?.removeEventListener('submit', this.onSubmit);
    }

    items() {
        return [...this.element.querySelectorAll('.field-collection-item')]
            .filter((item) => item.closest('[data-ea-collection-field]') === this.element);
    }

    refresh() {
        const items = this.items();
        items.forEach((item, index) => {
            let controls = item.querySelector('[data-quiz-answer-controls]');
            if (!controls) {
                controls = document.createElement('span');
                controls.dataset.quizAnswerControls = '';
                controls.className = 'd-inline-flex gap-1 ms-2';
                for (const [direction, label] of [['up', 'Monter'], ['down', 'Descendre']]) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'btn btn-sm btn-secondary';
                    button.dataset.quizAnswerMove = direction;
                    button.textContent = label;
                    controls.append(button);
                }
                item.querySelector('.accordion-header').append(controls);
            }
            controls.querySelector('[data-quiz-answer-move="up"]').disabled = index === 0;
            controls.querySelector('[data-quiz-answer-move="down"]').disabled = index === items.length - 1;
            item.querySelector('[data-quiz-answer-position]').value = String(index);
            item.classList.toggle('field-collection-item-first', index === 0);
            item.classList.toggle('field-collection-item-last', index === items.length - 1);
        });
    }

    move(event) {
        const button = event.target.closest('[data-quiz-answer-move]');
        if (!button || button.disabled || !this.element.contains(button)) {
            return;
        }
        event.preventDefault();
        const item = button.closest('.field-collection-item');
        const items = this.items();
        const index = items.indexOf(item);
        const up = button.dataset.quizAnswerMove === 'up';
        const neighbour = items[index + (up ? -1 : 1)];
        if (!neighbour) {
            return;
        }
        const focused = document.activeElement;
        if (up) {
            item.parentElement.insertBefore(item, neighbour);
        } else {
            item.parentElement.insertBefore(neighbour, item);
        }
        this.refresh();
        // A newly disabled boundary button cannot receive focus. Keep focus on the
        // same proposition, using its other move button in that case.
        const focusTarget = focused?.disabled
            ? item.querySelector('[data-quiz-answer-move]:not(:disabled)')
            : focused;
        focusTarget?.focus({ preventScroll: true });
    }
}

const collections = new Map();
const initialize = () => {
    for (const [element, controller] of collections) {
        if (!element.isConnected) {
            controller.disconnect();
            collections.delete(element);
        }
    }
    document.querySelectorAll('[data-quiz-answer-order]').forEach((element) => {
        if (!collections.has(element)) {
            collections.set(element, new QuizAnswerOrder(element));
        }
    });
};

document.addEventListener('DOMContentLoaded', initialize);
document.addEventListener('turbo:load', initialize);
document.addEventListener('turbo:before-cache', () => {
    for (const controller of collections.values()) {
        controller.disconnect();
    }
    collections.clear();
});
if (document.readyState !== 'loading') {
    initialize();
}
