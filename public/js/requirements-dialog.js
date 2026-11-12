/*
 * Requirements pop-up on each course card.
 *
 * The "Requirements" button opens its dialog through the browser's own
 * command="show-modal" attribute. This script adds the same for browsers
 * without it, and closes a dialog when the dimmed area around it is clicked.
 *
 * A similar course in a pop-up has its own "View full requirements" button.
 * Its pop-up is loaded from the server the first time it's pressed, and
 * opens on top. Closing it goes back to the pop-up underneath.
 *
 * The page links under a list of similar courses load the other pages
 * in the background and swap them in, so the pop-up stays open.
 *
 * The clicks are handled for the whole page, so the courses that
 * public/js/result-pages.js swaps in when changing pages work too.
 */
(function () {
    'use strict';

    var hasCommands = 'commandForElement' in HTMLButtonElement.prototype;

    function openSimilarCourse(button) {
        var dialog = document.getElementById(button.getAttribute('data-dialog-id'));

        if (dialog) {
            dialog.showModal();

            return;
        }

        // Ignore presses while the pop-up is still loading
        if (button.hasAttribute('aria-busy')) {
            return;
        }

        button.setAttribute('aria-busy', 'true');

        fetch(button.getAttribute('data-requirements-url'), {
            headers: { Accept: 'text/html' },
            credentials: 'same-origin',
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error(response.status);
                }

                return response.text();
            })
            .then(function (html) {
                document.body.insertAdjacentHTML('beforeend', html);
                document.getElementById(button.getAttribute('data-dialog-id')).showModal();
            })
            .catch(function () {
                button.textContent = 'That didn\'t work. Press to try again.';
            })
            .then(function () {
                button.removeAttribute('aria-busy');
            });
    }

    function showSimilarPage(link) {
        var list = link.closest('[data-similar-list]');

        if (list.hasAttribute('aria-busy')) {
            return;
        }

        var clickedText = link.textContent.trim();

        list.setAttribute('aria-busy', 'true');

        fetch(link.href, {
            headers: { Accept: 'text/html' },
            credentials: 'same-origin',
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error(response.status);
                }

                return response.text();
            })
            .then(function (html) {
                var template = document.createElement('template');

                template.innerHTML = html.trim();

                var newList = template.content.querySelector('[data-similar-list]');

                list.replaceWith(newList);
                keepFocus(newList, clickedText);
            })
            .catch(function () {
                list.removeAttribute('aria-busy');
                link.textContent = 'Try again';
            });
    }

    /**
     * The pressed link was replaced, so focus moves to the same link in the new page links, e.g. "Next", or to the
     * page links when it's now the current page. The list's first course is kept in view.
     */
    function keepFocus(list, clickedText) {
        var nav = list.querySelector('.pagination');
        var links = nav.querySelectorAll('a.pagination__link');

        for (var index = 0; index < links.length; index++) {
            if (links[index].textContent.trim() === clickedText) {
                links[index].focus({ preventScroll: true });
                list.scrollIntoView({ block: 'nearest' });

                return;
            }
        }

        nav.setAttribute('tabindex', '-1');
        nav.focus({ preventScroll: true });
        list.scrollIntoView({ block: 'nearest' });
    }

    document.addEventListener('click', function (event) {
        var pageLink = event.target.closest('[data-similar-list] .pagination a');

        // Let ctrl-click and the like open the page in a new tab as usual
        if (pageLink && !(event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0)) {
            event.preventDefault();
            showSimilarPage(pageLink);

            return;
        }

        // A click on the dialog element itself, not its contents, is a click on the backdrop
        if (event.target instanceof HTMLDialogElement) {
            event.target.close();

            return;
        }

        var similarCourseButton = event.target.closest('button[data-requirements-url]');

        if (similarCourseButton) {
            openSimilarCourse(similarCourseButton);

            return;
        }

        if (hasCommands) {
            return;
        }

        var button = event.target.closest('button[command="show-modal"]');
        var dialog = button && document.getElementById(button.getAttribute('commandfor'));

        if (dialog) {
            dialog.showModal();
        }
    });
})();
