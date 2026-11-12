<x-layout title="Admission trends for {{ $programme->programme_name }}">
    @push('scripts')
        <script src="{{ asset('js/favourites.js') }}?v={{ filemtime(public_path('js/favourites.js')) }}" defer></script>
    @endpush

    <section class="page-header">
        <div class="container">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('home') }}">Home</a>
                <span aria-hidden="true">/</span>
                <a href="{{ route('eligibility.index') }}">Courses</a>
                <span aria-hidden="true">/</span>
                <span>Admission trends</span>
            </nav>
            {{-- The heart's top lines up with the top of the course name --}}
            <div class="page-header__title-row">
                <h1 class="page-header__title">{{ $programme->programme_name }}</h1>
                @include('favourites.partials.favourite-button', ['programme' => $programme, 'isFavourite' => $isFavourite])
            </div>
            <p class="page-header__lead">
                {{ $programme->institution_name }} · {{ $programme->programme_code }}
                @if ($programme->locationLabel())
                    · {{ $programme->locationLabel() }}
                @endif
            </p>
            @if ($category)
                <p class="page-header__link">
                    <a class="text-link" href="{{ $programme->officialPortalLink($category) }}" rel="noopener" target="_blank">
                        View on e-Panduan<span class="sr-only"> (opens in a new tab)</span>
                    </a>
                </p>
            @endif
        </div>
    </section>

    <div class="container results">
        @if (count($applicantTypes) > 1)
            <nav class="segmented" aria-label="Applicant type">
                @foreach (['stpm' => 'STPM, matriculation and foundation', 'stam' => 'STAM'] as $type => $label)
                    <a @class(['segmented__option', 'is-active' => $applicantType === $type]) href="{{ route('trends.show', ['programme' => $programme->programme_code, 'applicants' => $type]) }}" @if ($applicantType === $type) aria-current="page" @endif>{{ $label }} applicants</a>
                @endforeach
            </nav>
        @endif

        @if (! $indicators->hasFigures())
            <section class="panel">
                <h2 class="panel__title">No admission figures</h2>
                <p class="panel__text">UPUOnline has no past applications on record for this course.</p>
                @if ($meritScore->score !== null)
                    <p class="panel__text">e-Panduan gives it an average merit score of {{ $meritScore->value() }} out of 100.</p>
                @endif
            </section>
        @else
            @php
                $competitiveness = $indicators->competitiveness();
                $session = $indicators->latestSession();
                $maximumApplications = max(array_column($indicators->sessions, 'applications')) ?: 1;
            @endphp

            <section class="stat-tiles" aria-label="Competitiveness in {{ $session }}">
                <div @class(['stat-tile', 'stat-tile--highlight' => $competitiveness !== null])>
                    <p class="stat-tile__label">Competition</p>
                    <p class="stat-tile__value">{{ $competitiveness?->label() ?? 'No offers' }}</p>
                    <p class="stat-tile__note">{{ $competitiveness?->description() ?? "No applications got an offer in {$session}." }}</p>
                </div>
                <div class="stat-tile">
                    <p class="stat-tile__label">Offer rate in {{ $session }}</p>
                    <p class="stat-tile__value">{{ $indicators->offerRate() }}%</p>
                    <p class="stat-tile__note">
                        @if ($indicators->offers() === 0)
                            None of the {{ number_format($indicators->applications()) }} applications got an offer.
                        @else
                            {{ number_format($indicators->offers()) }} of {{ number_format($indicators->applications()) }} applications got an offer, about 1 in {{ number_format((int) $indicators->applicationsPerOffer()) }}.
                        @endif
                    </p>
                </div>
                <div class="stat-tile">
                    <p class="stat-tile__label">Average merit score</p>
                    <p class="stat-tile__value">{{ $meritScore->value() }}</p>
                    <p class="stat-tile__note">{{ $meritScore->note() }}</p>
                </div>
            </section>

            <section class="panel">
                <h2 class="panel__title">Applications and offers by session</h2>
                <p class="bar-chart__key">
                    <span><span class="bar-chart__swatch" aria-hidden="true"></span> Applications</span>
                    <span><span class="bar-chart__swatch bar-chart__swatch--offers" aria-hidden="true"></span> Got an offer</span>
                </p>
                <ul class="bar-chart">
                    @foreach ($indicators->sessions as $sessionName => $figures)
                        <li class="bar-chart__row">
                            <span class="bar-chart__label">{{ $sessionName }}</span>
                            <span class="bar-chart__track" aria-hidden="true">
                                <span class="bar-chart__bar" style="width: {{ round($figures['applications'] / $maximumApplications * 100, 1) }}%">
                                    @if ($figures['offers'] > 0)
                                        <span class="bar-chart__offers" style="width: {{ round($figures['offers'] / $figures['applications'] * 100, 1) }}%"></span>
                                    @endif
                                </span>
                            </span>
                            <span class="bar-chart__value">
                                {{ number_format($figures['applications']) }} applications
                                <span class="bar-chart__detail">{{ number_format($figures['offers']) }} offers{{ $figures['offerRate'] === null ? '' : ', '.$figures['offerRate'].'%' }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="panel">
                <h2 class="panel__title">Applicants by group in {{ $session }}</h2>
                <p class="panel__text">UPUOnline splits the figures by academic group{{ $programme->level === 'spm' ? ', SPM stream' : '' }} and gender.</p>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th scope="col">Applicants</th>
                                <th scope="col">Applications</th>
                                <th scope="col">Offers</th>
                                <th scope="col">Offer rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($indicators->segments() as $segment)
                                <tr>
                                    <th scope="row">{{ $segment['label'] }}</th>
                                    <td>{{ number_format($segment['applications']) }}</td>
                                    <td>{{ number_format($segment['offers']) }}</td>
                                    <td>{{ $segment['offerRate'] === null ? '–' : $segment['offerRate'].'%' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <p class="trend-source">Figures from the UPUOnline admission trends. They show past sessions only and can't promise an offer.</p>
        @endif
    </div>

    <p class="flash flash--floating sr-only" role="status" data-favourite-status></p>
</x-layout>
