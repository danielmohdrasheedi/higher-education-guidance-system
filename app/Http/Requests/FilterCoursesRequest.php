<?php

namespace App\Http\Requests;

use App\Models\Programme;
use App\Trends\Competitiveness;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * FR04 Multi-Criteria Filtering: the filters chosen on the courses page.
 */
class FilterCoursesRequest extends FormRequest
{
    /**
     * Competition filter value for courses without UPUOnline figures.
     */
    public const NO_FIGURES = 'none';

    /**
     * Separates the state and campus in a location value, e.g. "Selangor|Kampus Bangi".
     * A location without it is a whole state.
     */
    public const CAMPUS_SEPARATOR = '|';

    /**
     * Interview or test filter values.
     */
    public const INTERVIEW_OR_TEST_REQUIRED = 'required';

    public const INTERVIEW_OR_TEST_NONE = 'none';

    /**
     * The courses page is open to every visitor with a saved profile.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Ignore filter values that aren't valid, e.g. from an old or edited link,
     * instead of redirecting away from the courses page.
     */
    protected function prepareForValidation(): void
    {
        foreach ($this->rules() as $field => $rules) {
            if ($this->query->has($field) && validator($this->query->all(), [$field => $rules])->fails()) {
                $this->query->remove($field);
            }
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'institution' => ['nullable', 'string', 'max:200'],
            'location' => ['nullable', 'string', 'max:200'],
            'competition' => ['nullable', Rule::in([...array_column(Competitiveness::cases(), 'value'), self::NO_FIGURES])],
            'interview_or_test' => ['nullable', Rule::in([self::INTERVIEW_OR_TEST_REQUIRED, self::INTERVIEW_OR_TEST_NONE])],
        ];
    }

    /**
     * Whether any filter is chosen.
     */
    public function isFiltering(): bool
    {
        return array_filter($this->filters(), filled(...)) !== [];
    }

    /**
     * @return array{search: ?string, institution: ?string, location: ?string, competition: ?string, interview_or_test: ?string}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'search' => isset($validated['search']) ? Str::squish($validated['search']) : null,
            'institution' => $validated['institution'] ?? null,
            'location' => $validated['location'] ?? null,
            'competition' => $validated['competition'] ?? null,
            'interview_or_test' => $validated['interview_or_test'] ?? null,
        ];
    }

    /**
     * Whether a course matches every chosen filter.
     */
    public function matches(Programme $programme, ?Competitiveness $competitiveness, bool $hasFigures, bool $requiresInterviewOrTest): bool
    {
        $filters = $this->filters();

        return $this->matchesSearch($programme, $filters['search'])
            && ($filters['institution'] === null || $programme->institution_name === $filters['institution'])
            && $this->matchesLocation($programme, $filters['location'])
            && match ($filters['competition']) {
                null => true,
                self::NO_FIGURES => ! $hasFigures,
                default => $competitiveness?->value === $filters['competition'],
            }
        && match ($filters['interview_or_test']) {
            null => true,
            self::INTERVIEW_OR_TEST_REQUIRED => $requiresInterviewOrTest,
            default => ! $requiresInterviewOrTest,
        };
    }

    /**
     * The state and campus of a location value. The campus is null when the location is a whole state.
     *
     * @return array{state: string, campus: ?string}
     */
    public static function parseLocation(string $location): array
    {
        [$state, $campus] = array_pad(explode(self::CAMPUS_SEPARATOR, $location, 2), 2, null);

        return ['state' => $state, 'campus' => $campus];
    }

    /**
     * A course matches a state when it has a campus in that state, and a campus when it is offered there.
     */
    private function matchesLocation(Programme $programme, ?string $location): bool
    {
        if ($location === null) {
            return true;
        }

        ['state' => $state, 'campus' => $campus] = self::parseLocation($location);

        if ($campus === null) {
            return in_array($state, $programme->states(), true);
        }

        foreach ($programme->campuses ?? [] as $offered) {
            if ($offered['state'] === $state && $offered['campus'] === $campus) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every word searched for must appear in the course name or code.
     */
    private function matchesSearch(Programme $programme, ?string $search): bool
    {
        if ($search === null || $search === '') {
            return true;
        }

        $haystack = Str::lower(Str::ascii($programme->programme_name.' '.$programme->programme_code));

        foreach (explode(' ', Str::lower(Str::ascii($search))) as $word) {
            if (! str_contains($haystack, $word)) {
                return false;
            }
        }

        return true;
    }
}
