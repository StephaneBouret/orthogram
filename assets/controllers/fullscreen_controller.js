import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['sidebar', 'button', 'expandIcon', 'restoreIcon', 'label'];

    connect() {
        this.onKeydown = this.handleKeydown.bind(this);
        document.addEventListener('keydown', this.onKeydown);
        this.desktop = window.matchMedia('(min-width: 768px)');
        this.onViewportChange = () => {
            if (!this.desktop.matches) {
                const menuHadFocus = document.activeElement === this.buttonTarget
                    || (this.hasSidebarTarget && this.sidebarTarget.contains(document.activeElement));
                this.apply(false);
                if (menuHadFocus) {
                    this.element.querySelector('.course-mobile-menu-toggle')?.focus({ preventScroll: true });
                }
            }
        };
        this.desktop.addEventListener('change', this.onViewportChange);
        // Chrome can blur a sidebar descendant before dispatching the media change.
        this.onFocusout = (event) => {
            if (!this.desktop.matches && !event.relatedTarget
                && (event.target === this.buttonTarget || (this.hasSidebarTarget && this.sidebarTarget.contains(event.target)))) {
                this.element.querySelector('.course-mobile-menu-toggle')?.focus({ preventScroll: true });
            }
        };
        this.element.addEventListener('focusout', this.onFocusout);
        this.apply(false);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
        this.desktop.removeEventListener('change', this.onViewportChange);
        this.element.removeEventListener('focusout', this.onFocusout);
        this.apply(false);
    }

    toggle(event) {
        event?.preventDefault();
        this.apply(!this.isActive());
        this.buttonTarget.focus({ preventScroll: true });
    }

    handleKeydown(event) {
        if (event.key === 'Escape' && this.isActive()) {
            this.apply(false);
            this.buttonTarget.focus({ preventScroll: true });
        }
    }

    apply(active) {
        active = active && this.desktop.matches;
        // Move focus before hiding a sidebar that may contain the focused link.
        if (active && this.hasSidebarTarget && this.sidebarTarget.contains(document.activeElement)) {
            this.buttonTarget.focus({ preventScroll: true });
        }
        this.element.classList.toggle(this.activeClass, active);

        if (this.hasSidebarTarget) {
            this.sidebarTarget.classList.toggle('d-none', active);
        }

        this.expandIconTarget.classList.toggle('d-none', active);
        this.restoreIconTarget.classList.toggle('d-none', !active);
        this.labelTarget.textContent = active ? 'Afficher le menu' : 'Agrandir l’affichage';
    }

    isActive() {
        return this.element.classList.contains(this.activeClass);
    }

    get activeClass() {
        return 'is-fullscreen';
    }
}
