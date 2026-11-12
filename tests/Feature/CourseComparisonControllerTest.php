<?php

use App\Http\Controllers\AcademicProfileController;
use App\Http\Controllers\CourseComparisonController;
use App\Http\Controllers\FavouriteController;
use App\Models\EligibilityRequirement;
use App\Models\HistoricalTrend;
use App\Models\Programme;

beforeEach(function () {
    Programme::query()->truncate();
    EligibilityRequirement::query()->truncate();
    HistoricalTrend::query()->truncate();
});

/**
 * @return array<string, mixed>
 */
function comparingApplicantProfile(): array
{
    return [
        'applicant_category' => 'stpm_science_current_year',
        'gender' => 'male',
        'spm_subjects' => [['subject' => 'Bahasa Melayu', 'grade' => 'A'], ['subject' => 'Sejarah', 'grade' => 'B']],
        'stpm_subjects' => [['subject' => 'Pengajian Am', 'grade' => 'B'], ['subject' => 'Mathematics T', 'grade' => 'B'], ['subject' => 'Physics', 'grade' => 'C']],
        'cgpa' => 3.0,
        'muet_band' => '3.5',
    ];
}

it('compares favourite courses side by side', function () {
    $eligible = EligibilityRequirement::factory()->create();
    $ineligible = EligibilityRequirement::factory()
        ->withSpecificConditions([
            ['condition_type' => 'subject_requirement', 'condition_group' => 'STPM', 'condition_count' => 1, 'minimum_grade' => 'A-', 'subject_list' => ['Biology']],
        ])
        ->create();
    HistoricalTrend::factory()->create(['programme_code' => $eligible->programme_code]);
    $eligible->programme->update(['average_merit_scores' => ['S' => 85.5, 'T' => 70.0]]);
    $eligible->programme->update(['campuses' => [['campus' => 'Kampus Jasin', 'state' => 'Melaka']]]);

    $response = $this->withSession([
        AcademicProfileController::SESSION_KEY => comparingApplicantProfile(),
        FavouriteController::SESSION_KEY => [$eligible->programme_code, $ineligible->programme_code],
    ])
        ->get(route('favourites.compare', ['courses' => [$ineligible->programme_code, $eligible->programme_code]]))
        ->assertOk();

    // In the order they were added
    expect(collect($response->viewData('courses'))->pluck('programme.programme_code')->all())
        ->toBe([$eligible->programme_code, $ineligible->programme_code]);

    $response->assertSeeInOrder([$eligible->programme->programme_name, $ineligible->programme->programme_name])
        ->assertSee('You qualify')
        ->assertSee("You don't qualify")
        ->assertSee('Grade A- or better in STPM Biology')
        ->assertSee("This subject isn't in your results.")
        ->assertSee('85.50')
        ->assertSee('e-Panduan has no merit score for this course.')
        ->assertSee('No UPUOnline figures')
        ->assertSeeInOrder(['Location', 'Kampus Jasin, Melaka', 'Not listed']);
});

it('needs at least two courses to compare', function () {
    $requirement = EligibilityRequirement::factory()->create();

    $this->withSession([
        AcademicProfileController::SESSION_KEY => comparingApplicantProfile(),
        FavouriteController::SESSION_KEY => [$requirement->programme_code],
    ])
        ->get(route('favourites.compare', ['courses' => [$requirement->programme_code]]))
        ->assertRedirect(route('favourites.index'))
        ->assertSessionHasErrors(['courses' => 'Choose at least 2 courses to compare.']);
});

it('compares up to the maximum number of courses', function () {
    $codes = EligibilityRequirement::factory()->count(CourseComparisonController::MAXIMUM_COMPARED + 1)->create()->pluck('programme_code')->all();

    $this->withSession([
        AcademicProfileController::SESSION_KEY => comparingApplicantProfile(),
        FavouriteController::SESSION_KEY => $codes,
    ])
        ->get(route('favourites.compare', ['courses' => $codes]))
        ->assertRedirect(route('favourites.index'))
        ->assertSessionHasErrors(['courses' => 'You can compare up to '.CourseComparisonController::MAXIMUM_COMPARED.' courses at a time.']);
});

it('only compares favourite courses', function () {
    [$favourite, $notFavourite] = EligibilityRequirement::factory()->count(2)->create();

    $this->withSession([
        AcademicProfileController::SESSION_KEY => comparingApplicantProfile(),
        FavouriteController::SESSION_KEY => [$favourite->programme_code],
    ])
        ->get(route('favourites.compare', ['courses' => [$favourite->programme_code, $notFavourite->programme_code]]))
        ->assertSessionHasErrors(['courses.1' => 'Only favourite courses can be compared.']);
});

it('shows a favourite course that is not open to the applicant category', function () {
    $open = EligibilityRequirement::factory()->create();
    $closed = Programme::factory()->create();

    $this->withSession([
        AcademicProfileController::SESSION_KEY => comparingApplicantProfile(),
        FavouriteController::SESSION_KEY => [$open->programme_code, $closed->programme_code],
    ])
        ->get(route('favourites.compare', ['courses' => [$open->programme_code, $closed->programme_code]]))
        ->assertOk()
        ->assertSee('Not open to STPM Science (current year)')
        ->assertSee("This course doesn't take applicants from your category.", false);
});

it('asks for results before comparing', function () {
    [$first, $second] = Programme::factory()->count(2)->create();

    $this->withSession([FavouriteController::SESSION_KEY => [$first->programme_code, $second->programme_code]])
        ->get(route('favourites.compare', ['courses' => [$first->programme_code, $second->programme_code]]))
        ->assertRedirect(route('academic-profile.create'));
});
