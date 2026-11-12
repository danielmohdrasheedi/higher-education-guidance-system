<x-layout title="Favourites">
    @push('scripts')
        <script src="{{ asset('js/requirements-dialog.js') }}?v={{ filemtime(public_path('js/requirements-dialog.js')) }}" defer></script>
    @endpush

    <section class="page-header">
        <div class="container page-header__split">
            <div>
                <nav class="breadcrumb" aria-label="Breadcrumb">
                    <a href="{{ route('home') }}">Home</a>
                    <span aria-hidden="true">/</span>
                    <a href="{{ route('eligibility.index') }}">Courses</a>
                    <span aria-hidden="true">/</span>
                    <span>Favourites</span>
                </nav>
                <h1 class="page-header__title">Favourites</h1>
                <p class="page-header__lead">Tick 2 to {{ $maximumCompared }} courses to compare.</p>
            </div>
            <div class="page-header__actions">
                <a class="button button--dark" href="{{ route('eligibility.index') }}">Find more courses</a>
            </div>
        </div>
    </section>

    <div class="container results">
        @if ($errors->any())
            <div class="error-summary" role="alert">
                <h2>Please fix the following</h2>
                <ul>
                    @foreach (array_unique($errors->all()) as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($courses->total() === 0)
            <section class="panel">
                <h2 class="panel__title">No favourites yet</h2>
                <p class="panel__text">Press the heart next to a course on the courses page to add it here, then compare your favourites.</p>
                <a class="button button--primary" href="{{ route('eligibility.index') }}">Go to courses</a>
            </section>
        @else
            <form class="panel" method="GET" action="{{ route('favourites.compare') }}" aria-labelledby="favourites-title">
                <h2 class="panel__title" id="favourites-title">
                    Your favourites
                    <span class="panel__count">{{ trans_choice(':count course|:count courses', $courses->total()) }}</span>
                </h2>

                <ul class="favourite-list">
                    @foreach ($courses as $course)
                        @php($programme = $course['programme'])
                        <li class="favourite-course" id="course-{{ $programme->programme_code }}">
                            <div class="favourite-course__main">
                                <label class="favourite-course__choice">
                                    <input type="checkbox" name="courses[]" value="{{ $programme->programme_code }}" @checked(in_array($programme->programme_code, $chosen, true))>
                                    <span>
                                        <span class="course__name">{{ $programme->programme_name }}</span>
                                        <span class="course__meta">
                                            {{ $programme->institution_name }} · {{ $programme->programme_code }}
                                            @if ($programme->locationLabel())
                                                · {{ $programme->locationLabel() }}
                                            @endif
                                        </span>
                                        {{-- Only courses open to the applicant's category have their UPUOnline figures --}}
                                        @include('eligibility.partials.competition-line', ['programme' => $programme, 'item' => $course])
                                        <span class="favourite-course__result">
                                            @if ($course['result'])
                                                Your result: <strong>{{ $course['result']->status->courseLabel() }}</strong>
                                            @else
                                                Not open to {{ $category->label() }}
                                            @endif
                                        </span>
                                    </span>
                                </label>
                                {{-- A course not open to the applicant's category has no requirements for them --}}
                                @if ($course['result'])
                                    <div class="course__actions favourite-course__actions">
                                        <button class="text-link" type="button" command="show-modal" commandfor="requirements-{{ $programme->programme_code }}" aria-haspopup="dialog">
                                            View full requirements
                                        </button>
                                        <a class="text-link" href="{{ $programme->officialPortalLink($category) }}" rel="noopener" target="_blank">
                                            View on e-Panduan<span class="sr-only"> (opens in a new tab)</span>
                                        </a>
                                    </div>
                                @endif
                            </div>
                            <button class="favourite-button is-favourite" type="submit" form="remove-{{ $programme->programme_code }}" aria-label="Remove {{ $programme->programme_name }}, {{ $programme->institution_name }} from favourites" title="Remove from favourites">
                                <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">
                                    <path d="M12 20.5 4.1 12.9a4.9 4.9 0 0 1 0-7.1 5.1 5.1 0 0 1 7.2 0l.7.7.7-.7a5.1 5.1 0 0 1 7.2 0 4.9 4.9 0 0 1 0 7.1Z" />
                                </svg>
                            </button>
                        </li>
                    @endforeach
                </ul>

                <x-pagination :paginator="$courses" label="Favourites pages" :as-buttons="true" />

                <div class="form-actions favourite-list__actions">
                    <button class="button button--primary" type="submit">Compare ticked courses</button>
                    @if ($chosenElsewhere !== [])
                        <p class="favourite-list__chosen">Also ticked on other pages: {{ count($chosenElsewhere) }}</p>
                    @endif
                </div>

                {{-- Courses ticked on other pages --}}
                @foreach ($chosenElsewhere as $code)
                    <input type="hidden" name="courses[]" value="{{ $code }}">
                @endforeach
            </form>

            {{-- The requirements pop-ups have their own close form, so they sit outside the compare form --}}
            @foreach ($courses as $course)
                @if ($course['result'])
                    @include('eligibility.partials.requirements-dialog', [
                        'dialogId' => 'requirements-'.$course['programme']->programme_code,
                        'programme' => $course['programme'],
                        'result' => $course['result'],
                        'officialLink' => $course['programme']->officialPortalLink($category),
                        'alternatives' => null,
                    ])
                @endif
            @endforeach

            {{-- The remove buttons sit inside the compare form, so they submit these forms instead --}}
            @foreach ($courses as $course)
                <form id="remove-{{ $course['programme']->programme_code }}" method="POST" action="{{ route('favourites.destroy', $course['programme']->programme_code) }}" hidden>
                    @csrf
                    @method('DELETE')
                </form>
            @endforeach
        @endif
    </div>
</x-layout>
