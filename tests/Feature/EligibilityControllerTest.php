<?php

use App\ApplicantCategory;
use App\Http\Controllers\AcademicProfileController;
use App\Models\EligibilityRequirement;
use App\Models\HistoricalTrend;
use App\Models\Programme;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

beforeEach(function () {
    Programme::query()->truncate();
    EligibilityRequirement::query()->truncate();
    HistoricalTrend::query()->truncate();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function savedStpmProfile(array $overrides = []): array
{
    return array_merge([
        'applicant_category' => 'stpm_science_current_year',
        'gender' => 'male',
        'spm_stream' => null,
        'spm_subjects' => [
            ['subject' => 'Bahasa Melayu', 'grade' => 'A'],
            ['subject' => 'Sejarah', 'grade' => 'B'],
        ],
        'stpm_subjects' => [
            ['subject' => 'Pengajian Am', 'grade' => 'B'],
            ['subject' => 'Mathematics T', 'grade' => 'B'],
            ['subject' => 'Physics', 'grade' => 'C'],
        ],
        'cgpa' => 3.0,
        'muet_band' => '3.5',
        'stam_grade' => null,
        'saved_at' => now()->toIso8601String(),
    ], $overrides);
}

it('asks for results before checking courses', function () {
    $this->get(route('eligibility.index'))
        ->assertRedirect(route('academic-profile.create'));
});

it('groups courses by whether the applicant qualifies', function () {
    $eligible = Programme::factory()->create(['programme_name' => 'Bacelor Sains Komputer Dengan Kepujian']);
    $ineligible = Programme::factory()->create(['programme_name' => 'Bacelor Perubatan']);
    $unchecked = Programme::factory()->create(['programme_name' => 'Bacelor Kejuruteraan Awam']);

    EligibilityRequirement::factory()->create(['programme_code' => $eligible->programme_code]);
    EligibilityRequirement::factory()
        ->withSpecificConditions([
            ['condition_type' => 'subject_requirement', 'condition_group' => 'STPM', 'condition_count' => 1, 'minimum_grade' => 'A-', 'subject_list' => ['Biology']],
        ])
        ->create(['programme_code' => $ineligible->programme_code]);
    EligibilityRequirement::factory()
        ->withSpecificConditions([
            ['condition_type' => 'subject_group_requirement', 'condition_group' => 'STPM', 'condition_count' => 2, 'minimum_grade' => 'C', 'subject_list' => ['Mathematics T']],
        ])
        ->create(['programme_code' => $unchecked->programme_code]);

    $response = $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index'))
        ->assertOk()
        ->assertSee('3 courses open to STPM Science (current year)');

    $programmeCodes = fn (string $group): array => collect($response->viewData($group)->items())->pluck('programme.programme_code')->all();

    expect($programmeCodes('eligible'))->toBe([$eligible->programme_code])
        ->and($programmeCodes('ineligible'))->toBe([$ineligible->programme_code])
        ->and($programmeCodes('unchecked'))->toBe([$unchecked->programme_code]);

    $response->assertSee('Grade A- or better in STPM Biology')
        ->assertSee("This subject isn't in your results.")
        ->assertSee($eligible->officialPortalLink(ApplicantCategory::StpmScienceCurrentYear), false);
});

it('shows the full requirements of a course it qualifies for in a pop-up', function () {
    $programme = Programme::factory()->create();

    EligibilityRequirement::factory()
        ->withSpecificConditions([
            ['condition_type' => 'subject_requirement', 'condition_group' => 'STPM', 'condition_count' => 1, 'minimum_grade' => 'C', 'subject_list' => ['Physics']],
            'Lulus Temu Duga.',
        ])
        ->create(['programme_code' => $programme->programme_code]);

    $response = $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index'))
        ->assertOk();

    expect(collect($response->viewData('eligible')->items())->pluck('programme.programme_code')->all())->toBe([$programme->programme_code]);

    $response->assertSee('commandfor="requirements-'.$programme->programme_code.'"', false)
        ->assertSee('<dialog class="dialog" id="requirements-'.$programme->programme_code.'"', false)
        ->assertSeeInOrder([
            'Checked against your results',
            'Met',
            'Grade C or better in STPM Physics.',
            'Your grade: Physics: C.',
            'Not checked by this site',
            'Pass the interview.',
        ]);
});

it('lists requirements the site cannot check apart from the ones it checks', function () {
    $programme = Programme::factory()->create();

    EligibilityRequirement::factory()
        ->withSpecificConditions([
            ['condition_type' => 'subject_requirement', 'condition_group' => 'STPM', 'condition_count' => 1, 'minimum_grade' => 'C', 'subject_list' => ['Matapelajaran']],
            ['condition_type' => 'subject_requirement', 'condition_group' => 'STPM', 'condition_count' => 1, 'minimum_grade' => 'C', 'subject_list' => ['Physics']],
        ])
        ->create(['programme_code' => $programme->programme_code]);

    $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index'))
        ->assertOk()
        ->assertSeeInOrder([
            'Checked against your results',
            'Grade C or better in STPM Physics.',
            'Not checked by this site',
            'Check yourself',
            'Grade C or better in one STPM subject.',
        ]);
});

it('shows a choice between options as numbered options, each with its requirements', function () {
    $programme = Programme::factory()->create();

    EligibilityRequirement::factory()
        ->withSpecificConditions([[
            'condition_type' => 'alternative_requirements',
            'alternatives' => [
                [['condition_type' => 'subject_requirement', 'condition_group' => 'STPM', 'condition_count' => 1, 'minimum_grade' => 'A', 'subject_list' => ['Biology']]],
                [['condition_type' => 'subject_requirement', 'condition_group' => 'STPM', 'condition_count' => 1, 'minimum_grade' => 'A', 'subject_list' => ['Chemistry / Physics']]],
            ],
        ]])
        ->create(['programme_code' => $programme->programme_code]);

    $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index'))
        ->assertOk()
        ->assertSeeInOrder([
            'Meet one of these options:',
            'Option 1',
            'Grade A or better in STPM Biology.',
            "This subject isn't in your results.",
            'Option 2',
            'Grade A or better in one STPM subject: Chemistry / Physics.',
            'Your grade: Physics: C.',
        ])
        ->assertDontSee('Option 1 of 2');
});

it('only lists courses offered to the applicant category', function () {
    $offered = EligibilityRequirement::factory()->create();
    EligibilityRequirement::factory()->notOffered()->create();
    EligibilityRequirement::factory()->create(['target_category' => 'A']);
    EligibilityRequirement::factory()->create(['level' => 'spm', 'target_category' => 'S']);

    $response = $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index'))
        ->assertOk();

    expect($response->viewData('total'))->toBe(1)
        ->and($response->viewData('eligible')[0]['programme']->programme_code)->toBe($offered->programme_code);
});

it('shows each result group five courses to a page', function () {
    foreach (range(1, 7) as $number) {
        openCourse(['programme_name' => sprintf('Bacelor Kursus %02d', $number)]);
    }

    $response = $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index', ['search' => 'kursus', 'eligible_page' => 2]))
        ->assertOk()
        ->assertSee('6–7 of 7')
        ->assertSee(e(route('eligibility.index', ['search' => 'kursus', 'eligible_page' => 1]).'#eligible'), false);

    expect($response->viewData('eligible')->total())->toBe(7)
        ->and(collect($response->viewData('eligible')->items())->pluck('programme.programme_name')->all())
        ->toBe(['Bacelor Kursus 06', 'Bacelor Kursus 07']);
});

it('shows a message when no courses are open to the category', function () {
    $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index'))
        ->assertOk()
        ->assertSee('No courses found');
});

it('links courses with figures to their trends', function () {
    $withTrends = Programme::factory()->create();
    $withoutTrends = Programme::factory()->create();
    EligibilityRequirement::factory()->create(['programme_code' => $withTrends->programme_code]);
    EligibilityRequirement::factory()->create(['programme_code' => $withoutTrends->programme_code]);
    HistoricalTrend::factory()->create(['programme_code' => $withTrends->programme_code]);

    $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index'))
        ->assertOk()
        ->assertSee(route('trends.show', $withTrends->programme_code))
        ->assertDontSee(route('trends.show', $withoutTrends->programme_code));
});

/**
 * Create a course open to STPM Science applicants that the sample profile qualifies for.
 *
 * @param  array<string, mixed>  $attributes
 */
function openCourse(array $attributes = []): Programme
{
    $programme = Programme::factory()->create($attributes);
    EligibilityRequirement::factory()->create(['programme_code' => $programme->programme_code]);

    return $programme;
}

/**
 * The programme codes on the courses page for the given filters.
 *
 * @param  array<string, string>  $filters
 * @return list<string>
 */
function filteredCourseCodes(array $filters): array
{
    $response = test()->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index', $filters))
        ->assertOk();

    return collect([...$response->viewData('eligible'), ...$response->viewData('unchecked'), ...$response->viewData('ineligible')])
        ->pluck('programme.programme_code')
        ->sort()
        ->values()
        ->all();
}

it('filters courses by several criteria at once', function () {
    $match = openCourse(['programme_name' => 'Sarjana Muda Sains Komputer Dengan Kepujian', 'institution_name' => 'Universiti Putra Malaysia', 'campuses' => [['campus' => 'Kampus Serdang', 'state' => 'Selangor']]]);
    openCourse(['programme_name' => 'Sarjana Muda Sains Komputer Dengan Kepujian', 'institution_name' => 'Universiti Malaya']);
    openCourse(['programme_name' => 'Diploma Sains Komputer', 'institution_name' => 'Universiti Putra Malaysia', 'campuses' => [['campus' => 'Kampus Bintulu', 'state' => 'Sarawak']]]);
    openCourse(['programme_name' => 'Sarjana Muda Ekonomi Dengan Kepujian', 'institution_name' => 'Universiti Putra Malaysia']);

    expect(filteredCourseCodes([
        'search' => 'komputer  SAINS',
        'institution' => 'Universiti Putra Malaysia',
        'location' => 'Selangor',
    ]))->toBe([$match->programme_code]);

    $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index', ['search' => 'komputer']))
        ->assertSee('Showing 3 of 4 courses')
        ->assertSee('Clear filters');
});

it('offers only institution and location pairs that have courses', function () {
    openCourse(['institution_name' => 'Universiti Malaysia Terengganu', 'campuses' => [['campus' => 'Kampus Kuala Nerus', 'state' => 'Terengganu']]]);
    openCourse(['institution_name' => 'Universiti Teknologi MARA', 'campuses' => [['campus' => 'Kampus Jasin', 'state' => 'Melaka'], ['campus' => 'Kampus Dungun', 'state' => 'Terengganu']]]);
    openCourse(['institution_name' => 'Universiti Teknologi MARA', 'campuses' => [['campus' => 'Kampus Bukit Besi', 'state' => 'Terengganu']]]);
    openCourse(['institution_name' => 'Universiti Malaya', 'campuses' => [['campus' => 'Kampus Utama', 'state' => 'Wilayah Persekutuan Kuala Lumpur']]]);

    $page = fn (array $filters) => $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index', $filters))
        ->assertOk();
    $state = fn (string $state): array => ['value' => $state, 'label' => $state, 'isCampus' => false];
    $campus = fn (string $state, string $campus): array => ['value' => "{$state}|{$campus}", 'label' => $campus, 'isCampus' => true];

    // Without an institution, only the states
    $page([])
        ->assertViewHas('institutions', ['Universiti Malaya', 'Universiti Malaysia Terengganu', 'Universiti Teknologi MARA'])
        ->assertViewHas('locations', [$state('Melaka'), $state('Terengganu'), $state('Wilayah Persekutuan Kuala Lumpur')])
        ->assertViewHas('campusesByInstitution', [
            'Universiti Malaya' => ['Wilayah Persekutuan Kuala Lumpur' => ['Kampus Utama']],
            'Universiti Malaysia Terengganu' => ['Terengganu' => ['Kampus Kuala Nerus']],
            'Universiti Teknologi MARA' => ['Melaka' => ['Kampus Jasin'], 'Terengganu' => ['Kampus Bukit Besi', 'Kampus Dungun']],
        ]);

    // With an institution, each state followed by its campuses
    $page(['institution' => 'Universiti Teknologi MARA'])
        ->assertViewHas('locations', [
            $state('Melaka'), $campus('Melaka', 'Kampus Jasin'),
            $state('Terengganu'), $campus('Terengganu', 'Kampus Bukit Besi'), $campus('Terengganu', 'Kampus Dungun'),
        ]);

    $page(['location' => 'Terengganu'])
        ->assertViewHas('institutions', ['Universiti Malaysia Terengganu', 'Universiti Teknologi MARA']);

    $page(['location' => 'Terengganu|Kampus Dungun'])
        ->assertViewHas('institutions', ['Universiti Teknologi MARA']);
});

it('filters courses by state or by campus and shows each campus', function () {
    $twoStates = openCourse(['campuses' => [['campus' => 'Kampus Jasin', 'state' => 'Melaka'], ['campus' => 'Kampus Shah Alam', 'state' => 'Selangor']]]);
    $selangor = openCourse(['campuses' => [['campus' => 'Kampus Puncak Alam', 'state' => 'Selangor']]]);
    openCourse(['campuses' => [['campus' => 'Kampus Kota Kinabalu', 'state' => 'Sabah']]]);
    openCourse();

    expect(filteredCourseCodes(['location' => 'Selangor']))->toBe(collect([$twoStates->programme_code, $selangor->programme_code])->sort()->values()->all())
        ->and(filteredCourseCodes(['location' => 'Selangor|Kampus Puncak Alam']))->toBe([$selangor->programme_code])
        ->and(filteredCourseCodes(['location' => 'Selangor|Kampus Jasin']))->toBe([]);

    $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index'))
        ->assertSee('Kampus Jasin, Melaka · Kampus Shah Alam, Selangor');
});

it('searches course names and codes only', function () {
    $match = openCourse(['programme_name' => 'Sarjana Muda Sains Komputer Dengan Kepujian', 'institution_name' => 'Universiti Malaya', 'campuses' => [['campus' => 'Kampus Utama', 'state' => 'Wilayah Persekutuan Kuala Lumpur']]]);
    openCourse(['programme_name' => 'Sarjana Muda Ekonomi Dengan Kepujian', 'institution_name' => 'Universiti Malaya', 'campuses' => [['campus' => 'Kampus Utama', 'state' => 'Wilayah Persekutuan Kuala Lumpur']]]);

    expect(filteredCourseCodes(['search' => 'komputer']))->toBe([$match->programme_code])
        ->and(filteredCourseCodes(['search' => 'malaya']))->toBe([])
        ->and(filteredCourseCodes(['search' => 'kampus utama']))->toBe([])
        ->and(filteredCourseCodes(['search' => $match->programme_code]))->toBe([$match->programme_code])
        ->and(filteredCourseCodes(['search' => Str::lower($match->programme_code)]))->toBe([$match->programme_code]);
});

it('shows every result group whatever the old result filter says', function () {
    $eligible = openCourse();
    $ineligible = Programme::factory()->create();
    EligibilityRequirement::factory()
        ->withSpecificConditions([
            ['condition_type' => 'subject_requirement', 'condition_group' => 'STPM', 'condition_count' => 1, 'minimum_grade' => 'A-', 'subject_list' => ['Biology']],
        ])
        ->create(['programme_code' => $ineligible->programme_code]);

    expect(filteredCourseCodes(['result' => 'met']))->toBe(collect([$eligible->programme_code, $ineligible->programme_code])->sort()->values()->all());
});

it('filters courses by competition', function () {
    $veryHigh = openCourse();
    $low = openCourse();
    $withoutFigures = openCourse();
    HistoricalTrend::factory()->create(['programme_code' => $veryHigh->programme_code, 'session_offers' => ['2024/2025' => 1, '2025/2026' => 2]]);
    HistoricalTrend::factory()->create(['programme_code' => $low->programme_code, 'session_offers' => ['2024/2025' => 200, '2025/2026' => 250]]);

    expect(filteredCourseCodes(['competition' => 'very_high']))->toBe([$veryHigh->programme_code])
        ->and(filteredCourseCodes(['competition' => 'low']))->toBe([$low->programme_code])
        ->and(filteredCourseCodes(['competition' => 'none']))->toBe([$withoutFigures->programme_code]);
});

it('filters courses by whether they ask for an interview or test', function () {
    $withInterview = Programme::factory()->create();
    EligibilityRequirement::factory()->create([
        'programme_code' => $withInterview->programme_code,
        'specific_conditions_content' => ['Lulus Temu Duga'],
    ]);
    $withTest = Programme::factory()->create();
    EligibilityRequirement::factory()->create([
        'programme_code' => $withTest->programme_code,
        'specific_conditions_content' => ['Lulus Ujian Lukisan Yang Ditetapkan.'],
    ]);
    $withNeither = openCourse();

    expect(filteredCourseCodes(['interview_or_test' => 'required']))->toEqualCanonicalizing([$withInterview->programme_code, $withTest->programme_code])
        ->and(filteredCourseCodes(['interview_or_test' => 'none']))->toBe([$withNeither->programme_code])
        ->and(filteredCourseCodes([]))->toHaveCount(3);
});

it('tells whether the requirements ask for an interview or test', function (array $content, bool $mentionsInterviewOrTest) {
    $requirement = EligibilityRequirement::factory()->make(['specific_conditions_content' => $content]);

    expect($requirement->mentionsInterviewOrTest())->toBe($mentionsInterviewOrTest);
})->with([
    'test and/or interview' => [['Lulus Ujian DAN / ATAU Temu Duga Yang Ditetapkan Oleh Universiti Awam.'], true],
    'temuduga in a nested list' => [[['conditions' => ['Lulus Psikometrik DAN Temuduga Yang Ditetapkan.']]], true],
    'temu bual' => [['Temu Bual Ii) Calon Digalakkan Membawa Folio Hasil Kerja Seni.'], true],
    'drawing test' => [['Lulus Ujian Lukisan DAN Cemerlang Dalam Portfolio.'], true],
    'MEdSI test' => [['Melepasi Tahap Minimum Ujian Malaysian Educators Selection Inventory (Medsi).'], true],
    'no interview needed' => [['Lulus Diploma Perancangan Bandar DAN Wilayah UITM (Tidak Perlu Temu Duga);'], false],
    'MUET results date' => [['BAND 2.0 Dalam MUET Mengikut Tempoh Sah Lakunya Ialah 5 Tahun Mulai Tarikh Keputusan Ujian Dikeluarkan.'], false],
    'English test' => [['Ujian Ielts (Academic)/ Score 5.5'], false],
    'medical check' => [['Calon Tidak Buta Warna DAN Lulus Ujian Kesihatan.'], false],
    'honours degree' => [['Sarjana Muda Sains Dengan Kepujian'], false],
    'no mention' => [['Gred C Bagi Matapelajaran Pengajian Am.'], false],
]);

it('uses the STAM figures for STAM applicants', function () {
    $programme = Programme::factory()->create();
    EligibilityRequirement::factory()->create(['programme_code' => $programme->programme_code, 'target_category' => 'T']);
    HistoricalTrend::factory()->create(['programme_code' => $programme->programme_code, 'session_offers' => ['2024/2025' => 1, '2025/2026' => 2]]);
    HistoricalTrend::factory()->stam()->create(['programme_code' => $programme->programme_code, 'session_offers' => ['2024/2025' => 200, '2025/2026' => 250]]);

    $response = $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile(['applicant_category' => 'stam_current_year', 'stam_grade' => 'Mumtaz'])])
        ->get(route('eligibility.index', ['competition' => 'low']))
        ->assertOk();

    expect($response->viewData('shown'))->toBe(1);
});

