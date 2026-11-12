<x-layout title="Courses you can apply for">
    @push('scripts')
        <script src="{{ asset('js/course-filters.js') }}?v={{ filemtime(public_path('js/course-filters.js')) }}" defer></script>
        <script src="{{ asset('js/requirements-dialog.js') }}?v={{ filemtime(public_path('js/requirements-dialog.js')) }}" defer></script>
        <script src="{{ asset('js/favourites.js') }}?v={{ filemtime(public_path('js/favourites.js')) }}" defer></script>
        <script src="{{ asset('js/result-pages.js') }}?v={{ filemtime(public_path('js/result-pages.js')) }}" defer></script>
    @endpush

    <section class="page-header">
        <div class="container page-header__split">
            <div>
                <nav class="breadcrumb" aria-label="Breadcrumb">
                    <a href="{{ route('home') }}">Home</a>
                    <span aria-hidden="true">/</span>
                    <a href="{{ route('academic-profile.show') }}">Your results</a>
                    <span aria-hidden="true">/</span>
                    <span>Courses</span>
                </nav>
                <h1 class="page-header__title">Courses you can apply for</h1>
                <p class="page-header__lead">Your results checked against the entry requirements of {{ number_format($total) }} courses open to {{ $category->label() }}.</p>
            </div>
            <div class="page-header__actions">
                <a class="button button--dark" href="{{ route('academic-profile.edit') }}">Edit your results</a>
            </div>
        </div>
    </section>

    <div class="container results">
        <form class="panel filters" method="GET" action="{{ route('eligibility.index') }}" role="search" aria-labelledby="filters-title" data-campuses-by-institution="{{ json_encode($campusesByInstitution) }}" data-campus-separator="{{ \App\Http\Requests\FilterCoursesRequest::CAMPUS_SEPARATOR }}">
            <h2 class="panel__title" id="filters-title">Filter courses</h2>
            <div class="filters__fields">
                <div class="field filters__search">
                    <label class="field__label" for="search">Course name/code</label>
                    <input class="input" type="search" id="search" name="search" value="{{ $filters['search'] }}" maxlength="100" placeholder="e.g. sains komputer or UE6481001">
                </div>

                <div class="field">
                    <label class="field__label" for="institution">Institution</label>
                    <select class="input" id="institution" name="institution">
                        <option value="">All institutions</option>
                        @foreach ($institutions as $institution)
                            <option value="{{ $institution }}" @selected($filters['institution'] === $institution)>{{ $institution }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label class="field__label" for="location">Location</label>
                    <select class="input" id="location" name="location">
                        <option value="">All states</option>
                        {{-- A campus is indented under its state; the state and each campus can be chosen --}}
                        @foreach ($locations as $location)
                            <option value="{{ $location['value'] }}" @selected($filters['location'] === $location['value'])>{{ $location['isCampus'] ? "\u{00A0}\u{00A0}\u{00A0}\u{00A0}".$location['label'] : $location['label'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label class="field__label" for="competition">Competition</label>
                    <select class="input" id="competition" name="competition">
                        <option value="">Any</option>
                        @foreach (\App\Trends\Competitiveness::cases() as $competitiveness)
                            <option value="{{ $competitiveness->value }}" @selected($filters['competition'] === $competitiveness->value)>{{ $competitiveness->label() }}</option>
                        @endforeach
                        <option value="{{ \App\Http\Requests\FilterCoursesRequest::NO_FIGURES }}" @selected($filters['competition'] === \App\Http\Requests\FilterCoursesRequest::NO_FIGURES)>No figures</option>
                    </select>
                </div>

                <div class="field">
                    <label class="field__label" for="interview_or_test">Interview or test</label>
                    <select class="input" id="interview_or_test" name="interview_or_test">
                        <option value="">Any</option>
                        <option value="{{ \App\Http\Requests\FilterCoursesRequest::INTERVIEW_OR_TEST_REQUIRED }}" @selected($filters['interview_or_test'] === \App\Http\Requests\FilterCoursesRequest::INTERVIEW_OR_TEST_REQUIRED)>Interview or test</option>
                        <option value="{{ \App\Http\Requests\FilterCoursesRequest::INTERVIEW_OR_TEST_NONE }}" @selected($filters['interview_or_test'] === \App\Http\Requests\FilterCoursesRequest::INTERVIEW_OR_TEST_NONE)>None</option>
                    </select>
                </div>
            </div>

            <div class="filters__actions">
                <button class="button button--primary" type="submit">Apply filters</button>
                @if ($isFiltering)
                    <a class="text-link" href="{{ route('eligibility.index') }}">Clear filters</a>
                @endif
                <p class="filters__count" role="status">Showing {{ number_format($shown) }} of {{ number_format($total) }} courses</p>
            </div>
        </form>

        <nav class="result-totals" aria-label="Result summary">
            <a class="result-total result-total--eligible" href="#eligible">
                <span class="result-total__count">{{ $eligible->total() }}</span>
                <span>You qualify</span>
            </a>
            <a class="result-total result-total--unchecked" href="#unchecked">
                <span class="result-total__count">{{ $unchecked->total() }}</span>
                <span>Check the rest yourself</span>
            </a>
            <a class="result-total result-total--ineligible" href="#ineligible">
                <span class="result-total__count">{{ $ineligible->total() }}</span>
                <span>You don't qualify</span>
            </a>
        </nav>

        @if ($total === 0)
            <section class="panel">
                <h2 class="panel__title">No courses found</h2>
                <p class="panel__text">No courses are open to {{ $category->label() }} at the moment.</p>
            </section>
        @elseif ($shown === 0)
            <section class="panel">
                <h2 class="panel__title">No courses match these filters</h2>
                <p class="panel__text">Try fewer filters, or <a class="text-link" href="{{ route('eligibility.index') }}">clear them all</a>.</p>
            </section>
        @endif

        @include('eligibility.partials.result-group', [
            'id' => 'eligible',
            'title' => 'You qualify',
            'text' => 'Your results meet every requirement that can be checked. Some courses also ask for things like an interview, listed on e-Panduan.',
            'items' => $eligible,
            'open' => true,
        ])

        @include('eligibility.partials.result-group', [
            'id' => 'unchecked',
            'title' => 'Check the rest yourself',
            'text' => "Some requirements for these courses couldn't be checked. Read them on e-Panduan to see if you qualify.",
            'items' => $unchecked,
            'open' => false,
        ])

        @include('eligibility.partials.result-group', [
            'id' => 'ineligible',
            'title' => "You don't qualify",
            'text' => "Your results don't meet at least one requirement for these courses.",
            'items' => $ineligible,
            'open' => false,
        ])
    </div>

    {{-- Messages from the hearts. Read out by screen readers, and shown at the bottom of the screen only when a heart couldn't change --}}
    <p class="flash flash--floating sr-only" role="status" data-favourite-status></p>
</x-layout>
