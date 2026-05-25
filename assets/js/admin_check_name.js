const initCharCounters = () => {
    document.querySelectorAll('.char-count input').forEach((input) => {
        if (input.dataset.charCounterReady === '1') {
            return;
        }

        input.dataset.charCounterReady = '1';

        const maxLength = Number(input.getAttribute('maxlength')) || 30;
        input.setAttribute('maxlength', String(maxLength));

        const counter = document.createElement('div');
        counter.className = 'ea-char-counter';
        counter.style.fontSize = '0.8rem';
        counter.style.marginTop = '0.25rem';
        counter.style.color = '#666';

        input.parentNode.appendChild(counter);

        const updateCounter = () => {
            const length = input.value.length;
            counter.textContent = `${length} / ${maxLength} caractères`;
            counter.style.color = length >= maxLength ? '#e74c3c' : '#666';
        };

        input.addEventListener('input', updateCounter);
        updateCounter();
    });
};

document.addEventListener('DOMContentLoaded', initCharCounters);
document.addEventListener('turbo:load', initCharCounters);