it('says when no courses match the filters', function () {
    openCourse(['programme_name' => 'Sarjana Muda Ekonomi Dengan Kepujian']);

    $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index', ['search' => 'perubatan']))
        ->assertOk()
        ->assertSee('No courses match these filters')
        ->assertSee('Showing 0 of 1 courses');
});

it('ignores filter values that are not valid', function () {
    $programme = openCourse();

    expect(filteredCourseCodes(['competition' => 'extreme']))->toBe([$programme->programme_code]);
});

it('shows e-Panduan wording in English when it has been translated', function () {
    $programme = Programme::factory()->create();

    EligibilityRequirement::factory()
        ->withSpecificConditions(['Lulus Ujian DAN / ATAU Temu Duga Yang Ditetapkan Oleh Universiti Awam.'])
        ->create(['programme_code' => $programme->programme_code]);

    $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index'))
        ->assertOk()
        ->assertSee('Pass the test and/or interview set by the university.')
        ->assertDontSee('Lulus Ujian DAN / ATAU Temu Duga');
});

it('shows a long translated line as a first line with a list under it', function () {
    app('translator')->addLines(['*.Lulus Ujian Bakat DAN Temu Duga' => "All of these:\nPass an audition\nPass an interview"], 'en');
    $programme = Programme::factory()->create();

    EligibilityRequirement::factory()
        ->withSpecificConditions(['Lulus Ujian Bakat DAN Temu Duga'])
        ->create(['programme_code' => $programme->programme_code]);

    $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index'))
        ->assertOk()
        ->assertSeeInOrder([
            'All of these:',
            '<ul class="wording-list">',
            '<li>Pass an audition</li>',
            '<li>Pass an interview</li>',
        ], false);
});

