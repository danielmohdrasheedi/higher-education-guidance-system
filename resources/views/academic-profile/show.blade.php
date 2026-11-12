<x-layout title="Your results">
    <section class="page-header">
        <div class="container page-header__split">
            <div>
                <nav class="breadcrumb" aria-label="Breadcrumb">
                    <a href="{{ route('home') }}">Home</a>
                    <span aria-hidden="true">/</span>
                    <span>Your results</span>
                </nav>
                <h1 class="page-header__title">Your results</h1>
                @php($savedAt = \Illuminate\Support\Carbon::parse($profile['saved_at']))
                <p class="page-header__lead">Saved {{ $savedAt->gt(now()->subMinute()) ? 'just now' : $savedAt->diffForHumans() }}. Your results are kept only while you use the site.</p>
            </div>
            <div class="page-header__actions">
                <a class="button button--dark" href="{{ route('academic-profile.edit') }}">Edit</a>
                <form method="POST" action="{{ route('academic-profile.destroy') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="button button--ghost">Delete my results</button>
                </form>
            </div>
        </div>
    </section>

    <div class="container profile-summary">
        <section class="panel">
            <h2 class="panel__title">About you</h2>
            <dl class="summary-list">
                <div>
                    <dt>Applying with</dt>
                    <dd>{{ $category->label() }}</dd>
                </div>
                @if ($profile['spm_stream'])
                    <div>
                        <dt>SPM stream</dt>
                        <dd>{{ config('academic.spm_streams')[$profile['spm_stream']] }}</dd>
                    </div>
                @endif
                @if (($profile['age'] ?? null) !== null)
                    <div>
                        <dt>Age</dt>
                        <dd>{{ $profile['age'] }}</dd>
                    </div>
                @endif
                <div>
                    <dt>Gender</dt>
                    <dd>{{ config('academic.genders')[$profile['gender']] }}</dd>
                </div>
                @if ($profile['eligible_descent'] ?? null)
                    <div>
                        <dt>Bumiputera status</dt>
                        <dd>{{ config('academic.bumiputera_statuses')[$profile['eligible_descent']] }}</dd>
                    </div>
                @endif
                @if ($profile['colour_blind'] ?? null)
                    <div>
                        <dt>Colour vision</dt>
                        <dd>{{ config('academic.colour_visions')[$profile['colour_blind']] }}</dd>
                    </div>
                @endif
                @if ($profile['cgpa'] !== null)
                    <div>
                        <dt>CGPA</dt>
                        <dd>{{ number_format($profile['cgpa'], 2) }}</dd>
                    </div>
                @endif
                @if (($profile['ib_points'] ?? null) !== null)
                    <div>
                        <dt>IB total points</dt>
                        <dd>{{ $profile['ib_points'] }}</dd>
                    </div>
                @endif
                @if (($profile['atar'] ?? null) !== null)
                    <div>
                        <dt>ATAR</dt>
                        <dd>{{ number_format($profile['atar'], 2) }}</dd>
                    </div>
                @endif
                @if (($profile['ausmat_aggregate'] ?? null) !== null)
                    <div>
                        <dt>AUSMAT aggregate</dt>
                        <dd>{{ $profile['ausmat_aggregate'] }}</dd>
                    </div>
                @endif
                @if ($profile['muet_band'])
                    <div>
                        <dt>MUET band</dt>
                        <dd>{{ $profile['muet_band'] === config('academic.no_muet') ? "Haven't taken MUET" : 'Band '.$profile['muet_band'] }}</dd>
                    </div>
                @endif
                @if ($profile['stam_grade'])
                    <div>
                        <dt>STAM grade</dt>
                        <dd>{{ $profile['stam_grade'] }}</dd>
                    </div>
                @endif
                @if ($profile['ielts_band'] ?? null)
                    <div>
                        <dt>IELTS band</dt>
                        <dd>Band {{ $profile['ielts_band'] }}</dd>
                    </div>
                @endif
                @if (($profile['toefl_ibt_score'] ?? null) !== null)
                    <div>
                        <dt>TOEFL iBT score</dt>
                        <dd>{{ $profile['toefl_ibt_score'] }}</dd>
                    </div>
                @endif
                @if ($profile['sports_level'] ?? null)
                    <div>
                        <dt>Sports level</dt>
                        <dd>{{ config('academic.sports_levels')[$profile['sports_level']] }}</dd>
                    </div>
                @endif
                @if ($profile['music_grade'] ?? null)
                    <div>
                        <dt>Music grade</dt>
                        <dd>{{ config('academic.music_grades')[$profile['music_grade']] }}</dd>
                    </div>
                @endif
                @if (($profile['quran_juz'] ?? null) !== null)
                    <div>
                        <dt>Quran memorisation</dt>
                        <dd>{{ trans_choice(':count juz|:count juz', $profile['quran_juz']) }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        <section class="panel">
            <h2 class="panel__title">SPM results <span class="panel__count">{{ trans_choice(':count subject|:count subjects', count($profile['spm_subjects'])) }}</span></h2>
            <table class="grades-table">
                <thead>
                    <tr><th scope="col">Subject</th><th scope="col">Grade</th></tr>
                </thead>
                <tbody>
                    @foreach ($profile['spm_subjects'] as $row)
                        <tr><td>{{ $row['subject'] }}</td><td><span class="grade">{{ $row['grade'] }}</span></td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        @if ($profile['stpm_subjects'])
            <section class="panel">
                <h2 class="panel__title">STPM results <span class="panel__count">{{ trans_choice(':count subject|:count subjects', count($profile['stpm_subjects'])) }}</span></h2>
                <table class="grades-table">
                    <thead>
                        <tr><th scope="col">Subject</th><th scope="col">Grade</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($profile['stpm_subjects'] as $row)
                            <tr><td>{{ $row['subject'] }}</td><td><span class="grade">{{ $row['grade'] }}</span></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        @foreach ([
            'matriculation_subjects' => 'Matriculation or foundation results',
            'diploma_subjects' => 'Diploma subject grades',
            'equivalent_subjects' => 'A-Level or AUSMAT results',
            'prau_subjects' => 'Pra-U (Sekolah Sukan Malaysia) results',
        ] as $field => $heading)
            @if ($profile[$field] ?? null)
                <section class="panel">
                    <h2 class="panel__title">{{ $heading }} <span class="panel__count">{{ trans_choice(':count subject|:count subjects', count($profile[$field])) }}</span></h2>
                    <table class="grades-table">
                        <thead>
                            <tr><th scope="col">Subject</th><th scope="col">Grade</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($profile[$field] as $row)
                                <tr><td>{{ $row['subject'] }}</td><td><span class="grade">{{ $row['grade'] }}</span></td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
            @endif
        @endforeach

        @if ($profile['ib_subjects'] ?? null)
            <section class="panel">
                <h2 class="panel__title">IB results <span class="panel__count">{{ trans_choice(':count subject|:count subjects', count($profile['ib_subjects'])) }}</span></h2>
                <table class="grades-table">
                    <thead>
                        <tr><th scope="col">Subject</th><th scope="col">Level</th><th scope="col">Grade</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($profile['ib_subjects'] as $row)
                            <tr><td>{{ $row['subject'] }}</td><td>{{ $row['level'] }}</td><td><span class="grade">{{ $row['grade'] }}</span></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        <section class="banner banner--compact">
            <div>
                <h2 class="banner__title">Next: see which courses you can apply for</h2>
                <p>Your results are checked against the entry requirements of every course open to you.</p>
            </div>
            <a class="button button--primary" href="{{ route('eligibility.index') }}">See your courses</a>
        </section>
    </div>
</x-layout>
