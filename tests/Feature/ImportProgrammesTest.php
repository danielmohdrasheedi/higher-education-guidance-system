<?php

use App\ApplicantCategory;
use App\Models\EligibilityRequirement;
use App\Models\Programme;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    Programme::query()->truncate();
    EligibilityRequirement::query()->truncate();

    $this->folder = storage_path('framework/testing/e-panduan-'.uniqid());
});

afterEach(function () {
    File::deleteDirectory($this->folder);
});

/**
 * Write collector output for one level into the test folder.
 *
 * @param  list<array<string, string>>  $programmes
 * @param  list<array<string, mixed>>  $documents
 */
function writeCollectorOutput(string $folder, string $level, array $programmes, array $documents): void
{
    $levelFolder = $folder.DIRECTORY_SEPARATOR.strtoupper($level);
    File::ensureDirectoryExists($levelFolder);

    $csv = "\xEF\xBB\xBFinstitution_name,program_code,program_name\n";

    foreach ($programmes as $programme) {
        $csv .= "\"{$programme['institution_name']}\",{$programme['program_code']},\"{$programme['program_name']}\"\n";
    }

    File::put("{$levelFolder}/upuonline_guide_{$level}_data.csv", $csv);
    File::put(
        "{$levelFolder}/upuonline_guide_{$level}_all_requirements_data_progress.jsonl",
        implode("\n", array_map(fn (array $document): string => json_encode($document), $documents))."\n",
    );
}

/**
 * @return array<string, mixed>
 */
function collectedCategory(string $code, bool $isOffered = true, string $note = 'Diluluskan Oleh Senat UA.'): array
{
    return [
        'category_code' => $code,
        'is_offered' => $isOffered,
        'general_conditions_content' => [],
        'general_conditions_logic' => [['condition_group' => 'UMUM', 'conditions' => [['condition_type' => 'unparsed_text', 'text' => $note]]]],
        'specific_conditions_content' => [],
        'specific_conditions_logic' => [],
    ];
}

it('imports programmes and their requirements for each level', function () {
    writeCollectorOutput($this->folder, 'spm', [
        ['institution_name' => 'Universiti Malaysia Sabah', 'program_code' => 'UH0010003', 'program_name' => 'Asasi Agrisains'],
    ], [
        ['programme_code' => 'UH0010003', 'programme_categories' => [collectedCategory('A'), collectedCategory('B', false)]],
    ]);
    writeCollectorOutput($this->folder, 'stpm', [
        ['institution_name' => 'Universiti Putra Malaysia', 'program_code' => 'UP6314001', 'program_name' => 'Bacelor Ekonomi Dengan Kepujian'],
    ], [
        ['programme_code' => 'UP6314001', 'programme_categories' => [collectedCategory('S'), collectedCategory('A')]],
    ]);

    $this->artisan('programmes:import', ['path' => $this->folder])
        ->expectsOutput('SPM: 1 programmes, 2 entry requirement records.')
        ->expectsOutput('STPM: 1 programmes, 2 entry requirement records.')
        ->assertSuccessful();

    $programme = Programme::query()->where('programme_code', 'UH0010003')->sole();

    expect($programme->only(['programme_name', 'institution_name', 'level']))->toBe([
        'programme_name' => 'Asasi Agrisains',
        'institution_name' => 'Universiti Malaysia Sabah',
        'level' => 'spm',
    ])
        ->and($programme->requirements()->where('target_category', 'B')->sole()->is_offered)->toBeFalse()
        ->and(EligibilityRequirement::query()->where('level', 'stpm')->where('target_category', 'A')->count())->toBe(1);
});

it('adds the campuses from the campus collector', function () {
    writeCollectorOutput($this->folder, 'spm', [], []);
    writeCollectorOutput($this->folder, 'stpm', [
        ['institution_name' => 'Universiti Teknologi MARA', 'program_code' => 'UE6481005', 'program_name' => 'Ijazah Sarjana Muda Sains Komputer'],
        ['institution_name' => 'Universiti Putra Malaysia', 'program_code' => 'UP6314001', 'program_name' => 'Bacelor Ekonomi Dengan Kepujian'],
    ], []);
    File::ensureDirectoryExists("{$this->folder}/Campus");
    File::put("{$this->folder}/Campus/upuonline_guide_campus_data.json", json_encode([
        ['programme_code' => 'UE6481005', 'campuses' => [['campus' => 'Kampus Jasin', 'state' => 'Melaka'], ['campus' => 'Kampus Shah Alam', 'state' => 'Selangor']]],
    ]));

    $this->artisan('programmes:import', ['path' => $this->folder])
        ->expectsOutput('Campuses: 1 programmes.')
        ->assertSuccessful();

    $programme = Programme::query()->where('programme_code', 'UE6481005')->sole();

    expect($programme->states())->toBe(['Melaka', 'Selangor'])
        ->and($programme->locationLabel())->toBe('Kampus Jasin, Melaka · Kampus Shah Alam, Selangor')
        ->and(Programme::query()->where('programme_code', 'UP6314001')->sole()->campuses)->toBe([]);
});

