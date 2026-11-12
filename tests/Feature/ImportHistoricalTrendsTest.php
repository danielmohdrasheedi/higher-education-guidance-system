<?php

use App\Models\HistoricalTrend;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    HistoricalTrend::query()->truncate();

    $this->folder = storage_path('framework/testing/upuonline-'.uniqid());
    File::ensureDirectoryExists($this->folder);
});

afterEach(function () {
    File::deleteDirectory($this->folder);
});

const SPM_TREND_HEADER = 'institution_name,programme_code,programme_name,group,stream,gender,average_merit,applications_session_2425,applications_session_2526,offers_session_2425,offers_session_2526,applications_choice_1,applications_choice_2,applications_choice_3,applications_other_choices,offers_choice_1,offers_choice_2,offers_choice_3,offers_other_choices';

const STPM_TREND_HEADER = 'institution_name,programme_code,programme_name,category,group,gender,average_merit,applications_session_2425,applications_session_2526,offers_session_2425,offers_session_2526,applications_choice_1,applications_choice_2,applications_choice_3,applications_other_choices,offers_choice_1,offers_choice_2,offers_choice_3,offers_other_choices';

/**
 * @param  list<string>  $rows
 */
function writeTrendFile(string $folder, string $level, string $header, array $rows): void
{
    File::put("{$folder}/upuonline_{$level}_admission_data.csv", "\xEF\xBB\xBF".$header."\n".implode("\n", $rows)."\n");
}

it('imports the figures for each group of applicants', function () {
    writeTrendFile($this->folder, 'spm', SPM_TREND_HEADER, [
        'Politeknik,FB4344001,Diploma Akauntansi,Arts,KSI,Female,62.64,8644,10824,1985,1574,2552,2850,3104,15984,1386,637,446,1090',
    ]);
    writeTrendFile($this->folder, 'stpm', STPM_TREND_HEADER, [
        'Universiti Putra Malaysia,UP6314001,Bacelor Ekonomi Dengan Kepujian,STPM/Matrikulasi/Asasi,Science,Female,99.43,70,111,,3,7,6,8,160,0,0,0,3',
        'Universiti Putra Malaysia,UP6314001,Bacelor Ekonomi Dengan Kepujian,STAM,STAM,Male,84.4,5,6,1,2,2,1,1,7,1,0,0,2',
    ]);

    $this->artisan('trends:import', ['path' => $this->folder])
        ->expectsOutput('SPM: 1 records for 1 programmes.')
        ->expectsOutput('STPM: 2 records for 1 programmes.')
        ->assertSuccessful();

    $spm = HistoricalTrend::query()->where('programme_code', 'FB4344001')->sole();

    expect($spm->only(['level', 'applicant_type', 'group', 'stream', 'gender', 'average_merit_score', 'session_applications', 'session_offers']))->toBe([
        'level' => 'spm',
        'applicant_type' => null,
        'group' => 'Arts',
        'stream' => 'KSI',
        'gender' => 'Female',
        'average_merit_score' => 62.64,
        'session_applications' => ['2024/2025' => 8644, '2025/2026' => 10824],
        'session_offers' => ['2024/2025' => 1985, '2025/2026' => 1574],
    ])
        ->and($spm->first_choice_applications)->toBe(2552)
        ->and($spm->first_choice_offers)->toBe(1386)
        ->and($spm->lower_choice_applications)->toBe(2850 + 3104 + 15984)
        ->and($spm->lower_choice_offers)->toBe(637 + 446 + 1090);

    $stpm = HistoricalTrend::query()->where('applicant_type', HistoricalTrend::STPM_APPLICANTS)->sole();

    expect($stpm->stream)->toBeNull()
        ->and($stpm->session_offers)->toBe(['2024/2025' => 0, '2025/2026' => 3]);
});

it('keeps one record when a programme is listed under two names', function () {
    writeTrendFile($this->folder, 'spm', SPM_TREND_HEADER, [
        'Politeknik,FB4344001,Diploma Akauntansi,Arts,KSI,Female,62.64,8644,10824,1985,1574,2552,2850,3104,15984,1386,637,446,1090',
        'Politeknik,FB4344001,Diploma Perakaunan,Arts,KSI,Female,62.64,8644,10824,1985,1574,2552,2850,3104,15984,1386,637,446,1090',
    ]);
    writeTrendFile($this->folder, 'stpm', STPM_TREND_HEADER, []);

    $this->artisan('trends:import', ['path' => $this->folder])
        ->expectsOutput('SPM: 1 records for 1 programmes.')
        ->assertSuccessful();

    expect(HistoricalTrend::query()->count())->toBe(1);
});

it('replaces the figures imported before', function () {
    HistoricalTrend::factory()->create(['programme_code' => 'UX6000001']);
    writeTrendFile($this->folder, 'spm', SPM_TREND_HEADER, []);
    writeTrendFile($this->folder, 'stpm', STPM_TREND_HEADER, []);

    $this->artisan('trends:import', ['path' => $this->folder])->assertSuccessful();

    expect(HistoricalTrend::query()->count())->toBe(0);
});

it('fails without changing anything when a file is missing', function () {
    HistoricalTrend::factory()->create();
    writeTrendFile($this->folder, 'spm', SPM_TREND_HEADER, []);

    $this->artisan('trends:import', ['path' => $this->folder])
        ->expectsOutputToContain('upuonline_stpm_admission_data.csv')
        ->assertFailed();

    expect(HistoricalTrend::query()->count())->toBe(1);
});
