/*
 * Course filters (FR04).
 *
 * The institution and location lists narrow each other, so only choices that
 * have courses are offered:
 * - With no institution chosen, the location list shows the states.
 * - With an institution chosen, it shows each of the institution's states
 *   followed by its campuses there. The state and each campus are separate
 *   choices, e.g. "Selangor", "Kampus Bangi".
 * - With a location chosen, the institution list shows only the institutions
 *   with courses there.
 * A choice that no longer fits is reset to "All".
 *
 * Without JavaScript the server builds the same lists after the filters are applied.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-campuses-by-institution]');

    if (!form) {
        return;
    }

    // { institution: { state: [campus, ...] } }
    var campusesByInstitution = JSON.parse(form.getAttribute('data-campuses-by-institution'));
    var separator = form.getAttribute('data-campus-separator');
    var institutionSelect = form.querySelector('#institution');
    var locationSelect = form.querySelector('#location');
    var allInstitutions = Object.keys(campusesByInstitution).sort();
    var campusIndent = '    ';

    function allStates() {
        var states = [];

        allInstitutions.forEach(function (institution) {
            Object.keys(campusesByInstitution[institution]).forEach(function (state) {
                if (states.indexOf(state) === -1) {
                    states.push(state);
                }
            });
        });

        return states.sort();
    }

    /*
     * The location options as [value, label] pairs.
     */
    function locationOptions(institution) {
        if (!institution) {
            return allStates().map(function (state) {
                return [state, state];
            });
        }

        var states = campusesByInstitution[institution] || {};
        var options = [];

        Object.keys(states).sort().forEach(function (state) {
            options.push([state, state]);
            states[state].forEach(function (campus) {
                options.push([state + separator + campus, campusIndent + campus]);
            });
        });

        return options;
    }

    function hasCoursesAt(institution, location) {
        var parts = location.split(separator);
        var campuses = campusesByInstitution[institution][parts[0]];

        if (!campuses) {
            return false;
        }

        return parts.length === 1 || campuses.indexOf(parts[1]) !== -1;
    }

    /*
     * Replace the options after the first ("All ...") option, keeping the
     * current choice when it is still in the list.
     * Each option is a [value, label] pair.
     */
    function setOptions(select, options) {
        var chosen = select.value;
        var values = [];

        while (select.options.length > 1) {
            select.remove(1);
        }

        options.forEach(function (option) {
            select.add(new Option(option[1], option[0]));
            values.push(option[0]);
        });

        if (values.indexOf(chosen) === -1) {
            // A campus that is no longer offered falls back to its state when the state still is
            var state = chosen.split(separator)[0];
            chosen = chosen.indexOf(separator) !== -1 && values.indexOf(state) !== -1 ? state : '';
        }

        select.value = chosen;
    }

    function updateOptions() {
        setOptions(locationSelect, locationOptions(institutionSelect.value));

        var location = locationSelect.value;

        setOptions(institutionSelect, allInstitutions
            .filter(function (institution) {
                return !location || hasCoursesAt(institution, location);
            })
            .map(function (institution) {
                return [institution, institution];
            }));
    }

    institutionSelect.addEventListener('change', updateOptions);
    locationSelect.addEventListener('change', updateOptions);
    updateOptions();
})();
