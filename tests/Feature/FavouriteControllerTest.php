<?php

use App\ApplicantCategory;
use App\Http\Controllers\AcademicProfileController;
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
function favouritingApplicantProfile(): array
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

it('adds a course to favourites and returns to its place in the list', function () {
    $programme = Programme::factory()->create();

    $this->from(route('eligibility.index', ['search' => 'sains']))
        ->post(route('favourites.store', $programme->programme_code))
        ->assertRedirect(route('eligibility.index', ['search' => 'sains']).'#course-'.$programme->programme_code)
        ->assertSessionHas(FavouriteController::SESSION_KEY, [$programme->programme_code]);
});

it('adds a course to favourites only once', function () {
    $programme = Programme::factory()->create();

    $this->withSession([FavouriteController::SESSION_KEY => [$programme->programme_code]])
        ->post(route('favourites.store', $programme->programme_code))
        ->assertSessionHas(FavouriteController::SESSION_KEY, [$programme->programme_code]);
});

it('limits how many courses can be favourites', function () {
    $programme = Programme::factory()->create();
    $favourites = array_map(fn (int $number): string => "UX6{$number}", range(100001, 100000 + FavouriteController::MAXIMUM_FAVOURITES));

    $this->withSession([FavouriteController::SESSION_KEY => $favourites])
        ->post(route('favourites.store', $programme->programme_code))
        ->assertSessionHas(FavouriteController::SESSION_KEY, $favourites)
        ->assertSessionHas('status', 'You can have up to '.FavouriteController::MAXIMUM_FAVOURITES.' favourites. Remove one to add another.');
});

it('removes a course from favourites', function () {
    [$kept, $removed] = Programme::factory()->count(2)->create();

    $this->withSession([FavouriteController::SESSION_KEY => [$kept->programme_code, $removed->programme_code]])
        ->delete(route('favourites.destroy', $removed->programme_code))
        ->assertSessionHas(FavouriteController::SESSION_KEY, [$kept->programme_code]);
});

it('answers the heart sent in the background with the new state instead of reloading the page', function () {
    [$kept, $added] = Programme::factory()->count(2)->create();

    $this->withSession([FavouriteController::SESSION_KEY => [$kept->programme_code]])
        ->postJson(route('favourites.store', $added->programme_code))
        ->assertOk()
        ->assertExactJson([
            'isFavourite' => true,
            'count' => 2,
            'message' => "Added {$added->programme_name} to your favourites.",
        ])
        ->assertSessionMissing('status');

    $this->deleteJson(route('favourites.destroy', $added->programme_code))
        ->assertOk()
        ->assertJson(['isFavourite' => false, 'count' => 1])
        ->assertSessionHas(FavouriteController::SESSION_KEY, [$kept->programme_code]);
});

it('tells the heart sent in the background when the favourites are full', function () {
    $programme = Programme::factory()->create();
    $favourites = array_map(fn (int $number): string => "UX6{$number}", range(100001, 100000 + FavouriteController::MAXIMUM_FAVOURITES));

    $this->withSession([FavouriteController::SESSION_KEY => $favourites])
        ->postJson(route('favourites.store', $programme->programme_code))
        ->assertOk()
        ->assertExactJson([
            'isFavourite' => false,
            'count' => FavouriteController::MAXIMUM_FAVOURITES,
            'message' => 'You can have up to '.FavouriteController::MAXIMUM_FAVOURITES.' favourites. Remove one to add another.',
        ]);
});

it('lists the favourites with the applicant result for each', function () {
    $requirement = EligibilityRequirement::factory()->create();
    $closed = Programme::factory()->create(['programme_name' => 'Bacelor Seni Bina']);

    $this->withSession([
        AcademicProfileController::SESSION_KEY => favouritingApplicantProfile(),
        FavouriteController::SESSION_KEY => [$requirement->programme_code, $closed->programme_code],
    ])
        ->get(route('favourites.index'))
        ->assertOk()
        ->assertSeeInOrder([$requirement->programme->programme_name, 'You qualify', 'Bacelor Seni Bina', 'Not open to STPM Science (current year)']);
});

