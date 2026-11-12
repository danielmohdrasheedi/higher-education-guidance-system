/*
 * Favourite hearts on the courses and admission trends pages (FR05).
 *
 * Pressing a heart sends its form in the background, so the page doesn't
 * reload or jump. The heart and the Favourites count in the menu are updated
 * in place. The message is read out by screen readers, and only shown on the
 * screen when the heart couldn't change, e.g. when the favourites are full.
 *
 * The submits are handled for the whole page, so the courses that
 * public/js/result-pages.js swaps in when changing pages work too.
 *
 * Without JavaScript the form is sent normally and the page reloads at the course.
 */
(function () {
    'use strict';

    var status = document.querySelector('[data-favourite-status]');
    var hideTimer = null;

    function showMessage(message, isVisible) {
        if (!status) {
            return;
        }

        status.textContent = message;
        status.classList.toggle('sr-only', !isVisible);
        clearTimeout(hideTimer);
        hideTimer = setTimeout(function () {
            status.textContent = '';
        }, 5000);
    }

    function updateCount(count) {
        var link = document.querySelector('[data-favourites-link]');

        if (!link) {
            return;
        }

        var badge = link.querySelector('.site-nav__count');

        if (count === 0) {
            if (badge) {
                badge.remove();
            }

            return;
        }

        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'site-nav__count';
            link.appendChild(badge);
        }

        badge.innerHTML = '<span class="sr-only">(</span>' + count + '<span class="sr-only">)</span>';
    }

    function showHeart(form, isFavourite) {
        var button = form.querySelector('.favourite-button');
        var name = form.getAttribute('data-course-name');

        form.action = form.getAttribute(isFavourite ? 'data-remove-url' : 'data-add-url');
        form.querySelector('input[name="_method"]').disabled = !isFavourite;
        button.classList.toggle('is-favourite', isFavourite);
        button.setAttribute('aria-label', (isFavourite ? 'Remove ' : 'Add ') + name + (isFavourite ? ' from' : ' to') + ' favourites');
        button.title = isFavourite ? 'Remove from favourites' : 'Add to favourites';
    }

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('[data-favourite-form]');

        if (!form) {
            return;
        }

        event.preventDefault();

        // Ignore presses while the last one is still being sent. The button isn't disabled, which would lose its focus
        if (form.hasAttribute('aria-busy')) {
            return;
        }

        var wasFavourite = form.querySelector('.favourite-button').classList.contains('is-favourite');

        form.setAttribute('aria-busy', 'true');

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error(response.status);
                }

                return response.json();
            })
            .then(function (data) {
                // A course can have more than one heart, e.g. its own and one in another course's similar courses
                document.querySelectorAll('[data-favourite-form]').forEach(function (other) {
                    if (other.getAttribute('data-add-url') === form.getAttribute('data-add-url')) {
                        showHeart(other, data.isFavourite);
                    }
                });
                updateCount(data.count);
                showMessage(data.message, data.isFavourite === wasFavourite);
            })
            .catch(function () {
                showMessage('That didn\'t work. Reload the page and try again.', true);
            })
            .then(function () {
                form.removeAttribute('aria-busy');
            });
    });
})();
