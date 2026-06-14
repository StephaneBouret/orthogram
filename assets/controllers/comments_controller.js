import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['collapse', 'content', 'parent', 'submit', 'title', 'rootButton'];

    connect() {
        this.toggleSubmit();
    }

    showRootForm(event) {
        event.preventDefault();
        this.parentTarget.value = '';
        this.titleTarget.textContent = 'Ajouter un commentaire';
        this.showForm();
    }

    reply(event) {
        event.preventDefault();
        this.parentTarget.value = event.currentTarget.dataset.commentId || '';
        this.titleTarget.textContent = 'Répondre au commentaire';
        this.showForm();
    }

    abandon(event) {
        event.preventDefault();
        this.collapseTarget.classList.remove('show');
        this.contentTarget.value = '';
        this.parentTarget.value = '';
        this.titleTarget.textContent = 'Ajouter un commentaire';
        this.toggleSubmit();
    }

    toggleSubmit() {
        this.submitTarget.disabled = this.contentTarget.value.trim() === '';
    }

    async toggleLike(event) {
        event.preventDefault();

        const button = event.currentTarget;
        const body = new URLSearchParams();
        body.append('_token', button.dataset.likeToken || '');
        button.disabled = true;

        try {
            const response = await fetch(button.dataset.likeUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body,
            });
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Impossible de mettre à jour le like.');
            }

            this.updateLikeButton(button, data.liked, data.likeCount);
        } catch (error) {
            console.error(error);
        } finally {
            button.disabled = false;
        }
    }

    showForm() {
        this.collapseTarget.classList.add('show');

        window.setTimeout(() => {
            this.contentTarget.scrollIntoView({ behavior: 'smooth', block: 'center' });
            this.contentTarget.focus();
        }, 150);
    }

    updateLikeButton(button, liked, likeCount) {
        button.classList.toggle('is-liked', liked);
        button.setAttribute('aria-pressed', liked ? 'true' : 'false');

        const icon = button.querySelector('i');
        if (icon) {
            icon.classList.toggle('bi-heart', !liked);
            icon.classList.toggle('bi-heart-fill', liked);
        }

        document.querySelectorAll(`.like-count[data-comment-id="${button.dataset.commentId}"]`).forEach((element) => {
            element.textContent = likeCount;
        });
    }
}
