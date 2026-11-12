<?php

namespace App\Eligibility;

use App\ApplicantCategory;

/**
 * An applicant's saved academic profile, prepared for eligibility checking.
 */
final readonly class ApplicantResults
{
    /**
     * The levels with subject grades, each saved in the profile as "{level}_subjects".
     *
     * @var list<string>
     */
    public const SUBJECT_LEVELS = ['spm', 'stpm', 'matriculation', 'diploma', 'equivalent', 'prau', 'ib'];

    /**
     * @param  array<string, array<string, array{subject: string, grade: string, level?: string}>>  $subjectGrades  Grades by level, each keyed by SubjectName::key(). IB grades also give the level taken, HL or SL.
     * @param  int|null  $ibPoints  The IB Diploma total, out of 45.
     * @param  float|null  $atar  The AUSMAT Australian Tertiary Admission Rank (ATAR), out of 99.95.
     * @param  int|null  $ausmatAggregate  The AUSMAT aggregate, out of 100.
     */
    public function __construct(
        public ApplicantCategory $category,
        public string $gender,
        public array $subjectGrades,
        public ?float $cgpa,
        public ?string $muetBand,
        public ?string $stamGrade,
        public ?int $age = null,
        public ?string $sportsLevel = null,
        public ?bool $isOfEligibleDescent = null,
        public ?bool $isColourBlind = null,
        public ?float $ieltsBand = null,
        public ?int $toeflIbtScore = null,
        public ?string $musicGrade = null,
        public ?int $quranJuzMemorised = null,
        public ?int $ibPoints = null,
        public ?float $atar = null,
        public ?int $ausmatAggregate = null,
    ) {}

    /**
     * @param  array{applicant_category: string, gender: string, cgpa?: float|null, muet_band?: string|null, stam_grade?: string|null, age?: int|null, sports_level?: string|null, eligible_descent?: string|null, colour_blind?: string|null, ielts_band?: string|null, toefl_ibt_score?: int|null, music_grade?: string|null, quran_juz?: int|null, spm_subjects?: list<array{subject: string, grade: string}>|null, stpm_subjects?: list<array{subject: string, grade: string}>|null, matriculation_subjects?: list<array{subject: string, grade: string}>|null, diploma_subjects?: list<array{subject: string, grade: string}>|null, equivalent_subjects?: list<array{subject: string, grade: string}>|null, prau_subjects?: list<array{subject: string, grade: string}>|null, ib_subjects?: list<array{subject: string, level: string, grade: string}>|null, ib_points?: int|null, atar?: float|string|null, ausmat_aggregate?: int|null}  $profile
     */
    public static function fromProfile(array $profile): self
    {
        $subjectGrades = [];

        foreach (self::SUBJECT_LEVELS as $level) {
            $subjectGrades[$level] = self::keyGrades($profile["{$level}_subjects"] ?? [], $level);
        }

        return new self(
            category: ApplicantCategory::from($profile['applicant_category']),
            gender: $profile['gender'],
            subjectGrades: $subjectGrades,
            cgpa: $profile['cgpa'] ?? null,
            muetBand: $profile['muet_band'] ?? null,
            stamGrade: $profile['stam_grade'] ?? null,
            age: $profile['age'] ?? null,
            sportsLevel: $profile['sports_level'] ?? null,
            isOfEligibleDescent: self::yesNo($profile['eligible_descent'] ?? null),
            isColourBlind: self::yesNo($profile['colour_blind'] ?? null),
            ieltsBand: isset($profile['ielts_band']) ? (float) $profile['ielts_band'] : null,
            toeflIbtScore: $profile['toefl_ibt_score'] ?? null,
            musicGrade: $profile['music_grade'] ?? null,
            quranJuzMemorised: $profile['quran_juz'] ?? null,
            ibPoints: $profile['ib_points'] ?? null,
            atar: isset($profile['atar']) ? (float) $profile['atar'] : null,
            ausmatAggregate: $profile['ausmat_aggregate'] ?? null,
        );
    }

    /**
     * The position of the applicant's music grade in config('academic.music_grades'), from 1 for
     * Grade 1 to 9 for a diploma, or 0 when no grade is given.
     */
    public function musicGradeRank(): int
    {
        // The grade keys "1" to "8" are integer keys in PHP, so they are compared as strings
        $grades = array_map(strval(...), array_keys(config('academic.music_grades')));
        $rank = $this->musicGrade === null ? false : array_search($this->musicGrade, $grades, true);

        return $rank === false ? 0 : $rank + 1;
    }

    /**
     * A saved "yes" or "no" answer, or null when the question wasn't answered.
     */
    private static function yesNo(?string $answer): ?bool
    {
        return match ($answer) {
            'yes' => true,
            'no' => false,
            default => null,
        };
    }

    /**
     * The position of the applicant's sports level in config('academic.sports_levels'),
     * where a higher number is a higher level, or null when it isn't given.
     */
    public function sportsRank(): ?int
    {
        return self::sportsLevelRank($this->sportsLevel);
    }

    public static function sportsLevelRank(?string $level): ?int
    {
        $rank = $level === null ? false : array_search($level, array_keys(config('academic.sports_levels')), true);

        return $rank === false ? null : $rank;
    }

    /**
     * Whether the applicant holds the qualification a requirement section is written for.
     */
    public function holds(string $qualification): bool
    {
        $group = $this->category->group();

        return match ($qualification) {
            'spm' => true,
            'stpm' => $group === 'STPM' && $this->category !== ApplicantCategory::SportsSchool,
            'matriculation' => $group === 'Matriculation / Foundation',
            'stam' => $group === 'STAM',
            'diploma' => $group === 'Diploma',
            // The general requirements list the sports school's Pra-U under "Kelayakan Setaraf"
            'equivalent' => $group === 'Other qualifications' || $this->category === ApplicantCategory::SportsSchool,
            'prau' => $this->category === ApplicantCategory::SportsSchool,
            'ib' => $this->category === ApplicantCategory::InternationalBaccalaureateDiploma,
            default => false,
        };
    }

    /**
     * @return array<string, array{subject: string, grade: string, level?: string}>
     */
    public function grades(string $level): array
    {
        return $this->subjectGrades[$level] ?? [];
    }

    /**
     * Whether the applicant said they haven't taken MUET.
     */
    public function hasNoMuet(): bool
    {
        return $this->muetBand === config('academic.no_muet');
    }

    /**
     * The MUET band as a number, with "5+" counted above 5.0, or null when there is no band.
     */
    public function muetScore(): ?float
    {
        if ($this->muetBand === null || $this->hasNoMuet()) {
            return null;
        }

        return $this->muetBand === '5+' ? 5.5 : (float) $this->muetBand;
    }

    /**
     * @param  list<array{subject: string, grade: string, level?: string}>  $rows
     * @return array<string, array{subject: string, grade: string, level?: string}>
     */
    private static function keyGrades(array $rows, string $level): array
    {
        $grades = [];

        foreach ($rows as $row) {
            $grades[SubjectName::key($row['subject'], $level)] = $row;
        }

        return $grades;
    }
}