it('shows a note in English whatever its "Catatan:" or ending punctuation', function (string $note) {
    $programme = Programme::factory()->create();

    EligibilityRequirement::factory()
        ->withSpecificConditions([$note])
        ->create(['programme_code' => $programme->programme_code]);

    $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index'))
        ->assertOk()
        ->assertSee('No visual impairment.')
        ->assertDontSee('Ketidakupayaan');
})->with([
    'Calon Tidak Mempunyai Ketidakupayaan Penglihatan.',
    'Nota: Calon Tidak Mempunyai Ketidakupayaan Penglihatan;',
    'Catatan: Calon Tidak Mempunyai Ketidakupayaan Penglihatan',
]);

it('recommends similar courses for a course the applicant does not qualify for', function () {
    $requirement = fn (string $code, string $name, array $specificConditions = []) => EligibilityRequirement::factory()
        ->withSpecificConditions($specificConditions)
        ->create(['programme_code' => Programme::factory()->create(['programme_code' => $code, 'programme_name' => $name])->programme_code]);
    $notMet = [['condition_type' => 'subject_requirement', 'condition_group' => 'STPM', 'condition_count' => 1, 'minimum_grade' => 'A-', 'subject_list' => ['Biology']]];
    $unchecked = [['condition_type' => 'unparsed_text', 'text' => 'Calon Tidak Buta Warna Dan Lulus Ujian Kesihatan']];
    $competition = fn (string $code, int $offers) => HistoricalTrend::factory()->create([
        'programme_code' => $code,
        'session_applications' => ['2025/2026' => 100],
        'session_offers' => ['2025/2026' => $offers],
    ]);

    $requirement('UM6481001', 'Bacelor Sains Komputer', $notMet);
    $requirement('UA6481009', 'Sarjana Muda Sains Komputer');
    $requirement('UE6481002', 'Ijazah Sarjana Muda Sains Komputer');
    $requirement('UB6481007', 'Bachelor Of Computer Science');
    $requirement('UC6481003', 'Bacelor Sains Komputer (Rangkaian)', $unchecked);
    $requirement('UD6481004', 'Bacelor Sains Komputer (Sains Data)', $notMet);
    $requirement('UD6481005', 'Bacelor Sains Kimia');
    $requirement('UD6314001', 'Bacelor Ekonomi');
    $requirement('UE4481001', 'Diploma Sains Komputer');
    $competition('UA6481009', 10);
    $competition('UE6481002', 50);

    $response = $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index'))
        ->assertOk();

    $alternatives = collect($response->viewData('ineligible')->items())->firstWhere('programme.programme_code', 'UM6481001')['alternatives'];
    $codes = fn (LengthAwarePaginator $courses): array => array_map(fn (array $course): string => $course['programme']->programme_code, $courses->items());

    // The same name first, with less competition first, then the same name in English
    expect($codes($alternatives['qualify']))->toBe(['UE6481002', 'UA6481009', 'UB6481007'])
        ->and($codes($alternatives['check-yourself']))->toBe(['UC6481003']);

    $response->assertSeeInOrder(['Similar courses you qualify for', 'Ijazah Sarjana Muda Sains Komputer', 'Low competition', 'Sarjana Muda Sains Komputer', 'High competition', 'Bachelor Of Computer Science', 'No competition figures'])
        ->assertSeeInOrder(['Similar courses to check yourself', 'Bacelor Sains Komputer (Rangkaian)'])
        ->assertSee('href="'.route('trends.show', 'UE6481002').'" title="See admission trends">Low competition', false)
        ->assertSee('<span class="alternative-list__name">Ijazah Sarjana Muda Sains Komputer</span>', false);

    // A similar course has a heart on its own card and in the pop-ups of the courses the applicant doesn't qualify for or has to check
    expect(substr_count($response->getContent(), 'data-add-url="'.route('favourites.store', 'UE6481002').'"'))->toBe(4);
});

