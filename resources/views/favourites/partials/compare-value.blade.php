{{--
    One course's value for one comparison row, shown in the table and in the course cards.
    Expects: $row (a key of the rows in favourites.compare), $course, $category.
--}}
@php($indicators = $course['indicators'])
@switch($row)
    @case('location')
        @forelse ($course['programme']->campuses ?? [] as $campus)
            <span class="compare-table__line">{{ implode(', ', array_filter([$campus['campus'], $campus['state']])) }}</span>
        @empty
            Not listed
        @endforelse
        @break

    @case('result')
        @if ($course['result'])
            <span class="status status--{{ $course['result']->status->value }}">{{ $course['result']->status->courseLabel() }}</span>
        @else
            <span class="status">Not open to {{ $category->label() }}</span>
        @endif
        @break

    @case('requirements')
        @if ($course['result'] && $course['result']->uniqueConditions() !== [])
            @include('eligibility.partials.conditions', ['conditions' => $course['result']->uniqueConditions(), 'listClass' => 'requirement-list', 'withStatus' => true])
        @elseif ($course['result'])
            <span class="course__reason">No requirements could be checked. Read them on e-Panduan.</span>
        @else
            <span class="course__reason">This course doesn't take applicants from your category.</span>
        @endif
        @break

    @case('competition')
        @if ($indicators?->hasFigures())
            <strong>{{ $indicators->competitiveness()?->label() ?? 'No offers' }}</strong>
            <span class="course__reason">{{ $indicators->competitiveness()?->description() ?? "No applications got an offer in {$indicators->latestSession()}." }}</span>
        @else
            <span class="course__reason">No UPUOnline figures</span>
        @endif
        @break

    @case('offer_rate')
        @if ($indicators?->hasFigures())
            <strong>{{ $indicators->offerRate() }}%</strong>
            <span class="course__reason">in {{ $indicators->latestSession() }}</span>
        @else
            –
        @endif
        @break

    @case('applications')
        @if ($indicators?->hasFigures())
            {{ number_format($indicators->applications()) }} applications, {{ number_format($indicators->offers()) }} offers
            <span class="course__reason">in {{ $indicators->latestSession() }}</span>
        @else
            –
        @endif
        @break

    @case('merit')
        @php($meritScore = App\Trends\MeritScore::for($course['programme'], $category))
        @if ($meritScore->score === null)
            <span class="course__reason">{{ $meritScore->note() }}</span>
        @else
            {{ $meritScore->value() }}
        @endif
        @break
@endswitch
