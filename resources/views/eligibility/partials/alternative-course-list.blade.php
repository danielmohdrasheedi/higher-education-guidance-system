{{--
    One page of a list of similar courses in a requirements pop-up. public/js/requirements-dialog.js swaps in the
    other pages without closing the pop-up, so the page links are hidden without JavaScript.
    Expects: $courses (a paginator of ['programme', 'hasTrends', 'competitiveness']), $label (the list's heading),
    $favouriteCourses (the codes of the applicant's favourite courses), $category (the applicant's category).
--}}
<div class="alternative-pages" data-similar-list>
    <ul class="alternative-list">
        @foreach ($courses as $course)
            <li class="alternative-list__item">
                <div class="alternative-list__details">
                    <span class="alternative-list__name">{{ $course['programme']->programme_name }}</span>
                    {{-- The same details as the course cards on the page, on one line to keep the card compact --}}
                    <span class="course__meta">
                        {{ $course['programme']->institution_name }} · {{ $course['programme']->programme_code }}
                        @if ($course['programme']->locationLabel())
                            · {{ $course['programme']->locationLabel() }}
                        @endif
                        @php($competition = $course['competitiveness'] === null ? 'No competition figures' : $course['competitiveness']->label().' competition')
                        ·
                        @if ($course['hasTrends'])
                            <a class="alternative-list__trends" href="{{ route('trends.show', $course['programme']->programme_code) }}" title="See admission trends">{{ $competition }}<span class="sr-only">, see admission trends of {{ $course['programme']->programme_name }}</span></a>
                        @else
                            {{ $competition }}
                        @endif
                        @if ($category->hasMeritScore())
                            · @include('eligibility.partials.merit-score', ['programme' => $course['programme']])
                        @endif
                    </span>
                    {{-- public/js/requirements-dialog.js loads the pop-up when the button is pressed, so it's hidden without JavaScript --}}
                    <button
                        class="text-link alternative-list__requirements"
                        type="button"
                        data-requirements-url="{{ route('eligibility.requirements', $course['programme']->programme_code) }}"
                        data-dialog-id="similar-requirements-{{ $course['programme']->programme_code }}"
                        aria-haspopup="dialog"
                    >
                        View full requirements<span class="sr-only"> of {{ $course['programme']->programme_name }}, {{ $course['programme']->institution_name }}</span>
                    </button>
                </div>
                @include('favourites.partials.favourite-button', ['programme' => $course['programme'], 'isFavourite' => in_array($course['programme']->programme_code, $favouriteCourses, true)])
            </li>
        @endforeach
    </ul>
    <x-pagination :paginator="$courses" :label="$label.' pages'" />
</div>
