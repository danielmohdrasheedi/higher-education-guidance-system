<?php

namespace App\Http\Controllers;

use App\Eligibility\ApplicantResults;
use App\Eligibility\CourseResults;
use App\Models\Programme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * FR05 Single-Page Programme Comparison: the courses the visitor adds to favourites to compare.
 *
 * Like the academic profile, favourites are kept in the session only (NFR05).
 */
class FavouriteController extends Controller
{
    public const SESSION_KEY = 'favourite_courses';

    /**
     * The most courses that can be favourites, so the list stays easy to choose from.
     */
    public const MAXIMUM_FAVOURITES = 30;

    public const PER_PAGE = 5;

    /**
     * The programme codes of the favourite courses, in the order they were added.
     *
     * @return list<string>
     */
    public static function favouriteCodes(Request $request): array
    {
        return array_values($request->session()->get(self::SESSION_KEY, []));
    }

    public function index(Request $request, CourseResults $courseResults): View|RedirectResponse
    {
        $profile = $request->session()->get(AcademicProfileController::SESSION_KEY);

        if ($profile === null) {
            return redirect()->route('academic-profile.create');
        }

        $applicant = ApplicantResults::fromProfile($profile);
        $codes = self::favouriteCodes($request);
        $page = max(1, $request->integer('page', 1));
        $lastPage = max(1, (int) ceil(count($codes) / self::PER_PAGE));

        // e.g. after removing the only course on the last page
        if ($page > $lastPage) {
            return redirect()->route('favourites.index', ['page' => $lastPage, 'courses' => $request->query('courses')]);
        }

        $pageCodes = array_slice($codes, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        $results = $courseResults->forApplicant($applicant, $pageCodes)->keyBy('programme.programme_code');
        $programmes = Programme::query()->whereIn('programme_code', $pageCodes)->get()->keyBy('programme_code');

        $courses = array_values(array_filter(array_map(
            fn (string $code): ?array => $programmes->has($code) ? [
                'programme' => $programmes->get($code),
                'result' => $results->get($code)['result'] ?? null,
                'hasTrends' => $results->get($code)['hasTrends'] ?? false,
                'indicators' => $results->get($code)['indicators'] ?? null,
                'competitiveness' => $results->get($code)['competitiveness'] ?? null,
            ] : null,
            $pageCodes,
        )));

        // Courses ticked for comparison, kept while moving between pages
        $chosen = array_values(array_intersect($codes, (array) $request->query('courses', old('courses', []))));

        return view('favourites.index', [
            'category' => $applicant->category,
            'courses' => new LengthAwarePaginator($courses, count($codes), self::PER_PAGE, $page, [
                'path' => route('favourites.index'),
            ]),
            'chosen' => $chosen,
            'chosenElsewhere' => array_values(array_diff($chosen, $pageCodes)),
            'maximumCompared' => CourseComparisonController::MAXIMUM_COMPARED,
        ]);
    }

    public function store(Request $request, Programme $programme): RedirectResponse|JsonResponse
    {
        $codes = self::favouriteCodes($request);

        if (! in_array($programme->programme_code, $codes, true)) {
            if (count($codes) >= self::MAXIMUM_FAVOURITES) {
                return $this->respond($request, $programme, 'You can have up to '.self::MAXIMUM_FAVOURITES.' favourites. Remove one to add another.');
            }

            $request->session()->put(self::SESSION_KEY, [...$codes, $programme->programme_code]);
        }

        return $this->respond($request, $programme, "Added {$programme->programme_name} to your favourites.");
    }

    public function destroy(Request $request, Programme $programme): RedirectResponse|JsonResponse
    {
        $request->session()->put(
            self::SESSION_KEY,
            array_values(array_diff(self::favouriteCodes($request), [$programme->programme_code])),
        );

        return $this->respond($request, $programme, "Removed {$programme->programme_name} from your favourites.");
    }

    /**
     * The heart on the courses and admission trends pages sends the form in the background and only needs the new state, so the
     * page stays where it is. Without JavaScript the form is sent normally, and the visitor goes back to the
     * page the button was on, at the course's place in the list.
     */
    private function respond(Request $request, Programme $programme, string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            $codes = self::favouriteCodes($request);

            return response()->json([
                'isFavourite' => in_array($programme->programme_code, $codes, true),
                'count' => count($codes),
                'message' => $message,
            ]);
        }

        return redirect()->to(url()->previous(route('favourites.index')).'#course-'.$programme->programme_code)
            ->with('status', $message);
    }
}
