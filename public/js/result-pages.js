/*
 * Page links in the result groups on the courses page.
 *
 * Choosing a page loads it in the background and swaps in only that group's
 * courses and page links, so the page doesn't reload or jump, and the page
 * links stay in the same spot on the screen. The address bar is
 * updated too, so reloading the page keeps the same pages.
 *
 * Without JavaScript the links load the whole page at the group.
 */
(function () {
    'use strict';

    var loading = null;

    function swapGroup(group, url, clickedText) {
        if (loading) {
            loading.abort();
        }

        loading = new AbortController();
        group.setAttribute('aria-busy', 'true');

        fetch(url, { credentials: 'same-origin', signal: loading.signal })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error(response.status);
                }

                return response.text();
            })
            .then(function (html) {
                var page = new DOMParser().parseFromString(html, 'text/html');
                var newGroup = page.getElementById(group.id);

                if (!newGroup) {
                    throw new Error('Group not found');
                }

                // The new courses can be longer or shorter, so the page scrolls by the difference to keep the
                // page links in the same spot on the screen, ready for the next press. The browser may already
                // have scrolled a little while swapping, so the scroll is measured after the swap
                var linksTop = group.querySelector(':scope > .pagination').getBoundingClientRect().top;

                group.querySelector('.course-list').replaceWith(newGroup.querySelector('.course-list'));
                group.querySelector(':scope > .pagination').replaceWith(newGroup.querySelector(':scope > .pagination'));
                window.scrollTo({
                    top: window.scrollY + group.querySelector(':scope > .pagination').getBoundingClientRect().top - linksTop,
                    behavior: 'instant',
                });
                history.replaceState(null, '', url);
                keepFocus(group, clickedText);
            })
            .catch(function (error) {
                // A newer page was chosen before this one arrived
                if (error.name === 'AbortError') {
                    return;
                }

                window.location.href = url;
            })
            .then(function () {
                group.removeAttribute('aria-busy');
            });
    }

    /**
     * The clicked link was replaced, so focus moves to the same link in the new
     * page links, e.g. "Next", or to the page links when it's now the current page.
     */
    function keepFocus(group, clickedText) {
        var nav = group.querySelector(':scope > .pagination');
        var links = nav.querySelectorAll('a.pagination__link');

        for (var index = 0; index < links.length; index++) {
            if (links[index].textContent.trim() === clickedText) {
                links[index].focus({ preventScroll: true });

                return;
            }
        }

        nav.setAttribute('tabindex', '-1');
        nav.focus({ preventScroll: true });
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('.result-group .pagination a');

        // Page links of similar courses in a pop-up are handled by public/js/requirements-dialog.js
        if (link && link.closest('[data-similar-list]')) {
            return;
        }

        // Let ctrl-click and the like open the page in a new tab as usual
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) {
            return;
        }

        event.preventDefault();

        // The link was made before other groups changed page, so its page number is added to the current address instead
        var group = link.closest('.result-group');
        var pageName = group.id + '_page';
        var url = new URL(window.location.href);

        url.searchParams.set(pageName, new URL(link.href).searchParams.get(pageName) || '1');
        url.hash = '';
        swapGroup(group, url.toString(), link.textContent.trim());
    });
})();
