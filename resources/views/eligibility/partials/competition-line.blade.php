{{--
    A course's competition, linking to its admission trends, and the merit score e-Panduan gives the applicant's
    category. A span, so it can sit inside the favourites page's labels.
    Expects: $programme, $item (with 'hasTrends', 'indicators' and 'competitiveness'), $category.
--}}
@if ($item['hasTrends'] || $category->hasMeritScore())
    <span class="course__competition">
        {{-- The merit score moves to the next line when there isn't room beside the competition --}}
        @if ($item['hasTrends'])
            <span class="course__fact">
                Competition
                <a class="competition-tag" href="{{ route('trends.show', $programme->programme_code) }}" title="See admission trends">
                    {{ $item['competitiveness']?->label() ?? ($item['indicators']->hasFigures() ? 'No offers' : 'No figures') }}
                    <span aria-hidden="true">→</span>
                    <span class="sr-only">, see admission trends</span>
                </a>
            </span>
        @endif
        @if ($category->hasMeritScore())
            @include('eligibility.partials.merit-score', ['programme' => $programme])
        @endif
    </span>
@endif
