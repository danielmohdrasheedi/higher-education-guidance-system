@php
    $isEditing = $profile !== null;
    $selectedCategory = (string) old('applicant_category', $profile['applicant_category'] ?? '');
    $selectedGender = (string) old('gender', $profile['gender'] ?? '');
    $selectedStream = (string) old('spm_stream', $profile['spm_stream'] ?? '');
    $selectedMuet = (string) old('muet_band', $profile['muet_band'] ?? '');
    $selectedStam = (string) old('stam_grade', $profile['stam_grade'] ?? '');
    $selectedSports = (string) old('sports_level', $profile['sports_level'] ?? '');
    $cgpa = old('cgpa', isset($profile['cgpa']) ? number_format($profile['cgpa'], 2) : '');
    $ibPoints = old('ib_points', $profile['ib_points'] ?? '');
    $atar = old('atar', isset($profile['atar']) ? number_format($profile['atar'], 2) : '');
    $ausmatAggregate = old('ausmat_aggregate', $profile['ausmat_aggregate'] ?? '');
    $age = old('age', $profile['age'] ?? '');
    $selectedDescent = (string) old('eligible_descent', $profile['eligible_descent'] ?? '');
    $selectedColourBlind = (string) old('colour_blind', $profile['colour_blind'] ?? '');
    $selectedIelts = (string) old('ielts_band', $profile['ielts_band'] ?? '');
    $toeflIbtScore = old('toefl_ibt_score', $profile['toefl_ibt_score'] ?? '');
    $selectedMusicGrade = (string) old('music_grade', $profile['music_grade'] ?? '');
    $quranJuz = old('quran_juz', $profile['quran_juz'] ?? '');
@endphp

@push('scripts')
    <script src="{{ asset('js/academic-profile.js') }}?v={{ filemtime(public_path('js/academic-profile.js')) }}" defer></script>
@endpush

