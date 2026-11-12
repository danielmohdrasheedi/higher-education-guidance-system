<?php

namespace App\Eligibility;

use App\Models\Programme;
use App\Trends\Competitiveness;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * FR06 Alternative Programme Recommendation.
 *
 * Finds courses like one the applicant doesn't qualify for, or has to check the rest of, that they do qualify for,
 * or may qualify for once they check what the site can't. A course is like another when it leads to the same kind of qualification and its name is
 * alike, which CourseNameSimilarity works out with natural language processing, so "Diploma In Computer Science" is
 * like "Diploma Sains Komputer". The most alike courses come first. Courses about as alike as each other come in order
 * of competition, the least first.
 */
final class AlternativeCourses
{
    /**
     * The similar courses shown on each page of a list, so the requirements pop-up stays short.
     */
    public const PER_PAGE = 3;

    /**
     * The lists of similar courses: ones the applicant qualifies for, and ones they may qualify for once they check
     * what the site can't. The keys are used in the address of each list's pages.
     */
    public const LISTS = [
        'qualify' => RequirementStatus::Met,
        'check-yourself' => RequirementStatus::Unknown,
    ];

    /**
     * How alike two course names must be, from 0 to 1, for one course to be suggested in place of the other.
     */
    public const MINIMUM_SIMILARITY = 0.25;

    /**
     * Courses this much apart in similarity count as about as alike, so the one with less competition comes first.
     */
    private const SIMILARITY_STEP = 0.1;

    /**
     * The courses the similarity was last worked out for, so it isn't worked out again for every course on a page.
     *
     * @var Collection<int, array{programme: Programme, result: EligibilityResult, hasTrends: bool, competitiveness: Competitiveness|null}>|null
     */
    private ?Collection $comparedResults = null;

    private ?CourseNameSimilarity $similarity = null;

    /**
     * The first page of each list of similar courses.
     *
     * @param  Collection<int, array{programme: Programme, result: EligibilityResult, hasTrends: bool, competitiveness: Competitiveness|null}>  $results  Every course open to the applicant.
     * @return array<key-of<self::LISTS>, LengthAwarePaginator<int, array{programme: Programme, hasTrends: bool, competitiveness: Competitiveness|null}>>
     */
    public function for(Programme $programme, Collection $results): array
    {
        return array_map(
            fn (string $list): LengthAwarePaginator => $this->page($programme, $results, $list, 1),
            array_combine(array_keys(self::LISTS), array_keys(self::LISTS)),
        );
    }

    /**
     * One page of a list of similar courses, most alike first.
     *
     * @param  Collection<int, array{programme: Programme, result: EligibilityResult, hasTrends: bool, competitiveness: Competitiveness|null}>  $results  Every course open to the applicant.
     * @param  key-of<self::LISTS>  $list
     * @return LengthAwarePaginator<int, array{programme: Programme, hasTrends: bool, competitiveness: Competitiveness|null}>
     */
    public function page(Programme $programme, Collection $results, string $list, int $page): LengthAwarePaginator
    {
        $courses = $this->similarCourses($programme, $results)
            ->filter(fn (array $item): bool => $item['result']->status === self::LISTS[$list])
            ->values();
        $page = min(max(1, $page), max(1, (int) ceil($courses->count() / self::PER_PAGE)));

        return new LengthAwarePaginator(
            $courses->forPage($page, self::PER_PAGE)
                ->map(fn (array $item): array => ['programme' => $item['programme'], 'hasTrends' => $item['hasTrends'], 'competitiveness' => $item['competitiveness']])
                ->values()
                ->all(),
            $courses->count(),
            self::PER_PAGE,
            $page,
            ['path' => route('eligibility.similar', ['programme' => $programme->programme_code, 'list' => $list])],
        );
    }

    /**
     * The courses at the same study level whose names are alike enough, most alike first.
     *
     * @param  Collection<int, array{programme: Programme, result: EligibilityResult, hasTrends: bool, competitiveness: Competitiveness|null}>  $results
     * @return Collection<int, array{programme: Programme, result: EligibilityResult, hasTrends: bool, competitiveness: Competitiveness|null, similarity: float}>
     */
    private function similarCourses(Programme $programme, Collection $results): Collection
    {
        $similarity = $this->similarityFor($results);
        $studyLevel = $programme->studyLevel();

        return $results
            ->filter(fn (array $item): bool => $item['programme']->programme_code !== $programme->programme_code
                && $item['programme']->studyLevel() === $studyLevel)
            ->map(fn (array $item): array => $item + [
                'similarity' => $similarity->between($programme->programme_name, $item['programme']->programme_name),
            ])
            ->filter(fn (array $item): bool => $item['similarity'] >= self::MINIMUM_SIMILARITY)
            ->sortBy(fn (array $item): array => [
                -(int) floor($item['similarity'] / self::SIMILARITY_STEP + 1e-9),
                self::competitionRank($item['competitiveness']),
                -$item['similarity'],
            ])
            ->values();
    }

    /**
     * @param  Collection<int, array{programme: Programme, result: EligibilityResult, hasTrends: bool, competitiveness: Competitiveness|null}>  $results
     */
    private function similarityFor(Collection $results): CourseNameSimilarity
    {
        if ($this->similarity === null || $this->comparedResults !== $results) {
            $this->similarity = new CourseNameSimilarity($results->map(fn (array $item): string => $item['programme']->programme_name)->all());
            $this->comparedResults = $results;
        }

        return $this->similarity;
    }

    /**
     * Low competition first, then moderate, high and very high, then courses without UPUOnline figures.
     */
    private static function competitionRank(?Competitiveness $competitiveness): int
    {
        $levels = Competitiveness::cases();

        return $competitiveness === null ? count($levels) : count($levels) - 1 - (int) array_search($competitiveness, $levels, true);
    }
}
