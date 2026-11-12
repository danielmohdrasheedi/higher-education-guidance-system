<?php

namespace App\Http\Controllers;

use App\Eligibility\AlternativeCourses;
use App\Eligibility\ApplicantResults;
use App\Eligibility\CourseResults;
use App\Eligibility\EligibilityResult;
use App\Eligibility\RequirementStatus;
use App\Http\Requests\FilterCoursesRequest;
use App\Models\Programme;
use App\Trends\Competitiveness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * FR02 Automated Eligibility Checking, FR04 Multi-Criteria Filtering and FR06 Alternative Programme Recommendation.
 *
 * Checks the academic profile in the session against the entry requirements
 * of every programme open to the applicant's category, then keeps the
 * programmes that match the filters chosen on the page. Each course the applicant
 * doesn't qualify for, or has to check the rest of, comes with similar courses they do qualify for, and each
 * similar course's requirements can be opened in a pop-up of their own.
 */
class EligibilityController extends Controller
{
    public const PER_PAGE = 5;

    public function index(FilterCoursesRequest $request, CourseResults $courseResults, AlternativeCourses $alternativeCourses): View|RedirectResponse
    {
        $profile = $request->session()->get(AcademicProfileController::SESSION_KEY);

        if ($profile === null) {
            return redirect()->route('academic-profile.create');
        }

        $applicant = ApplicantResults::fromProfile($profile);
        $results = $courseResults->forApplicant($applicant);

        $filtered = $results
            ->filter(fn (array $item): bool => $request->matches($item['programme'], $item['competitiveness'], $item['hasTrends'], $item['requiresInterviewOrTest']))
            ->values();

        /** @var array<string, list<array{programme: Programme, result: EligibilityResult, hasTrends: bool, competitiveness: Competitiveness|null}>> $grouped */
        $grouped = $filtered->groupBy(fn (array $item): string => $item['result']->status->value)->map->all()->all();

        $filters = $request->filters();
        $campusesByInstitution = $this->campusesByInstitution($results);

        return view('eligibility.index', [
            'category' => $applicant->category,
            'total' => $results->count(),
            'shown' => $filtered->count(),
            'filters' => $filters,
            'isFiltering' => $request->isFiltering(),
            'campusesByInstitution' => $campusesByInstitution,
            'institutions' => $this->institutionOptions($campusesByInstitution, $filters['location'], $filters['institution']),
            'locations' => $this->locationOptions($campusesByInstitution, $filters['institution'], $filters['location']),
            'favouriteCourses' => FavouriteController::favouriteCodes($request),
            'eligible' => $this->paginate($grouped[RequirementStatus::Met->value] ?? [], 'eligible', $request),
            // Similar courses come from every course open to the applicant, not only those matching the filters
            'unchecked' => $this->paginate($grouped[RequirementStatus::Unknown->value] ?? [], 'unchecked', $request)
                ->through(fn (array $item): array => $item + ['alternatives' => $alternativeCourses->for($item['programme'], $results)]),
            'ineligible' => $this->paginate($grouped[RequirementStatus::NotMet->value] ?? [], 'ineligible', $request)
                ->through(fn (array $item): array => $item + ['alternatives' => $alternativeCourses->for($item['programme'], $results)]),
        ]);
    }

    /**
     * The requirements pop-up of one course, checked against the academic profile in the session. A similar course in
     * another course's pop-up opens it on top, through public/js/requirements-dialog.js.
     */
    public function requirements(Request $request, Programme $programme, CourseResults $courseResults): View|RedirectResponse
    {
        $profile = $request->session()->get(AcademicProfileController::SESSION_KEY);

        if ($profile === null) {
            return redirect()->route('academic-profile.create');
        }

        $applicant = ApplicantResults::fromProfile($profile);
        $item = $courseResults->forApplicant($applicant, [$programme->programme_code])->first();

        abort_if($item === null, 404);

        return view('eligibility.partials.requirements-dialog', [
            'dialogId' => 'similar-requirements-'.$programme->programme_code,
            'programme' => $programme,
            'result' => $item['result'],
            'officialLink' => $programme->officialPortalLink($applicant->category),
            'alternatives' => null,
        ]);
    }