it('shows the favourites five to a page and keeps courses ticked on other pages', function () {
    $codes = Programme::factory()->count(7)->create()->pluck('programme_code')->all();

    $response = $this->withSession([
        AcademicProfileController::SESSION_KEY => favouritingApplicantProfile(),
        FavouriteController::SESSION_KEY => $codes,
    ])
        ->get(route('favourites.index', ['page' => 2, 'courses' => [$codes[0], $codes[6]]]))
        ->assertOk()
        ->assertSee('6–7 of 7')
        ->assertSee('<input type="hidden" name="courses[]" value="'.$codes[0].'">', false)
        ->assertSee('Also ticked on other pages: 1');

    expect(collect($response->viewData('courses')->items())->pluck('programme.programme_code')->all())->toBe([$codes[5], $codes[6]]);
});

it('goes to the last page when the page asked for has no courses', function () {
    $codes = Programme::factory()->count(6)->create()->pluck('programme_code')->all();

    $this->withSession([
        AcademicProfileController::SESSION_KEY => favouritingApplicantProfile(),
        FavouriteController::SESSION_KEY => array_slice($codes, 0, 5),
    ])
        ->get(route('favourites.index', ['page' => 2]))
        ->assertRedirect(route('favourites.index', ['page' => 1]));
});

it('shows the heart button for each course on the courses page', function () {
    $favourite = EligibilityRequirement::factory()->create();
    $notFavourite = EligibilityRequirement::factory()->create();

    $this->withSession([
        AcademicProfileController::SESSION_KEY => favouritingApplicantProfile(),
        FavouriteController::SESSION_KEY => [$favourite->programme_code],
    ])
        ->get(route('eligibility.index'))
        ->assertOk()
        ->assertSee(route('favourites.destroy', $favourite->programme_code))
        ->assertSee(route('favourites.store', $notFavourite->programme_code))
        ->assertSee('Remove from favourites')
        ->assertSee('Add to favourites');
});

it('forgets the favourites when the results are deleted', function () {
    $this->withSession([
        AcademicProfileController::SESSION_KEY => favouritingApplicantProfile(),
        FavouriteController::SESSION_KEY => ['UA6481001'],
    ])
        ->delete(route('academic-profile.destroy'))
        ->assertSessionMissing(FavouriteController::SESSION_KEY);
});

it('shows each favourite\'s campus, competition and merit score', function () {
    $programme = Programme::factory()->create([
        'programme_code' => 'UP6314001',
        'institution_name' => 'Universiti Putra Malaysia',
        'campuses' => [['campus' => 'Kampus Serdang', 'state' => 'Selangor']],
        'average_merit_scores' => ['S' => 99.43],
    ]);
    EligibilityRequirement::factory()->create(['programme_code' => $programme->programme_code]);
    HistoricalTrend::factory()->create(['programme_code' => $programme->programme_code, 'session_applications' => ['2025/2026' => 1000], 'session_offers' => ['2025/2026' => 10]]);

    $this->withSession([
        AcademicProfileController::SESSION_KEY => favouritingApplicantProfile(),
        FavouriteController::SESSION_KEY => [$programme->programme_code],
    ])
        ->get(route('favourites.index'))
        ->assertOk()
        ->assertSeeInOrder(['Universiti Putra Malaysia · UP6314001', '· Kampus Serdang, Selangor', 'Competition', 'Very high', 'Merit score: 99.43'])
        ->assertSee(route('trends.show', $programme->programme_code));
});

it('lets each favourite open its full requirements and its e-Panduan page', function () {
    $open = Programme::factory()->create(['programme_code' => 'UM6481001']);
    $closed = Programme::factory()->create(['programme_code' => 'UK6481002']);
    EligibilityRequirement::factory()->create(['programme_code' => $open->programme_code]);
    EligibilityRequirement::factory()->create(['programme_code' => $closed->programme_code, 'target_category' => 'T']);

    $content = $this->withSession([
        AcademicProfileController::SESSION_KEY => favouritingApplicantProfile(),
        FavouriteController::SESSION_KEY => [$open->programme_code, $closed->programme_code],
    ])
        ->get(route('favourites.index'))
        ->assertOk()
        ->assertSee('commandfor="requirements-UM6481001"', false)
        ->assertSee($open->officialPortalLink(ApplicantCategory::StpmScienceCurrentYear))
        ->assertDontSee('commandfor="requirements-UK6481002"', false)
        ->getContent();

    // The pop-up has its own close form, so it can't sit inside the compare form
    $compareFormEnd = strpos($content, '</form>', strpos($content, 'action="'.route('favourites.compare').'"'));

    expect(strpos($content, 'id="requirements-UM6481001"'))->toBeGreaterThan($compareFormEnd);
});
