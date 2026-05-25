import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        threshold: { type: Number, default: 280 },
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
        const isVisible = window.scrollY > this.thresholdValue;

        this.element.classList.toggle('i3u89z', isVisible);
        this.element.classList.toggle('xya3m1', !isVisible);
    }
}