it('says when no similar course is open to the applicant', function () {
    EligibilityRequirement::factory()
        ->withSpecificConditions([['condition_type' => 'subject_requirement', 'condition_group' => 'STPM', 'condition_count' => 1, 'minimum_grade' => 'A-', 'subject_list' => ['Biology']]])
        ->create(['programme_code' => Programme::factory()->create(['programme_code' => 'UM6723001', 'programme_name' => 'Bacelor Kejururawatan'])->programme_code]);

    $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index'))
        ->assertSee("You don't qualify for any course like this one.", false)
        ->assertDontSee('Similar courses to check yourself');
});

it('lets a similar course open its own requirements', function () {
    $requirement = fn (string $code, string $name, array $specificConditions = []) => EligibilityRequirement::factory()
        ->withSpecificConditions($specificConditions)
        ->create(['programme_code' => Programme::factory()->create(['programme_code' => $code, 'programme_name' => $name])->programme_code]);

    $requirement('UM6481001', 'Bacelor Sains Komputer', [['condition_type' => 'subject_requirement', 'condition_group' => 'STPM', 'condition_count' => 1, 'minimum_grade' => 'A-', 'subject_list' => ['Biology']]]);
    $requirement('UE6481002', 'Ijazah Sarjana Muda Sains Komputer', [['condition_type' => 'subject_requirement', 'condition_group' => 'STPM', 'condition_count' => 1, 'minimum_grade' => 'C', 'subject_list' => ['Physics']]]);

    $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index'))
        ->assertSee('data-requirements-url="'.route('eligibility.requirements', 'UE6481002').'"', false);

    $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.requirements', 'UE6481002'))
        ->assertOk()
        ->assertSee('id="similar-requirements-UE6481002"', false)
        ->assertSeeInOrder(['Ijazah Sarjana Muda Sains Komputer', 'Checked against your results', 'Physics', 'View on e-Panduan'])
        ->assertDontSee('<html', false)
        ->assertDontSee('Similar courses you qualify for');
});

