import { Controller } from '@hotwired/stimulus';

// UX Chart.js owns creation and destruction; this controller only supplies colors.
export default class extends Controller {
    connect() {
        this.onPrepare = (event) => this.colorize(event.detail.config.data);
        this.onReady = (event) => {
            this.chart = event.detail.chart;
            this.refresh();
        };
        this.onForget = () => { this.chart = null; };
        this.element.addEventListener('chartjs:pre-connect', this.onPrepare);
        this.element.addEventListener('chartjs:connect', this.onReady);
        this.element.addEventListener('chartjs:disconnect', this.onForget);
        this.themeObserver = new MutationObserver(() => this.refresh());
        this.themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
    }

    disconnect() {
        this.themeObserver?.disconnect();
        this.element.removeEventListener('chartjs:pre-connect', this.onPrepare);
        this.element.removeEventListener('chartjs:connect', this.onReady);
        this.element.removeEventListener('chartjs:disconnect', this.onForget);
        this.chart = null;
    }

    colorize(data) {
        const styles = getComputedStyle(this.element);
        const colors = [styles.getPropertyValue('--results-correct').trim(), styles.getPropertyValue('--results-incorrect').trim()];
        data.datasets.forEach(dataset => { dataset.backgroundColor = colors; });
    }

    refresh() {
        if (!this.chart?.ctx) return;
        this.colorize(this.chart.data);
        this.chart.update('none');
    }
}
