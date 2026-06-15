const initCourseFields = () => {
    const contentTypeSelect = document.querySelector('select[name$="[contentType]"]');

    if (!contentTypeSelect) {
        return;
    }

    const twigFields = document.querySelectorAll('.field-partialFile');
    const audioFields = document.querySelectorAll('.field-audioFile');
    const videoFields = document.querySelectorAll('.field-videoFile');
    const dictationFields = document.querySelectorAll('.field-correctionText');
    const exerciseFields = document.querySelectorAll('.field-exercice');

    const toggle = (fields, visible) => {
        fields.forEach((field) => {
            field.style.display = visible ? '' : 'none';
        });
    };

    const updateFields = () => {
        const type = contentTypeSelect.value;

        toggle(twigFields, type === 'twig' || type === 'link');
        toggle(audioFields, type === 'audio');
        toggle(videoFields, type === 'video');
        toggle(dictationFields, type === 'audio');
        toggle(exerciseFields, type === 'exercise');
    };

    if (contentTypeSelect.dataset.courseFieldsReady !== '1') {
        contentTypeSelect.dataset.courseFieldsReady = '1';
        contentTypeSelect.addEventListener('change', updateFields);
    }

    updateFields();
};

document.addEventListener('DOMContentLoaded', initCourseFields);
document.addEventListener('turbo:load', initCourseFields);