it('only opens the requirements of a course open to the applicant', function () {
    $programme = Programme::factory()->create(['programme_code' => 'UM6481001']);
    EligibilityRequirement::factory()->create(['programme_code' => $programme->programme_code, 'target_category' => 'T']);

    $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.requirements', 'UM6481001'))
        ->assertNotFound();
});

it('asks for results before opening the requirements of a course', function () {
    Programme::factory()->create(['programme_code' => 'UM6481001']);

    $this->get(route('eligibility.requirements', 'UM6481001'))
        ->assertRedirect(route('academic-profile.create'));
});

it('shows the merit score e-Panduan gives the applicant\'s category beside the competition', function () {
    $scored = Programme::factory()->create(['programme_code' => 'UM6481001', 'programme_name' => 'Bacelor Sains Komputer', 'average_merit_scores' => ['S' => 88.5, 'T' => 70.0]]);
    $unscored = Programme::factory()->create(['programme_code' => 'UE6481002', 'programme_name' => 'Ijazah Sarjana Muda Sains Komputer', 'average_merit_scores' => ['T' => 70.0]]);
    EligibilityRequirement::factory()->create(['programme_code' => $scored->programme_code]);
    EligibilityRequirement::factory()->create(['programme_code' => $unscored->programme_code]);
    HistoricalTrend::factory()->create(['programme_code' => $scored->programme_code, 'session_applications' => ['2025/2026' => 100], 'session_offers' => ['2025/2026' => 50]]);

    $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index'))
        ->assertSeeInOrder(['Bacelor Sains Komputer', 'Competition', 'Low', 'Merit score: 88.50', 'Ijazah Sarjana Muda Sains Komputer', 'No merit score'])
        ->assertDontSee('70.00');
});

