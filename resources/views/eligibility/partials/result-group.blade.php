@if ($items->total() > 0)
    {{-- A group stays open while moving between its pages --}}
    <details class="result-group" id="{{ $id }}" @if ($open || request()->has($items->getPageName())) open @endif>
        <summary class="result-group__summary">
            <h2 class="result-group__title">{{ $title }}</h2>
            <span class="panel__count">{{ trans_choice(':count course|:count courses', $items->total()) }}</span>
        </summary>
        <p class="result-group__text">{{ $text }}</p>

        <ul class="course-list">
            @foreach ($items as $item)
                @php
                    $programme = $item['programme'];
                    $problems = match ($id) {
                        'ineligible' => $item['result']->unmetConditions(),
                        'unchecked' => $item['result']->uncheckedConditions(),
                        default => [],
                    };
                @endphp
                <li class="course" id="course-{{ $programme->programme_code }}">
                    <div class="course__main">
                        <h3 class="course__name">{{ $programme->programme_name }}</h3>
                        <p class="course__meta">
                            {{ $programme->institution_name }} · {{ $programme->programme_code }}
                            @if ($programme->locationLabel())
                                · {{ $programme->locationLabel() }}
                            @endif
                        </p>
                        @include('eligibility.partials.competition-line', ['programme' => $programme, 'item' => $item])

                        @if ($problems !== [])
                            @include('eligibility.partials.conditions', ['conditions' => $problems, 'listClass' => 'course__problems', 'withStatus' => false])
                        @endif

                        <div class="course__actions">
                            <button class="text-link" type="button" command="show-modal" commandfor="requirements-{{ $programme->programme_code }}" aria-haspopup="dialog">
                                View full requirements
                            </button>
                            <a class="text-link" href="{{ $programme->officialPortalLink($category) }}" rel="noopener" target="_blank">
                                View on e-Panduan<span class="sr-only"> (opens in a new tab)</span>
                            </a>
                        </div>
                    </div>
                    @include('favourites.partials.favourite-button', ['programme' => $programme, 'isFavourite' => in_array($programme->programme_code, $favouriteCourses, true)])
                    @include('eligibility.partials.requirements-dialog', [
                        'dialogId' => 'requirements-'.$programme->programme_code,
                        'programme' => $programme,
                        'result' => $item['result'],
                        'officialLink' => $programme->officialPortalLink($category),
                        'alternatives' => $item['alternatives'] ?? null,
                    ])
                </li>
            @endforeach
        </ul>

        <x-pagination :paginator="$items" :label="$title.' pages'" />
    </details>
@endif
