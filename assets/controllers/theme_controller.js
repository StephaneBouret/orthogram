import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['icon', 'button'];

    connect() {
        // 1) Si un thème est déjà choisi, on l'applique
        // 2) Sinon, on suit la préférence système
        const stored = localStorage.getItem('theme');
        const initial = stored ?? (this.prefersDark() ? 'dark' : 'light');

        this.apply(initial);
        this.updateIcon(initial);

        // Si aucun thème stocké et que l'OS change, on suit.
        this.onMediaChange = (e) => {
            if (localStorage.getItem('theme')) return; // l'utilisateur a décidé
            const t = e.matches ? 'dark' : 'light';
            this.apply(t);
            this.updateIcon(t);
        };

        this.media = window.matchMedia('(prefers-color-scheme: dark)');
        this.media.addEventListener('change', this.onMediaChange);
    }

    disconnect() {
        this.media?.removeEventListener?.('change', this.onMediaChange);
    }

    toggle() {
        const current = this.getCurrentTheme();
        const next = current === 'dark' ? 'light' : 'dark';

        localStorage.setItem('theme', next);
        this.apply(next);
        this.updateIcon(next);
    }

    apply(theme) {
        // Bootstrap 5.3 : data-bs-theme sur <html>
        document.documentElement.setAttribute('data-bs-theme', theme);
        this.buttonTarget?.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
    }

    updateIcon(theme) {
        // dark => soleil (pour revenir en light), light => lune
        const dark = theme === 'dark';

        this.iconTarget.classList.toggle('bi-moon-stars', !dark);
        this.iconTarget.classList.toggle('bi-sun', dark);

        const label = dark ? 'Passer en mode clair' : 'Passer en mode sombre';
        this.buttonTarget?.setAttribute('title', label);
        this.buttonTarget?.setAttribute('aria-label', label);
    }

    getCurrentTheme() {
        return document.documentElement.getAttribute('data-bs-theme') || 'light';
    }

    prefersDark() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    }
}