<x-layout :title="$isEditing ? 'Edit your results' : 'Add your results'">
    <section class="page-header">
        <div class="container">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('home') }}">Home</a>
                <span aria-hidden="true">/</span>
                <span>{{ $isEditing ? 'Edit your results' : 'Add your results' }}</span>
            </nav>
            <h1 class="page-header__title">{{ $isEditing ? 'Edit your results' : 'Add your results' }}</h1>
            <p class="page-header__lead">Update your grades to see your eligible courses.</p>
        </div>
    </section>

    <div class="container form-layout">
        <form
            class="profile-form"
            method="POST"
            action="{{ $isEditing ? route('academic-profile.update') : route('academic-profile.store') }}"
            novalidate
        >
            @csrf
            @if ($isEditing)
                @method('PUT')
            @endif

            @if ($errors->any())
                <div class="error-summary" role="alert" tabindex="-1" data-error-summary>
                    <h2>Please fix the following</h2>
                    <ul>
                        @foreach (array_unique($errors->all()) as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Step 1: applicant details --}}
            <fieldset class="panel">
                <legend class="panel__title"><span class="panel__step">1</span> About you</legend>

                <div class="field">
                    <label class="field__label" for="applicant_category">What are you applying with?</label>
                    <p class="field__hint" id="applicant_category-hint">Choose the qualification you're using to apply.</p>
                    <select
                        id="applicant_category"
                        name="applicant_category"
                        @class(['input', 'input--invalid' => $errors->has('applicant_category')])
                        aria-describedby="applicant_category-hint"
                        data-category-select
                    >
                        <option value="" data-sections="">Choose one</option>
                        @foreach ($categoryGroups as $group => $categories)
                            <optgroup label="{{ $group }}">
                                @foreach ($categories as $category)
                                    <option
                                        value="{{ $category->value }}"
                                        data-sections="{{ implode(' ', $category->sections()) }}"
                                        @selected($selectedCategory === $category->value)
                                    >{{ $category->label() }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('applicant_category')
                        <p class="field__error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field" data-section="spm-stream">
                    <label class="field__label" for="spm_stream">SPM stream</label>
                    <p class="field__hint">The stream you took in Form 4 and 5.</p>
                    <select id="spm_stream" name="spm_stream" @class(['input', 'input--narrow', 'input--invalid' => $errors->has('spm_stream')])>
                        <option value="">Choose your stream</option>
                        @foreach (config('academic.spm_streams') as $value => $label)
                            <option value="{{ $value }}" @selected($selectedStream === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('spm_stream')
                        <p class="field__error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label class="field__label" for="age">Age</label>
                    <p class="field__hint" id="age-hint">Your age in full years when you apply. Some courses have an age limit.</p>
                    <input
                        id="age"
                        name="age"
                        type="number"
                        inputmode="numeric"
                        step="1"
                        min="15"
                        max="70"
                        placeholder="e.g. 19"
                        value="{{ $age }}"
                        @class(['input', 'input--pair', 'input--invalid' => $errors->has('age')])
                        aria-describedby="age-hint"
                    >
                    @error('age')
                        <p class="field__error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <span class="field__label" id="gender-label">Gender</span>
                    <p class="field__hint">Some courses only accept men or only women.</p>
                    <div class="choice-group" role="radiogroup" aria-labelledby="gender-label">
                        @foreach (config('academic.genders') as $value => $label)
                            <label class="choice">
                                <input type="radio" name="gender" value="{{ $value }}" @checked($selectedGender === $value)>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('gender')
                        <p class="field__error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <span class="field__label" id="eligible_descent-label">Bumiputera status</span>
                    <p class="field__hint">Some courses, such as most at UiTM, only accept Bumiputera applicants.</p>
                    <div class="choice-group" role="radiogroup" aria-labelledby="eligible_descent-label">
                        @foreach (config('academic.bumiputera_statuses') as $value => $label)
                            <label class="choice">
                                <input type="radio" name="eligible_descent" value="{{ $value }}" @checked($selectedDescent === $value)>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('eligible_descent')
                        <p class="field__error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <span class="field__label" id="colour_blind-label">Colour vision</span>
                    <p class="field__hint">Some courses, such as design and nursing, don't accept colour-blind applicants.</p>
                    <div class="choice-group" role="radiogroup" aria-labelledby="colour_blind-label">
                        @foreach (config('academic.colour_visions') as $value => $label)
                            <label class="choice">
                                <input type="radio" name="colour_blind" value="{{ $value }}" @checked($selectedColourBlind === $value)>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('colour_blind')
                        <p class="field__error">{{ $message }}</p>
                    @enderror
                </div>
            </fieldset>

            {{-- Step 2: SPM results, needed by every applicant --}}
            <fieldset class="panel">
                <legend class="panel__title"><span class="panel__step">2</span> SPM results</legend>
                <p class="panel__text">Everyone needs SPM results. Add each subject and your grade.</p>

                <div class="subject-list" data-subject-list="spm" data-max-rows="20">
                    <div class="subject-list__head" aria-hidden="true">
                        <span>Subject</span>
                        <span>Grade</span>
                    </div>
                    <div data-rows>
                        @foreach ($spmSubjectRows as $index => $row)
                            @include('academic-profile.partials.subject-row', [
                                'level' => 'spm',
                                'index' => $index,
                                'row' => $row,
                                'subjects' => config('academic.spm_subjects'),
                                'grades' => config('academic.spm_grades'),
                            ])
                        @endforeach
                    </div>
                    <template data-row-template>
                        @include('academic-profile.partials.subject-row', [
                            'level' => 'spm',
                            'index' => '__INDEX__',
                            'row' => [],
                            'subjects' => config('academic.spm_subjects'),
                            'grades' => config('academic.spm_grades'),
                        ])
                    </template>
                    <button type="button" class="button button--outline" data-add-row>+ Add a subject</button>
                </div>
                @error('spm_subjects')
                    <p class="field__error">{{ $message }}</p>
                @enderror
            </fieldset>

            {{-- Step 3: results for the applicant's current qualification --}}
            <fieldset class="panel" data-section="stpm matriculation diploma equivalent ib ausmat prau cgpa muet stam">
                <legend class="panel__title"><span class="panel__step">3</span> Your other results</legend>
                <p class="panel__text">Results from the qualification you're applying with.</p>

                @include('academic-profile.partials.subject-list', [
                    'level' => 'stpm',
                    'levelLabel' => 'STPM',
                    'label' => 'STPM results',
                    'hint' => 'Include Pengajian Am. You can add up to 6 subjects.',
                    'rows' => $stpmSubjectRows,
                    'subjects' => config('academic.stpm_subjects'),
                    'grades' => config('academic.stpm_grades'),
                    'maxRows' => 6,
                ])

                @include('academic-profile.partials.subject-list', [
                    'level' => 'matriculation',
                    'levelLabel' => 'Matriculation or foundation',
                    'label' => 'Matriculation or foundation results',
                    'hint' => 'The subjects and grades on your matriculation or foundation (Asasi) result slip. You can add up to 12 subjects.',
                    'rows' => $matriculationSubjectRows,
                    'subjects' => config('academic.matriculation_subjects'),
                    'grades' => config('academic.stpm_grades'),
                    'maxRows' => 12,
                ])

                @include('academic-profile.partials.subject-list', [
                    'level' => 'diploma',
                    'levelLabel' => 'Diploma',
                    'label' => 'Diploma subject grades (optional)',
                    'hint' => 'Some courses ask for a grade in a diploma subject, such as Mathematics or Physics. Add those subjects to check them.',
                    'rows' => $diplomaSubjectRows,
                    'subjects' => config('academic.diploma_subjects'),
                    'grades' => config('academic.stpm_grades'),
                    'maxRows' => 12,
                ])

                @include('academic-profile.partials.subject-list', [
                    'level' => 'equivalent',
                    'levelLabel' => 'Qualification',
                    'label' => 'A-Level or AUSMAT results',
                    'hint' => 'The subjects and grades on your result slip. You can add up to 10 subjects.',
                    'rows' => $equivalentSubjectRows,
                    'subjects' => config('academic.equivalent_subjects'),
                    'grades' => config('academic.equivalent_grades'),
                    'maxRows' => 10,
                ])

                @include('academic-profile.partials.subject-list', [
                    'level' => 'ib',
                    'levelLabel' => 'IB',
                    'label' => 'IB results',
                    'hint' => 'Each subject with the level you took it at, Higher Level (HL) or Standard Level (SL), and its grade from 7 to 1. You can add up to 10 subjects.',
                    'rows' => $ibSubjectRows,
                    'subjects' => config('academic.ib_subjects'),
                    'levels' => config('academic.ib_levels'),
                    'grades' => config('academic.ib_grades'),
                    'maxRows' => 10,
                ])

                @include('academic-profile.partials.subject-list', [
                    'level' => 'prau',
                    'levelLabel' => 'Pra-U',
                    'label' => 'Pra-U (Sekolah Sukan Malaysia) results',
                    'hint' => 'The subjects and grades on your Pra-U result slip. You can add up to 10 subjects.',
                    'rows' => $prauSubjectRows,
                    'subjects' => config('academic.prau_subjects'),
                    'grades' => config('academic.stpm_grades'),
                    'maxRows' => 10,
                ])

                <div class="field-row field-row--aligned">
                    <div class="field" data-section="ib">
                        <label class="field__label" for="ib_points">IB total points (optional)</label>
                        <p class="field__hint" id="ib_points-hint">Your IB Diploma total, from 0 to 45. Some courses ask for a minimum.</p>
                        <input
                            id="ib_points"
                            name="ib_points"
                            type="number"
                            inputmode="numeric"
                            step="1"
                            min="0"
                            max="45"
                            placeholder="e.g. 32"
                            value="{{ $ibPoints }}"
                            @class(['input', 'input--invalid' => $errors->has('ib_points')])
                            aria-describedby="ib_points-hint"
                        >
                        @error('ib_points')
                            <p class="field__error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field" data-section="ausmat">
                        <label class="field__label" for="atar">ATAR (optional)</label>
                        <p class="field__hint" id="atar-hint">Your Australian Tertiary Admission Rank, from 0.00 to 99.95.</p>
                        <input
                            id="atar"
                            name="atar"
                            type="number"
                            inputmode="decimal"
                            step="0.01"
                            min="0"
                            max="99.95"
                            placeholder="e.g. 85.50"
                            value="{{ $atar }}"
                            @class(['input', 'input--invalid' => $errors->has('atar')])
                            aria-describedby="atar-hint"
                        >
                        @error('atar')
                            <p class="field__error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field" data-section="ausmat">
                        <label class="field__label" for="ausmat_aggregate">AUSMAT aggregate (optional)</label>
                        <p class="field__hint" id="ausmat_aggregate-hint">Your AUSMAT aggregate, from 0 to 100.</p>
                        <input
                            id="ausmat_aggregate"
                            name="ausmat_aggregate"
                            type="number"
                            inputmode="numeric"
                            step="1"
                            min="0"
                            max="100"
                            placeholder="e.g. 80"
                            value="{{ $ausmatAggregate }}"
                            @class(['input', 'input--invalid' => $errors->has('ausmat_aggregate')])
                            aria-describedby="ausmat_aggregate-hint"
                        >
                        @error('ausmat_aggregate')
                            <p class="field__error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field" data-section="cgpa">
                        <label class="field__label" for="cgpa">CGPA (PNGK)</label>
                        <p class="field__hint" id="cgpa-hint">A number from 0.00 to 4.00. Not needed for A-Level, IB or AUSMAT / SAM / CPU.</p>
                        <input
                            id="cgpa"
                            name="cgpa"
                            type="number"
                            inputmode="decimal"
                            step="0.01"
                            min="0"
                            max="4"
                            placeholder="e.g. 3.50"
                            value="{{ $cgpa }}"
                            @class(['input', 'input--invalid' => $errors->has('cgpa')])
                            aria-describedby="cgpa-hint"
                        >
                        @error('cgpa')
                            <p class="field__error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field" data-section="muet">
                        <label class="field__label" for="muet_band">MUET band</label>
                        <p class="field__hint" id="muet_band-hint">The band shown on your Malaysian University English Test result slip.</p>
                        <select id="muet_band" name="muet_band" @class(['input', 'input--invalid' => $errors->has('muet_band')]) aria-describedby="muet_band-hint">
                            <option value="{{ config('academic.no_muet') }}" data-placeholder @selected($selectedMuet === config('academic.no_muet'))>I haven't taken MUET</option>
                            @foreach (config('academic.muet_bands') as $band)
                                <option value="{{ $band }}" @selected($selectedMuet === $band)>Band {{ $band }}</option>
                            @endforeach
                        </select>
                        @error('muet_band')
                            <p class="field__error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field" data-section="stam">
                        <label class="field__label" for="stam_grade">STAM grade</label>
                        <p class="field__hint" id="stam_grade-hint">Your overall STAM grade.</p>
                        <select id="stam_grade" name="stam_grade" @class(['input', 'input--invalid' => $errors->has('stam_grade')]) aria-describedby="stam_grade-hint">
                            <option value="">Choose a grade</option>
                            @foreach (config('academic.stam_grades') as $grade)
                                <option value="{{ $grade }}" @selected($selectedStam === $grade)>{{ $grade }}</option>
                            @endforeach
                        </select>
                        @error('stam_grade')
                            <p class="field__error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </fieldset>

            {{-- Step 4: details some courses ask for besides results --}}
            <fieldset class="panel">
                <legend class="panel__title"><span class="panel__step">4</span> Other details (Optional)</legend>
                <p class="panel__text">Some courses ask for more than results. Leave a field blank if it doesn't apply to you.</p>

                <div class="field-row field-row--aligned">
                    <div class="field">
                        <label class="field__label" for="ielts_band">IELTS band</label>
                        <p class="field__hint" id="ielts_band-hint">Your band, if you have taken IELTS.</p>
                        <select id="ielts_band" name="ielts_band" @class(['input', 'input--narrow', 'input--invalid' => $errors->has('ielts_band')]) aria-describedby="ielts_band-hint">
                            <option value="">Choose a band</option>
                            @foreach (config('academic.ielts_bands') as $band)
                                <option value="{{ $band }}" @selected($selectedIelts === $band)>Band {{ $band }}</option>
                            @endforeach
                        </select>
                        @error('ielts_band')
                            <p class="field__error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label class="field__label" for="toefl_ibt_score">TOEFL iBT score</label>
                        <p class="field__hint" id="toefl_ibt_score-hint">Your score from 0 to 120, if you have taken it.</p>
                        <input
                            id="toefl_ibt_score"
                            name="toefl_ibt_score"
                            type="number"
                            inputmode="numeric"
                            step="1"
                            min="0"
                            max="120"
                            placeholder="e.g. 80"
                            value="{{ $toeflIbtScore }}"
                            @class(['input', 'input--narrow', 'input--invalid' => $errors->has('toefl_ibt_score')])
                            aria-describedby="toefl_ibt_score-hint"
                        >
                        @error('toefl_ibt_score')
                            <p class="field__error">{{ $message }}</p>
                        @enderror
                    </div>


                    <div class="field">
                        <label class="field__label" for="sports_level">Sports level</label>
                        <p class="field__hint" id="sports_level-hint">The highest level you have represented.</p>
                        <select id="sports_level" name="sports_level" @class(['input', 'input--narrow', 'input--invalid' => $errors->has('sports_level')]) aria-describedby="sports_level-hint">
                            <option value="">Choose a level</option>
                            @foreach (config('academic.sports_levels') as $value => $label)
                                <option value="{{ $value }}" @selected($selectedSports === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('sports_level')
                            <p class="field__error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label class="field__label" for="music_grade">Music grade</label>
                        <p class="field__hint" id="music_grade-hint">Your highest ABRSM or Trinity College London grade.</p>
                        <select id="music_grade" name="music_grade" @class(['input', 'input--narrow', 'input--invalid' => $errors->has('music_grade')]) aria-describedby="music_grade-hint">
                            <option value="">Choose a grade</option>
                            @foreach (config('academic.music_grades') as $value => $label)
                                <option value="{{ $value }}" @selected($selectedMusicGrade === (string) $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('music_grade')
                            <p class="field__error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label class="field__label" for="quran_juz">Quran memorisation (juz)</label>
                        <p class="field__hint" id="quran_juz-hint">The number of juz you have memorised, from 0 to 30.</p>
                        <input
                            id="quran_juz"
                            name="quran_juz"
                            type="number"
                            inputmode="numeric"
                            step="1"
                            min="0"
                            max="30"
                            placeholder="e.g. 30"
                            value="{{ $quranJuz }}"
                            @class(['input', 'input--narrow', 'input--invalid' => $errors->has('quran_juz')])
                            aria-describedby="quran_juz-hint"
                        >
                        @error('quran_juz')
                            <p class="field__error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </fieldset>

            <div class="form-actions">
                <button type="submit" class="button button--primary button--large">{{ $isEditing ? 'Save changes' : 'Save' }}</button>
                <a class="button button--ghost button--large" href="{{ $isEditing ? route('academic-profile.show') : route('home') }}">Cancel</a>
            </div>
        </form>
    </div>
</x-layout>