    /**
     * One page of a list of similar courses in a course's requirements pop-up, e.g. the courses like it that the
     * applicant qualifies for. public/js/requirements-dialog.js swaps it in when a page link in the pop-up is pressed.
     */
    public function similarCourses(Request $request, Programme $programme, string $list, CourseResults $courseResults, AlternativeCourses $alternativeCourses): View|RedirectResponse
    {
        $profile = $request->session()->get(AcademicProfileController::SESSION_KEY);

        if ($profile === null) {
            return redirect()->route('academic-profile.create');
        }

        $applicant = ApplicantResults::fromProfile($profile);
        $results = $courseResults->forApplicant($applicant);

        abort_unless($results->contains(fn (array $item): bool => $item['programme']->programme_code === $programme->programme_code), 404);

        $titles = ['qualify' => 'Similar courses you qualify for', 'check-yourself' => 'Similar courses to check yourself'];

        return view('eligibility.partials.alternative-course-list', [
            'courses' => $alternativeCourses->page($programme, $results, $list, $request->integer('page', 1)),
            'label' => $titles[$list],
            'favouriteCourses' => FavouriteController::favouriteCodes($request),
            'category' => $applicant->category,
        ]);
    }

    /**
     * One page of a result group. Each group has its own page number, e.g. "?eligible_page=2",
     * and its links go back to the group on the page.
     *
     * @param  list<array<string, mixed>>  $items
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginate(array $items, string $group, FilterCoursesRequest $request): LengthAwarePaginator
    {
        $pageName = "{$group}_page";
        $lastPage = max(1, (int) ceil(count($items) / self::PER_PAGE));
        $page = min(max(1, $request->integer($pageName, 1)), $lastPage);

        return (new LengthAwarePaginator(
            array_slice($items, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            count($items),
            self::PER_PAGE,
            $page,
            ['path' => route('eligibility.index'), 'pageName' => $pageName, 'query' => $request->query()],
        ))->fragment($group);
    }

    /**
     * The campuses each institution offers courses at, grouped by state, for the courses open
     * to the applicant. The page uses it to offer only institution and location pairs that have courses.
     *
     * @param  Collection<int, array{programme: Programme}>  $results
     * @return array<string, array<string, list<string>>>
     */
    private function campusesByInstitution(Collection $results): array
    {
        $campuses = [];

        foreach ($results as $item) {
            $programme = $item['programme'];
            $campuses[$programme->institution_name] ??= [];

            foreach ($programme->campuses ?? [] as $campus) {
                if ($campus['state'] !== '') {
                    $campuses[$programme->institution_name][$campus['state']][$campus['campus']] = true;
                }
            }
        }

        ksort($campuses);

        foreach ($campuses as &$states) {
            ksort($states);
            $states = array_map(function (array $names): array {
                $names = array_keys($names);
                sort($names);

                return $names;
            }, $states);
        }

        return $campuses;
    }

    /**
     * The institutions with courses at the chosen state or campus, or every institution when no location is chosen.
     *
     * @param  array<string, array<string, list<string>>>  $campusesByInstitution
     * @return list<string>
     */
    private function institutionOptions(array $campusesByInstitution, ?string $location, ?string $chosen): array
    {
        $place = $location === null ? null : FilterCoursesRequest::parseLocation($location);

        $institutions = array_keys(array_filter(
            $campusesByInstitution,
            fn (array $states): bool => $place === null
                || ($place['campus'] === null
                    ? isset($states[$place['state']])
                    : in_array($place['campus'], $states[$place['state']] ?? [], true)),
        ));

        if ($chosen !== null && ! in_array($chosen, $institutions, true)) {
            $institutions[] = $chosen;
            sort($institutions);
        }

        return $institutions;
    }

    /**
     * The location options. With no institution chosen these are the states. With an institution
     * chosen, each of its states is followed by its campuses there, and a state or a campus can be chosen.
     *
     * @param  array<string, array<string, list<string>>>  $campusesByInstitution
     * @return list<array{value: string, label: string, isCampus: bool}>
     */
    private function locationOptions(array $campusesByInstitution, ?string $institution, ?string $chosen): array
    {
        $options = [];

        if ($institution === null) {
            $states = array_unique(array_merge([], ...array_map(array_keys(...), array_values($campusesByInstitution))));
            sort($states);

            foreach ($states as $state) {
                $options[] = ['value' => $state, 'label' => $state, 'isCampus' => false];
            }
        } else {
            foreach ($campusesByInstitution[$institution] ?? [] as $state => $campuses) {
                $options[] = ['value' => $state, 'label' => $state, 'isCampus' => false];

                foreach ($campuses as $campus) {
                    $options[] = ['value' => $state.FilterCoursesRequest::CAMPUS_SEPARATOR.$campus, 'label' => $campus, 'isCampus' => true];
                }
            }
        }

        // Keep the chosen location so the filter still shows what was asked for
        if ($chosen !== null && ! in_array($chosen, array_column($options, 'value'), true)) {
            $place = FilterCoursesRequest::parseLocation($chosen);
            $options[] = ['value' => $chosen, 'label' => $place['campus'] ?? $place['state'], 'isCampus' => $place['campus'] !== null];
        }

        return $options;
    }
}
