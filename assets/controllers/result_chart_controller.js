import { Controller } from '@hotwired/stimulus';

// UX Chart.js owns creation and destruction; this controller supplies colors and tooltips.
export default class extends Controller {
    static values = { evolution: Boolean };

    connect() {
        this.onPrepare = (event) => {
            const { config } = event.detail;
            this.colorize(config.data, config.options);
            if (this.evolutionValue) {
                // Keep unique category identities; only their displayed ticks are dates.
                const dates = new Map(config.data.datasets.flatMap(dataset => dataset.data.map(point => [point.x, point.date.slice(0, 5)])));
                config.options.scales.x.ticks.callback = function (value) { return dates.get(this.getLabelForValue(value)); };
                config.options.scales.x.ticks.maxRotation = 0;
                config.options.layout = { padding: { top: 26, left: 8, right: 12 } };
                config.plugins ??= [];
                config.plugins.push({ id: 'result-percentages', afterDatasetsDraw: chart => this.drawPercentages(chart) });
                config.options.plugins.tooltip = {
                    callbacks: {
                        title: items => items.length ? `Tentative ${items[0].raw.number} · ${items[0].raw.date}` : '',
                        label: context => `${context.raw.score}/${context.raw.total} · ${context.parsed.y} %`,
                    },
                };
            }
        };
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

    colorize(data, options) {
        const styles = getComputedStyle(this.element);
        if (this.evolutionValue) {
            const line = styles.getPropertyValue('--results-line').trim();
            const text = styles.getPropertyValue('--orthogram-muted').trim();
            const grid = styles.getPropertyValue('--orthogram-border').trim();
            data.datasets.forEach(dataset => {
                dataset.borderColor = line;
                dataset.backgroundColor = line;
                dataset.pointBackgroundColor = line;
                dataset.pointBorderColor = styles.getPropertyValue('--orthogram-surface').trim();
                dataset.pointBorderWidth = 2;
            });
            Object.values(options.scales).forEach(scale => {
                scale.ticks ??= {};
                scale.ticks.color = text;
                scale.title.color = text;
                scale.grid = { color: grid };
                scale.border = { color: grid };
            });
            return;
        }
        const colors = [styles.getPropertyValue('--results-correct').trim(), styles.getPropertyValue('--results-incorrect').trim()];
        data.datasets.forEach(dataset => { dataset.backgroundColor = colors; });
    }

    refresh() {
        if (!this.chart?.ctx) return;
        this.colorize(this.chart.data, this.chart.options);
        this.chart.update('none');
    }

    drawPercentages(chart) {
        const { ctx, chartArea } = chart;
        const styles = getComputedStyle(this.element);
        const points = chart.data.datasets.flatMap((dataset, datasetIndex) =>
            chart.getDatasetMeta(datasetIndex).data.flatMap((point, index) =>
                point.skip || dataset.data[index].y === null ? [] : [{ point, value: dataset.data[index] }]));
        if (!points.length) return;
        // Reserve the selected point and endpoints first; omit colliding labels only,
        // never points. Re-evaluated on every draw, including resize and theme changes.
        const ordered = [...points].sort((a, b) => b.point.options.radius - a.point.options.radius);
        const priority = [...new Set([ordered[0], points[0], points.at(-1), ...points])];
        const boxes = [];
        ctx.save();
        ctx.font = `600 13px ${styles.fontFamily}`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = styles.getPropertyValue('--orthogram-text').trim();
        ctx.strokeStyle = styles.getPropertyValue('--orthogram-surface').trim();
        ctx.lineWidth = 4;
        ctx.lineJoin = 'round';
        for (const { point, value } of priority) {
            const text = `${value.y} %`;
            const halfWidth = ctx.measureText(text).width / 2;
            const x = Math.max(chartArea.left + halfWidth, Math.min(chartArea.right - halfWidth, point.x));
            const y = Math.max(10, point.y - point.options.radius - 14);
            const box = { left: x - halfWidth - 5, right: x + halfWidth + 5, top: y - 10, bottom: y + 10 };
            if (boxes.some(other => box.left < other.right && box.right > other.left && box.top < other.bottom && box.bottom > other.top)) continue;
            boxes.push(box);
            ctx.strokeText(text, x, y);
            ctx.fillText(text, x, y);
        }
        ctx.restore();
    }
}
