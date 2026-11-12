{{-- The full entry requirements of one course, opened from its "Requirements" button --}}
<dialog class="dialog" id="{{ $dialogId }}" aria-labelledby="{{ $dialogId }}-title">
    <div class="dialog__header">
        <div>
            <h3 class="dialog__title" id="{{ $dialogId }}-title">{{ $programme->programme_name }}</h3>
            <p class="course__meta">{{ $programme->institution_name }} · {{ $programme->programme_code }}</p>
        </div>
        <form method="dialog">
            <button class="dialog__close" type="submit" aria-label="Close">
                <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true"><path d="M1 1l12 12M13 1L1 13" fill="none" stroke="currentColor" stroke-width="2"/></svg>
            </button>
        </form>
    </div>

    @php
        $checkable = $result->checkableConditions();
        $uncheckable = $result->uncheckableConditions();
        // A line already shown as a requirement isn't listed again
        $shown = array_map(fn ($condition) => $condition->description, $result->conditions);
        $notes = array_values(array_diff(array_unique($result->notes), $shown));
    @endphp

    <h4 class="dialog__heading">Checked against your results</h4>
    @if ($checkable !== [])
        @include('eligibility.partials.conditions', ['conditions' => $checkable, 'listClass' => 'requirement-list', 'withStatus' => true])
    @else
        <p class="course__reason">None of this course's requirements can be checked against your results.</p>
    @endif

    @if ($uncheckable !== [] || $notes !== [])
        <h4 class="dialog__heading">Not checked by this site</h4>
        @if ($uncheckable !== [])
            @include('eligibility.partials.conditions', ['conditions' => $uncheckable, 'listClass' => 'requirement-list', 'withStatus' => true])
        @endif
        @if ($notes !== [])
            <ul class="dialog__notes">
                @foreach ($notes as $note)
                    @php
                        // A translation can split a long line into a first line and a list, like the items under it
                        $noteLines = explode("\n", $note);
                        $firstLines = explode("\n", \App\Eligibility\Wording::inEnglish(array_shift($noteLines)));
                        $noteLines = [...array_slice($firstLines, 1), ...$noteLines];
                    @endphp
                    <li>
                        {{ \App\Eligibility\Wording::sentence($firstLines[0], $noteLines !== []) }}
                        @include('eligibility.partials.wording-list', ['items' => $noteLines])
                    </li>
                @endforeach
            </ul>
        @endif
    @endif

    @if ($alternatives !== null)
        @include('eligibility.partials.alternative-courses', ['alternatives' => $alternatives])
    @endif

    <p class="dialog__link">
        <a class="text-link" href="{{ $officialLink }}" rel="noopener" target="_blank">
            View on e-Panduan<span class="sr-only"> (opens in a new tab)</span>
        </a>
    </p>
</dialog>
