<?php

namespace App\Http\Controllers;

use App\Eligibility\ApplicantResults;
use App\Eligibility\CourseResults;
use App\Http\Requests\CompareCoursesRequest;
use App\Models\Programme;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * FR05 Single-Page Programme Comparison.
 *
 * Shows two or more favourite courses side by side: the applicant's result for each
 * entry requirement and the course's UPUOnline competition figures.
 */
class CourseComparisonController extends Controller
{
    /**
     * The most courses shown side by side, so each column stays readable.
     */
    public const MAXIMUM_COMPARED = 4;

    public function show(CompareCoursesRequest $request, CourseResults $courseResults): View|RedirectResponse
    {
        $profile = $request->session()->get(AcademicProfileController::SESSION_KEY);

        if ($profile === null) {
            return redirect()->route('academic-profile.create');
        }

        $applicant = ApplicantResults::fromProfile($profile);
        $codes = $request->programmeCodes();
        $results = $courseResults->forApplicant($applicant, $codes)->keyBy('programme.programme_code');
        $programmes = Programme::query()->whereIn('programme_code', $codes)->get()->keyBy('programme_code');

        return view('favourites.compare', [
            'category' => $applicant->category,
            // A favourite course that isn't open to the applicant's category has no result
            'courses' => array_values(array_filter(array_map(
                fn (string $code): ?array => $programmes->has($code) ? [
                    'programme' => $programmes->get($code),
                    'result' => $results->get($code)['result'] ?? null,
                    'indicators' => $results->get($code)['indicators'] ?? null,
                ] : null,
                $codes,
            ))),
        ]);
    }
}
