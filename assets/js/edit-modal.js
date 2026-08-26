(function() {
    /**
     * Attach an edit-button handler that prefills a Bootstrap modal using data-* attributes.
     *
     * @param {Object} options
     * @param {string} options.buttonSelector Selector for edit buttons.
     * @param {string} options.modalSelector Selector for the modal to open.
     * @param {Object} options.fieldMap Map of data-* keys to modal input/select/textarea selectors.
     * @param {Function} [options.onShow] Optional callback executed before showing the modal.
     */
    window.attachEditModal = function({ buttonSelector, modalSelector, fieldMap, onShow }) {
        const modalElement = document.querySelector(modalSelector);
        if (!modalElement) return;

        const modal = new bootstrap.Modal(modalElement);

        document.querySelectorAll(buttonSelector).forEach(button => {
            button.addEventListener('click', () => {
                const data = button.dataset;

                Object.entries(fieldMap || {}).forEach(([dataKey, selector]) => {
                    const field = modalElement.querySelector(selector);
                    if (!field) return;
                    const value = data[dataKey] ?? '';
                    if ('value' in field) {
                        field.value = value;
                    }
                });

                if (typeof onShow === 'function') {
                    onShow({ modalElement, modal, data, button });
                }

                modal.show();
            });
        });
    };
})();