it('adds the average merit score e-Panduan gives each applicant category', function () {
    writeCollectorOutput($this->folder, 'spm', [
        ['institution_name' => 'Universiti Malaysia Sabah', 'program_code' => 'UH0010003', 'program_name' => 'Asasi Agrisains'],
    ], []);
    writeCollectorOutput($this->folder, 'stpm', [
        ['institution_name' => 'Universiti Putra Malaysia', 'program_code' => 'UP6314001', 'program_name' => 'Bacelor Ekonomi Dengan Kepujian'],
    ], []);
    File::ensureDirectoryExists("{$this->folder}/Merit");
    File::put("{$this->folder}/Merit/upuonline_guide_merit_data.csv", implode("\n", [
        "\xEF\xBB\xBFlevel,category_code,programme_code,average_merit,year",
        'spm,A,UH0010003,79.59,2026',
        'stpm,S,UP6314001,99.43,2026',
        'stpm,A,UP6314001,98.1,2026',
        'stpm,T,UP6314001,,2026',
    ])."\n");

    $this->artisan('programmes:import', ['path' => $this->folder])
        ->expectsOutput('Merit scores: 3.')
        ->assertSuccessful();

    $stpmProgramme = Programme::query()->where('programme_code', 'UP6314001')->sole();

    expect($stpmProgramme->average_merit_scores)->toBe(['S' => 99.43, 'A' => 98.1])
        ->and($stpmProgramme->averageMeritScore(ApplicantCategory::StpmArtsCurrentYear))->toBe(98.1)
        ->and($stpmProgramme->averageMeritScore(ApplicantCategory::StamCurrentYear))->toBeNull()
        ->and($stpmProgramme->averageMeritScore(ApplicantCategory::DiplomaPublicUniversity))->toBeNull()
        ->and(Programme::query()->where('programme_code', 'UH0010003')->sole()->averageMeritScore(ApplicantCategory::SpmCurrentYear))->toBe(79.59);
});

it('leaves merit scores empty without the merit collector output', function () {
    writeCollectorOutput($this->folder, 'spm', [], []);
    writeCollectorOutput($this->folder, 'stpm', [
        ['institution_name' => 'Universiti Putra Malaysia', 'program_code' => 'UP6314001', 'program_name' => 'Bacelor Ekonomi Dengan Kepujian'],
    ], []);

    $this->artisan('programmes:import', ['path' => $this->folder])->assertSuccessful();

    expect(Programme::query()->where('programme_code', 'UP6314001')->sole()->average_merit_scores)->toBe([]);
});

it('keeps the last collected copy of a programme', function () {
    writeCollectorOutput($this->folder, 'spm', [], []);
    writeCollectorOutput($this->folder, 'stpm', [
        ['institution_name' => 'Universiti Putra Malaysia', 'program_code' => 'UP6314001', 'program_name' => 'Bacelor Ekonomi Dengan Kepujian'],
    ], [
        ['programme_code' => 'UP6314001', 'programme_categories' => [collectedCategory('S', note: 'First copy')]],
        ['programme_code' => 'UP6314001', 'programme_categories' => [collectedCategory('S', note: 'Second copy')]],
    ]);

    $this->artisan('programmes:import', ['path' => $this->folder])->assertSuccessful();

    $requirement = EligibilityRequirement::query()->sole();

    expect($requirement->general_conditions_logic[0]['conditions'][0]['text'])->toBe('Second copy');
});

it('replaces previously imported data', function () {
    Programme::factory()->create(['programme_code' => 'UX6000001']);
    writeCollectorOutput($this->folder, 'spm', [], []);
    writeCollectorOutput($this->folder, 'stpm', [], []);

    $this->artisan('programmes:import', ['path' => $this->folder])->assertSuccessful();

    expect(Programme::query()->count())->toBe(0);
});

it('fails without changing anything when a file is missing', function () {
    Programme::factory()->create();
    writeCollectorOutput($this->folder, 'spm', [], []);

    $this->artisan('programmes:import', ['path' => $this->folder])->assertFailed();

    expect(Programme::query()->count())->toBe(1);
});
