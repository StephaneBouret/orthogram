(() => {
    const updateSentence = (sentence) => {
        const toggle = sentence.querySelector('[data-no-answer-toggle]');
        if (!toggle) {
            return;
        }

        sentence.querySelectorAll('[data-no-answer-detail]').forEach((row) => {
            row.style.display = toggle.checked ? '' : 'none';
        });
    };

    const initialize = () => {
        document.querySelectorAll('[data-exercice-sentence]').forEach(updateSentence);
    };

    // Delegation also covers sentence forms inserted from the collection prototype.
    document.addEventListener('change', (event) => {
        if (event.target.matches('[data-no-answer-toggle]')) {
            const sentence = event.target.closest('[data-exercice-sentence]');
            if (sentence) {
                updateSentence(sentence);
            }
        }
    });
    document.addEventListener('DOMContentLoaded', initialize);
    document.addEventListener('turbo:load', initialize);
    document.addEventListener('ea.collection.item-added', initialize);
    initialize();
})();