it('shows the merit scores of similar courses', function () {
    $programme = fn (string $code, string $name, array $specificConditions = []) => EligibilityRequirement::factory()
        ->withSpecificConditions($specificConditions)
        ->create(['programme_code' => Programme::factory()->create(['programme_code' => $code, 'programme_name' => $name, 'average_merit_scores' => ['S' => 91.25]])->programme_code]);

    $programme('UM6481001', 'Bacelor Sains Komputer', [['condition_type' => 'subject_requirement', 'condition_group' => 'STPM', 'condition_count' => 1, 'minimum_grade' => 'A-', 'subject_list' => ['Biology']]]);
    $programme('UE6481002', 'Ijazah Sarjana Muda Sains Komputer');

    $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index'))
        ->assertSeeInOrder(['Similar courses you qualify for', 'Ijazah Sarjana Muda Sains Komputer', 'No competition figures', '· ', 'Merit score: 91.25']);
});

it('leaves out the merit score for applicants e-Panduan gives none to', function () {
    $programme = Programme::factory()->create(['average_merit_scores' => ['S' => 88.5]]);
    EligibilityRequirement::factory()->create(['programme_code' => $programme->programme_code, 'target_category' => 'G1']);

    $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile(['applicant_category' => 'diploma_public_university'])])
        ->get(route('eligibility.index'))
        ->assertOk()
        ->assertSee($programme->programme_name)
        ->assertDontSee('Merit score')
        ->assertDontSee('No merit score');
});

