<?php

namespace App\Http\Controllers;

use App\ApplicantCategory;
use App\Models\HistoricalTrend;
use App\Models\Programme;
use App\Trends\CompetitivenessIndicators;
use App\Trends\MeritScore;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * FR07 Historical Trend & Competitiveness Indicators.
 *
 * Shows a programme's past UPUOnline applications and offers, and the average merit score e-Panduan gives it.
 * STPM-level programmes have separate figures for STPM, matriculation and foundation
 * applicants and for STAM applicants, so the page shows one applicant type at a time.
 */
class HistoricalTrendController extends Controller
{
    private const APPLICANT_TYPES = [
        'stpm' => HistoricalTrend::STPM_APPLICANTS,
        'stam' => HistoricalTrend::STAM_APPLICANTS,
    ];

    public function show(Request $request, Programme $programme): View
    {
        $profile = $request->session()->get(AcademicProfileController::SESSION_KEY);
        $category = $profile === null ? null : ApplicantCategory::from($profile['applicant_category']);

        $trends = HistoricalTrend::query()
            ->where('programme_code', $programme->programme_code)
            ->get();

        $applicantTypes = array_intersect(self::APPLICANT_TYPES, $trends->pluck('applicant_type')->filter()->unique()->all());
        $applicantType = $this->applicantType($request, $category, $applicantTypes);

        if ($applicantType !== null) {
            $trends = $trends->where('applicant_type', self::APPLICANT_TYPES[$applicantType]);
        }

        return view('trends.show', [
            'programme' => $programme,
            'category' => $category,
            'indicators' => new CompetitivenessIndicators($trends->values()->all()),
            'applicantTypes' => $applicantTypes,
            'applicantType' => $applicantType,
            'isFavourite' => in_array($programme->programme_code, FavouriteController::favouriteCodes($request), true),
            'meritScore' => MeritScore::for($programme, $this->meritCategory($programme, $category, $applicantType)),
        ]);
    }

    /**
     * The applicant's own category when it fits the programme and the applicant type shown. Otherwise the current-year
     * category of the programme's level: SPM, STAM or STPM Science.
     */
    private function meritCategory(Programme $programme, ?ApplicantCategory $category, ?string $applicantType): ApplicantCategory
    {
        $isStam = $applicantType === 'stam';

        if ($category !== null && $category->level() === $programme->level && ($programme->level === 'spm' || $category->requiresStamGrade() === $isStam)) {
            return $category;
        }

        return match (true) {
            $programme->level === 'spm' => ApplicantCategory::SpmCurrentYear,
            $isStam => ApplicantCategory::StamCurrentYear,
            default => ApplicantCategory::StpmScienceCurrentYear,
        };
    }

    /**
     * The applicant type asked for, or the one that matches the applicant's category.
     *
     * @param  array<string, string>  $applicantTypes  The applicant types the programme has figures for.
     */
    private function applicantType(Request $request, ?ApplicantCategory $category, array $applicantTypes): ?string
    {
        if ($applicantTypes === []) {
            return null;
        }

        $preferred = $request->query('applicants', $category?->requiresStamGrade() ? 'stam' : 'stpm');

        return array_key_exists($preferred, $applicantTypes) ? $preferred : array_key_first($applicantTypes);
    }
}
