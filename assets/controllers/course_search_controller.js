import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['select'];
    static values = {
        detailsUrl: String,
    };

    async navigate() {
        const courseId = this.selectTarget.value;

        if (!courseId) {
            return;
        }

        const response = await fetch(this.detailsUrlValue.replace('__id__', encodeURIComponent(courseId)), {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            return;
        }

        const data = await response.json();

        if (data.url) {
            window.location.href = data.url;
        }
    }
}
