/*
 * Academic profile form (FR01).
 *
 * - Shows only the sections that apply to the selected applicant category.
 *   Hidden sections are disabled so their fields are not submitted.
 * - Adds and removes subject rows.
 *
 * Without JavaScript every section stays visible and the server ignores
 * fields that do not apply to the selected category.
 */
(function () {
    'use strict';

    var form = document.querySelector('.profile-form');

    if (!form) {
        return;
    }

    var categorySelect = form.querySelector('[data-category-select]');
    var sections = form.querySelectorAll('[data-section]');

    function activeSections() {
        var option = categorySelect.options[categorySelect.selectedIndex];
        var value = option ? option.getAttribute('data-sections') : '';

        return value ? value.split(' ') : [];
    }

    function updateSections() {
        var active = activeSections();

        sections.forEach(function (section) {
            var names = section.getAttribute('data-section').split(' ');
            var isVisible = names.some(function (name) {
                return active.indexOf(name) !== -1;
            });

            section.hidden = !isVisible;

            if (section.tagName === 'FIELDSET') {
                section.disabled = !isVisible;
            } else {
                section.querySelectorAll('input, select, textarea, button').forEach(function (control) {
                    control.disabled = !isVisible;
                });
            }
        });

        numberSteps();
        form.querySelectorAll('[data-subject-list]').forEach(updateAddButton);
    }

    // Numbers the visible steps in order, so no number is skipped when a step is hidden
    function numberSteps() {
        var number = 0;

        form.querySelectorAll('.panel__step').forEach(function (step) {
            if (!step.closest('[hidden]')) {
                number += 1;
                step.textContent = number;
            }
        });
    }

    function updateAddButton(list) {
        var maxRows = parseInt(list.getAttribute('data-max-rows'), 10);
        var rowCount = list.querySelectorAll('[data-rows] [data-subject-row]').length;
        var addButton = list.querySelector('[data-add-row]');

        if (addButton && !addButton.closest('[hidden]')) {
            addButton.disabled = rowCount >= maxRows;
        }
    }

    var nextIndex = Date.now();

    form.querySelectorAll('[data-subject-list]').forEach(function (list) {
        var rows = list.querySelector('[data-rows]');
        var template = list.querySelector('[data-row-template]');
        var addButton = list.querySelector('[data-add-row]');

        addButton.addEventListener('click', function () {
            var html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex++));
            rows.insertAdjacentHTML('beforeend', html);

            var newRow = rows.lastElementChild;
            newRow.querySelector('select').focus();
            updateAddButton(list);
        });

        list.addEventListener('click', function (event) {
            var removeButton = event.target.closest('[data-remove-row]');

            if (!removeButton) {
                return;
            }

            var row = removeButton.closest('[data-subject-row]');
            var nextFocus = row.nextElementSibling || row.previousElementSibling;
            row.remove();

            (nextFocus ? nextFocus.querySelector('select') : addButton).focus();
            updateAddButton(list);
        });

        updateAddButton(list);
    });

    categorySelect.addEventListener('change', updateSections);
    updateSections();

    var errorSummary = form.querySelector('[data-error-summary]');

    if (errorSummary) {
        errorSummary.focus();
    }
})();
