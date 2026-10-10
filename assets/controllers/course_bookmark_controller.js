import { Controller } from '@hotwired/stimulus';
import { recordBookmark } from '../js/course_bookmark.js';

export default class extends Controller {
    static values = { url: String, token: String, version: Number, account: String };

    connect() {
        this.record = this.record.bind(this);
        document.addEventListener('visibilitychange', this.record);
        document.addEventListener('turbo:load', this.record);
        this.record();
    }

    disconnect() {
        document.removeEventListener('visibilitychange', this.record);
        document.removeEventListener('turbo:load', this.record);
    }

    record() {
        // Prefetches never connect Stimulus. Previews and background/prerendered
        // documents must not count as consultations either.
        if (this.sent || !this.element.isConnected || document.visibilityState !== 'visible'
            || document.prerendering || document.documentElement.hasAttribute('data-turbo-preview')) return;
        this.sent = true;
        recordBookmark({ url: this.urlValue, token: this.tokenValue, version: this.versionValue, account: this.accountValue });
    }
}
