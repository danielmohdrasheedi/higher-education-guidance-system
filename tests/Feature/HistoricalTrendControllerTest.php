<?php

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

it('shows a programme\'s admission trends and competitiveness', function () {
    $programme = Programme::factory()->create(['programme_name' => 'Bacelor Ekonomi Dengan Kepujian', 'average_merit_scores' => ['S' => 99.43]]);
    HistoricalTrend::factory()->create([
        'programme_code' => $programme->programme_code,
        'average_merit_score' => 50,
        'session_applications' => ['2024/2025' => 2139, '2025/2026' => 2155],
        'session_offers' => ['2024/2025' => 33, '2025/2026' => 26],
    ]);

    $this->get(route('trends.show', $programme->programme_code))
        ->assertOk()
        ->assertSee('Bacelor Ekonomi Dengan Kepujian')
        ->assertSeeInOrder(['Competition', 'Very high', 'Offer rate in 2025/2026', '1.2%', '26 of 2,155 applications got an offer, about 1 in 83.', 'Average merit score', '99.43', 'Out of 100, which is very high.'])
        ->assertSeeInOrder(['Applications and offers by session', '2024/2025', '2,139 applications', '33 offers, 1.5%', '2025/2026', '2,155 applications', '26 offers, 1.2%'])
        ->assertDontSee('Applications stayed about the same')
        ->assertDontSee('Applications per offer')
        ->assertDontSee('Figures by session')
        ->assertDontSee('View on e-Panduan');
});

it('shows STAM figures to STAM applicants', function () {
    $programme = Programme::factory()->create(['average_merit_scores' => ['S' => 91.25, 'T' => 84.4]]);
    HistoricalTrend::factory()->create(['programme_code' => $programme->programme_code]);
    HistoricalTrend::factory()->stam()->create(['programme_code' => $programme->programme_code]);

    $this->withSession([AcademicProfileController::SESSION_KEY => ['applicant_category' => 'stam_current_year']])
        ->get(route('trends.show', $programme->programme_code))
        ->assertOk()
        ->assertSee('84.40')
        ->assertDontSee('91.25')
        ->assertSeeInOrder([$programme->institution_name.' · '.$programme->programme_code, 'class="text-link"', 'View on e-Panduan'], false);

    $this->get(route('trends.show', ['programme' => $programme->programme_code, 'applicants' => 'stpm']))
        ->assertSee('91.25')
        ->assertDontSee('84.40');
});

it('shows the merit score e-Panduan gives the applicant\'s category', function () {
    $programme = Programme::factory()->create(['average_merit_scores' => ['S' => 91.25, 'N' => 88.5]]);
    HistoricalTrend::factory()->create(['programme_code' => $programme->programme_code]);

    $this->withSession([AcademicProfileController::SESSION_KEY => ['applicant_category' => 'matriculation_or_foundation_science']])
        ->get(route('trends.show', $programme->programme_code))
        ->assertSeeInOrder(['Average merit score', '88.50', 'Out of 100, which is high.'])
        ->assertDontSee('91.25');
});

it('says when e-Panduan gives no merit score', function (array $scores, ?string $category, string $note) {
    $programme = Programme::factory()->create(['average_merit_scores' => $scores]);
    HistoricalTrend::factory()->create(['programme_code' => $programme->programme_code]);

    $this->withSession($category === null ? [] : [AcademicProfileController::SESSION_KEY => ['applicant_category' => $category]])
        ->get(route('trends.show', $programme->programme_code))
        ->assertSeeInOrder(['Average merit score', '–', $note], false);
})->with([
    'to diploma holders' => [['S' => 91.25], 'diploma_public_university', 'e-Panduan doesn&#039;t give a merit score for Diploma (public university) applicants.'],
    'for the course' => [[], null, 'e-Panduan has no merit score for this course.'],
]);

it('says when a programme has no figures', function () {
    $programme = Programme::factory()->create();

    $this->get(route('trends.show', $programme->programme_code))
        ->assertOk()
        ->assertSee('No admission figures');
});

it('returns not found for an unknown programme', function () {
    $this->get(route('trends.show', 'XX0000000'))->assertNotFound();
});

it('lets the course be added to or removed from favourites', function () {
    $programme = Programme::factory()->create(['programme_code' => 'UM6481001', 'programme_name' => 'Bacelor Sains Komputer']);

    $this->get(route('trends.show', $programme->programme_code))
        ->assertOk()
        ->assertSee('action="'.route('favourites.store', $programme->programme_code).'"', false)
        ->assertSee('Add Bacelor Sains Komputer');

    $this->withSession([FavouriteController::SESSION_KEY => [$programme->programme_code]])
        ->get(route('trends.show', $programme->programme_code))
        ->assertSee('favourite-button is-favourite', false)
        ->assertSee('Remove Bacelor Sains Komputer');
});

it('shows the campuses the course is offered at', function () {
    $programme = Programme::factory()->create([
        'programme_code' => 'UP6421002',
        'institution_name' => 'Universiti Putra Malaysia',
        'campuses' => [['campus' => 'Kampus Serdang', 'state' => 'Selangor'], ['campus' => 'Kampus Bintulu', 'state' => 'Sarawak']],
    ]);

    $this->get(route('trends.show', $programme->programme_code))
        ->assertSeeInOrder(['Universiti Putra Malaysia · UP6421002', '· Kampus Serdang, Selangor · Kampus Bintulu, Sarawak']);
});
