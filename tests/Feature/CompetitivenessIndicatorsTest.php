<?php

use App\Models\HistoricalTrend;
use App\Trends\Competitiveness;
use App\Trends\CompetitivenessIndicators;

/**
 * @param  array<string, mixed>  $attributes
 */
function trendRecord(array $attributes = []): HistoricalTrend
{
    return HistoricalTrend::factory()->make($attributes);
}

it('adds up the figures for every group of applicants', function () {
    $indicators = new CompetitivenessIndicators([
        trendRecord(['gender' => 'Female', 'session_applications' => ['2024/2025' => 600, '2025/2026' => 900], 'session_offers' => ['2024/2025' => 30, '2025/2026' => 20]]),
        trendRecord(['gender' => 'Male', 'session_applications' => ['2024/2025' => 400, '2025/2026' => 100], 'session_offers' => ['2024/2025' => 20, '2025/2026' => 5]]),
    ]);

    expect($indicators->sessions)->toBe([
        '2024/2025' => ['applications' => 1000, 'offers' => 50, 'offerRate' => 5.0],
        '2025/2026' => ['applications' => 1000, 'offers' => 25, 'offerRate' => 2.5],
    ])
        ->and($indicators->latestSession())->toBe('2025/2026')
        ->and($indicators->offerRate())->toBe(2.5)
        ->and($indicators->applicationsPerOffer())->toBe(40)
        ->and($indicators->applicationChange())->toBe(0.0)
        ->and($indicators->competitiveness())->toBe(Competitiveness::VeryHigh);
});

it('rates competitiveness by the share of applications that got an offer', function (float $offerRate, Competitiveness $expected) {
    expect(Competitiveness::fromOfferRate($offerRate))->toBe($expected);
})->with([
    'under 5%' => [4.9, Competitiveness::VeryHigh],
    '5%' => [5.0, Competitiveness::High],
    '15%' => [15.0, Competitiveness::Moderate],
    '35%' => [35.0, Competitiveness::Low],
]);

it('adds up the figures across sessions and choices', function () {
    $indicators = new CompetitivenessIndicators([trendRecord([
        'session_applications' => ['2024/2025' => 400, '2025/2026' => 500],
        'session_offers' => ['2024/2025' => 40, '2025/2026' => 50],
        'first_choice_applications' => 100,
        'first_choice_offers' => 30,
        'lower_choice_applications' => 800,
        'lower_choice_offers' => 60,
    ])]);

    expect($indicators->applicationChange())->toBe(25.0)
        ->and($indicators->firstChoiceOfferRate())->toBe(30.0)
        ->and($indicators->lowerChoiceOfferRate())->toBe(7.5);
});

it('uses the latest session that had applications', function () {
    $indicators = new CompetitivenessIndicators([trendRecord([
        'session_applications' => ['2024/2025' => 300, '2025/2026' => 0],
        'session_offers' => ['2024/2025' => 0, '2025/2026' => 0],
    ])]);

    expect($indicators->latestSession())->toBe('2024/2025')
        ->and($indicators->applicationChange())->toBeNull()
        ->and($indicators->competitiveness())->toBeNull()
        ->and($indicators->applications())->toBe(300)
        ->and($indicators->offers())->toBe(0);
});

it('says when there are no figures', function () {
    $indicators = new CompetitivenessIndicators([]);

    expect($indicators->hasFigures())->toBeFalse()
        ->and($indicators->segments())->toBe([])
        ->and($indicators->applications())->toBe(0);
});

it('lists each group of applicants with the most applications first', function () {
    $indicators = new CompetitivenessIndicators([
        trendRecord(['group' => 'Arts', 'gender' => 'Male', 'session_applications' => ['2025/2026' => 10], 'session_offers' => ['2025/2026' => 1]]),
        trendRecord(['group' => 'Science', 'gender' => 'Female', 'session_applications' => ['2025/2026' => 90], 'session_offers' => ['2025/2026' => 9]]),
    ]);

    expect($indicators->segments())->toBe([
        ['label' => 'Science · Female', 'applications' => 90, 'offers' => 9, 'offerRate' => 10.0],
        ['label' => 'Arts · Male', 'applications' => 10, 'offers' => 1, 'offerRate' => 10.0],
    ]);
});