it('recommends similar courses for a course the applicant has to check the rest of', function () {
    $requirement = fn (string $code, string $name, array $specificConditions = []) => EligibilityRequirement::factory()
        ->withSpecificConditions($specificConditions)
        ->create(['programme_code' => Programme::factory()->create(['programme_code' => $code, 'programme_name' => $name])->programme_code]);
    $unchecked = [['condition_type' => 'unparsed_text', 'text' => 'Calon Tidak Buta Warna Dan Lulus Ujian Kesihatan']];

    $requirement('UM6481001', 'Bacelor Sains Komputer', $unchecked);
    $requirement('UE6481002', 'Ijazah Sarjana Muda Sains Komputer');
    $requirement('UC6481003', 'Bacelor Sains Komputer (Rangkaian)', $unchecked);

    $response = $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index'))
        ->assertOk();

    $alternatives = collect($response->viewData('unchecked')->items())->firstWhere('programme.programme_code', 'UM6481001')['alternatives'];
    $codes = fn (LengthAwarePaginator $courses): array => array_map(fn (array $course): string => $course['programme']->programme_code, $courses->items());

    expect($codes($alternatives['qualify']))->toBe(['UE6481002'])
        ->and($codes($alternatives['check-yourself']))->toBe(['UC6481003']);
});

