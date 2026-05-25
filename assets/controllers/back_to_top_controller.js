import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        threshold: { type: Number, default: 100 },
    };

    connect() {
        this.onScroll = this.toggle.bind(this);

        window.addEventListener('scroll', this.onScroll, { passive: true });
        this.toggle();
    }

    disconnect() {
        window.removeEventListener('scroll', this.onScroll);
    }

    toggle() {
        this.element.classList.toggle('active', window.scrollY > this.thresholdValue);
    }

    scroll() {
        const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        window.scrollTo({
            top: 0,
            behavior: prefersReducedMotion ? 'auto' : 'smooth',
        });
    }
}
