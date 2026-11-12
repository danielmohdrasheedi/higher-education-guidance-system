<?php

namespace App\Eligibility;

use App\Models\EligibilityRequirement;
use App\Models\HistoricalTrend;
use App\Models\Programme;
use App\Trends\Competitiveness;
use App\Trends\CompetitivenessIndicators;
use Illuminate\Support\Collection;

/**
 * Checks an applicant's results against the programmes open to their category,
 * with each programme's UPUOnline figures for the applicant's type.
 */
final readonly class CourseResults
{
    public function __construct(private EligibilityChecker $checker) {}

    /**
     * @param  list<string>|null  $programmeCodes  Only these programmes, or every programme when null.
     * @return Collection<int, array{programme: Programme, result: EligibilityResult, requiresInterviewOrTest: bool, indicators: CompetitivenessIndicators|null, hasTrends: bool, competitiveness: Competitiveness|null}>
     */
    public function forApplicant(ApplicantResults $applicant, ?array $programmeCodes = null): Collection
    {
        $results = EligibilityRequirement::query()
            ->where('level', $applicant->category->level())
            ->where('target_category', $applicant->category->code())
            ->where('is_offered', true)
            ->when($programmeCodes !== null, fn ($query) => $query->whereIn('programme_code', $programmeCodes))
            ->with('programme')
            ->get()
            ->filter(fn (EligibilityRequirement $requirement): bool => $requirement->programme !== null)
            ->map(fn (EligibilityRequirement $requirement): array => [
                'programme' => $requirement->programme,
                'result' => $this->checker->checkRequirement($requirement, $applicant),
                'requiresInterviewOrTest' => $requirement->mentionsInterviewOrTest(),
            ])
            ->sortBy([
                fn (array $a, array $b): int => $a['programme']->programme_name <=> $b['programme']->programme_name,
                fn (array $a, array $b): int => $a['programme']->institution_name <=> $b['programme']->institution_name,
            ])
            ->values();

        $trendsByProgramme = $this->trendsByProgramme(
            $results->pluck('programme.programme_code')->all(),
            HistoricalTrend::applicantTypeFor($applicant->category),
        );

        return $results->map(function (array $item) use ($trendsByProgramme): array {
            $trends = $trendsByProgramme[$item['programme']->programme_code] ?? [];
            $indicators = $trends === [] ? null : new CompetitivenessIndicators($trends);

            return $item + [
                'indicators' => $indicators,
                'hasTrends' => $indicators !== null,
                'competitiveness' => $indicators?->competitiveness(),
            ];
        });
    }

    /**
     * The UPUOnline figures for each programme, for the given applicant type.
     *
     * @param  list<string>  $programmeCodes
     * @return array<string, list<HistoricalTrend>>
     */
    private function trendsByProgramme(array $programmeCodes, ?string $applicantType): array
    {
        return HistoricalTrend::query()
            ->whereIn('programme_code', $programmeCodes)
            ->when($applicantType !== null, fn ($query) => $query->where('applicant_type', $applicantType))
            ->get()
            ->groupBy('programme_code')
            ->map(fn (Collection $trends): array => $trends->all())
            ->all();
    }
}