it('shows similar courses three to a page', function () {
    $requirement = fn (string $code, string $name, array $specificConditions = []) => EligibilityRequirement::factory()
        ->withSpecificConditions($specificConditions)
        ->create(['programme_code' => Programme::factory()->create(['programme_code' => $code, 'programme_name' => $name])->programme_code]);

    $requirement('UM6481001', 'Bacelor Sains Komputer', [['condition_type' => 'subject_requirement', 'condition_group' => 'STPM', 'condition_count' => 1, 'minimum_grade' => 'A-', 'subject_list' => ['Biology']]]);

    foreach (['UA', 'UB', 'UC', 'UD'] as $institution) {
        $requirement("{$institution}6481002", 'Sarjana Muda Sains Komputer');
    }

    $response = $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.index'));

    $qualify = collect($response->viewData('ineligible')->items())->firstWhere('programme.programme_code', 'UM6481001')['alternatives']['qualify'];

    expect($qualify->total())->toBe(4)
        ->and($qualify->items())->toHaveCount(3);

    $response->assertSee(route('eligibility.similar', ['programme' => 'UM6481001', 'list' => 'qualify', 'page' => 2]), false);

    $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.similar', ['programme' => 'UM6481001', 'list' => 'qualify', 'page' => 2]))
        ->assertOk()
        ->assertSee('data-similar-list', false)
        ->assertSee('4–4 of 4')
        ->assertSee('Sarjana Muda Sains Komputer')
        ->assertDontSee('<html', false);
});

it('only pages through the similar courses of a course open to the applicant', function () {
    Programme::factory()->create(['programme_code' => 'UM6481001']);

    $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get(route('eligibility.similar', ['programme' => 'UM6481001', 'list' => 'qualify']))
        ->assertNotFound();

    $this->withSession([AcademicProfileController::SESSION_KEY => savedStpmProfile()])
        ->get('/courses/UM6481001/similar/everything')
        ->assertNotFound();

    $this->flushSession()
        ->get(route('eligibility.similar', ['programme' => 'UM6481001', 'list' => 'qualify']))
        ->assertRedirect(route('academic-profile.create'));
});
